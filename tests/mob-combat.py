#!/usr/bin/env python3
"""Ordinary mob melee comparisons: weapon damage/wear, armor, target cooldown and restart.
Special attacks, enchantments, knockback, effects and species immunities remain separate coverage.
"""
from pathlib import Path
import argparse,hashlib,json,os,re,struct,sys,tempfile
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/parity'))
from run import Server,difference
from protocol import Probe,wire,METADATA,TRACKING
MOBS=json.loads((ROOT/'reference/26.3/mobs.json').read_text())
COMBAT=json.loads((ROOT/'reference/26.3/combat.json').read_text())
DAMAGE_TYPES=json.loads((ROOT/'reference/26.3/dynamic-registries.json').read_text())['minecraft:damage_type']

class CombatProbe(Probe):
    def __init__(self,*args):
        super().__init__(*args);self.mob_health={};self.mob_profiles={};self.damage_events=[];self.death_events=[]
    def receive(self):
        name,p=super().receive()
        if self.state=='play':
            if name=='add_entity':
                entity,off=wire.read_vi(p);self.mob_profiles[entity]=p[off:off+16].hex()
            elif name=='set_entity_data':
                entity,off=wire.read_vi(p)
                while p[off]!=255:
                    index=p[off];serializer,off=wire.read_vi(p,off+1)
                    if serializer==MOBS['float_serializer']:
                        value=struct.unpack_from('>f',p,off)[0];off+=4
                        if index==MOBS['health_index']:self.mob_health[entity]=value
                    elif serializer in [METADATA['byte_serializer'],COMBAT['serializers']['boolean']]:off+=1
                    elif serializer in [METADATA['int_serializer'],TRACKING['pose_serializer']]:_,off=wire.read_vi(p,off)
                    else:break # Preserve unfamiliar metadata in the independently decoded capture.
            elif name=='damage_event':
                entity,off=wire.read_vi(p);kind,off=wire.read_vi(p,off);cause,off=wire.read_vi(p,off);direct,off=wire.read_vi(p,off)
                assert p[off]==0 and off+1==len(p),'Unexpected damage source position'
                self.damage_events.append({'entity':entity,'type':DAMAGE_TYPES[kind],'player_source':cause!=0 and cause==direct})
            elif name=='entity_event' and p[4]==3:self.death_events.append(struct.unpack_from('>i',p)[0])
        return name,p

def command(c,text):c.send('chat_command',wire.string(text));c.tick_barrier()
def sync_observer(observer,actor):
    # Observe the same simulation fence as the actor without advancing the
    # server another forty ticks just to read the observer's queued updates.
    observer.barrier()
    if observer.game_time is None or observer.game_time<actor.game_time:
        observer.until('set_time',lambda payload:struct.unpack_from('>q',payload)[0]>=actor.game_time)
    observer.barrier()

def equip(a,item,survival=True):
    command(a,'gamemode creative')
    stack=b'\0' if item=='air' else b'\1'+wire.vi(wire.BLOCKS['items'][item])+b'\0\0'
    a.send('set_creative_mode_slot',struct.pack('>h',36)+stack);a.send('set_carried_item',struct.pack('>h',0));a.tick_barrier()
    if survival:command(a,'gamemode survival')
def summon(a,kind):
    before=set(a.mob_profiles);command(a,'summon minecraft:'+kind+' 2.5 64 0.5')
    found=[entity for entity in a.mob_profiles if entity not in before and a.entities.get(entity)==MOBS['entities'][kind]['id']]
    assert len(found)==1,(kind,found)
    return found[0]
def snapshot(c,entity):
    return {'health':c.mob_health.get(entity,MOBS['entities']['cow']['attributes']['max_health']),
            'damage_events':[{k:v for k,v in row.items() if k!='entity'} for row in c.damage_events if row['entity']==entity],
            'deaths':c.death_events.count(entity)}

def observe(server,out):
    traces=[];results=[];a=b=None
    try:
        server.start();a=CombatProbe(server.port,server.kind+'-actor').login();b=CombatProbe(server.port,server.kind+'-observer').login('ParityObserver')
        for text in ['gamerule minecraft:spawn_mobs false','time set 18000','fill -2 63 -2 4 67 2 minecraft:stone','fill -1 64 -1 3 66 1 minecraft:air','tp @s 0.5 64 0.5','tp ParityObserver 0.5 64 0.5']:
            command(a,text)
        sync_observer(b,a)
        for item in ['air','wooden_sword','iron_sword','diamond_sword','wooden_axe','netherite_axe','trident','mace']:
            a.step=item;b.step=item;equip(a,item);entity=summon(a,'cow');a.tick_barrier();sync_observer(b,a)
            a.send('attack',wire.vi(entity));a.tick_barrier();sync_observer(b,a);a.refresh_inventory()
            expected=COMBAT['items'].get(item,COMBAT['default_item']);health=max(0,10-expected['damage'])
            assert a.mob_health[entity]==health,(server.kind,item,a.mob_health[entity],health)
            assert b.mob_health[entity]==health,(server.kind,item,'observer health')
            row={'item':item,'actor':snapshot(a,entity),'observer':snapshot(b,entity),'wear':(a.inventory[36] or {}).get('damage',0)}
            assert row['wear']==expected['durability_cost'],row
            assert len(row['actor']['damage_events'])==1 and row['actor']['damage_events']==row['observer']['damage_events'],row
            assert row['actor']['deaths']==int(health==0) and row['observer']['deaths']==int(health==0),row
            results.append(row)
        # Two different players hitting within the same invulnerability window
        # must apply only the stronger raw hit, independent of network ordering.
        equip(a,'air');b.send('set_creative_mode_slot',struct.pack('>h',36)+b'\1'+wire.vi(wire.BLOCKS['items']['iron_sword'])+b'\0\0');b.tick_barrier()
        entity=summon(a,'cow');a.tick_barrier();sync_observer(b,a);a.send('attack',wire.vi(entity));b.send('attack',wire.vi(entity));a.tick_barrier();sync_observer(b,a)
        assert a.mob_health[entity]==4 and b.mob_health[entity]==4,(server.kind,'shared cooldown',a.mob_health.get(entity),b.mob_health.get(entity))
        row={'case':'shared target cooldown','actor':snapshot(a,entity),'observer':snapshot(b,entity)}
        assert len(row['actor']['damage_events'])==1 and row['actor']==row['observer'],row
        results.append(row)
        # Damage cooldown is transient; a timestamp from the old tick clock cannot
        # leave a saved mob invulnerable after a restart.
        equip(a,'air');entity=summon(a,'cow');a.tick_barrier();a.send('attack',wire.vi(entity));a.tick_barrier();profile=a.mob_profiles[entity];assert a.mob_health[entity]==9
        command(a,'save-all');a.close();b.close();traces.extend(a.trace);traces.extend(b.trace);a=b=None;server.stop();server.start()
        a=CombatProbe(server.port,server.kind+'-restart').login();a.tick_barrier()
        entity=next(entity for entity,uuid in a.mob_profiles.items() if uuid==profile)
        assert a.mob_health[entity]==9,(server.kind,'saved health',a.mob_health.get(entity))
        a.send('attack',wire.vi(entity));a.tick_barrier();assert a.mob_health[entity]==8,(server.kind,'restart cooldown',a.mob_health[entity]);results.append({'case':'restart','health':a.mob_health[entity]})
        b=CombatProbe(server.port,server.kind+'-restart-observer').login('ParityObserver')
        # Mob base armor must change applied damage; held sword damage is not a flat six.
        # Creative keeps this incoming-damage check independent of hostile AI.
        command(a,'time set 18000');equip(a,'diamond_sword',survival=False);entity=summon(a,'zombie');a.tick_barrier();sync_observer(b,a);a.send('attack',wire.vi(entity));a.tick_barrier();sync_observer(b,a)
        row={'case':'zombie base armor','actor':snapshot(a,entity),'observer':snapshot(b,entity)};assert row['actor']==row['observer'] and a.mob_health[entity]>13,row;results.append(row)
    finally:
        for probe in [a,b]:
            if probe:probe.close();traces.extend(probe.trace)
        server.stop();(out/(server.kind+'-packets.json')).write_text(json.dumps(traces,indent=2)+'\n')
        (out/(server.kind+'-observations.json')).write_text(json.dumps(results,indent=2)+'\n')
    (out/(server.kind+'-observations.json')).write_text(json.dumps(results,indent=2)+'\n');return results

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--java-jar',type=Path);parser.add_argument('--eula-file',type=Path);parser.add_argument('--java-home',type=Path,default=Path(os.environ['JAVA_HOME']) if 'JAVA_HOME' in os.environ else Path('/usr'));parser.add_argument('--output',type=Path)
    args=parser.parse_args()
    if args.java_jar:
        if not args.eula_file or not args.eula_file.is_file() or not re.search(r'^\s*eula\s*=\s*true\s*$',args.eula_file.read_text(),re.M|re.I):parser.error('Provide an existing accepted --eula-file; this tool does not accept the EULA for you.')
        if hashlib.sha1(args.java_jar.read_bytes()).hexdigest()!=json.loads((ROOT/'reference/26.3/version.json').read_text())['server_sha1']:parser.error('Official JAR checksum mismatch')
        args.java_jar=args.java_jar.resolve();args.eula_file=args.eula_file.resolve()
    out=(args.output or Path(tempfile.mkdtemp(prefix='php-minecraft-mob-combat-'))).resolve()
    if out.exists() and any(out.iterdir()):parser.error('--output must be empty; previous evidence is never overwritten')
    out.mkdir(parents=True,exist_ok=True)
    php=observe(Server(out/'php','php',None,None,None,difficulty=2),out)
    if args.java_jar:
        java=observe(Server(out/'java','java',str(args.java_home/'bin/java'),args.java_jar,args.eula_file,difficulty=2),out)
        delta=difference(java,php);(out/'comparison.json').write_text(json.dumps({'passed':delta is None,'first_divergence':delta},indent=2)+'\n');assert delta is None,delta
    print('PASS: mob weapon damage/wear, observed health/damage events, shared cooldown, armor and restart'+(' against pinned Java 26.3' if args.java_jar else ''))
if __name__=='__main__':main()
