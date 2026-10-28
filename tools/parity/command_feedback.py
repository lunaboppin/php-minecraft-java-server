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
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
from protocol import Probe, ROOT, wire
from run import Server

# Commands whose feedback does not depend on timing or randomness. Each entry is
# (command, reconnect the witness first).
SCRIPT = [
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
    ('setblock 5 64 5 minecraft:stone', False), ('setblock 5 64 5 minecraft:stone', False), ('setblock 5 64 5 minecraft:air', False),
    ('fill 6 64 6 7 64 7 minecraft:dirt', False), ('fill 6 64 6 7 64 7 minecraft:dirt', False), ('fill 6 64 6 7 65 7 minecraft:air', False),
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
            marks = (len(operator.seen), len(witness.seen))
            operator.send('chat_command', wire.string(command))
            operator.tick_barrier()
            try:
                witness.tick_barrier()
            except (AssertionError, EOFError, OSError, TimeoutError):
                pass  # The witness may have been kicked.
            results.append({'command': command, 'operator': collect(operator, marks[0]), 'witness': collect(witness, marks[1])})
        operator.close()
        witness.close()
    finally:
        server.stop()
    (output / f'{kind}-feedback.json').write_text(json.dumps(results, indent=1))
    return results


def compare(reference, php):
    differences = []
    for expected, actual in zip(reference, php):
        # Vanilla's kick report and leave message race the network thread closing the channel.
        unordered = expected['command'].startswith('kick ')
        for role in ('operator', 'witness'):
            key = (lambda items: sorted(item['hex'] for item in items)) if unordered else (lambda items: items)
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
    matched = len(script) * 2 - len(differences)
    print(f'{matched}/{len(script) * 2} command observations identical; differences in {args.output / "differences.json"}')
    sys.exit(1 if differences else 0)


if __name__ == '__main__':
    main()
