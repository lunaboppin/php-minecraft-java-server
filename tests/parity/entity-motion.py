#!/usr/bin/env python3
"""Check independent Python reconstruction against the actual Java delta decoder."""
from pathlib import Path
import json
import struct
import sys

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import wire
from entity_motion import EntityPositions

reference = json.loads((ROOT / 'reference/26.3/mob-tracking.json').read_text())
packets = json.loads((ROOT / 'reference/26.3/packets.json').read_text())['play']['clientbound']
names = {row['protocol_id']: name.removeprefix('minecraft:') for name, row in packets.items()}
checks = 0
for case in reference['cases']:
    observer = EntityPositions()
    observer.positions[17] = [struct.unpack('>d', bytes.fromhex(value))[0] for value in case['initial']['position_bits']]
    for tick, frame in enumerate(case['frames']):
        for packet in frame['packets']:
            data = bytes.fromhex(packet['hex'])
            identifier, offset = wire.read_vi(data)
            observer.apply(names[identifier], data[offset:])
        actual = [struct.pack('>d', value).hex() for value in observer.positions[17]]
        assert actual == frame['observed_bits'], (case['name'], tick, frame['observed_bits'], actual)
        late = EntityPositions()
        data = bytes.fromhex(frame['pairing_hex'])
        identifier, offset = wire.read_vi(data)
        late.apply(names[identifier], data[offset:])
        actual = [struct.pack('>d', value).hex() for value in late.positions[17]]
        assert actual == frame['pairing_position_bits'], (case['name'], tick, 'pairing base')
        checks += 2
assert checks == 8156
print('PASS: 8,156 independently reconstructed entity positions and late-pairing bases against actual Java codecs')
