#!/usr/bin/env python3
"""Compare default melee impulses and settled ordinary travel through real TCP clients."""
from pathlib import Path
import argparse
import hashlib
import json
import os
import re
import sqlite3
import struct
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from run import Server, difference
from protocol import Probe, wire
from mob_motion import Recorder, bits
from entity_motion import EntityPositions

MOBS = json.loads((ROOT / 'reference/26.3/mobs.json').read_text())


class MotionProbe(Probe):
    def __init__(self, *args):
        super().__init__(*args)
        self.impulses = []
        self.motion = EntityPositions()
        self.positions = self.motion.positions
        self.motion_samples = []

    def receive(self):
        name, payload = super().receive()
        if self.state == 'play' and name == 'set_entity_motion':
            entity, offset = wire.read_vi(payload)
            self.impulses.append((entity, payload[offset:].hex()))
        if self.state == 'play':
            self.motion.apply(name, payload)
            if name in ['entity_position_sync', 'move_entity_pos', 'move_entity_pos_rot', 'move_entity_rot', 'teleport_entity']:
                entity, _ = wire.read_vi(payload)
                if entity in self.positions:
                    self.motion_samples.append((entity, name, payload.hex(), list(self.positions[entity])))
        return name, payload


def command(client, text):
    client.send('chat_command', wire.string(text))
    client.tick_barrier()


def snapshot(server, client):
    if server.kind == 'java':
        # The stationary goal-free actor is settled before these running-world
        # queries; no navigation can change its position between queries.
        return Recorder(server, client).snapshot('knockback_fixture')
    command(client, 'save-all')
    with sqlite3.connect(server.path / 'world.sqlite') as database:
        animals = json.loads(database.execute("SELECT value FROM metadata WHERE key='animals'").fetchone()[0])
    values = animals.values() if isinstance(animals, dict) else animals
    mob, = [mob for mob in values if mob['kind'] == 'giant']
    return {'position_bits': bits([mob['x'], mob['y'], mob['z']]),
            'velocity_bits': bits(mob['velocity']), 'ground': mob['ground']}


def observe(server, output):
    actor = observer = late = None
    results = {}
    try:
        server.start()
        actor = MotionProbe(server.port, server.kind + '-actor').login()
        observer = MotionProbe(server.port, server.kind + '-observer').login('ParityObserver')
        for text in ['gamerule minecraft:spawn_mobs false', 'time set 18000',
                     'fill -12 63 -12 12 63 12 minecraft:stone',
                     'fill -12 64 -12 12 82 12 minecraft:air',
                     'tp @s 5 64 0.5', 'tp ParityObserver 8.5 64 8.5', 'setworldspawn 8 64 8']:
            command(actor, text)
        summon = 'summon minecraft:giant 2.5 64 0.5'
        if server.kind == 'java':
            summon += ' {PersistenceRequired:1b,Tags:["knockback_fixture"]}'
        command(actor, summon)
        actor.tick_barrier()
        observer.barrier()
        entity, = [entity for entity, kind in actor.entities.items() if kind == MOBS['entities']['giant']['id']]
        before = snapshot(server, actor)
        actor.impulses.clear()
        observer.impulses.clear()
        actor.step = observer.step = 'default-melee-knockback'
        actor.send('attack', wire.vi(entity))
        late = MotionProbe(server.port, server.kind + '-late-observer').login('ParityLate')
        actor.tick_barrier()
        actor.tick_barrier()
        observer.barrier()
        after = snapshot(server, actor)
        observer.barrier()
        late.barrier()
        exact_position = [struct.unpack('>d', bytes.fromhex(value))[0] for value in after['position_bits']]
        for client in [actor, observer, late]:
            if entity not in client.positions or max(abs(a - b) for a, b in zip(client.positions[entity], exact_position)) > 1 / 4096:
                raise AssertionError('Tracked client did not settle at the authoritative position: ' + str(client.positions.get(entity)))
        # Correlate identical position updates seen by the established and late
        # clients. Cached pairing may retain sub-quantization precision; larger
        # offsets indicate pairing from the wrong relative-position base.
        established = {}
        for target, name, payload, position in actor.motion_samples:
            if target == entity:
                established.setdefault((name, payload), []).append(position)
        late_checks = 0
        for target, name, payload, position in late.motion_samples:
            candidates = established.get((name, payload), []) if target == entity else []
            if len(candidates) == 1:
                if max(abs(a - b) for a, b in zip(position, candidates[0])) > 1 / 4096:
                    raise AssertionError('Late client diverged after the same movement update')
                late_checks += 1
        if late_checks == 0:
            raise AssertionError('No unique common movement update was checked for the late client')
        (output / (server.kind + '-position-evidence.json')).write_text(json.dumps({
            'actor': actor.positions[entity], 'observer': observer.positions[entity], 'late': late.positions[entity],
            'late_common_updates_checked': late_checks,
            'actor_updates': [sample for sample in actor.motion_samples if sample[0] == entity],
            'late_updates': [sample for sample in late.motion_samples if sample[0] == entity],
        }, indent=2) + '\n')
        results = {'before': before, 'after': after,
                   'actor_impulses': [value for target, value in actor.impulses if target == entity],
                   'observer_impulses': [value for target, value in observer.impulses if target == entity],
                   'observed_position_bits': bits(actor.positions[entity]),
                   'observer_position_bits': bits(observer.positions[entity]),
                   'late_pairing_consistent': True}
        if not results['actor_impulses'] or results['actor_impulses'] != results['observer_impulses']:
            raise AssertionError('Both tracked clients must receive the same nonempty impulses: ' + str(results))
        if before['position_bits'] == after['position_bits'] or not after['ground']:
            raise AssertionError('The mob must move away and settle on the floor')
        return results
    finally:
        traces = []
        for client in [actor, observer, late]:
            if client:
                client.close()
                traces.extend(client.trace)
        (output / (server.kind + '-packets.json')).write_text(json.dumps(traces, indent=2) + '\n')
        (output / (server.kind + '-observations.json')).write_text(json.dumps(results, indent=2) + '\n')
        server.stop()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--java-jar', type=Path)
    parser.add_argument('--java-home', type=Path, default=Path(os.environ.get('JAVA_HOME', '/usr')))
    parser.add_argument('--eula-file', type=Path)
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    if args.java_jar:
        if not args.eula_file or not re.search(r'^\s*eula\s*=\s*true\s*$', args.eula_file.read_text(), re.M | re.I):
            parser.error('An existing accepted EULA file is required')
        expected = json.loads((ROOT / 'reference/26.3/version.json').read_text())['server_sha1']
        if hashlib.sha1(args.java_jar.read_bytes()).hexdigest() != expected:
            parser.error('Official server JAR checksum mismatch')
    output = (args.output or Path(tempfile.mkdtemp(prefix='php-minecraft-knockback-'))).resolve()
    if output.exists() and any(output.iterdir()):
        parser.error('Output must be empty; previous evidence is never overwritten')
    output.mkdir(parents=True, exist_ok=True)
    native = observe(Server(output / 'php', 'php', None, None, None, difficulty=2), output)
    if args.java_jar:
        java = observe(Server(output / 'java', 'java', str(args.java_home / 'bin/java'), args.java_jar.resolve(), args.eula_file.resolve(), difficulty=2), output)
        delta = difference(java, native)
        (output / 'comparison.json').write_text(json.dumps({'passed': delta is None, 'first_divergence': delta}, indent=2) + '\n')
        if delta:
            raise AssertionError(delta)
    print('PASS: tracked-client melee impulses and settled native mob travel' + (' against official Java 26.3' if args.java_jar else ''))


if __name__ == '__main__':
    main()
