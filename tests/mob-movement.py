#!/usr/bin/env python3
"""Common mob collision/medium regressions; not a species-AI parity test."""
from pathlib import Path
import json,sqlite3,sys,tempfile
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/parity'))
from protocol import Probe,wire


def command(c,text):c.send('chat_command',wire.string(text));c.tick_barrier()
def saved(world):
    with sqlite3.connect(world) as db:
        data=json.loads(db.execute("SELECT value FROM metadata WHERE key='animals'").fetchone()[0]);return list(data.values()) if isinstance(data,dict) else data

def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-mob-movement-') as tmp:
        world=str(Path(tmp)/'world.sqlite')
        with wire.server({'port':0,'generator':'flat','worldPath':world,'defaultGameMode':1,'operators':['ParityProbe']}) as port:
            c=Probe(port,'mob-movement').login()
            try:
                # A sealed underground chamber: movement must use the local floor and roof.
                command(c,'fill 8 48 8 13 52 13 minecraft:stone')
                command(c,'fill 9 49 9 12 51 12 minecraft:air')
                command(c,'summon cow 10.5 49 10.5');command(c,'summon pig 11.5 49 10.5')
                # Enclosed source-water pool and an airborne bat use different movement media.
                command(c,'fill -6 63 -6 -1 67 -1 minecraft:stone')
                command(c,'fill -5 64 -5 -2 66 -2 minecraft:water')
                command(c,'summon cod -3.5 65 -3.5');command(c,'summon bat 3.5 72 3.5')
                command(c,'save-all');before={m['kind']:m for m in saved(world)}
                start=c.game_time;c.until('set_time',lambda p:__import__('struct').unpack_from('>q',p)[0]>=start+100)
                command(c,'save-all');after={m['kind']:m for m in saved(world)}
                for kind in ['cow','pig']:
                    m=after[kind];assert 9.4<=m['x']<=12.6 and 9.4<=m['z']<=12.6 and 49<=m['y']<=50.61,m
                assert any(abs(after[k]['x']-before[k]['x'])+abs(after[k]['z']-before[k]['z'])>.05 for k in ['cow','pig']),'underground mobs froze'
                cod=after['cod'];assert -5<=cod['x']< -1 and -5<=cod['z']< -1 and 64<=cod['y']<67,cod
                assert after['bat']['y']>68,after['bat']
            finally:c.close()
    print('PASS: local cave floor/roof/walls, underground movement, source-water containment and flight without gravity')
if __name__=='__main__':main()
