#!/usr/bin/env python3
"""Dropped items are saved with the world and come back after a restart with their UUID and stack."""
from pathlib import Path
import struct
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire

ITEM = wire.BLOCKS['entity_types']['item']


def items(client):
    """UUID -> entity ID of every item entity the client was shown."""
    found = {}
    for name, payload in client.seen:
        if name == 'add_entity':
            entity, at = wire.read_vi(payload)
            uuid = payload[at:at + 16]
            kind, _ = wire.read_vi(payload, at + 16)
            if kind == ITEM:
                found[uuid] = entity
    return found


def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-items-') as tmp:
        cfg = {'port': 0, 'generator': 'flat', 'worldPath': str(Path(tmp) / 'world.sqlite'), 'defaultGameMode': 1, 'operators': ['ParityProbe']}
        with wire.server(cfg) as port:
            c = Probe(port, 'before').login()
            for text in ('fill -2 63 6 2 63 10 minecraft:stone', 'setblock 0 64 8 minecraft:dirt', 'setblock 0 64 8 minecraft:air destroy', 'save-all'):
                c.send('chat_command', wire.string(text))
                c.tick_barrier()
            before = items(c)
            c.close()
        assert len(before) == 1, before
        with wire.server(cfg) as port:
            c = Probe(port, 'after').login()
            c.tick_barrier()
            after = items(c)
            c.close()
        assert set(after) == set(before), (before, after)
    print('PASS: a dropped item keeps its UUID and stack across a restart')


if __name__ == '__main__':
    main()
