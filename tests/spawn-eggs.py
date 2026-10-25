#!/usr/bin/env python3
"""Egg mapping, actual spawning, consumption, collision surfaces, persistence and all 88 types.
This does not prove species AI, randomized spawn variants or boss-fight parity.
"""
from pathlib import Path
import json,math,sqlite3,struct,sys,tempfile
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/parity'))
from protocol import Probe,wire
MOBS=json.loads((ROOT/'reference/26.3/mobs.json').read_text())


def command(c,text):c.send('chat_command',wire.string(text));c.tick_barrier()
def edit(c,item,count=1,slot=36):c.send('set_creative_mode_slot',struct.pack('>h',slot)+wire.vi(count)+wire.vi(wire.BLOCKS['items'][item])+b'\0\0')
def use(c,pos,sequence=500,face=1,hand=0):
    c.send('use_item_on',wire.vi(hand)+wire.block_position(*pos)+wire.vi(face)+struct.pack('>fff',.5,1,.5)+b'\0\0'+wire.vi(sequence))
    c.until('block_changed_ack',lambda p:wire.read_vi(p)[0]>=sequence)
def saved(world):
    with sqlite3.connect(world) as db:
        data=json.loads(db.execute("SELECT value FROM metadata WHERE key='animals'").fetchone()[0]);return list(data.values()) if isinstance(data,dict) else data
def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-eggs-') as tmp:
        world=str(Path(tmp)/'world.sqlite');cfg={'port':0,'generator':'flat','defaultGameMode':1,'difficulty':2,'operators':['ParityProbe'],'worldPath':world}
        with wire.server(cfg) as port:
            a=Probe(port,'egg-user').login();b=Probe(port,'egg-observer').login('Observer')
            try:
                for index,(egg,kind) in enumerate(MOBS['spawn_eggs'].items()):
                    edit(a,egg);use(a,[2,63,0],500+index)
                    assert MOBS['entities'][kind]['id'] in a.entities.values(),(egg,kind)
                a.tick_barrier();b.tick_barrier()
                expected={MOBS['entities'][kind]['id'] for kind in MOBS['spawn_eggs'].values()}
                assert expected<=set(b.entities.values()),expected-set(b.entities.values())
                skeleton=next(i for i,kind in b.entities.items() if kind==MOBS['entities']['skeleton']['id'])
                assert b.equipment[skeleton][0]['item']==wire.BLOCKS['items']['bow']
                command(a,'save-all');records=saved(world)
                assert set(MOBS['spawn_eggs'].values())<={m['kind'] for m in records}
                # Block/face geometry uses actual collision height instead of full-cube assumptions.
                command(a,'setblock 1 64 2 minecraft:stone_slab[type=bottom]')
                start=len(a.seen);edit(a,'cow_spawn_egg');use(a,[1,64,2],700)
                spawned=[]
                for name,payload in a.seen[start:]:
                    if name!='add_entity':continue
                    _,off=wire.read_vi(payload);kind,off=wire.read_vi(payload,off+16)
                    if kind==MOBS['entities']['cow']['id']:spawned.append(struct.unpack_from('>ddd',payload,off))
                assert (1.5,64.5,2.5) in spawned,spawned
                # Empty air consumes nothing. Source-fluid right-click use spawns at the fluid block.
                edit(a,'cow_spawn_egg',3);a.tick_barrier();a.refresh_inventory();before=a.inventory[36]['count']
                a.send('use_item',b'\0'+wire.vi(701)+struct.pack('>ff',0,-90));a.until('block_changed_ack',lambda p:wire.read_vi(p)[0]>=701);a.refresh_inventory()
                assert a.inventory[36]['count']==before
                command(a,'setblock 0 64 2 minecraft:water');start=len(a.seen)
                a.send('use_item',b'\0'+wire.vi(703)+struct.pack('>ff',0,45));a.until('block_changed_ack',lambda p:wire.read_vi(p)[0]>=703)
                positions=[]
                for name,payload in a.seen[start:]:
                    if name=='add_entity':
                        _,off=wire.read_vi(payload);kind,off=wire.read_vi(payload,off+16)
                        if kind==MOBS['entities']['cow']['id']:positions.append(struct.unpack_from('>ddd',payload,off))
                assert (.5,64.,2.5) in positions,positions
                # Offhand survival consumes exactly one; creative main-hand preserves it.
                edit(a,'pig_spawn_egg',3,45);command(a,'gamemode survival');use(a,[1,63,0],702,hand=1);a.refresh_inventory();assert a.inventory[45]['count']==2
                command(a,'gamemode creative');command(a,'save-all')
                # Existing IDs must not collide with client-created Ender Dragon parts.
                dragon=next(m for m in saved(world) if m['kind']=='ender_dragon')
                ids={m['id'] for m in saved(world)}
                assert all(dragon['id']+part not in ids for part in range(1,9))
            finally:a.close();b.close()
        with wire.server(cfg) as port:
            c=Probe(port,'egg-restart').login()
            try:
                c.tick_barrier();assert expected<=set(c.entities.values()),'egg types missing after restart'
                skeleton=next(i for i,kind in c.entities.items() if kind==MOBS['entities']['skeleton']['id'])
                assert c.equipment[skeleton][0]['item']==wire.BLOCKS['items']['bow'],'saved mob equipment missing'
                # Peaceful behavior is tested in its own world below.
            finally:c.close()
        with wire.server({**cfg,'difficulty':0,'worldPath':str(Path(tmp)/'peaceful.sqlite')}) as port:
            c=Probe(port,'peaceful').login()
            try:
                edit(c,'zombie_spawn_egg',3);use(c,[1,63,0],800);c.refresh_inventory()
                assert c.inventory[36]['count']==3 and MOBS['entities']['zombie']['id'] not in c.entities.values()
            finally:c.close()
    Path('/tmp/php-minecraft-spawn-eggs-capture.json').write_text(json.dumps(wire.CAPTURE))
    print('PASS: all 88 egg/type mappings, multiplayer spawn and restart, slab height, empty-air use, survival offhand consumption, peaceful rejection and dragon ID reservation')
if __name__=='__main__':main()
