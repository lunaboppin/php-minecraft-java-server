#!/usr/bin/env python3
"""Run a non-root container, join over TCP and prove config/world survival through restart."""
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import time
import uuid
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/parity'))
from protocol import Probe,wire


def docker(*args):return subprocess.check_output(['docker',*args],text=True).strip()
def ready(name,starts=1):
    deadline=time.monotonic()+60
    while time.monotonic()<deadline:
        if docker('logs',name).count('Listening on')>=starts:return
        if docker('inspect','--format','{{.State.Running}}',name)!='true':raise AssertionError(docker('logs',name))
        time.sleep(.1)
    raise AssertionError('Container did not become ready')
def main():
    image=os.environ.get('MINECRAFT_TEST_IMAGE','php-minecraft-java-server:gameplay');name='php-minecraft-test-'+uuid.uuid4().hex[:10]
    with tempfile.TemporaryDirectory(prefix='php-minecraft-docker-') as tmp:
        path=Path(tmp);cfg=path/'server.json';cfg.write_text(json.dumps({'worldPath':'data/world.sqlite','generator':'flat','defaultGameMode':1,'operators':['ParityProbe'],'logDirectory':'data/logs'}))
        before=cfg.read_bytes()
        try:
            docker('run','-d','--name',name,'--init','--user',f'{os.getuid()}:{os.getgid()}','--cap-drop','ALL','--security-opt','no-new-privileges:true','-p','127.0.0.1::25676','--mount',f'type=bind,src={tmp},dst=/app/data',image)
            ready(name);port=int(docker('port',name,'25676/tcp').rsplit(':',1)[1]);c=Probe(port,'container').login()
            try:
                for text in ['give @s minecraft:diamond 3','setblock 1 64 1 minecraft:diamond_block']:
                    c.send('chat_command',wire.string(text));c.tick_barrier()
                assert c.block([1,64,1])==wire.BLOCKS['states']['diamond_block']
                assert int(docker('exec',name,'id','-u'))==os.getuid()!=0
                docker('exec',name,'php','deploy/docker/health.php')
                version=docker('exec',name,'php','-r','require "src/bootstrap.php"; if (!extension_loaded("pcntl")) exit(1); echo PHP_VERSION;')
            finally:c.close()
            docker('restart','--timeout','45',name);ready(name,2);port=int(docker('port',name,'25676/tcp').rsplit(':',1)[1]);c=Probe(port,'container-restart').login()
            try:
                c.refresh_inventory();assert c.inventory[36]['item']==wire.BLOCKS['items']['diamond'] and c.inventory[36]['count']==3
                assert c.block([1,64,1])==wire.BLOCKS['states']['diamond_block']
                update='Development update: improving mob movement, damage reactions and sounds.'
                announcement=path/'server-announcement.txt';announcement.write_text(update+'\n')
                c.until('system_chat',lambda payload:update.encode() in payload)
                assert not announcement.exists(),'announcement not consumed'
                message='Server restarting: mob damage and sound fixes added. Please reconnect shortly.'
                notice=path/'restart-reason.txt';notice.write_text(message+'\n')
                docker('stop','--time','45',name)
                reason=c.until('disconnect');assert message.encode() in reason,reason
                assert not notice.exists(),'restart notice was not consumed'
                assert not list(path.glob('restart-reason.txt.consuming-*')),'claimed notice was not cleaned up'
            finally:c.close()
            assert cfg.read_bytes()==before,'configuration overwritten'
            assert 'Server stopped' in docker('logs',name)
            assert list((path/'logs').glob('server-*.log')),'logs not persisted'
            assert all((path/('world.sqlite'+suffix)).is_file() for suffix in ['', '.the_nether.sqlite','.the_end.sqlite'])
            print(f'PASS: non-root Docker PHP {version}, protocol health, actual join, SIGTERM saves, config/inventory/block/dimension persistence')
        finally:subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)


if __name__=='__main__':main()
