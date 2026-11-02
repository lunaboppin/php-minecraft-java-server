#!/usr/bin/env python3
"""Bows: a full draw from survival uses an arrow and wears the bow, and the arrow shot at the
archer's feet sticks there and is picked up again; creative shoots without arrows, and survival
without arrows shoots nothing.
"""
from pathlib import Path
import sys,tempfile,time
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/parity'))
from protocol import Probe,wire

RELEASE=bytes.fromhex('0600000000000000000000')


def command(c,text):c.send('chat_command',wire.string(text));c.tick_barrier()
def wait(c,seconds):
    end=time.monotonic()+seconds
    while time.monotonic()<end:c.barrier();time.sleep(.05)
def stacks(c):
    c.refresh_inventory()
    return [s for s in c.inventory if s]
def shoot(c,sequence,pitch):
    c.send('use_item',wire.vi(0)+wire.vi(sequence)+bytes(4)+bytes.fromhex(pitch));c.tick_barrier()
    wait(c,1.2)
    c.send('player_action',RELEASE);c.tick_barrier()
def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-bows-') as tmp:
        cfg={'port':0,'generator':'flat','defaultGameMode':0,'operators':['ParityProbe'],'worldPath':str(Path(tmp)/'world.sqlite')}
        with wire.server(cfg) as port:
            a=Probe(port,'bows').login()
            for text in ['gamerule spawn_mobs false','fill -4 -61 -4 4 -61 4 minecraft:stone','fill -4 -60 -4 4 -55 4 minecraft:air','tp @s 0.5 -60 0.5 0 90','clear @s','give @s bow','give @s arrow 2']:
                command(a,text)
            shoot(a,1,'42b40000')
            held=stacks(a)
            assert any(s.get('damage')==1 for s in held),('the bow did not wear',held)
            wait(a,1)
            assert sorted(s['count'] for s in stacks(a))==[1,2],('the arrow was not picked up again',stacks(a))
            command(a,'gamemode creative @s');command(a,'clear @s');command(a,'give @s bow')
            shoot(a,2,'42b40000')
            wait(a,1)
            assert [s.get('damage',0) for s in stacks(a)]==[0],('creative used an arrow or wore the bow',stacks(a))
            command(a,'gamemode survival @s')
            shoot(a,3,'42b40000')
            assert [s.get('damage',0) for s in stacks(a)]==[0],('a bow shot without arrows',stacks(a))
    print('PASS: bows use arrows and wear from survival, arrows stick and are picked up, creative needs no arrows')


if __name__=='__main__':main()
