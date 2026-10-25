#!/usr/bin/env python3
"""Player head yaw for rotation-only, movement and late multiplayer joins."""
from pathlib import Path
import json
import struct
import sys
import tempfile
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/parity'))
from protocol import Probe,wire


def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-heads-') as tmp:
        with wire.server({'port':0,'generator':'flat','defaultGameMode':1,'worldPath':str(Path(tmp)/'world.sqlite')}) as port:
            a=Probe(port,'turner').login('Turner');b=Probe(port,'observer').login('Observer')
            try:
                for yaw,angle in [(90,64),(-90,192),(540,128),(359,255)]:
                    a.send('move_player_rot',struct.pack('>ffB',yaw,20,1));a.barrier()
                    b.until('rotate_head',lambda p:wire.read_vi(p)[0]==a.entity_id and p[-1]==angle)
                a.send('move_player_pos_rot',struct.pack('>dddffB',1.5,64,.5,45,0,1));a.barrier()
                b.until('rotate_head',lambda p:wire.read_vi(p)[0]==a.entity_id and p[-1]==32)
                late=Probe(port,'late').login('LateObserver')
                try:
                    assert any(n=='rotate_head' and wire.read_vi(p)[0]==a.entity_id and p[-1]==32 for n,p in late.seen)
                    spawned=next(p for n,p in late.seen if n=='add_entity' and wire.read_vi(p)[0]==a.entity_id)
                    _,off=wire.read_vi(spawned);_,off=wire.read_vi(spawned,off+16)
                    assert spawned[off+26]==32,'spawn head yaw'
                finally:late.close()
                print('PASS: multiplayer head rotation, wrapped angles, position/rotation and late joins')
            finally:a.close();b.close()
    Path('/tmp/php-minecraft-head-capture.json').write_text(json.dumps(wire.CAPTURE))


if __name__=='__main__':main()
