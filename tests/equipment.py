#!/usr/bin/env python3
"""Multiplayer equipment changes, lifecycle and persisted authoritative inventory."""
from pathlib import Path
import json
import sqlite3
import struct
import sys
import tempfile
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/parity'))
from protocol import Probe,wire


def equipment(payload):
    entity,off=wire.read_vi(payload);slots={}
    while True:
        slot=payload[off];off+=1
        stack,off=wire.read_stack(payload,off);slots[slot&127]=stack
        if not slot&128:break
    assert off==len(payload),'trailing equipment bytes'
    return entity,slots


def collect(viewer,entity,start=0):
    result={}
    for name,payload in viewer.seen[start:]:
        if name=='set_equipment':
            owner,slots=equipment(payload)
            if owner==entity:result.update(slots)
    return result


def edit(player,slot,item,count=1):
    payload=b'\0' if item is None else wire.vi(count)+wire.vi(wire.BLOCKS['items'][item])+b'\0\0'
    player.send('set_creative_mode_slot',struct.pack('>h',slot)+payload)


def command(player,text):
    player.send('chat_command',wire.string(text));player.tick_barrier()


def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-equipment-') as tmp:
        cfg={'port':0,'generator':'flat','defaultGameMode':1,'worldPath':str(Path(tmp)/'world.sqlite'),'operators':['Wearer','Watcher']}
        with wire.server(cfg) as port:
            a=Probe(port,'wearer').login('Wearer');b=Probe(port,'watcher').login('Watcher')
            try:
                expected={0:'diamond_sword',1:'shield',2:'diamond_boots',3:'diamond_leggings',4:'diamond_chestplate',5:'diamond_helmet'}
                start=len(b.seen)
                for slot,item in zip([36,45,8,7,6,5],expected.values()):edit(a,slot,item)
                a.tick_barrier();b.tick_barrier()
                received=collect(b,a.entity_id,start)
                assert {slot:stack['item'] for slot,stack in received.items()}=={slot:wire.BLOCKS['items'][item] for slot,item in expected.items()},received
                # Unselected hotbar changes must not change visible equipment.
                start=len(b.seen);edit(a,37,'stone',12);a.tick_barrier();b.tick_barrier()
                assert not collect(b,a.entity_id,start),'unselected item broadcast'
                a.send('set_carried_item',struct.pack('>h',1));a.tick_barrier();b.tick_barrier()
                assert collect(b,a.entity_id,start)=={0:{'item':wire.BLOCKS['items']['stone'],'count':12}}
                start=len(b.seen)
                a.send('player_action',wire.vi(7)+wire.block_position(0,0,0)+b'\0'+wire.vi(0));a.tick_barrier();b.tick_barrier()
                assert collect(b,a.entity_id,start)=={0:{'item':wire.BLOCKS['items']['shield'],'count':1},1:{'item':wire.BLOCKS['items']['stone'],'count':12}}
                start=len(b.seen);edit(a,5,None);edit(a,37,None);a.tick_barrier();b.tick_barrier()
                assert collect(b,a.entity_id,start)=={0:None,5:None}
                # Late observers receive every slot, including explicit empty hands/head.
                late=Probe(port,'late').login('LateWatcher')
                try:
                    snapshot=collect(late,a.entity_id)
                    assert set(snapshot)==set(range(6)) and snapshot[0] is None and snapshot[5] is None
                    assert snapshot[1]['count']==12 and snapshot[4]['item']==wire.BLOCKS['items']['diamond_chestplate']
                finally:late.close()
                command(a,'dimension the_nether');b.tick_barrier();start=len(b.seen)
                edit(a,36,'golden_axe');a.send('set_carried_item',struct.pack('>h',0));a.tick_barrier();b.tick_barrier()
                assert not collect(b,a.entity_id,start),'cross-dimension equipment leak'
                command(a,'dimension overworld');b.tick_barrier()
                assert collect(b,a.entity_id,start)[0]['item']==wire.BLOCKS['items']['golden_axe']
                command(a,'save-all')
            finally:a.close();b.close()
        # A saved damaged tool must keep its component in the equipment snapshot.
        with sqlite3.connect(cfg['worldPath']) as db:
            for uuid,data in db.execute('SELECT uuid,data FROM players').fetchall():
                saved=json.loads(data)
                if isinstance(saved['inventory'],dict) and saved['inventory'].get('36',{}).get('item')==wire.BLOCKS['items']['golden_axe']:
                    saved['inventory']['36']['damage']=7
                    db.execute('UPDATE players SET data=? WHERE uuid=?',(json.dumps(saved),uuid))
        with wire.server(cfg) as port:
            a=Probe(port,'restored').login('Wearer');b=Probe(port,'restored-observer').login('Watcher')
            try:
                snapshot=collect(b,a.entity_id)
                assert snapshot[0]['item']==wire.BLOCKS['items']['golden_axe'] and snapshot[5] is None
                assert snapshot[0]['damage']==7,'damage component lost'
                assert snapshot[1]['count']==12
                # A stable inventory must not emit periodic duplicate equipment updates.
                b.tick_barrier();start=len(b.seen);a.tick_barrier();b.tick_barrier()
                assert not collect(b,a.entity_id,start),'unchanged equipment repeated'
            finally:a.close();b.close()
    Path('/tmp/php-minecraft-equipment-capture.json').write_text(json.dumps(wire.CAPTURE))
    print('PASS: six equipment slots, selection, hand swap, removals, late joins, dimension isolation/re-entry, restart and deduplication')


if __name__=='__main__':main()
