#!/usr/bin/env python3
"""Validate the egg; optionally exercise install, network play and reinstall in real Docker images."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import time

ROOT=Path(__file__).resolve().parents[1]
EGG=ROOT/'deploy/panel/egg-php-minecraft-java-server.json'


def run(args, **kwargs):
    return subprocess.run(args,check=True,text=True,**kwargs)


def unit():
    run(['python3',str(ROOT/'tools/build_panel_egg.py'),'--check'])
    egg=json.loads(EGG.read_text())
    assert egg['meta']['version']=='PTDL_v2'
    for key in ('files','startup','logs'):
        assert isinstance(json.loads(egg['config'][key]),dict)
    assert json.loads(egg['config']['startup'])['done']=='Listening on '
    assert egg['config']['stop']=='^SIGTERM'
    assert egg['scripts']['installation']['entrypoint']=='bash'
    assert egg['scripts']['installation']['script']==(ROOT/'deploy/panel/install.sh').read_text()
    assert 'SERVER_PORT' not in [v['env_variable'] for v in egg['variables']]
    assert len({v['env_variable'] for v in egg['variables']})==len(egg['variables'])
    for path in ('install.sh','start.sh'):
        run(['bash','-n',str(ROOT/'deploy/panel'/path)])
    for bad in ({'SERVER_REF':'../escape'},{'SERVER_REF':'main;false'},{'BUILD_JOBS':'0'}):
        result=subprocess.run(['bash',str(ROOT/'deploy/panel/install.sh')],env={**os.environ,**bad},capture_output=True,text=True)
        assert result.returncode!=0
        assert 'Invalid SERVER_REF' in result.stderr or 'BUILD_JOBS must' in result.stderr
    with tempfile.TemporaryDirectory(prefix='panel-config-') as temporary:
        env={**os.environ,'SERVER_PORT':'25676','WORLD_SEED':'-2147483648','MAX_PLAYERS':'12','VIEW_DISTANCE':'2',
             'GAME_MODE':'1','DIFFICULTY':'3','OPERATORS':' Luna,Test_2 ','SERVER_MOTD':'A panel world 🌍'}
        run(['php',str(ROOT/'deploy/panel/configure.php')],cwd=temporary,env=env)
        config=Path(temporary)/'server.json';data=json.loads(config.read_text())
        assert data['port']==25676 and isinstance(data['port'],int) and data['host']=='0.0.0.0'
        assert data['seed']==-2147483648 and data['operators']==['Luna','Test_2'] and data['motd']=='A panel world 🌍'
        original=config.read_bytes()
        run(['php',str(ROOT/'deploy/panel/configure.php')],cwd=temporary,env={**env,'WORLD_SEED':'42','OPERATORS':'Other'})
        assert config.read_bytes()==original
        config.unlink()
        result=subprocess.run(['php',str(ROOT/'deploy/panel/configure.php')],cwd=temporary,env={**env,'VIEW_DISTANCE':'5'},capture_output=True,text=True)
        assert result.returncode!=0 and not config.exists()
    print('PASS: egg schema, embedded installer, input validation and preserved configuration',flush=True)


def container():
    import integration as net
    egg=json.loads(EGG.read_text())
    source_ref=os.environ.get('PANEL_TEST_REF') or os.environ.get('GITHUB_SHA') or subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    image=next(iter(egg['docker_images'].values()))
    installer_image=egg['scripts']['installation']['container']
    run(['docker','pull',image])
    with tempfile.TemporaryDirectory(prefix='panel-egg-container-') as temp:
        temp=Path(temp);server=temp/'server';server.mkdir()
        script=temp/'install.sh';script.write_text(egg['scripts']['installation']['script'])
        variables={v['env_variable']:v['default_value'] for v in egg['variables']}
        variables.update({'SERVER_REF':source_ref,'WORLD_SEED':'42','VIEW_DISTANCE':'2','OPERATORS':'EggProbe','SERVER_PORT':'25676','ONLINE_MODE':'false'})
        uid,gid=os.getuid(),os.getgid()
        def install(changed=False):
            env={**variables}
            if changed:env.update({'WORLD_SEED':'98765','OPERATORS':'Changed','SERVER_MOTD':'Do not overwrite settings'})
            args=['docker','run','--rm','--volume',f'{server}:/mnt/server','--volume',f'{script}:/egg-install.sh:ro']
            for key,value in env.items():args+=['--env',f'{key}={value}']
            args+=[installer_image,'bash','/egg-install.sh']
            run(args)
        def own_files():
            run(['docker','run','--rm','--volume',f'{server}:/mnt/server',installer_image,'chown','-R',f'{uid}:{gid}','/mnt/server'])
        def start(port=25676):
            args=['docker','run','--detach','--user',f'{uid}:{gid}','--memory','1536m',
                  '--volume',f'{server}:/home/container','--publish',f'127.0.0.1::{port}',
                  '--env',f'STARTUP={egg["startup"]}','--env',f'SERVER_PORT={port}',image]
            name=subprocess.check_output(args,text=True).strip()
            for _ in range(100):
                logs=subprocess.check_output(['docker','logs',name],text=True,stderr=subprocess.STDOUT)
                if 'Listening on ' in logs:break
                if subprocess.check_output(['docker','inspect','--format','{{.State.Running}}',name],text=True).strip()!='true':
                    raise AssertionError(logs)
                time.sleep(.1)
            else:raise AssertionError('Runtime startup timed out: '+logs)
            mapping=json.loads(subprocess.check_output(['docker','inspect',name],text=True))[0]['NetworkSettings']['Ports']
            return name,int(mapping[f'{port}/tcp'][0]['HostPort'])
        def stop(name):
            run(['docker','kill','--signal=TERM',name],stdout=subprocess.DEVNULL)
            result=run(['docker','wait',name],capture_output=True,timeout=30)
            assert result.stdout.strip()=='0',subprocess.check_output(['docker','logs',name],text=True,stderr=subprocess.STDOUT)
            logs=subprocess.check_output(['docker','logs',name],text=True,stderr=subprocess.STDOUT)
            assert 'Server stopped' in logs and 'PHP 8.5.' in logs
            run(['docker','rm',name],stdout=subprocess.DEVNULL)
        active=None
        try:
            install();own_files()
            active,port=start()
            c=net.Client(port,terrain=True).login('EggProbe')
            try:
                c.send('chat_command',net.string('setblock 1 65 1 diamond_block'))
                c.until('system_chat',lambda p:b'Block updated' in p)
                c.send('chat_command',net.string('give @s diamond 3'))
                c.until('system_chat',lambda p:b'Gave' in p)
            finally:c.close()
            stop(active);active=None
            config=(server/'server.json').read_bytes()
            with sqlite3.connect(server/'data/world-terrain.sqlite') as db:
                assert db.execute('SELECT state FROM blocks WHERE x=1 AND y=65 AND z=1').fetchone()[0]==net.BLOCKS['states']['diamond_block']
                profile=json.loads(db.execute('SELECT data FROM players').fetchone()[0])
                assert any(s['item']==net.BLOCKS['items']['diamond'] and s['count']==3 for s in profile['inventory'].values())
            databases={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in (server/'data').glob('*.sqlite')}
            assert len(databases)==3
            install(changed=True);own_files()
            assert (server/'server.json').read_bytes()==config
            assert databases=={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in (server/'data').glob('*.sqlite')}
            # A changed panel allocation must override the saved JSON port on the next boot.
            active,port=start(25677)
            c=net.Client(port,terrain=True).login('EggProbe');c.close()
            stop(active);active=None
            print('PASS: actual GitHub CDN install, non-root yolk, Java login, SIGTERM save, all dimensions/inventory/config preserved on reinstall, changed allocation',flush=True)
        finally:
            if active:
                subprocess.run(['docker','rm','-f',active],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            own_files()


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--container',action='store_true')
    args=parser.parse_args()
    unit()
    if args.container:container()
