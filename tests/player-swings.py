#!/usr/bin/env python3
"""Player arm swings: each punch swings the main hand for the players tracking the puncher.

Two punches in the same tick both swing, since the first has not ticked yet. With
--eula-file (and the pinned JAR), the same steps run against the official 26.3 server and the
swing_animation packets each player receives must match.
"""
import argparse
import json
import os
from pathlib import Path
import shutil
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire
from run import Server


def swings(client, who):
    """swing_animation payloads, without the entity ID, until a ping returns."""
    token = os.urandom(8)
    client.send('ping_request', token)
    seen = []
    while True:
        name, payload = client.receive()
        if name == 'swing_animation':
            seen.append([who, payload[1:].hex()])
        if name == 'pong_response' and payload == token:
            return seen


def record(kind, output, java, jar, eula):
    server = Server(output / kind, kind, java, jar, eula)
    server.start()
    steps = []
    try:
        operator = Probe(server.port, 'operator').login()
        witness = Probe(server.port, 'witness').login('Witness')
        for client in (operator, witness):
            client.tick_barrier()
        operator.send('chat_command', wire.string('fill -3 199 -3 3 199 3 stone'))
        operator.send('chat_command', wire.string('tp @a 0 200 0'))
        for client in (operator, witness):
            client.tick_barrier()
            client.tick_barrier()
        # One punch, two in the same tick, then another once those swings have ended.
        for punches, wait in ((1, 0.5), (2, 0.5), (1, 0.5)):
            for _ in range(punches):
                operator.send('punch', b'')
            # The official server handles the packet on its next tick.
            time.sleep(wait)
            steps.append(swings(witness, 'witness') + swings(operator, 'operator'))
    finally:
        server.stop()
    (output / f'{kind}-swings.json').write_text(json.dumps(steps, indent=1))
    return steps


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'tools/.cache/player-swings')
    parser.add_argument('--eula-file', type=Path, help='Existing user-accepted eula.txt; compares with the official server')
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    args.output = args.output.resolve()
    shutil.rmtree(args.output, ignore_errors=True)
    args.output.mkdir(parents=True)
    php = record('php', args.output, args.java, args.jar.resolve(), args.eula_file)
    assert php[0] == [['witness', '000106']] and php[1] == [['witness', '000106']] * 2, php
    if args.eula_file is not None:
        java = record('java', args.output, args.java, args.jar.resolve(), args.eula_file.resolve())
        assert php == java, json.dumps({'php': php, 'java': java}, indent=1)
        print('PASS: punch swings identical to the official server')
    else:
        print('PASS: punches swing the main hand for trackers')


if __name__ == '__main__':
    main()
