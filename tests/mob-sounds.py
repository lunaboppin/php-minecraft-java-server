#!/usr/bin/env python3
"""Compare primary adult mob voices and nearby/far listeners over actual TCP connections."""
import argparse,hashlib,json,os,re,struct,sys,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/parity'))
from run import Server,difference
from protocol import Probe,wire
from mob_sounds import sounds
REFERENCE=json.loads((ROOT/'reference/26.3/mob-sounds.json').read_text());AUDIO=REFERENCE['mobs'];SERIALIZERS=REFERENCE['serializers']
NAMES={s['id']:s['name'] for info in AUDIO.values() for voice in [info,*info.get('variants',{}).values()] for phase in ['hurt','death'] if (s:=voice[phase])}
KINDS={value:key for key,value in wire.BLOCKS['entity_types'].items()}

class AudioProbe(Probe):
    def __init__(self,*args):
        super().__init__(*args);self.sound_variants={};self.profiles={}
    def receive(self):
        name,p=super().receive()
        if self.state=='play' and name=='add_entity':
            entity,off=wire.read_vi(p);self.profiles[entity]=p[off:off+16].hex()
        if self.state!='play' or name!='set_entity_data':return name,p
        entity,off=wire.read_vi(p);kind=KINDS.get(self.entities.get(entity));info=AUDIO.get(kind)
        if info is None:return name,p
        while p[off]!=255:
            index=p[off];serializer,off=wire.read_vi(p,off+1)
            if serializer in [SERIALIZERS['BYTE'],SERIALIZERS['BOOLEAN']]:off+=1
            elif serializer==SERIALIZERS['FLOAT']:off+=4
            elif serializer in SERIALIZERS.values():
                value,off=wire.read_vi(p,off)
                if index==info.get('variant_index'):
                    assert serializer==info['variant_serializer'],(kind,index,serializer)
                    self.sound_variants[entity]=list(info['variants'])[value]
            else:raise AssertionError(('Uninterpreted mob metadata in audio fixture',kind,index,serializer))
        assert off+1==len(p),'Trailing metadata bytes'
        return name,p

def command(c,text):c.send('chat_command',wire.string(text));c.tick_barrier()
def sync(observer,actor):
    target=actor.game_time
    while observer.game_time is None or observer.game_time<target:observer.receive()
def equip(c,item):
    stack=b'\0' if item=='air' else b'\1'+wire.vi(wire.BLOCKS['items'][item])+b'\0\0'
    c.send('set_creative_mode_slot',struct.pack('>h',36)+stack);c.send('set_carried_item',struct.pack('>h',0));c.tick_barrier()
def primary(trace,kind,phase,variant):
    # All raw sounds remain in the capture. This checkpoint covers primary mob
    # voices; player attack, ambient and secondary health-state sounds are separate.
    selected=[]
    for row in trace:
        if row['state']!='play' or row['direction']!='clientbound':continue
        p=bytes.fromhex(row['hex']);packet,off=wire.read_vi(p)
        if packet!=wire.packet_id('play','clientbound','sound'):continue
        sound,off=wire.read_vi(p,off)
        if sound-1 not in NAMES:continue
        for event in sounds([row],NAMES):
            expected=AUDIO[kind];voice=expected.get('variants',{}).get(variant,expected)[phase]
            assert event['name']==voice['name'] and event['volume']==expected['volume'] and event['category']==expected['category'],(kind,phase,event)
            lower,upper=(.7599999,1.1400001) if kind=='bat' else (.7999999,1.2000001)
            assert lower<=event['pitch']<=upper,(kind,'voice pitch outside actual getter range',event)
            selected.append({k:event[k] for k in ['name','volume','category']})
    return selected

def observe(server,out):
    a=b=None;traces=[];result=[]
    try:
        server.start();a=AudioProbe(server.port,server.kind+'-voice-actor').login();b=AudioProbe(server.port,server.kind+'-voice-observer').login('ParityObserver')
        for text in ['gamerule minecraft:spawn_mobs false','time set 18000','fill -2 63 -2 4 68 2 minecraft:stone','fill -1 64 -1 3 67 1 minecraft:air','tp @s 0.5 64 0.5','tp ParityObserver 0.5 64 0.5']:command(a,text)
        sync(b,a)
        for kind in ['cow','pig','chicken','cat','bat']:
            equip(a,'air');before=set(a.entities);command(a,'summon minecraft:'+kind+' 2.5 64 0.5');sync(b,a)
            entity=next(e for e in a.entities if e not in before and a.entities[e]==wire.BLOCKS['entity_types'][kind])
            variant=a.sound_variants.get(entity,'minecraft:classic');assert b.sound_variants.get(entity,'minecraft:classic')==variant
            for phase,item in [('hurt','air'),('death','netherite_axe')]:
                if item!='air':equip(a,item);sync(b,a)
                a.step=b.step=kind+':'+phase;start_a=len(a.trace);start_b=len(b.trace)
                a.send('attack',wire.vi(entity))
                if phase=='hurt':a.send('attack',wire.vi(entity)) # Weaker immediate retry must not duplicate the voice.
                a.tick_barrier();sync(b,a)
                actor=primary(a.trace[start_a:],kind,phase,variant);observer=primary(b.trace[start_b:],kind,phase,variant)
                assert len(actor)==1 and actor==observer,(server.kind,kind,phase,actor,observer)
                result.append({'kind':kind,'phase':phase,'variant':variant,'actor':actor,'observer':observer})
        # A listener outside the event's broadcast radius must not hear it.
        command(a,'tp ParityObserver 50.5 64 0.5');equip(a,'air');before=set(a.entities);command(a,'summon minecraft:cow 2.5 64 0.5');sync(b,a)
        entity=next(e for e in a.entities if e not in before and a.entities[e]==wire.BLOCKS['entity_types']['cow'])
        a.step=b.step='far-listener';start_a=len(a.trace);start_b=len(b.trace);a.send('attack',wire.vi(entity));a.tick_barrier();sync(b,a)
        variant=a.sound_variants.get(entity,'minecraft:classic')
        actor=primary(a.trace[start_a:],'cow','hurt',variant);observer=primary(b.trace[start_b:],'cow','hurt',variant);assert len(actor)==1 and observer==[],(server.kind,actor,observer)
        result.append({'case':'far listener','variant':variant,'actor':actor,'observer':observer})
        profile=a.profiles[entity];a.close();b.close();traces.extend(a.trace);traces.extend(b.trace);a=b=None;server.stop();server.start()
        a=AudioProbe(server.port,server.kind+'-voice-restart').login();entity=next(e for e in a.entities if a.profiles.get(e)==profile)
        assert a.sound_variants.get(entity,'minecraft:classic')==variant,(server.kind,'voice changed after restart')
        start=len(a.trace);a.step='restored-voice';a.send('attack',wire.vi(entity));a.tick_barrier();restored=primary(a.trace[start:],'cow','hurt',variant);assert len(restored)==1
        result.append({'case':'restored voice','variant':variant,'actor':restored,'observer':[]})
    finally:
        for c in [a,b]:
            if c:c.close();traces.extend(c.trace)
        server.stop();(out/(server.kind+'-packets.json')).write_text(json.dumps(traces,indent=2)+'\n');(out/(server.kind+'-observations.json')).write_text(json.dumps(result,indent=2)+'\n')
    return result

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--java-jar',type=Path);p.add_argument('--java-home',type=Path,default=Path(os.environ.get('JAVA_HOME','/usr')));p.add_argument('--eula-file',type=Path);p.add_argument('--output',type=Path)
    args=p.parse_args();out=(args.output or Path(tempfile.mkdtemp(prefix='php-minecraft-voices-'))).resolve()
    if out.exists() and any(out.iterdir()):p.error('--output must be empty; existing evidence is never overwritten')
    if args.java_jar:
        if not args.eula_file or not args.eula_file.is_file() or not re.search(r'^\s*eula\s*=\s*true\s*$',args.eula_file.read_text(),re.I|re.M):p.error('An existing accepted --eula-file is required')
        if hashlib.sha1(args.java_jar.read_bytes()).hexdigest()!=json.loads((ROOT/'reference/26.3/version.json').read_text())['server_sha1']:p.error('Official JAR checksum mismatch')
    out.mkdir(parents=True,exist_ok=True);php=observe(Server(out/'php','php',None,None,None,difficulty=2),out)
    if args.java_jar:
        java=observe(Server(out/'java','java',str(args.java_home/'bin/java'),args.java_jar.resolve(),args.eula_file.resolve(),difficulty=2),out)
        def normalized(rows):
            rows=json.loads(json.dumps(rows))
            for row in rows:
                row.pop('variant',None)
                for listener in ['actor','observer']:
                    for event in row[listener]:event['name']='validated primary '+row.get('phase','hurt')
            return rows
        delta=difference(normalized(java),normalized(php));(out/'comparison.json').write_text(json.dumps({'passed':delta is None,'first_divergence':delta,'normalizations':['Each exact sound identifier is checked against the synchronized selected variant on both servers. Random selected variants, pitch samples and sound seeds differ between fresh servers. Compare validated primary phase, category, volume, delivery counts and restart stability. Preserve chosen variants and exact sound identifiers in observations, and retain all raw sounds.']},indent=2)+'\n');assert delta is None,delta
    print('PASS: mob primary hurt/death voices, repeated-hit suppression and nearby/far listeners'+(' against pinned Java 26.3' if args.java_jar else ''))
if __name__=='__main__':main()
