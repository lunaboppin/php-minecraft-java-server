#!/usr/bin/env python3
"""Survival and creative block breaking as the digging player and an observer see it.

With --eula-file (and the pinned JAR), the same steps run against the official 26.3 server and the
block updates, acknowledgements and crack stages must match byte for byte.
"""
import argparse
import json
import os
from pathlib import Path
import shutil
import struct
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire
from run import Server

# START, ABORT and STOP; the face is the top.
START, ABORT, STOP = 0, 2, 3
DIGGER = ('block_update', 'block_changed_ack')
OBSERVER = ('block_update', 'block_destruction', 'level_event')


def record(kind, output, java, jar, eula):
    server = Server(output / kind, kind, java, jar, eula)
    server.start()
    steps = []
    try:
        observer = Probe(server.port, 'observer').login()
        digger = Probe(server.port, 'digger').login('Digger')
        clients = (observer, digger)

        def settle():
            for client in clients:
                client.tick_barrier()

        def command(text):
            observer.send('chat_command', wire.string(text))
            settle()

        for text in ('gamerule spawn_mobs false', 'gamerule block_drops false', 'fill -8 99 -8 8 99 8 minecraft:stone', 'tp @s 0.5 100 -3.5',
                     'tp Digger 0.5 100 0.5', 'gamemode survival Digger'):
            command(text)
        sequence = [0]

        def dig(action, x, y, z):
            sequence[0] += 1
            digger.send('player_action', wire.vi(action) + wire.block_position(x, y, z) + b'\1' + wire.vi(sequence[0]))

        def step(title, *actions, wait=1):
            starts = [len(client.seen) for client in clients]
            for action in actions:
                action()
            for _ in range(wait):
                settle()
            packets = {}
            for client, start, label, names in ((digger, starts[1], 'digger', DIGGER), (observer, starts[0], 'observer', OBSERVER)):
                seen = []
                for name, payload in client.seen[start:]:
                    if name not in names:
                        continue
                    # The crack and hit events each tick depend on how long the step took.
                    if name == 'level_event' and struct.unpack_from('>i', payload)[0] in (2019, 2020):
                        continue
                    if name == 'block_destruction':
                        # The breaker's entity ID differs between servers; the position and stage remain.
                        payload = payload[len(wire.vi(digger.entity_id)):]
                        # Stages after the first follow the number of ticks each step took; the
                        # start (0 by hand on these blocks) and the clearing (-1) remain.
                        if 0 < payload[-1] < 255:
                            continue
                    seen.append({'packet': name, 'hex': payload.hex()})
                packets[label] = seen
            steps.append({'step': title, **packets})

        for x, block in ((1, 'stone'), (2, 'dirt'), (3, 'torch'), (4, 'stone'), (5, 'stone'), (6, 'stone')):
            command(f'setblock {x} 100 0 minecraft:{block}')
        # Give the digger's game mode a long tick count before the delayed destroy below.
        for _ in range(8):
            settle()
        step('start stone', lambda: dig(START, 1, 100, 0))
        step('abort stone', lambda: dig(ABORT, 1, 100, 0))
        step('start then start another', lambda: dig(START, 1, 100, 0), lambda: dig(START, 2, 100, 0))
        step('abort the other', lambda: dig(ABORT, 2, 100, 0))
        step('insta-mine torch', lambda: dig(START, 3, 100, 0))
        step('early stop delays the destroy', lambda: dig(START, 4, 100, 0), lambda: dig(STOP, 4, 100, 0), wait=3)
        step('stop a block not started', lambda: dig(STOP, 5, 100, 0))
        step('too far away', lambda: dig(START, 8, 100, 8))
        step('start air', lambda: dig(START, 0, 101, 0))
        step('abort air', lambda: dig(ABORT, 0, 101, 0))
        step('dig stone to the end', lambda: dig(START, 5, 100, 0), wait=8)
        step('stop after digging', lambda: dig(STOP, 5, 100, 0))
        command('gamemode creative Digger')
        command('give Digger minecraft:diamond_sword')
        step('creative sword cannot break', lambda: dig(START, 6, 100, 0))
        observer.close()
        digger.close()
    finally:
        server.stop()
    (output / f'{kind}-steps.json').write_text(json.dumps(steps, indent=1))
    return steps


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'tools/.cache/block-breaking')
    parser.add_argument('--eula-file', type=Path, help='Existing user-accepted eula.txt; compares with the official server')
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    args.output = args.output.resolve()
    # Each run's servers start from empty directories.
    shutil.rmtree(args.output, ignore_errors=True)
    args.output.mkdir(parents=True)
    php = record('php', args.output, args.java, args.jar, args.eula_file)
    by_step = {entry['step']: entry for entry in php}
    assert [p['packet'] for p in by_step['start stone']['digger']] == ['block_changed_ack'], by_step['start stone']
    assert 'block_destruction' in [p['packet'] for p in by_step['start stone']['observer']], by_step['start stone']
    assert [p['packet'] for p in by_step['too far away']['digger']] == ['block_changed_ack'], by_step['too far away']
    if args.eula_file is not None:
        java = record('java', args.output, args.java, args.jar, args.eula_file)
        differences = [(a['step'], a, b) for a, b in zip(java, php) if a != b]
        assert not differences, json.dumps(differences, indent=1)
        print(f'PASS: {len(php)} block breaking steps identical to the official server')
    else:
        print(f'PASS: {len(php)} block breaking steps (starts, aborts, delayed destroys, insta-mining and refusals)')


if __name__ == '__main__':
    main()
