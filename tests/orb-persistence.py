#!/usr/bin/env python3
"""Experience orbs are saved with the world and come back after a restart with their UUIDs."""
import json
from pathlib import Path
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire

ORB = json.loads((ROOT / 'reference/26.3/experience.json').read_text())['orb']['type']


def orbs(client):
    found = set()
    for name, payload in client.seen:
        if name == 'add_entity':
            _, at = wire.read_vi(payload)
            uuid = payload[at:at + 16]
            kind, _ = wire.read_vi(payload, at + 16)
            if kind == ORB:
                found.add(uuid)
    return found


def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-orbs-') as tmp:
        cfg = {'port': 0, 'generator': 'flat', 'worldPath': str(Path(tmp) / 'world.sqlite'), 'defaultGameMode': 1, 'operators': ['ParityProbe']}
        with wire.server(cfg) as port:
            c = Probe(port, 'before').login()
            witness = Probe(port, 'witness').login('Witness')
            # The witness dies away from the operator, who must not collect the orbs.
            for text in ('fill 10 63 10 14 63 14 minecraft:stone', 'tp Witness 12.5 64 12.5', 'xp set Witness 10 levels', 'kill Witness', 'save-all'):
                c.send('chat_command', wire.string(text))
                c.tick_barrier()
            before = orbs(c)
            witness.close()
            c.close()
        assert len(before) >= 4, before
        with wire.server(cfg) as port:
            c = Probe(port, 'after').login()
            c.tick_barrier()
            after = orbs(c)
            c.close()
        assert after == before, (before, after)
    print(f'PASS: {len(before)} experience orbs keep their UUIDs across a restart')


if __name__ == '__main__':
    main()
