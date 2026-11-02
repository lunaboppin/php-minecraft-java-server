#!/usr/bin/env python3
"""Run scripted observations against PHP and the checksum-pinned official Java server."""
import argparse
import contextlib
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import socket
import struct
import subprocess
import sys
import time
import uuid

from protocol import Probe,ROOT,wire
sys.path.insert(0,str(ROOT/'tools'))
from java_logging import plain_reference_logging


def difference(expected,actual,path='$'):
    if type(expected)!=type(actual):return {'path':path,'reference':expected,'php':actual}
    if isinstance(expected,dict):
        if expected.keys()!=actual.keys():return {'path':path,'reference_keys':list(expected),'php_keys':list(actual)}
        for key in expected:
            result=difference(expected[key],actual[key],path+'.'+key)
            if result:return result
    elif isinstance(expected,list):
        if len(expected)!=len(actual):return {'path':path+'.length','reference':len(expected),'php':len(actual)}
        for i,(a,b) in enumerate(zip(expected,actual)):
            result=difference(a,b,f'{path}[{i}]')
            if result:return result
    elif expected!=actual:return {'path':path,'reference':expected,'php':actual}
    return None


def free_port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]




class Server:
    def __init__(self,path,kind,java,jar,eula,difficulty=0,spawn_protection=0):
        self.path=path;path.mkdir();self.kind=kind;self.java=java;self.jar=jar;self.eula=eula;self.proc=None
        self.port=free_port();self.log=path/'server.log'
        if kind=='php':
            (path/'server.json').write_text(json.dumps({'host':'127.0.0.1','port':self.port,'worldPath':str(path/'world.sqlite'),
                'logDirectory':str(path/'logs'),'generator':'flat','seed':42,'viewDistance':2,'defaultGameMode':1,'difficulty':difficulty,'operators':['ParityProbe'],'spawnProtection':spawn_protection}))
        else:
            plain_reference_logging(jar,path/'log4j2-plain.xml')
            # Copy an existing acknowledgement. This harness never accepts the EULA on the user's behalf.
            shutil.copyfile(eula,path/'eula.txt')
            profile=bytearray(hashlib.md5(b'OfflinePlayer:ParityProbe').digest());profile[6]=(profile[6]&15)|48;profile[8]=(profile[8]&63)|128
            (path/'ops.json').write_text(json.dumps([{'uuid':str(uuid.UUID(bytes=bytes(profile))),'name':'ParityProbe','level':4,'bypassesPlayerLimit':True}]))
            settings={'layers':[{'height':124,'block':'minecraft:air'},{'height':1,'block':'minecraft:bedrock'},
                {'height':2,'block':'minecraft:dirt'},{'height':1,'block':'minecraft:grass_block'}],'biome':'minecraft:plains','features':False,'lakes':False}
            props={'server-ip':'127.0.0.1','server-port':self.port,'online-mode':'false','enforce-secure-profile':'false','white-list':'false','max-players':20,
                'view-distance':2,'simulation-distance':2,'gamemode':'creative','difficulty':['peaceful','easy','normal','hard'][difficulty],'spawn-protection':spawn_protection,
                'level-seed':42,'level-type':'minecraft:flat','generator-settings':json.dumps(settings),'generate-structures':'false',
                'spawn-animals':'false','spawn-monsters':'false','pause-when-empty-seconds':0,'network-compression-threshold':256}
            (path/'server.properties').write_text(''.join(f'{k}={v}\n' for k,v in props.items()))

    def start(self):
        command=['php',str(ROOT/'bin/server.php'),'--config='+str(self.path/'server.json')] if self.kind=='php' else [self.java,'-Xms256M','-Xmx1024M','-Dlog4j.configurationFile='+str(self.path/'log4j2-plain.xml'),'-jar',str(self.jar),'nogui']
        self.output=self.log.open('a')
        self.proc=subprocess.Popen(command,cwd=self.path,stdin=subprocess.PIPE,stdout=self.output,stderr=subprocess.STDOUT,text=True)
        deadline=time.monotonic()+120
        while time.monotonic()<deadline:
            text=self.log.read_text(errors='replace')
            if ('Listening on ' in text if self.kind=='php' else re.search(r'Done \([\d.]+s\)!',text)):
                with contextlib.suppress(OSError):
                    with socket.create_connection(('127.0.0.1',self.port),timeout=.2):return
            if self.proc.poll() is not None:raise RuntimeError('Server startup failed:\n'+text[-6000:])
            time.sleep(.1)
        raise TimeoutError('Server startup timed out: '+str(self.log))

    def stop(self):
        if self.proc is None:return
        try:
            if self.proc.poll() is None:
                if self.kind=='java':
                    with contextlib.suppress(BrokenPipeError):
                        self.proc.stdin.write('stop\n');self.proc.stdin.flush()
                else:self.proc.terminate()
                try:self.proc.wait(timeout=45)
                except subprocess.TimeoutExpired:
                    self.proc.kill();self.proc.wait();raise RuntimeError('Server failed graceful shutdown')
        finally:
            self.proc.stdin.close();self.output.close();self.proc=None
        if self.kind=='java' and any((self.path/'logs').glob('*.gz')):
            raise RuntimeError('Reference logs must remain uncompressed plain text')


def observe(controller,scenario,output):
    results=[];traces=[];probe=None;observer=None
    try:
        controller.start()
        probe=Probe(controller.port,controller.kind).login()
        if scenario.get('observer'):observer=Probe(controller.port,controller.kind+'-observer').login('ParityObserver')
        for index,step in enumerate(scenario['steps']):
            probe.step=f'{index}:{step["action"]}'
            action=step['action']
            if action=='command':probe.send('chat_command',wire.string(step['text']));probe.tick_barrier()
            elif action=='wait':
                deadline=time.monotonic()+step['seconds']
                while time.monotonic()<deadline:probe.barrier();time.sleep(.05)
            elif action=='packet':probe.send(step['name'],bytes.fromhex(step['hex']));probe.tick_barrier()
            elif action=='observer_packet':
                if observer is None:raise ValueError('Observer packet requires an observer')
                observer.send(step['name'],bytes.fromhex(step['hex']));observer.tick_barrier()
            elif action=='interact':
                # The entity of a type the probe tracks, the index-th by entity ID: right-clicked with a hand.
                kind=json.loads((ROOT/'reference/26.3/blocks.json').read_text())['entity_types'][step['entity']]
                ids=sorted(entity for entity,seen in probe.entities.items() if seen==kind)
                probe.send('interact',wire.vi(ids[step.get('index',0)])+wire.vi(step.get('hand',0))+b'\0\0');probe.tick_barrier()
            elif action=='click':
                probe.send('container_click',wire.vi(probe.window)+wire.vi(probe.revision)+struct.pack('>hB',step['slot'],step.get('button',0))+wire.vi(step.get('mode',0))+b'\0\0');probe.tick_barrier()
            elif action=='dig':
                probe.send('player_action',wire.vi(step.get('type',0))+wire.block_position(*step['position'])+b'\1'+wire.vi(index+1));probe.until('block_changed_ack',lambda p:wire.read_vi(p)[0]>=index+1)
            elif action=='place':
                probe.send('use_item_on',b'\0'+wire.block_position(*step['position'])+wire.vi(step.get('face',1))+struct.pack('>fff',*map(float,step.get('cursor',[.5,1,.5])))+b'\0\0'+wire.vi(index+1));probe.until('block_changed_ack',lambda p:wire.read_vi(p)[0]>=index+1)
            elif action=='respawn':
                probe.send('client_command',b'\0');probe.until('respawn');probe.until('player_position');probe.barrier()
            elif action=='restart':
                if observer is not None:traces+=observer.trace;observer.close();observer=None
                traces+=probe.trace;probe.close();probe=None;controller.stop();controller.start();probe=Probe(controller.port,controller.kind).login()
                if scenario.get('observer'):observer=Probe(controller.port,controller.kind+'-observer').login('ParityObserver')
            elif action=='snapshot':
                probe.tick_barrier()
                if any(field in step['fields'] for field in ('inventory','cursor')):probe.refresh_inventory()
                fields=[field for field in step['fields'] if field not in ('equipment','tracked_player')]
                state=probe.snapshot(fields,step.get('positions',[]))
                if 'equipment' in step['fields']:
                    if observer is None:raise ValueError('Equipment snapshots require an observer')
                    observer.step=probe.step;observer.tick_barrier()
                    state['equipment']=[observer.equipment.get(probe.entity_id,{}).get(slot) for slot in range(6)]
                if 'tracked_player' in step['fields']:
                    if observer is None:raise ValueError('Player tracking snapshots require an observer')
                    observer.step=probe.step;observer.tick_barrier()
                    visible=probe.entity_id in observer.entities
                    values=observer.player_metadata.get(probe.entity_id,{})
                    state['tracked_player']={'visible':visible,'flags':values.get('flags',0) if visible else None,'pose':values.get('pose',0) if visible else None}
                results.append({'name':step['name'],'state':state})
            else:raise ValueError('Unknown scenario action: '+action)
        return results
    except Exception as e:
        raise RuntimeError(f'{controller.kind} at {probe.step if probe else "startup/restart"}: {e}') from e
    finally:
        if probe is not None:traces+=probe.trace;probe.close()
        if observer is not None:traces+=observer.trace;observer.close()
        try:controller.stop()
        finally:output.write_text(json.dumps(traces,indent=2)+'\n')


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--scenario',type=Path,default=ROOT/'tests/parity/smoke.json')
    parser.add_argument('--output',type=Path,default=ROOT/'tools/.cache/parity-run')
    parser.add_argument('--self-test',action='store_true',help='Compare two independent PHP saves to check harness mechanics, not vanilla parity')
    parser.add_argument('--eula-file',type=Path,help='Existing user-accepted eula.txt for an isolated vanilla reference server')
    parser.add_argument('--jar',type=Path,default=ROOT/'tools/.cache/26.3/server.jar')
    parser.add_argument('--java',default=str(Path(os.environ['JAVA_HOME'])/'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args=parser.parse_args();args.output=args.output.resolve()
    if args.output.exists() and any(args.output.iterdir()):parser.error('--output must be empty; previous evidence is never overwritten')
    if not args.self_test:
        if args.eula_file is None or not args.eula_file.is_file() or not re.search(r'^\s*eula\s*=\s*true\s*$',args.eula_file.read_text(),re.M|re.I):
            parser.error('Provide --eula-file pointing to an existing acknowledgement of https://www.minecraft.net/eula. This tool does not accept terms for you.')
        expected=json.loads((ROOT/'reference/26.3/version.json').read_text())['server_sha1']
        if not args.jar.is_file() or hashlib.sha1(args.jar.read_bytes()).hexdigest()!=expected:parser.error('Reference JAR checksum mismatch or missing; rebuild reference data first')
    scenario=json.loads(args.scenario.read_text());args.output.mkdir(parents=True,exist_ok=True)
    (args.output/'scenario.json').write_text(json.dumps(scenario,indent=2)+'\n')
    difficulty=scenario.get('difficulty',0)
    if type(difficulty) is not int or not 0<=difficulty<=3:parser.error('Scenario difficulty must be 0..3')
    reference=Server(args.output/'reference','php' if args.self_test else 'java',args.java,args.jar.resolve(),args.eula_file,difficulty)
    php=Server(args.output/'php','php',args.java,args.jar.resolve(),args.eula_file,difficulty)
    report={'scenario':scenario['name'],'reference':'php-self-test' if args.self_test else 'official-java-26.3','normalizations':scenario.get('normalizations',[])}
    try:
        expected=observe(reference,scenario,args.output/'reference-packets.json');report['reference_observations']=expected
        actual=observe(php,scenario,args.output/'php-packets.json');report['php_observations']=actual
        report['first_difference']=difference(expected,actual);report['passed']=report['first_difference'] is None
    except Exception as e:
        report['passed']=False;report['error']=repr(e)
    (args.output/'report.json').write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k not in ('reference_observations','php_observations')},indent=2))
    return 0 if report['passed'] else 1


if __name__=='__main__':sys.exit(main())
