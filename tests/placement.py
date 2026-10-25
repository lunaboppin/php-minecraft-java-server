#!/usr/bin/env python3
"""Regression: vanilla edge hit coordinates must not disconnect clients placing obsidian."""
import json
from pathlib import Path
import struct
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/parity'))
from protocol import Probe,wire


def main():
    scenario=json.loads((ROOT/'tests/parity/placement.json').read_text())
    with tempfile.TemporaryDirectory(prefix='php-minecraft-placement-') as tmp:
        config={'port':0,'generator':'flat','worldPath':str(Path(tmp)/'world.sqlite'),
                'defaultGameMode':1,'operators':['ParityProbe']}
        with wire.server(config) as port:
            probe=Probe(port,'placement').login()
            try:
                for index,step in enumerate(scenario['steps']):
                    if step['action']=='command':probe.send('chat_command',wire.string(step['text']));probe.barrier()
                    if step['action']=='place':
                        probe.send('use_item_on',b'\0'+wire.block_position(*step['position'])+b'\1'+struct.pack('>fff',*map(float,step['cursor']))+b'\0\0'+wire.vi(index+1))
                        probe.until('block_changed_ack',lambda p:wire.read_vi(p)[0]==index+1)
                        probe.barrier()  # All eight requests, including rejected hits, leave the connection usable.
                probe.tick_barrier()
                positions=scenario['steps'][-1]['positions']
                expected=[wire.BLOCKS['states']['obsidian']]*3+[0]*4+[wire.BLOCKS['states']['obsidian']]
                actual=[probe.block(pos) for pos in positions]
                assert actual==expected,(actual,expected)
                print('PASS: obsidian edge hits, out-of-bounds/NaN/infinite hits and continuing gameplay')
            finally:probe.close()


if __name__=='__main__':main()
