#!/usr/bin/env python3
"""Capture default adult mob hurt/death sounds from the pinned official server.
Uses generic command damage, not species AI or a claim about every damage source/variant.
Retains all raw equipment packets for official-codec validation without interpreting gear.
"""
import argparse,hashlib,json,os,re,struct
from pathlib import Path
from run import Server
from protocol import Probe,ROOT,wire

class SoundProbe(Probe):
    def receive(self):
        self._receiving=True
        try:name,payload=wire.Client.receive(self)
        finally:self._receiving=False
        self.trace.append({**wire.CAPTURE[-1],'direction':'clientbound','step':self.step,'connection':self.label})
        self.trace.extend(self._automatic);self._automatic=[]
        if self.state=='configuration' and name=='keep_alive':self.send('keep_alive',payload)
        if self.state=='play':
            if name=='set_time':self.game_time=struct.unpack_from('>q',payload)[0]
            elif name=='chunk_batch_finished' and not self.loaded:self.send('player_loaded');self.loaded=True
        return name,payload

def command(c,text):c.send('chat_command',wire.string(text));c.tick_barrier()
def sounds(trace,sound_names):
    result=[];sound_id=wire.packet_id('play','clientbound','sound');entity_sound_id=wire.packet_id('play','clientbound','sound_entity')
    for entry in trace:
        if entry['direction']!='clientbound' or entry['state']!='play':continue
        payload=bytes.fromhex(entry['hex']);packet,off=wire.read_vi(payload)
        if packet not in [sound_id,entity_sound_id]:continue
        sound,off=wire.read_vi(payload,off);category,off=wire.read_vi(payload,off)
        assert sound>0,'Unexpected inline sound event'
        if packet==sound_id:
            x,y,z,volume,pitch,seed=struct.unpack_from('>iiiffq',payload,off);assert off+28==len(payload),'Trailing sound bytes';location={'position':[x,y,z]}
        else:
            entity,off=wire.read_vi(payload,off);volume,pitch,seed=struct.unpack_from('>ffq',payload,off);assert off+16==len(payload),'Trailing entity sound bytes';location={'entity':entity}
        result.append({'name':sound_names[sound-1],'id':sound-1,'category':category,**location,'volume':volume,'pitch':pitch,'seed':str(seed)})
    return result

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--jar',type=Path,default=ROOT/'tools/.cache/26.3/server.jar');parser.add_argument('--eula-file',type=Path,required=True);parser.add_argument('--java-home',type=Path,default=Path(os.environ['JAVA_HOME']) if 'JAVA_HOME' in os.environ else Path('/usr'));parser.add_argument('--output',type=Path,required=True);parser.add_argument('--kinds',nargs='+')
    args=parser.parse_args();out=args.output.resolve();jar=args.jar.resolve();eula=args.eula_file.resolve()
    if out.exists() and any(out.iterdir()):parser.error('--output must be empty; previous evidence is never overwritten')
    if not eula.is_file() or not re.search(r'^\s*eula\s*=\s*true\s*$',eula.read_text(),re.M|re.I):parser.error('An existing accepted EULA file is required; this tool does not accept terms for you.')
    if hashlib.sha1(jar.read_bytes()).hexdigest()!=json.loads((ROOT/'reference/26.3/version.json').read_text())['server_sha1']:parser.error('Official JAR checksum mismatch')
    mobs=json.loads((ROOT/'reference/26.3/mobs.json').read_text())['entities'];eligible=[name for name,mob in mobs.items() if name not in ['player','armor_stand','mannequin'] and mob['summonable'] and mob['enabled'] and 'max_health' in mob['attributes']]
    if args.kinds and (len(set(args.kinds))!=len(args.kinds) or set(args.kinds)-set(eligible)):parser.error('Every --kinds entry must be a distinct official enabled mob')
    kinds=args.kinds or eligible
    reports=json.loads((ROOT/'tools/.cache/26.3/generated/reports/registries.json').read_text());names={v['protocol_id']:k for k,v in reports['minecraft:sound_event']['entries'].items()}
    out.mkdir(parents=True,exist_ok=True);server=Server(out/'java','java',str(args.java_home/'bin/java'),jar,eula,difficulty=2);observations=[];c=None
    try:
        server.start();c=SoundProbe(server.port,'java-mob-sounds').login()
        # Stay outside item pickup range so inventory changes cannot affect this audio oracle.
        for text in ['gamerule minecraft:spawn_mobs false','gamerule minecraft:advance_time false','gamerule minecraft:advance_weather false','time set 18000','weather clear','tp @s 0.5 64 -8.5']:command(c,text)
        for kind in kinds:
            c.step=kind+':spawn';command(c,'summon minecraft:'+kind+' 2.5 64 0.5 {NoAI:1b,PersistenceRequired:1b,Tags:["sound_fixture"]}')
            row={'kind':kind}
            for phase,amount in [('hurt',0.25),('death',1000000)]:
                c.step=kind+':'+phase;start=len(c.trace);command(c,'damage @e[tag=sound_fixture,limit=1] '+str(amount)+' minecraft:generic');row[phase]=sounds(c.trace[start:],names)
            c.step=kind+':cleanup';command(c,'kill @e[tag=sound_fixture]');observations.append(row);(out/'observations.json').write_text(json.dumps(observations,indent=2)+'\n')
    finally:
        if c:c.close();(out/'packets.json').write_text(json.dumps(c.trace,indent=2)+'\n')
        server.stop()
    print('Recorded official generic hurt/death sound observations for '+str(len(observations))+' species')

if __name__=='__main__':main()
