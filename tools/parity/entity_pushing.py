#!/usr/bin/env python3
"""Capture controlled player/mob and mob/mob pushing in tick-stepped Java worlds."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re

from mob_motion import Recorder
from protocol import Probe, ROOT
from run import Server, difference

CASES = [
    {'name': 'player-outside', 'player': [8.5, 64, 8.5], 'mode': 'creative', 'mobs': [[2.5, 64, .5]]},
    {'name': 'player-overlap', 'player': [.5, 64, 2.5], 'mode': 'creative', 'mobs': [[2.5, 64, .5]]},
    {'name': 'spectator-overlap', 'player': [.5, 64, 2.5], 'mode': 'spectator', 'mobs': [[2.5, 64, .5]]},
    {'name': 'two-giants', 'player': [8.5, 64, 8.5], 'mode': 'creative', 'mobs': [[.5, 64, .5], [3.5, 64, .5]]},
]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java-home', type=Path, default=Path(os.environ.get('JAVA_HOME', '/usr')))
    parser.add_argument('--eula-file', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--ticks', type=int, default=24)
    parser.add_argument('--compare', type=Path, help='Require complete observed states to match the pinned capture')
    args = parser.parse_args()
    if not 1 <= args.ticks <= 100:
        parser.error('Tick count must be between 1 and 100')
    if not args.eula_file.is_file() or not re.search(r'^\s*eula\s*=\s*true\s*$', args.eula_file.read_text(), re.M | re.I):
        parser.error('An existing accepted EULA file is required')
    version = json.loads((ROOT / 'reference/26.3/version.json').read_text())
    with args.jar.open('rb') as stream:
        if hashlib.file_digest(stream, 'sha1').hexdigest() != version['server_sha1']:
            parser.error('Official server JAR checksum mismatch')
    output = args.output.resolve()
    if output.exists() and any(output.iterdir()):
        parser.error('Output must be empty; previous evidence is never overwritten')
    output.mkdir(parents=True, exist_ok=True)
    server = Server(output / 'java', 'java', str(args.java_home / 'bin/java'), args.jar.resolve(), args.eula_file.resolve(), difficulty=2)
    client = None
    observations = []
    try:
        server.start()
        client = Probe(server.port, 'java-collision-oracle').login()
        recorder = Recorder(server, client)
        recorder.command('tick freeze')
        client.until('set_time')
        previous = client.game_time
        client.until('set_time')
        if client.game_time != previous:
            raise AssertionError('Reference world did not remain frozen')
        recorder.command('gamerule minecraft:spawn_mobs false')
        recorder.command('time set 18000')
        recorder.command('tag ParityProbe add push_player')
        for case in CASES:
            client.step = case['name'] + ':setup'
            recorder.command('tp ParityProbe 8.5 64 8.5')
            recorder.command('gamemode creative ParityProbe')
            recorder.command('kill @e[type=!minecraft:player]')
            recorder.step(21)
            recorder.command('fill -12 64 -12 12 82 12 minecraft:air')
            recorder.command('fill -12 63 -12 12 63 12 minecraft:stone')
            recorder.command('gamemode ' + case['mode'] + ' ParityProbe')
            recorder.command('tp ParityProbe ' + ' '.join(str(value) for value in case['player']))
            tags = []
            for index, position in enumerate(case['mobs']):
                tag = f'push_mob_{index}'
                recorder.command('summon minecraft:giant ' + ' '.join(str(value) for value in position)
                                 + ' {PersistenceRequired:1b,Tags:["' + tag + '"],Motion:[0d,0d,0d],OnGround:1b}')
                tags.append(tag)
            def snapshot():
                return {'player': recorder.snapshot('push_player'), 'mobs': [recorder.snapshot(tag) for tag in tags]}
            row = {**case, 'kind': 'giant', 'initial': snapshot(), 'steps': []}
            observations.append(row)
            for tick in range(args.ticks):
                client.step = f'{case["name"]}:tick-{tick + 1}'
                recorder.step()
                row['steps'].append(snapshot())
                (output / 'observations.json').write_text(json.dumps(observations, indent=2) + '\n')
            print('Recorded', case['name'], len(row['steps']), 'controlled native collision ticks', flush=True)
    finally:
        if client:
            client.close()
            (output / 'packets.json').write_text(json.dumps(client.trace, indent=2) + '\n')
        server.stop()
    (output / 'observations.json').write_text(json.dumps(observations, indent=2) + '\n')
    if args.compare:
        expected = json.loads(args.compare.read_text())
        delta = difference(expected, observations)
        (output / 'comparison.json').write_text(json.dumps({'passed': delta is None, 'first_divergence': delta}, indent=2) + '\n')
        if delta:
            raise AssertionError(delta)
    print('Recorded all controlled native entity collision trajectories')


if __name__ == '__main__':
    main()
