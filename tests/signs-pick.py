#!/usr/bin/env python3
"""Protocol-777 sign editing, multiplayer text, persistence, pick block and chat logs."""
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
def use(c,pos,seq=900):
    start=len(c.seen)
    c.send('use_item_on',b'\0'+wire.block_position(*pos)+b'\1'+struct.pack('>fff',.5,1,.5)+b'\0\0'+wire.vi(seq));c.until('block_changed_ack',lambda p:wire.read_vi(p)[0]>=seq)
    return next((p for n,p in c.seen[start:] if n=='open_sign_editor'),None)
def update(c,pos,lines,side):c.send('sign_update',wire.block_position(*pos)+b''.join(wire.string(s) for s in lines)+wire.vi(side));c.tick_barrier()


def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-signs-') as tmp:
        world=str(Path(tmp)/'world.sqlite');logs=Path(tmp)/'logs';cfg={'port':0,'generator':'flat','defaultGameMode':1,'operators':['ParityProbe'],'worldPath':world,'logDirectory':str(logs)}
        with wire.server(cfg) as port:
            a=Probe(port,'signer').login();b=Probe(port,'witness').login('Witness')
            try:
                a.send('set_creative_mode_slot',struct.pack('>h',36)+b'\1'+wire.vi(wire.BLOCKS['items']['oak_sign'])+b'\0\0');a.barrier()
                edit=use(a,[1,63,1]);assert edit==wire.block_position(1,64,1)+b'\1'
                lines=['Hello','world','😀 <text>','line four'];update(a,[1,64,1],lines,1)
                b.barrier();assert any(n=='block_entity_data' and b'Hello' in p and b'world' in p for n,p in b.seen)
                # Unsolicited edits from another connection must not change the text.
                update(b,[1,64,1],['forged','','',''],1)
                with sqlite3.connect(world) as db:stored=json.loads(db.execute("SELECT data FROM containers WHERE position='1:64:1'").fetchone()[0])
                assert stored['front']==lines
                command(a,'tp @s 1.5 64 2.5');edit=use(a,[1,64,1],901);side=wire.read_vi(edit,8)[0]
                assert side==0,side
                update(a,[1,64,1],['Back','','',''],side)
                command(a,'setblock 2 64 2 minecraft:obsidian')
                a.send('pick_item_from_block',wire.block_position(2,64,2)+b'\0');a.until('set_held_slot');a.refresh_inventory()
                assert any(s and s['item']==wire.BLOCKS['items']['obsidian'] for s in a.inventory)
                a.send('chat',bytes.fromhex(wire.FIXTURES['client_chat']));a.barrier()
                command(a,'msg Witness Private test');command(a,'me waves')
                command(a,'save-all')
            finally:a.close();b.close()
        with wire.server(cfg) as port:
            c=Probe(port,'rejoin').login()
            try:
                assert any(n=='level_chunk_with_light' and b'Hello' in p and b'Back' in p for n,p in c.seen),'text missing from streamed chunks'
                with sqlite3.connect(world) as db:stored=json.loads(db.execute("SELECT data FROM containers WHERE position='1:64:1'").fetchone()[0])
                assert stored['front']==lines and stored['back'][0]=='Back'
                c.barrier()
            finally:c.close()
        text=''.join(p.read_text() for p in logs.glob('server-*.log'))
        for expected in ['<ParityProbe> Hello','Private test','waves','joined from','left the game']:
            assert expected in text,(expected,text)
        print('PASS: sign editor/wire order, both sides, unauthorized edits, chunk/restart persistence, middle pick and chat/join/leave logs')
    Path('/tmp/php-minecraft-sign-capture.json').write_text(json.dumps(wire.CAPTURE))


if __name__=='__main__':main()
