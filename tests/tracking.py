#!/usr/bin/env python3
"""Player/mob/item pairing boundaries, spectator visibility and metadata lifecycle."""
from pathlib import Path
import json
import struct
import sys
import tempfile
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/parity'))
from protocol import Probe,wire
TRACKING=json.loads((ROOT/'reference/26.3/tracking.json').read_text())
META=json.loads((ROOT/'reference/26.3/gameplay.json').read_text())['entity_metadata']


def command(c,text):c.send('chat_command',wire.string(text));c.tick_barrier()
def settle(*clients):
    for c in clients:c.tick_barrier()
def events(c,start,name,entity):
    return [p for n,p in c.seen[start:] if n==name and wire.read_vi(p)[0]==entity]
def state(c,entity):
    result={}
    for n,p in c.seen:
        if n!='set_entity_data':continue
        owner,off=wire.read_vi(p)
        if owner!=entity:continue
        while p[off]!=255:
            index=p[off];serializer,off=wire.read_vi(p,off+1)
            if serializer==META['byte_serializer']:value=p[off];off+=1
            elif serializer in [META['int_serializer'],META['main_hand_serializer'],TRACKING['pose_serializer']]:value,off=wire.read_vi(p,off)
            else:break
            result[index]=value
    return result


def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-tracking-') as tmp:
        cfg={'port':0,'generator':'flat','viewDistance':2,'defaultGameMode':1,'worldPath':str(Path(tmp)/'world.sqlite'),'operators':['Subject','Observer']}
        with wire.server(cfg) as port:
            a=Probe(port,'subject').login('Subject');b=Probe(port,'observer').login('Observer')
            try:
                settle(a,b);assert a.entity_id in b.entities
                # Range is a horizontal circle, including its exact boundary. Chunk visibility alone is insufficient.
                for position,visible in [([32.5,64,.5],True),([32.501,64,.5],False),([31.5,64,31.5],False),([.5,250,.5],True)]:
                    command(a,'tp @s '+' '.join(map(str,position)));settle(b)
                    assert (a.entity_id in b.entities)==visible,(position,b.entities)
                command(a,'tp @s .5 64 .5');settle(b)
                start=len(b.seen);a.send('player_command',wire.vi(a.entity_id)+b'\1\0');a.send('player_input',b'\x60');settle(a,b)
                metadata=state(b,a.entity_id)
                assert metadata[META['flags_index']]&10==10 and metadata[TRACKING['pose_index']]==TRACKING['poses']['crouching'],metadata
                a.send('player_input',b'\0');settle(a,b)
                assert state(b,a.entity_id)[TRACKING['pose_index']]==TRACKING['poses']['standing']
                a.send('player_command',wire.vi(a.entity_id)+b'\2\0');settle(a,b)
                assert state(b,a.entity_id)[META['flags_index']]&10==0
                # Spectators are hidden from non-spectators; spectator observers can see them.
                command(a,'gamemode spectator');settle(b)
                assert state(b,a.entity_id)[META['flags_index']]&32
                command(a,'tp @s 16.5 64 .5');settle(b);assert a.entity_id not in b.entities
                command(b,'gamemode spectator');command(b,'tp @s .5 64 .5');settle(a,b);assert a.entity_id in b.entities
                command(a,'gamemode creative');command(b,'gamemode creative');settle(a,b)
                # Unloading a viewer's chunks also removes tracked mobs and items, with no duplicate spawns on return.
                command(a,'summon pig 1 64 1');settle(b)
                pig=next(i for i,kind in b.entities.items() if kind==wire.BLOCKS['entity_types']['pig'])
                command(a,'tp @s 4.5 64 .5')
                a.send('set_creative_mode_slot',struct.pack('>h',36)+b'\1'+wire.vi(wire.BLOCKS['items']['diamond'])+b'\0\0')
                a.send('player_action',wire.vi(4)+wire.block_position(0,0,0)+b'\0'+wire.vi(0))
                command(a,'tp @s .5 64 .5');b.tick_barrier()
                item=next(i for i,kind in b.entities.items() if kind==wire.BLOCKS['entity_types']['item'])
                command(b,'tp @s 200 64 200');settle(a,b)
                assert a.entity_id not in b.entities and pig not in b.entities and item not in b.entities
                start=len(b.seen);command(b,'tp @s .5 64 .5');settle(a,b)
                assert len(events(b,start,'add_entity',a.entity_id))==1 and len(events(b,start,'add_entity',pig))==1
                assert len(events(b,start,'add_entity',item))==1
                for _ in range(2):
                    command(a,'dimension the_nether');settle(b);assert a.entity_id not in b.entities
                    command(a,'dimension overworld');settle(a,b);assert a.entity_id in b.entities
                old=a.entity_id;a.close();settle(b);assert old not in b.entities
                a=Probe(port,'reconnect').login('Subject');settle(a,b)
                assert a.entity_id!=old and a.entity_id in b.entities and old not in b.entities
                print('PASS: circular tracking range, vertical independence, teleport/unload/re-entry, crouch/sprint, spectators, mobs and reconnect/dimension lifecycle')
            finally:a.close();b.close()
    Path('/tmp/php-minecraft-tracking-capture.json').write_text(json.dumps(wire.CAPTURE))


if __name__=='__main__':main()
