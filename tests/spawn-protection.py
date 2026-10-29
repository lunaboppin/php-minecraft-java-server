#!/usr/bin/env python3
"""Spawn protection for non-operators breaking and using blocks, optionally compared with the official server.

With --eula-file (and the pinned JAR), the same steps run against the official 26.3 server and the
spawn-protection notices and block acknowledgements must match byte for byte.
"""
import argparse
import os
from pathlib import Path
import struct
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire
from run import Server

RADIUS = 4
# Blocks on the stone floor at x = 0 (the spawn), the protection edge, and just outside it.
COLUMNS = [0, RADIUS, RADIUS + 1]


def record(kind, output, java, jar, eula):
    server = Server(output / kind, kind, java, jar, eula, spawn_protection=RADIUS)
    server.start()
    steps = []
    try:
        operator = Probe(server.port, 'operator').login()
        visitor = Probe(server.port, 'visitor').login('Visitor')
        for client in (operator, visitor):
            client.tick_barrier()

        def command(text):
            operator.send('chat_command', wire.string(text))
            operator.tick_barrier()
            visitor.tick_barrier()

        for text in ('gamerule spawn_mobs false', 'setworldspawn 0 100 0', 'fill -8 99 -2 8 99 2 minecraft:stone',
                     'gamemode survival Visitor', 'tp Visitor 2 100 1', 'give Visitor stone 4'):
            command(text)
        sequence = 1

        def step(client, name, packet, payload):
            nonlocal sequence
            start = len(client.seen)
            client.send(packet, payload + wire.vi(sequence))
            sequence += 1
            client.tick_barrier()
            seen = [{'packet': n, 'hex': p.hex()} for n, p in client.seen[start:] if n in ('system_chat', 'block_changed_ack')]
            steps.append({'step': name, 'packets': seen})

        for x in COLUMNS:
            step(visitor, f'break {x}', 'player_action', wire.vi(0) + wire.block_position(x, 99, 0) + b'\1')
            # use_item_on: main hand, the block's top face, its centre, outside the block, within the border.
            step(visitor, f'use {x}', 'use_item_on', wire.vi(0) + wire.block_position(x, 99, 0) + wire.vi(1) + struct.pack('>fff', .5, 1, .5) + b'\0\0')
        step(operator, 'operator break 0', 'player_action', wire.vi(0) + wire.block_position(0, 99, 0) + b'\1')
        operator.close()
        visitor.close()
    finally:
        server.stop()
    return steps


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'tools/.cache/spawn-protection')
    parser.add_argument('--eula-file', type=Path, help='Existing user-accepted eula.txt; compares with the official server')
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    args.output = args.output.resolve()
    args.output.mkdir(parents=True, exist_ok=False)
    php = record('php', args.output, args.java, args.jar, args.eula_file)
    notice = b'build.spawn_protection'.hex()
    for entry in php:
        protected = entry['step'] in ('break 0', 'use 0', f'break {RADIUS}', f'use {RADIUS}')
        noticed = any(p['packet'] == 'system_chat' and notice in p['hex'] for p in entry['packets'])
        assert noticed == protected, entry
        assert entry['packets'][-1]['packet'] == 'block_changed_ack', entry
    if args.eula_file is not None:
        java = record('java', args.output, args.java, args.jar, args.eula_file)
        differences = [(a['step'], a['packets'], b['packets']) for a, b in zip(java, php) if a != b]
        assert not differences, differences
        print(f'PASS: {len(php)} spawn-protection steps identical to the official server')
    else:
        print(f'PASS: {len(php)} spawn-protection steps (notices inside radius {RADIUS}, none outside or for operators)')


if __name__ == '__main__':
    main()
