#!/usr/bin/env python3
"""Record exact ordinary-mob motion from tick-stepped official Java worlds.

Uses a goal-free Giant with its normal AI enabled. World physics and collision
run in the actual server; console queries read positions/velocities while frozen.
"""
import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import re
import struct
import time

from run import Server
from protocol import Probe, ROOT

CASES = [
    {'name': 'resting-stone', 'floor': 'stone', 'position': [.5, 64, .5], 'velocity': [0, 0, 0], 'ground': True},
    {'name': 'stone-hop', 'floor': 'stone', 'position': [.5, 64, .5], 'velocity': [.4, .36, 0], 'ground': True},
    {'name': 'stone-wall', 'floor': 'stone', 'position': [.5, 64, .5], 'velocity': [.8, 0, 0], 'ground': True, 'obstacle': 'stone'},
    {'name': 'bottom-slab', 'floor': 'stone', 'position': [.5, 64, .5], 'velocity': [.8, 0, 0], 'ground': True, 'obstacle': 'stone_slab[type=bottom]'},
    {'name': 'ice-drag', 'floor': 'ice', 'position': [.5, 64, .5], 'velocity': [.4, 0, .2], 'ground': True},
    {'name': 'slime-bounce', 'floor': 'slime_block', 'position': [.5, 64.8, .5], 'velocity': [.2, -.4, 0], 'ground': False},
]


def bits(values):
    return [struct.pack('>d', value).hex() for value in values]


class Recorder:
    def __init__(self, server, client):
        self.server = server
        self.client = client
        self.counter = 0

    def command(self, text):
        self.counter += 1
        marker = f'physics-oracle-marker-{self.counter}'
        offset = len(self.server.log.read_text())
        self.server.proc.stdin.write(text + '\nsay ' + marker + '\n')
        self.server.proc.stdin.flush()
        deadline = time.monotonic() + 20
        while marker not in self.server.log.read_text()[offset:]:
            if self.server.proc.poll() is not None:
                raise RuntimeError('Reference server exited during a console command')
            if time.monotonic() >= deadline:
                raise TimeoutError('Reference console did not finish: ' + text)
            time.sleep(.05)
        self.client.barrier()
        return self.server.log.read_text()[offset:]

    def value(self, tag, field):
        text = self.command(f'data get entity @e[tag={tag},limit=1] {field}')
        matches = re.findall(r'following entity data: (.+)', text)
        if len(matches) != 1:
            raise ValueError('Missing or ambiguous entity data query: ' + text)
        return matches[0].strip()

    def vector(self, tag, field):
        value = self.value(tag, field)
        if not value.startswith('[') or not value.endswith(']'):
            raise ValueError('Unexpected SNBT vector: ' + value)
        components = [float(part.strip().removesuffix('d')) for part in value[1:-1].split(',')]
        if len(components) != 3 or not all(math.isfinite(part) for part in components):
            raise ValueError('Invalid observed motion vector: ' + value)
        return components

    def snapshot(self, tag):
        ground = self.value(tag, 'OnGround')
        if ground not in ['0b', '1b', 'false', 'true']:
            raise ValueError('Unexpected ground flag: ' + ground)
        return {
            'position_bits': bits(self.vector(tag, 'Pos')),
            'velocity_bits': bits(self.vector(tag, 'Motion')),
            'ground': ground in ['1b', 'true'],
        }

    def step(self, count=1):
        before = self.client.game_time
        self.command(f'tick step {count}')
        self.client.until('set_time', lambda packet: self.client.game_time >= before + count)
        if self.client.game_time != before + count:
            raise AssertionError('Reference world advanced by an unexpected number of ticks')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java-home', type=Path, default=Path(os.environ.get('JAVA_HOME', '/usr')))
    parser.add_argument('--eula-file', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--ticks', type=int, default=24)
    parser.add_argument('--compare', type=Path, help='Require every observed bit and ground flag to match this fixture')
    args = parser.parse_args()
    if not 1 <= args.ticks <= 200:
        parser.error('--ticks must be between 1 and 200')
    out = args.output.resolve()
    if out.exists() and any(out.iterdir()):
        parser.error('--output must be empty; existing evidence is never overwritten')
    if not args.eula_file.is_file() or not re.search(r'^\s*eula\s*=\s*true\s*$', args.eula_file.read_text(), re.I | re.M):
        parser.error('An existing accepted EULA file is required')
    version = json.loads((ROOT / 'reference/26.3/version.json').read_text())
    with args.jar.open('rb') as stream:
        if hashlib.file_digest(stream, 'sha1').hexdigest() != version['server_sha1']:
            parser.error('Official server JAR checksum mismatch')

    out.mkdir(parents=True, exist_ok=True)
    server = Server(out / 'java', 'java', str(args.java_home / 'bin/java'), args.jar.resolve(), args.eula_file.resolve(), difficulty=2)
    client = None
    observations = []
    try:
        server.start()
        client = Probe(server.port, 'java-motion-oracle').login()
        recorder = Recorder(server, client)
        recorder.command('tick freeze')
        # Confirm that the world clock really stopped, independently of the
        # command's log acknowledgement. Network/service ticks keep running.
        client.until('set_time')
        previous = client.game_time
        client.until('set_time')
        if client.game_time != previous:
            raise AssertionError('Reference world did not remain frozen')
        recorder.command('gamerule minecraft:spawn_mobs false')
        recorder.command('time set 18000')
        recorder.command('tp ParityProbe 8.5 64 8.5')

        for index, case in enumerate(CASES):
            client.step = case['name'] + ':setup'
            # Establish an empty fixture world; the PHP flat-world fixture is
            # also initialized without incidental entities.
            recorder.command('kill @e[type=!minecraft:player]')
            # Killed mobs remain present for their death animation. Complete
            # removal before spawning the next actor so the cases cannot push
            # each other while one is dying.
            recorder.step(21)
            recorder.command('fill -12 64 -12 12 82 12 minecraft:air')
            recorder.command(f'fill -12 63 -12 12 63 12 minecraft:{case["floor"]}')
            if 'obstacle' in case:
                maximum = 64 if 'slab' in case['obstacle'] else 82
                recorder.command(f'fill 3 64 -12 3 {maximum} 12 minecraft:{case["obstacle"]}')
            tag = f'physics_fixture_{index}'
            motion = ','.join(str(value) + 'd' for value in case['velocity'])
            position = ' '.join(str(value) for value in case['position'])
            recorder.command(f'summon minecraft:giant {position} {{PersistenceRequired:1b,Tags:["{tag}"],Motion:[{motion}],OnGround:{int(case["ground"])}b}}')
            row = {**case, 'kind': 'giant', 'initial': recorder.snapshot(tag), 'steps': []}
            observations.append(row)
            for tick in range(args.ticks):
                client.step = f'{case["name"]}:tick-{tick + 1}'
                recorder.step()
                row['steps'].append(recorder.snapshot(tag))
                (out / 'observations.json').write_text(json.dumps(observations, indent=2) + '\n')
            print('Recorded', case['name'], len(row['steps']), 'exact world ticks', flush=True)
    finally:
        if client:
            client.close()
            (out / 'packets.json').write_text(json.dumps(client.trace, indent=2) + '\n')
        (out / 'observations.json').write_text(json.dumps(observations, indent=2) + '\n')
        server.stop()
    if args.compare and observations != json.loads(args.compare.read_text()):
        raise AssertionError('Fresh official trajectories differ from the checked-in fixture')
    print('Recorded all official mob motion trajectories' + ('; checked-in fixture matches exactly' if args.compare else ''), flush=True)


if __name__ == '__main__':
    main()
