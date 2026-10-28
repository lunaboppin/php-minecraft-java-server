#!/usr/bin/env python3
"""/op, /deop and /kick feedback, permission-level events, command trees and disconnect reasons."""
import argparse
import json
from pathlib import Path
import struct
import sys
import tempfile
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
sys.path.insert(0, str(ROOT / 'tests/support'))
from protocol import Probe, wire
from text_components import admin, chat, component, player, translate


def run(client, text):
    start = len(client.seen)
    client.send('chat_command', wire.string(text))
    client.tick_barrier()
    return client.seen[start:]


def named(packets, name):
    return [payload for packet, payload in packets if packet == name]


def permission_events(client, packets):
    """Op-level entity events (24 + level) addressed to the client's own entity."""
    own = struct.pack('>i', client.entity_id)
    return [payload[4] for payload in named(packets, 'entity_event') if payload[:4] == own and 24 <= payload[4] <= 28]


def failure(key):
    return chat({'text': '', 'color': 'red', 'extra': [translate(key)]})


def uuid_of(observer, name):
    return next(payload[2:18] for packet, payload in observer.seen if packet == 'player_info_update' and name.encode() in payload)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, help='Write PHP packet traces for the official codec verifier')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='php-minecraft-admin-') as tmp:
        config = {'port': 0, 'generator': 'flat', 'worldPath': str(Path(tmp) / 'world.sqlite'), 'operators': ['ParityProbe', 'Watcher']}
        traces = []
        with wire.server(config) as port:
            op = Probe(port, 'operator').login()
            watcher = Probe(port, 'watcher').login('Watcher')
            guest = Probe(port, 'guest').login('Guest')
            clients = [op, watcher, guest]
            try:
                for client in clients:
                    client.tick_barrier()
                # Vanilla sends the permission level, then the command tree, on join.
                assert permission_events(op, op.seen) == [28] and permission_events(guest, guest.seen) == [24]
                names = [name for name, _ in guest.seen]
                assert names.index('entity_event') < names.index('commands'), 'permission level precedes the command tree'
                op_uuid, guest_uuid = uuid_of(watcher, 'ParityProbe'), uuid_of(op, 'Guest')

                # /op: target receives level 28 and a new command tree; the operator echo includes the new operator.
                start = {client: len(client.seen) for client in clients}
                packets = run(op, 'op Guest')
                success = translate('commands.op.success', {'text': 'Guest'})
                assert named(packets, 'system_chat') == [chat(success)], named(packets, 'system_chat')
                for client in (watcher, guest):
                    client.tick_barrier()
                guest_packets = guest.seen[start[guest]:]
                assert permission_events(guest, guest_packets) == [28] and named(guest_packets, 'commands')
                assert b'deop' in named(guest_packets, 'commands')[0], 'operator commands are advertised immediately'
                for client in (watcher, guest):
                    assert named(client.seen[start[client]:], 'system_chat') == [chat(admin(player('ParityProbe', op_uuid), success))]
                assert named(run(op, 'op Guest'), 'system_chat') == [failure('commands.op.failed')]

                # /deop: back to level 24 and the guest command tree.
                start = len(guest.seen)
                packets = run(op, 'deop guest')
                assert named(packets, 'system_chat') == [chat(translate('commands.deop.success', {'text': 'Guest'}))]
                guest.tick_barrier()
                guest_packets = guest.seen[start:]
                assert permission_events(guest, guest_packets) == [24]
                assert b'deop' not in named(guest_packets, 'commands')[0]
                assert named(run(op, 'deop NobodyHere'), 'system_chat') == [failure('commands.deop.failed')]
                assert [p for p in named(run(guest, 'op Guest'), 'system_chat') if b'Unknown command' in p], 'non-operators cannot op themselves'
                assert named(run(op, 'kick NobodyHere'), 'system_chat') == [failure('argument.entity.notfound.player')]

                # /kick with a verbatim reason (double space preserved), then with the default reason.
                start = len(watcher.seen)
                packets = run(op, 'kick Guest Bye  now')
                reason = {'text': 'Bye  now'}
                kicked = translate('commands.kick.success', player('Guest', guest_uuid), reason)
                assert named(packets, 'system_chat')[0] == chat(kicked), 'the report precedes the leave message, as vanilla normally orders them'
                assert guest.until('disconnect') == component(reason)
                watcher.until('system_chat', lambda payload: payload == chat({'translate': 'multiplayer.player.left', 'with': [player('Guest', guest_uuid)], 'color': 'yellow'}))
                watcher.tick_barrier()
                assert chat(admin(player('ParityProbe', op_uuid), kicked)) in named(watcher.seen[start:], 'system_chat')
                traces.extend(guest.trace)
                clients.remove(guest)
                guest.close()

                late = Probe(port, 'late').login('Latecomer')
                try:
                    late_uuid = op.until('player_info_update', lambda payload: b'Latecomer' in payload)[2:18]
                    packets = run(op, 'kick Latecomer')
                    default = translate('multiplayer.disconnect.kicked')
                    assert chat(translate('commands.kick.success', player('Latecomer', late_uuid), default)) in named(packets, 'system_chat')
                    assert late.until('disconnect') == component(default)
                    traces.extend(late.trace)
                finally:
                    late.close()
            finally:
                for client in clients:
                    traces.extend(client.trace)
                    client.close()
        if args.output:
            args.output.write_text(json.dumps(traces))
    print('PASS: /op, /deop and /kick feedback, admin echo, permission levels, command trees and disconnect reasons')


if __name__ == '__main__':
    main()
