#!/usr/bin/env python3
"""/difficulty and /defaultgamemode feedback, admin echo, broadcast and startup reapplication."""
import argparse
import json
from pathlib import Path
import struct
import sys
import tempfile
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire


# Independent of the PHP encoder: vanilla NBT text components. CompoundTag is a Java HashMap,
# so fields appear in bucket order (the official join message in tests/text-component-unit.php
# pins this against captured bytes).
def nbt_string(value):
    data = value.encode('utf-8')
    return struct.pack('>H', len(data)) + data


def java_order(keys):
    def bucket(key):
        value = 0
        for unit in struct.unpack('>%dH' % len(key), key.encode('utf-16-be')):
            value = (31 * value + unit) & 0xffffffff
        return (value ^ (value >> 16)) & 15
    return sorted(keys, key=bucket)


def body(component):
    out = b''
    for key in java_order(list(component)):
        value = component[key]
        if isinstance(value, bool):
            out += b'\x01' + nbt_string(key) + bytes([int(value)])
        elif key == 'uuid':
            out += b'\x0b' + nbt_string(key) + struct.pack('>i', 4) + value
        elif isinstance(value, str):
            out += b'\x08' + nbt_string(key) + nbt_string(value)
        elif isinstance(value, dict):
            out += b'\x0a' + nbt_string(key) + body(value)
        elif all(list(c) == ['text'] for c in value):
            out += b'\x09' + nbt_string(key) + b'\x08' + struct.pack('>i', len(value)) + b''.join(nbt_string(c['text']) for c in value)
        else:
            out += b'\x09' + nbt_string(key) + b'\x0a' + struct.pack('>i', len(value)) + b''.join(body(c) for c in value)
    return out + b'\0'


def player(name, uuid):
    return {'text': name, 'insertion': name, 'click_event': {'action': 'suggest_command', 'command': '/tell ' + name + ' '},
            'hover_event': {'action': 'show_entity', 'id': 'minecraft:player', 'uuid': uuid, 'name': name}}


def chat(component):
    return b'\x0a' + body(component) + b'\0'


def translate(key, *arguments):
    return {'translate': key, 'with': list(arguments)} if arguments else {'translate': key}


def admin(sender, message):
    return {'translate': 'chat.type.admin', 'with': [sender, message], 'color': 'gray', 'italic': True}


def run(client, text):
    start = len(client.seen)
    client.send('chat_command', wire.string(text))
    client.tick_barrier()
    return client.seen[start:]


def chats(packets):
    return [payload for name, payload in packets if name == 'system_chat']


def difficulties(packets):
    return [payload for name, payload in packets if name == 'change_difficulty']


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, help='Write PHP packet traces for the official codec verifier')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='php-minecraft-difficulty-') as tmp:
        config = {'port': 0, 'generator': 'flat', 'difficulty': 2, 'defaultGameMode': 0,
                  'worldPath': str(Path(tmp) / 'world.sqlite'), 'operators': ['ParityProbe', 'Witness']}
        traces = []
        with wire.server(config) as port:
            op = Probe(port, 'operator').login()
            witness = Probe(port, 'witness').login('Witness')
            # Offline-mode UUID, as listed to the other player.
            op_uuid = next(payload[2:18] for name, payload in witness.seen if name == 'player_info_update' and b'ParityProbe' in payload)
            guest = Probe(port, 'guest').login('Guest')
            try:
                for client in (op, witness, guest):
                    client.tick_barrier()  # Drain join messages before measuring command output.
                    assert difficulties(client.seen) == [b'\x02\x00'], 'dedicated servers send an unlocked difficulty'
                guest_uuid = next(payload[2:18] for name, payload in op.seen if name == 'player_info_update' and b'Guest' in payload)
                joined = {'translate': 'multiplayer.player.joined', 'with': [player('Guest', guest_uuid)], 'color': 'yellow'}
                assert chat(joined) in chats(op.seen) and chat(joined) in chats(witness.seen), 'vanilla join message'
                normal = translate('options.difficulty.normal')
                hard = translate('options.difficulty.hard')

                assert chats(run(op, 'difficulty')) == [chat(translate('commands.difficulty.query', normal))]

                start = {client: len(client.seen) for client in (witness, guest)}
                packets = run(op, 'difficulty hard')
                success = translate('commands.difficulty.success', hard)
                assert chats(packets) == [chat(success)], chats(packets)
                assert difficulties(packets) == [b'\x03\x00']
                witness.tick_barrier()
                guest.tick_barrier()
                assert chats(witness.seen[start[witness]:]) == [chat(admin(player('ParityProbe', op_uuid), success))]
                assert chats(guest.seen[start[guest]:]) == [], 'non-operators do not receive admin echoes'
                assert difficulties(witness.seen[start[witness]:]) == difficulties(guest.seen[start[guest]:]) == [b'\x03\x00']

                failure = {'text': '', 'color': 'red', 'extra': [translate('commands.difficulty.failure', hard)]}
                packets = run(op, 'difficulty hard')
                assert chats(packets) == [chat(failure)] and difficulties(packets) == []

                packets = run(op, 'defaultgamemode creative')
                assert chats(packets) == [chat(translate('commands.defaultgamemode.success', translate('gameMode.creative')))]
                late = Probe(port, 'late').login('Latecomer')
                try:
                    assert late.mode == 1, 'new players receive the changed default game mode'
                    assert difficulties(late.seen) == [b'\x03\x00']
                    traces.extend(late.trace)
                finally:
                    late.close()
                late_uuid = op.until('player_info_update', lambda payload: b'Latecomer' in payload)[2:18]
                left = {'translate': 'multiplayer.player.left', 'with': [player('Latecomer', late_uuid)], 'color': 'yellow'}
                op.until('system_chat', lambda payload: payload == chat(left))
                # Numeric game modes are not GameModeArgument input; the operator's mode is unchanged.
                assert chats(run(op, 'defaultgamemode 1')) != []
                assert op.mode == 0

                guest.tick_barrier()
                denied = run(guest, 'difficulty peaceful')
                assert [p for p in chats(denied) if b'Unknown command' in p] and difficulties(denied) == [], 'non-operators cannot change difficulty'
            finally:
                for client in (op, witness, guest):
                    traces.extend(client.trace)
                    client.close()
        # As on a dedicated server, server.json values are reapplied at startup.
        with wire.server(config) as port:
            again = Probe(port, 'restart').login()
            try:
                assert difficulties(again.seen) == [b'\x02\x00']
                assert chats(run(again, 'difficulty')) == [chat(translate('commands.difficulty.query', normal))]
                traces.extend(again.trace)
            finally:
                again.close()
        if args.output:
            args.output.write_text(json.dumps(traces))
    print('PASS: /difficulty query/set/failure, admin echo, unlocked broadcast, /defaultgamemode and startup reapplication')


if __name__ == '__main__':
    main()
