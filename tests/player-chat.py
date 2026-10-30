#!/usr/bin/env python3
"""Chat from unsigned clients: player chat packets, chat visibility, illegal characters and spam.

With --eula-file (and the pinned JAR), the same packets go to the official 26.3 server and every
recorded packet must match byte for byte, apart from the server's message timestamp.
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

RECORDED = ('player_chat', 'system_chat', 'disguised_chat', 'disconnect')


def information(chat):
    # Language, view distance, chat visibility, colours, skin parts, main hand, filtering, listing, particles.
    return wire.string('en_us') + bytes([2]) + wire.vi(chat) + bytes([1, 127]) + wire.vi(1) + bytes([0, 1]) + wire.vi(0)


def chat(text):
    # The message, a timestamp, a salt, no signature, nothing acknowledged and an empty checksum.
    return wire.string(text) + struct.pack('>qq', 1000, 0) + b'\0' + wire.vi(0) + bytes(3) + b'\0'


def mask_timestamp(name, payload):
    """A player_chat timestamp is the server's clock; it follows the message content."""
    if name != 'player_chat':
        return payload.hex()
    _, at = wire.read_vi(payload)
    at += 16
    _, at = wire.read_vi(payload, at)
    at += 1
    length, at = wire.read_vi(payload, at)
    at += length
    return (payload[:at] + bytes(8) + payload[at + 8:]).hex()


def record(kind, output, java, jar, eula):
    server = Server(output / kind, kind, java, jar, eula)
    server.start()
    steps = []
    try:
        talker = Probe(server.port, 'talker').login('Talker')
        quiet = Probe(server.port, 'quiet').login('Quiet', information(1))
        hidden = Probe(server.port, 'hidden').login('Hidden', information(2))
        clients = {'talker': talker, 'quiet': quiet, 'hidden': hidden}
        # Join messages arrive before the steps start.
        for client in clients.values():
            client.tick_barrier()

        def step(name, action, settle=True):
            starts = {label: len(client.seen) for label, client in clients.items()}
            action()
            if settle:
                for client in list(clients.values()):
                    try:
                        client.tick_barrier()
                    except (AssertionError, OSError, EOFError):
                        pass
            steps.append({'step': name, **{label: [{'packet': n, 'hex': mask_timestamp(n, p)} for n, p in client.seen[starts[label]:] if n in RECORDED]
                                           for label, client in clients.items()}})

        step('chat', lambda: talker.send('chat', chat('Hello there')))
        step('second chat', lambda: talker.send('chat', chat('again')))
        step('hidden chat', lambda: hidden.send('chat', chat('nobody hears')))
        step('quiet chat', lambda: quiet.send('chat', chat('quiet speaks')))
        # Command chat follows the same settings.
        step('me', lambda: talker.send('chat_command', wire.string('me waves')))

        def burst():
            for number in range(15):
                talker.send('chat', chat('spam %d' % number))

        step('spam', burst)
        del clients['talker']
        step('illegal character', lambda: quiet.send('chat', chat('bad§text')))
        hidden.close()
    finally:
        server.stop()
    (output / f'{kind}-chat.json').write_text(json.dumps(steps, indent=1))
    return steps


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'tools/.cache/player-chat')
    parser.add_argument('--eula-file', type=Path, help='Existing user-accepted eula.txt; compares with the official server')
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    args.output = args.output.resolve()
    shutil.rmtree(args.output, ignore_errors=True)
    args.output.mkdir(parents=True)
    php = record('php', args.output, args.java, args.jar, args.eula_file)
    by_step = {entry['step']: entry for entry in php}
    assert [p['packet'] for p in by_step['chat']['talker']] == ['player_chat'], by_step['chat']
    assert by_step['chat']['quiet'] == [] and by_step['chat']['hidden'] == [], by_step['chat']
    assert 'disconnect' in [p['packet'] for p in by_step['spam']['talker']], by_step['spam']
    if args.eula_file is not None:
        java = record('java', args.output, args.java, args.jar, args.eula_file)
        differences = [(a['step'], a, b) for a, b in zip(java, php) if a != b]
        assert not differences, json.dumps(differences, indent=1)[:4000]
        print(f'PASS: {len(php)} chat steps identical to the official server')
    else:
        print(f'PASS: {len(php)} chat steps (player chat, visibility, spam and illegal characters)')


if __name__ == '__main__':
    main()
