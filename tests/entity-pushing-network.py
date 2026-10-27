#!/usr/bin/env python3
"""Real TCP clients and stable collision fixtures, optionally compared with Java 26.3."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import struct
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
sys.path.insert(0, str(ROOT / 'tests'))
from run import Server, difference
from protocol import wire
from mob_motion import Recorder, bits
import importlib.util

spec = importlib.util.spec_from_file_location('mob_knockback', ROOT / 'tests/mob-knockback.py')
knockback = importlib.util.module_from_spec(spec)
spec.loader.exec_module(knockback)
MotionProbe = knockback.MotionProbe
command = knockback.command
GIANT = json.loads((ROOT / 'reference/26.3/mobs.json').read_text())['entities']['giant']['id']
CASES = ['outside', 'player-wall', 'spectator-wall', 'two-giants']


def state(server, actor, count):
    if server.kind == 'java':
        recorder = Recorder(server, actor)
        return [recorder.snapshot('contact_fixture_' + str(index)) for index in range(count)]
    command(actor, 'save-all')
    with sqlite3.connect(server.path / 'world.sqlite') as database:
        saved = json.loads(database.execute("SELECT value FROM metadata WHERE key='animals'").fetchone()[0])
    values = saved.values() if isinstance(saved, dict) else saved
    mobs = sorted((entry for entry in values if entry['kind'] == 'giant'), key=lambda entry: entry['x'])
    if len(mobs) != count:
        raise AssertionError('Unexpected complete Giant roster')
    return [{'position_bits': bits([mob['x'], mob['y'], mob['z']]), 'velocity_bits': bits(mob['velocity']), 'ground': mob['ground']} for mob in mobs]


def observe(server, case, output):
    actor = observer = late = None
    result = {}
    try:
        server.start()
        actor = MotionProbe(server.port, server.kind + '-actor').login()
        observer = MotionProbe(server.port, server.kind + '-observer').login('PushWatcher')
        def execute(text):
            command(actor, text)
            observer.barrier()
        for text in ['gamerule minecraft:spawn_mobs false', 'time set 18000',
                     'tp @s 8.5 64 8.5', 'tp PushWatcher 8.5 64 8.5',
                     'fill -12 63 -12 12 63 12 minecraft:stone',
                     'fill -12 64 -12 12 82 12 minecraft:air',
                     'fill 5 64 -12 5 82 12 minecraft:stone']:
            execute(text)
        if server.kind == 'java':
            execute('kill @e[type=!minecraft:player]')
            actor.tick_barrier()
            execute('gamerule minecraft:respawn_radius 0')
            execute('setworldspawn 8 64 8')
        else:
            # Establish the same final collider position despite incomplete
            # native block-centering/respawn-radius command behavior in PHP.
            execute('setworldspawn 8.5 64 8.5')
        positions = [[2.5, 64, .5]]
        if case == 'two-giants':
            execute('fill -2 64 -12 -2 82 12 minecraft:stone')
            positions = [[1.0, 64, .5], [3.0, 64, .5]]
        for index, position in enumerate(positions):
            text = 'summon minecraft:giant ' + ' '.join(str(value) for value in position)
            if server.kind == 'java':
                text += ' {PersistenceRequired:1b,Tags:["contact_fixture_' + str(index) + '"]}'
            execute(text)
        if case == 'spectator-wall':
            execute('gamemode spectator')
        if case in ['player-wall', 'spectator-wall']:
            execute('tp @s 1.5 64 0.5')
        actor.step = observer.step = case
        actor.tick_barrier()
        actor.tick_barrier()
        observer.barrier()
        first = state(server, actor, len(positions))
        actor.tick_barrier()
        second = state(server, actor, len(positions))
        (output / 'stability-samples.json').write_text(json.dumps({'first': first, 'second': second}, indent=2) + '\n')
        if first != second:
            raise AssertionError('The fixture must reach a stable authoritative collision state')
        late = MotionProbe(server.port, server.kind + '-late').login('PushLate')
        late.step = case + ':late-pairing'
        if late.position != [8.5, 64.0, 8.5]:
            raise AssertionError('Late observer did not enter at the controlled outside position: ' + str(late.position))
        for client in [actor, observer, late]:
            client.barrier()
            ids = sorted((entity for entity, kind in client.entities.items() if kind == GIANT), key=lambda entity: client.positions[entity][0])
            if len(ids) != len(positions):
                raise AssertionError('Every observer must track every fixture Giant')
            for index, entity in enumerate(ids):
                expected = [struct.unpack('>d', bytes.fromhex(value))[0] for value in second[index]['position_bits']]
                if max(abs(actual - wanted) for actual, wanted in zip(client.positions[entity], expected)) > 1 / 4096:
                    raise AssertionError('Observer position differs from authoritative collision result')
        result = {'case': case, 'mobs': second, 'late_position': late.position, 'all_pairing_consistent': True}
        (output / 'observations.json').write_text(json.dumps(result, indent=2) + '\n')
        return result
    finally:
        trace = []
        for client in [actor, observer, late]:
            if client:
                client.close()
                trace.extend(client.trace)
        (output / 'packets.json').write_text(json.dumps(trace, indent=2) + '\n')
        (output / 'observations.json').write_text(json.dumps(result, indent=2) + '\n')
        server.stop()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--java-jar', type=Path)
    parser.add_argument('--java-home', type=Path, default=Path(os.environ.get('JAVA_HOME', '/usr')))
    parser.add_argument('--eula-file', type=Path)
    parser.add_argument('--output', type=Path)
    parser.add_argument('--case', choices=CASES, action='append')
    args = parser.parse_args()
    if args.java_jar:
        if not args.eula_file or not re.search(r'^\s*eula\s*=\s*true\s*$', args.eula_file.read_text(), re.M | re.I):
            parser.error('An existing accepted EULA file is required')
        expected = json.loads((ROOT / 'reference/26.3/version.json').read_text())['server_sha1']
        if hashlib.sha1(args.java_jar.read_bytes()).hexdigest() != expected:
            parser.error('Official server JAR checksum mismatch')
    output = (args.output or Path(tempfile.mkdtemp(prefix='php-native-live-pushing-'))).resolve()
    if output.exists() and any(output.iterdir()):
        parser.error('Output must be empty; existing evidence is retained')
    output.mkdir(parents=True, exist_ok=True)
    for case in args.case or CASES:
        states = {}
        for kind in ['php', 'java'] if args.java_jar else ['php']:
            folder = output / (case + '-' + kind)
            folder.mkdir()
            server = Server(folder / 'server', kind, str(args.java_home / 'bin/java') if kind == 'java' else None,
                            args.java_jar.resolve() if kind == 'java' else None,
                            args.eula_file.resolve() if kind == 'java' else None, difficulty=2)
            states[kind] = observe(server, case, folder)
        if args.java_jar:
            delta = difference(states['java'], states['php'])
            (output / (case + '-comparison.json')).write_text(json.dumps({'passed': delta is None, 'first_divergence': delta}, indent=2) + '\n')
            if delta:
                raise AssertionError(delta)
        print('PASS:', case, 'authoritative collision state and three tracked clients' + (' against actual Java' if args.java_jar else ''), flush=True)


if __name__ == '__main__':
    main()
