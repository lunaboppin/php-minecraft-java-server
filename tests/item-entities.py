#!/usr/bin/env python3
"""Dropped items: thrown from the hand, popped from broken blocks, merging, resting and picked up.

With --eula-file (and the pinned JAR), the same steps run against the official 26.3 server. Drop
offsets and spins are random, so each server is checked for the same heights, merged counts and
pickups rather than identical bytes.
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
from entity_motion import EntityPositions

ITEM_TYPE = json.loads((ROOT / 'reference/26.3/blocks.json').read_text())['entity_types']['item']


class Watcher(Probe):
    def __init__(self, *args):
        super().__init__(*args)
        self.motion = EntityPositions()

    def receive(self):
        name, payload = super().receive()
        if self.state == 'play':
            self.motion.apply(name, payload)
        return name, payload


def spawns(client, start):
    """Item entities added since start: id -> (x, y, z)."""
    added = {}
    for name, payload in client.seen[start:]:
        if name == 'add_entity':
            entity, at = wire.read_vi(payload)
            kind, at = wire.read_vi(payload, at + 16)
            if kind == ITEM_TYPE:
                added[entity] = struct.unpack_from('>ddd', payload, at)
    return added


def counts(client, entities):
    """The last stack count each item was shown with."""
    result = {}
    for name, payload in client.seen:
        if name == 'set_entity_data':
            entity, at = wire.read_vi(payload)
            if entity in entities:
                # Index, serializer, then the stack's count.
                _, at = wire.read_vi(payload, at + 1)
                result[entity] = wire.read_vi(payload, at)[0]
    return result


def removed(client, start):
    gone = set()
    for name, payload in client.seen[start:]:
        if name == 'remove_entities':
            count, at = wire.read_vi(payload)
            for _ in range(count):
                entity, at = wire.read_vi(payload, at)
                gone.add(entity)
    return gone


def record(kind, output, java, jar, eula):
    server = Server(output / kind, kind, java, jar, eula)
    server.start()
    result = {}
    try:
        watcher = Watcher(server.port, 'watcher').login()
        thrower = Watcher(server.port, 'thrower').login('Thrower')
        clients = (watcher, thrower)

        def settle(times=1):
            for _ in range(times):
                for client in clients:
                    client.tick_barrier()

        def command(text):
            watcher.send('chat_command', wire.string(text))
            settle()

        for text in ('gamerule spawn_mobs false', 'gamerule respawn_radius 0', 'fill -16 99 -16 16 99 16 minecraft:stone',
                     'tp @s 0.5 100 -6.5', 'tp Thrower 0.5 100 6.5 0 0', 'gamemode survival Thrower', 'clear Thrower', 'give Thrower minecraft:stone 1'):
            command(text)
        thrower.send('set_carried_item', struct.pack('>h', 0))
        settle()
        # Thrown along the look from the eyes, 0.3 lower.
        start = len(watcher.seen)
        thrower.send('player_action', wire.vi(4) + wire.block_position(0, 0, 0) + b'\0' + wire.vi(0))
        settle(3)
        thrown = spawns(watcher, start)
        result['thrown'] = len(thrown)
        result['thrown spawn y'] = sorted(round(y, 6) for _, y, _ in thrown.values())
        result['thrown rest y'] = sorted(round(watcher.motion.positions[e][1], 3) for e in thrown if e in watcher.motion.positions)
        # The same block broken twice leaves two dirt items close enough to merge into one stack.
        start = len(watcher.seen)
        for _ in range(2):
            command('setblock 3 100 0 minecraft:dirt')
            command('setblock 3 100 0 minecraft:air destroy')
        settle(2)
        # Their random scatter can leave them out of reach of each other; together they merge.
        popped_spawns = spawns(watcher, start)
        command('tp @e[type=item,distance=..12] 3.5 100 0.5')
        settle(3)
        popped = popped_spawns
        result['popped spawn y offsets'] = sorted(round(y - 100, 3) for _, y, _ in popped.values())
        gone = removed(watcher, start)
        left = [e for e in popped if e not in gone]
        result['popped left'] = len(left)
        result['popped counts'] = sorted(counts(watcher, set(left)).values())
        result['popped rest y'] = sorted(round(watcher.motion.positions[e][1], 3) for e in left if e in watcher.motion.positions)
        # The thrower walks onto the merged stack and takes both.
        start = len(thrower.seen)
        command('tp Thrower 3.5 100 0.5')
        settle(2)
        result['takes'] = sum(1 for name, _ in thrower.seen[start:] if name == 'take_item_entity')
        watcher.close()
        thrower.close()
    finally:
        server.stop()
    (output / f'{kind}-items.json').write_text(json.dumps(result, indent=1))
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'tools/.cache/item-entities')
    parser.add_argument('--eula-file', type=Path, help='Existing user-accepted eula.txt; compares with the official server')
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    args.output = args.output.resolve()
    shutil.rmtree(args.output, ignore_errors=True)
    args.output.mkdir(parents=True)
    php = record('php', args.output, args.java, args.jar, args.eula_file)
    assert php['thrown'] == 1 and php['popped left'] == 1 and php['popped counts'] == [2], php
    if args.eula_file is not None:
        java = record('java', args.output, args.java, args.jar, args.eula_file)
        # Spawn offsets are random within the same bounds; everything else must agree.
        for key in ('thrown', 'thrown spawn y', 'thrown rest y', 'popped left', 'popped counts', 'popped rest y', 'takes'):
            assert php[key] == java[key], (key, php[key], java[key])
        for value in php['popped spawn y offsets'] + java['popped spawn y offsets']:
            assert 0.125 <= value <= 0.625, value
        print('PASS: dropped items thrown, popped, merged, rested and picked up as on the official server')
    else:
        print('PASS: dropped items thrown, popped, merged, rested and picked up')


if __name__ == '__main__':
    main()
