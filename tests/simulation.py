#!/usr/bin/env python3
"""Live TCP checks for scheduled water, jukeboxes, note blocks and basic redstone circuits."""
import json
from pathlib import Path
import sqlite3
import struct
import sys
import tempfile
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/parity'))
from protocol import Probe,wire


def command(c,text):c.send('chat_command',wire.string(text));c.tick_barrier()
def use(c,pos,sequence=800):
    c.send('use_item_on',b'\0'+wire.block_position(*pos)+b'\1'+struct.pack('>fff',.5,1,.5)+b'\0\0'+wire.vi(sequence))
    c.until('block_changed_ack',lambda p:wire.read_vi(p)[0]>=sequence);c.tick_barrier()
def ticks(c,count):
    start=c.game_time;c.until('set_time',lambda p:struct.unpack_from('>q',p)[0]>=start+count,limit=100000,timeout=120)
def state(path,pos):
    with sqlite3.connect(path) as db:
        row=db.execute('SELECT state FROM blocks WHERE x=? AND y=? AND z=?',pos).fetchone()
    return row[0] if row else 0

def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-simulation-') as tmp:
        world=str(Path(tmp)/'world.sqlite');config={'port':0,'generator':'flat','defaultGameMode':1,'operators':['ParityProbe'],'worldPath':world}
        with wire.server(config) as port:
            c=Probe(port,'simulation').login()
            try:
                command(c,'setblock 2 64 1 minecraft:note_block')
                start=len(c.seen);use(c,[2,64,1])
                assert any(n=='block_event' for n,p in c.seen[start:]),'note event missing'
                assert any(n=='sound' for n,p in c.seen[start:]),'note sound missing'
                command(c,'setblock 2 63 1 minecraft:gold_block')
                command(c,'setblock 3 64 0 minecraft:lever[face=floor,facing=north]')
                # Place dust through the actual item interaction rather than only commands.
                c.send('set_creative_mode_slot',struct.pack('>h',36)+b'\1'+wire.vi(wire.BLOCKS['items']['redstone'])+b'\0\0');c.barrier()
                use(c,[4,63,0]);command(c,'setblock 5 64 0 minecraft:redstone_wire')
                command(c,'setblock 6 64 0 minecraft:redstone_lamp');use(c,[3,64,0]);ticks(c,40)
                data=json.loads((ROOT/'reference/26.3/gameplay.json').read_text())['blocks']
                def props(name,position):
                    value=state(world,position);block=data[name];offset=value-block['first'];assert 0<=offset<block['count'],(name,position,value)
                    return {key:info['values'][0 if info['stride']==0 else (offset//info['stride'])%len(info['values'])] for key,info in block['properties'].items()}
                assert props('redstone_wire',[4,64,0])['power']=='15'
                assert props('redstone_wire',[5,64,0])['power']=='14'
                assert props('redstone_lamp',[6,64,0])['lit']=='true'
                use(c,[3,64,0]);ticks(c,100)
                assert props('redstone_wire',[5,64,0])['power']=='0'
                assert props('redstone_lamp',[6,64,0])['lit']=='false'
                command(c,'setblock 4 64 2 minecraft:redstone_block');command(c,'setblock 5 64 2 minecraft:repeater[facing=west]');command(c,'setblock 6 64 2 minecraft:redstone_lamp')
                assert props('repeater',[5,64,2])['powered']=='true'
                assert props('redstone_lamp',[6,64,2])['lit']=='true'
                command(c,'setblock 1 64 4 minecraft:jukebox')
                c.send('set_creative_mode_slot',struct.pack('>h',36)+b'\1'+wire.vi(wire.BLOCKS['items']['music_disc_cat'])+b'\0\0');c.barrier()
                start=len(c.seen);use(c,[1,64,4])
                events=[p for n,p in c.seen[start:] if n=='level_event']
                assert any(struct.unpack_from('>i',p)[0]==1010 for p in events),'record start missing'
                assert props('jukebox',[1,64,4])['has_record']=='true'
                command(c,'setblock 2 64 4 minecraft:comparator[facing=west]');command(c,'setblock 3 64 4 minecraft:redstone_lamp')
                assert props('comparator',[2,64,4])['powered']=='true'
                assert props('redstone_lamp',[3,64,4])['lit']=='true'
                start=len(c.seen);use(c,[1,64,4])
                assert any(n=='level_event' and struct.unpack_from('>i',p)[0]==1011 for n,p in c.seen[start:])
                assert props('jukebox',[1,64,4])['has_record']=='false'
                use(c,[1,64,4]);command(c,'save-all')
            finally:c.close()
        # Advance the saved playback clock to its final tick without waiting several minutes.
        with sqlite3.connect(world) as db:
            playback=json.loads(db.execute("SELECT value FROM metadata WHERE key='jukeboxes'").fetchone()[0])
            assert playback['1:64:4']['slots'][0]['item']==wire.BLOCKS['items']['music_disc_cat']
            playback['1:64:4']['remaining']=1
            db.execute("UPDATE metadata SET value=? WHERE key='jukeboxes'",(json.dumps(playback),))
        with wire.server(config) as port:
            c=Probe(port,'restart').login()
            try:
                with sqlite3.connect(world) as db:
                    record=json.loads(db.execute("SELECT data FROM containers WHERE position='1:64:4'").fetchone()[0])
                assert record['slots'][0]['item']==wire.BLOCKS['items']['music_disc_cat'],'inserted disc did not persist'
                assert record['remaining']==0,'song did not finish on its scheduled tick'
                use(c,[1,64,4]);assert props('jukebox',[1,64,4])['has_record']=='false'
                # Separate the water tests spatially from the circuit board.
                command(c,'setblock -3 67 -3 minecraft:water');ticks(c,120)
                assert props('water',[-3,66,-3])['level']=='8','waterfall missing'
                assert props('water',[-3,64,-3])['level']=='8'
                assert props('water',[-2,64,-3])['level']=='8'
                assert props('water',[-1,64,-3])['level']=='1','sideways flow missing'
                command(c,'setblock -3 67 -3 minecraft:air');ticks(c,200)
                assert state(world,[-3,64,-3])==0,'water did not drain'
                command(c,'setblock -3 64 -3 minecraft:water');command(c,'setblock -1 64 -3 minecraft:water');ticks(c,100)
                assert props('water',[-2,64,-3])['level']=='0','two-source regeneration missing'
                command(c,'save-all')
                print('PASS: note sound/tuning, dust attenuation and decay, lever/lamp/repeater/comparator, jukebox play/eject/restart, water falling/spreading/draining/source regeneration')
            finally:c.close()
    Path('/tmp/php-minecraft-simulation-capture.json').write_text(json.dumps(wire.CAPTURE))


if __name__=='__main__':main()
