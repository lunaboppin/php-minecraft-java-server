#!/usr/bin/env python3
"""Experience orbs from a dying player and a mob killed by a player, collected by a nearby player.

With --eula-file (and the pinned JAR), the same steps run against the official 26.3 server. Orbs
merge at random, so each server is checked for the same orb values and the same collected total.
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

EXPERIENCE = json.loads((ROOT / 'reference/26.3/experience.json').read_text())
ORB = EXPERIENCE['orb']
# ExperienceOrb.getExperienceValue splits 70 points (10 levels) and a blaze's 10.
PLAYER_ORBS = [37, 17, 7, 7, 1, 1]
BLAZE_ORBS = [7, 3]


def total(collector):
    for name, payload in reversed(collector.seen):
        if name == 'set_experience':
            _, at = wire.read_vi(payload, 4)
            return wire.read_vi(payload, at)[0]
    return 0


def orb_values(client, start):
    """Each orb's value, and how many value updates it got, since start."""
    orbs, values = set(), {}
    for name, payload in client.seen[start:]:
        if name == 'add_entity':
            entity, at = wire.read_vi(payload)
            kind, _ = wire.read_vi(payload, at + 16)
            if kind == ORB['type']:
                orbs.add(entity)
        elif name == 'set_entity_data':
            entity, at = wire.read_vi(payload)
            if entity in orbs and payload[at] == ORB['value_index']:
                _, at = wire.read_vi(payload, at + 1)
                values.setdefault(entity, []).append(wire.read_vi(payload, at)[0])
    return sorted(updates[0] for updates in values.values()), sorted(len(updates) for updates in values.values())


def record(kind, output, java, jar, eula):
    # Easy, as every mob with a fixed experience reward is hostile; NoAI keeps the blaze still.
    server = Server(output / kind, kind, java, jar, eula, difficulty=1)
    server.start()
    result = {}
    try:
        collector = Probe(server.port, 'collector').login()
        witness = Probe(server.port, 'witness').login('Witness')
        clients = (collector, witness)

        def settle(times=1):
            for _ in range(times):
                for client in clients:
                    client.tick_barrier()

        def command(text):
            collector.send('chat_command', wire.string(text))
            settle()

        for text in ('gamerule spawn_mobs false', 'gamerule spawn_monsters false', 'gamerule respawn_radius 0', 'fill -6 99 -6 6 99 6 minecraft:stone', 'fill -1 99 29 1 99 31 minecraft:stone',
                     'tp @s 0.5 100 0.5', 'gamemode survival Witness', 'tp Witness 2.5 100 0.5', 'xp set @s 0 points', 'xp set @s 0 levels',
                     'xp set Witness 10 levels', 'spawnpoint Witness 0 100 30'):
            command(text)
        start = len(collector.seen)
        command('kill Witness')
        # Orbs follow only the nearest player, and not while that is the dead witness, who
        # respawns too far away to take any.
        witness.send('client_command', wire.vi(0))
        witness.until('respawn')
        settle(4)
        result['player orbs'], result['player orb updates'] = orb_values(collector, start)
        result['after player death'] = total(collector)
        command('summon blaze 2.5 100 0.5 {NoAI:1b}')
        start = len(collector.seen)
        command('damage @e[type=blaze,limit=1] 100 player_attack by @s')
        settle(4)
        result['blaze orbs'], result['blaze orb updates'] = orb_values(collector, start)
        result['after blaze'] = total(collector)
        collector.close()
        witness.close()
    finally:
        server.stop()
    (output / f'{kind}-experience.json').write_text(json.dumps(result, indent=1))
    return result


def check(kind, result):
    assert result['after player death'] == 70, (kind, result)
    assert result['after blaze'] == 80, (kind, result)
    # Orbs of one value may merge as they spawn, so each shown value comes from the split.
    for name, split in (('player orbs', PLAYER_ORBS), ('blaze orbs', BLAZE_ORBS)):
        remaining = list(split)
        for value in result[name]:
            assert value in remaining, (kind, name, result[name])
            remaining.remove(value)
        assert set(result[name]) == set(split), (kind, name, result[name])
    # ServerEntity sends the value at pairing and again as the new orb's dirty data.
    for name in ('player orb updates', 'blaze orb updates'):
        assert set(result[name]) == {2}, (kind, name, result[name])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'tools/.cache/experience-orbs')
    parser.add_argument('--eula-file', type=Path, help='Existing user-accepted eula.txt; also checks the official server')
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    args.output = args.output.resolve()
    shutil.rmtree(args.output, ignore_errors=True)
    args.output.mkdir(parents=True)
    check('php', record('php', args.output, args.java, args.jar, args.eula_file))
    if args.eula_file is not None:
        check('java', record('java', args.output, args.java, args.jar, args.eula_file))
        print('PASS: experience orbs split, shown and collected as on the official server')
    else:
        print('PASS: experience orbs split, shown and collected')


if __name__ == '__main__':
    main()
