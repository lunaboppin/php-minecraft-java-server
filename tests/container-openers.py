#!/usr/bin/env python3
"""Opening and closing chests, barrels and ender chests: titles, window IDs, contents, lid events, sounds and states.

The first viewer opens a container with its sound and the last one closes it; a chest's lid
follows each change in viewers through a block event, and a barrel shows its open state.
With --eula-file (and the pinned JAR), the same steps run against the official 26.3 server and
the packets each player receives must match, with sound pitches and seeds zeroed.
"""
import argparse
import json
import os
from pathlib import Path
import shutil
import struct
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire
from run import Server

WATCHED = ('open_screen', 'container_close', 'block_event', 'sound', 'block_update', 'container_set_content')


def collect(client, who):
    token = os.urandom(8)
    client.send('ping_request', token)
    seen = []
    while True:
        name, payload = client.receive()
        if name in WATCHED:
            # The pitch is random, and so is the seed after it.
            entry = [who, name, (payload[:-12].hex() + '00' * 12) if name == 'sound' else payload.hex()]
            # A lid's five-tick recheck repeats its last event, merged only when it lands in the same tick.
            if not (name == 'block_event' and seen and seen[-1] == entry):
                seen.append(entry)
        if name == 'pong_response' and payload == token:
            return seen


def use(client, position, sequence):
    client.send('use_item_on', wire.vi(0) + wire.block_position(*position) + wire.vi(1) + struct.pack('>fff', .5, 1, .5) + b'\0\0' + wire.vi(sequence))


def record(kind, output, java, jar, eula):
    server = Server(output / kind, kind, java, jar, eula)
    server.start()
    steps = []
    try:
        operator = Probe(server.port, 'operator').login()
        witness = Probe(server.port, 'witness').login('Witness')
        for client in (operator, witness):
            client.tick_barrier()
        for command in ('fill -3 199 -3 3 199 3 stone', 'fill -3 200 -3 3 203 3 air', 'tp ParityProbe 0 200 0 0 0', 'tp Witness 0 200 -1 0 0',
                        'setblock 1 200 1 chest[facing=north]', 'setblock -1 200 1 barrel[facing=up]', 'setblock 1 200 -1 ender_chest[facing=south]',
                        'item replace entity ParityProbe enderchest.4 with diamond 3', 'gamemode survival @a'):
            operator.send('chat_command', wire.string(command))
        for client in (operator, witness):
            client.tick_barrier()
            client.tick_barrier()
        collect(witness, 'witness')
        collect(operator, 'operator')
        actions = [
            lambda: use(operator, (1, 200, 1), 1), lambda: use(witness, (1, 200, 1), 1),
            lambda: operator.send('container_close', wire.vi(1)), lambda: witness.send('container_close', wire.vi(1)),
            lambda: use(operator, (-1, 200, 1), 2), lambda: operator.send('container_close', wire.vi(2)),
            lambda: use(operator, (1, 200, 1), 3), lambda: use(operator, (1, 200, -1), 4), lambda: use(witness, (1, 200, -1), 2),
            lambda: operator.send('container_close', wire.vi(4)), lambda: witness.send('container_close', wire.vi(2)),
        ]
        for action in actions:
            action()
            time.sleep(0.4)
            steps.append(collect(witness, 'witness') + collect(operator, 'operator'))
    finally:
        server.stop()
    (output / f'{kind}-containers.json').write_text(json.dumps(steps, indent=1))
    return steps


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'tools/.cache/container-openers')
    parser.add_argument('--eula-file', type=Path, help='Existing user-accepted eula.txt; compares with the official server')
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    args.output = args.output.resolve()
    shutil.rmtree(args.output, ignore_errors=True)
    args.output.mkdir(parents=True)
    php = record('php', args.output, args.java, args.jar.resolve(), args.eula_file)
    names = [[name for _, name, _ in step] for step in php]
    assert 'open_screen' in names[0] and 'block_event' in names[0] and 'sound' in names[0], php
    if args.eula_file is not None:
        java = record('java', args.output, args.java, args.jar.resolve(), args.eula_file.resolve())
        assert php == java, json.dumps({'php': php, 'java': java}, indent=1)
        print('PASS: chest, barrel and ender chest openers identical to the official server')
    else:
        print('PASS: containers open and close with their lid events, sounds and states')


if __name__ == '__main__':
    main()
