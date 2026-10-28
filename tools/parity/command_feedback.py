#!/usr/bin/env python3
"""Record exact command feedback from PHP or the official server, and compare two recordings.

An operator (ParityProbe) runs each command while a non-operator witness is connected. Every
system_chat and disconnect payload either client receives after the command is recorded as hex,
so a PHP recording can be compared byte for byte with an official one.
"""
import argparse
import json
import os
from pathlib import Path
import re
import struct
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
from protocol import Probe, ROOT, wire
from run import Server

# Commands whose feedback does not depend on timing or randomness. Each entry is
# (command, reconnect the witness first).
SCRIPT = [
    # 26.3 has no spawn-monsters property; without these, night-time monsters kill the witness.
    ('gamerule spawn_monsters false', False), ('gamerule spawn_mobs false', False),
    ('list', False), ('seed', False),
    ('difficulty', False), ('difficulty hard', False), ('difficulty hard', False),
    ('defaultgamemode survival', False), ('defaultgamemode survival', False),
    ('save-all', False), ('save-all flush', False),
    ('save-off', False), ('save-off', False), ('save-on', False), ('save-on', False),
    ('op Witness', False), ('op Witness', False), ('deop Witness', False), ('deop Witness', False),
    # An unknown profile name makes vanilla query Mojang's profile service, so none is used here.
    ('kick NobodyHere', False),
    ('kick Witness Bye  now', False), ('kick Witness', True),
    ('weather clear', True), ('weather rain', False), ('weather thunder', False),
    ('gamemode creative', False), ('gamemode survival', False), ('gamemode survival Witness', False), ('gamemode creative @a', False),
    ('setworldspawn 1 64 2', False), ('setworldspawn 1 64 2 90 10', False),
    ('spawnpoint Witness 3 64 4', False), ('spawnpoint @a 3 64 4 45 0', False),
    # Above the terrain, so both seeds give the same replies.
    ('setblock 5 200 5 minecraft:stone', False), ('setblock 5 200 5 minecraft:stone', False), ('setblock 5 200 5 minecraft:air', False),
    ('fill 6 200 6 7 200 7 minecraft:dirt', False), ('fill 6 200 6 7 200 7 minecraft:dirt', False), ('fill 6 200 6 7 201 7 minecraft:air', False),
    ('give Witness minecraft:dirt 3', False), ('give @a minecraft:dirt 2', False), ('give Witness minecraft:diamond_sword 2', False),
    ('summon minecraft:pig 0 70 0', False), ('summon minecraft:cow 1.5 70 1.5', False),
    ('tp Witness 0 70 0', False), ('tp 1 70 1', False), ('tp Witness ParityProbe', False), ('teleport @a 2 70 2', False), ('tp @s ~ ~1 ~', False),
    ('kill Witness', False), ('kill Witness', False), ('!respawn', False),
    # Entity selectors (random mob UUIDs are normalized for @e commands).
    ('summon minecraft:pig 0 64 3', False), ('summon minecraft:cow 3 64 0', False), ('tp @e[type=cow] 0 64 0', False),
    ('kill @e[type=pig,limit=1,sort=nearest]', False), ('kill @e[type=minecraft:cow]', False), ('kill @e[type=pig]', False),
    ('give @a[gamemode=survival] dirt 1', False), ('give @a[name=!Witness] dirt 2', False), ('give @a[limit=1,sort=furthest] dirt 3', False),
    ('version', False), ('gamerule keep_inventory', False), ('gamerule minecraft:keep_inventory true', False), ('gamerule keep_inventory true', False),
    ('gamerule random_tick_speed 5', False), ('gamerule random_tick_speed', False),
    # Death messages: the witness respawns between deaths.
    ('gamemode survival Witness', False), ('damage Witness 2.5', False),
    ('damage Witness 100 drown', False), ('!respawn', False), ('damage Witness 100 fall', False), ('!respawn', False),
    ('damage Witness 100 player_attack by ParityProbe', False), ('!respawn', False),
    ('damage Witness 1 player_attack by ParityProbe', False), ('damage Witness 100 drown', False), ('!respawn', False),
    ('damage Witness 100 out_of_world', False), ('!respawn', False), ('damage ParityProbe 5', False),
    # /time cases whose replies do not depend on how many ticks pass between commands.
    ('time set 1000', False), ('time set minecraft:night', False), ('time set noon', False),
    ('time pause', False), ('time pause', False), ('time resume', False), ('time resume', False),
    ('time rate 2', False), ('time rate 2', False), ('time rate 1', False),
    # Parse errors: the message, then the input up to the cursor; "!witness" runs a command as the non-operator.
    ('foo bar', False), ('time', False), ('time foo', False), ('time add 1.5.5', False),
    ('time add 1q', False), ('gamemode bogus', False), ('gamemode creative @x', False), ('gamemode creative @e[foo=1]', False),
    ('gamemode creative @a[', False), ('difficulty peaceful extra', False), ('give Witness minecraft:dirt abc', False), ('give Witness minecraft:dirt 0', False),
    ('give Witness minecraft:dirt 5x', False), ('give Witness notanitem', False), ('gamerule keep_inventory maybe', False), ('gamerule random_tick_speed 1.5', False),
    ('gamerule nonexistent_rule', False), ('setblock 1 2', False), ('setblock ~ ~ ^1 minecraft:stone', False), ('setblock a b c minecraft:stone', False),
    ('setblock 1.5 2 3 stone', False), ('tp ~ ~ ~ ~ x', False), ('summon minecraft:nope', False), ('summon minecraft:pig 1 2', False),
    ('damage Witness -1', False), ('damage Witness 5 nope', False), ('weather rain -5', False), ('weather rain 5x', False),
    ('kill @e[type=nope]', False), ('kill @e[limit=0]', False), ('kill @a[distance=-1]', False), ('kill @s[limit=1]', False),
    ('kill @e[type=pig]x', False), ('kill averyveryverylongplayername', False), ('list uuids extra', False), ('time set abc', False),
    ('time set -5', False), ('setblock 1 2 3 minecraft:stone[facing=north]', False), ('setblock 1 2 3 minecraft:oak_stairs[facing=up]', False), ('setblock 1 2 3 minecraft:oak_stairs[facing]', False),
    ('setblock 1 2 3 minecraft:oak_stairs[facing=north,facing=south]', False), ('give @s diamond_sword[nope=5] 1', False), ('give @s diamond_sword{foo:1} 1', False), ('give @s diamond_sword[damage=5,damage=6] 1', False),
    ('give @s diamond_sword[damage=5 1', False), ('gamerule keep_inventory "true"', False), ('!witness gamemode creative', False), ('!witness foo', False),
    ('!witness msg @a hi', False),
    ('list', False),
]


def collect(client, since):
    return [{'packet': name, 'hex': payload.hex()} for name, payload in client.seen[since:] if name in ('system_chat', 'disconnect')]


def record(kind, output, java, jar, eula, script=SCRIPT):
    server = Server(output / kind, kind, java, jar, eula, difficulty=2)
    server.start()
    results = []
    try:
        operator = Probe(server.port, 'operator').login()
        witness = Probe(server.port, 'witness').login('Witness')
        for client in (operator, witness):
            client.tick_barrier()
        for command, reconnect in script:
            if reconnect:
                witness.close()
                witness = Probe(server.port, 'witness').login('Witness')
                witness.tick_barrier()
                operator.tick_barrier()
            if command == '!respawn':
                # Not a command: the witness leaves the death screen before the next step.
                witness.send('client_command', wire.vi(0))
                witness.tick_barrier()
                operator.tick_barrier()
                continue
            marks = (len(operator.seen), len(witness.seen))
            # "!witness <command>" runs the command as the non-operator witness.
            sender, other = (witness, operator) if command.startswith('!witness ') else (operator, witness)
            sender.send('chat_command', wire.string(command.removeprefix('!witness ')))
            sender.tick_barrier()
            try:
                other.tick_barrier()
            except (AssertionError, EOFError, OSError, TimeoutError):
                pass  # The witness may have been kicked.
            results.append({'command': command, 'operator': collect(operator, marks[0]), 'witness': collect(witness, marks[1])})
        operator.close()
        witness.close()
    finally:
        server.stop()
    (output / f'{kind}-feedback.json').write_text(json.dumps(results, indent=1))
    return results


def normalize_uuids(items):
    """Zero random entity UUIDs (int-array tags and dashed insertion strings) for comparison."""
    uuid_tag = re.compile(r'0b0004' + b'uuid'.hex() + r'00000004[0-9a-f]{32}')
    insertion = re.compile(r'08' + struct.pack('>H', 9).hex() + b'insertion'.hex() + r'0024[0-9a-f]{72}')
    return [{**item, 'hex': insertion.sub('08' + struct.pack('>H', 9).hex() + b'insertion'.hex() + '0024' + '30' * 36,
                                         uuid_tag.sub('0b0004' + b'uuid'.hex() + '00000004' + '0' * 32, item['hex']))} for item in items]


def compare(reference, php):
    differences = []
    for expected, actual in zip(reference, php):
        # Vanilla's kick report and leave message race the network thread closing the channel.
        unordered = expected['command'].startswith('kick ')
        # A summoned entity has a random UUID on each server.
        random_ids = expected['command'].startswith('summon ') or '@e' in expected['command'] or '@n' in expected['command']
        for role in ('operator', 'witness'):
            key = (lambda items: sorted(item['hex'] for item in items)) if unordered else (normalize_uuids if random_ids else (lambda items: items))
            if key(expected[role]) != key(actual[role]):
                differences.append({'command': expected['command'], 'role': role, 'reference': expected[role], 'php': actual[role]})
    return differences


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True, help='Empty directory for servers and recordings')
    parser.add_argument('--eula-file', type=Path, help='Existing user-accepted eula.txt; required to record the official server')
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--commands', type=Path, help='Run these commands (one per line) instead of the built-in script')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    # The servers run in their own directories, so their paths must not be relative.
    args.output = args.output.resolve()
    args.output.mkdir(parents=True, exist_ok=False)
    script = [(line.strip(), False) for line in args.commands.read_text().splitlines() if line.strip()] if args.commands else SCRIPT
    php = record('php', args.output, args.java, args.jar, args.eula_file, script)
    if args.eula_file is None:
        print('Recorded PHP feedback only; pass --eula-file to compare with the official server')
        return
    reference = record('java', args.output, args.java, args.jar, args.eula_file, script)
    differences = compare(reference, php)
    (args.output / 'differences.json').write_text(json.dumps(differences, indent=1))
    observed = sum(command != '!respawn' for command, _ in script) * 2
    print(f'{observed - len(differences)}/{observed} command observations identical; differences in {args.output / "differences.json"}')
    sys.exit(1 if differences else 0)


if __name__ == '__main__':
    main()
