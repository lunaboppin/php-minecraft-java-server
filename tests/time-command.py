#!/usr/bin/env python3
"""/time on the overworld clock: markers, absolute and relative time, pause, rate, packets and restart."""
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
from text_components import Long, chat, translate


def run(client, text):
    start = len(client.seen)
    client.send('chat_command', wire.string(text))
    client.tick_barrier()
    return client.seen[start:]


def chats(packets):
    return [payload for name, payload in packets if name == 'system_chat']


def failure(key, *arguments):
    return chat({'text': '', 'color': 'red', 'extra': [translate(key, *arguments)]})


def clock(payload):
    """The overworld clock's total ticks, partial tick and rate from set_time."""
    off = 9 + len(wire.vi(wire.read_vi(payload, 9)[0]))
    total, off = wire.read_vi(payload, off)
    return (total,) + struct.unpack_from('>ff', payload, off)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, help='Write PHP packet traces for the official codec verifier')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='php-minecraft-time-') as tmp:
        config = {'port': 0, 'generator': 'flat', 'worldPath': str(Path(tmp) / 'world.sqlite'), 'operators': ['ParityProbe']}
        traces = []
        overworld = {'text': 'minecraft:overworld'}
        with wire.server(config) as port:
            op = Probe(port, 'operator').login()
            try:
                op.tick_barrier()
                # A new world's clock starts at 0, like vanilla's.
                total = clock(next(p for name, p in op.seen if name == 'set_time'))[0]
                assert total < 200, total
                packets = run(op, 'time set 1000')
                assert chats(packets) == [chat(translate('commands.time.set.absolute', overworld, 1000))]
                assert clock([p for name, p in packets if name == 'set_time'][0])[0] == 1000
                # Markers move forward to their next occurrence, including a full day when repeated.
                assert chats(run(op, 'time set minecraft:night')) == [chat(translate('commands.time.set.time_marker', overworld, {'text': 'minecraft:night'}))]
                now = clock([p for name, p in run(op, 'time pause') if name == 'set_time'][0])
                assert 13000 <= now[0] < 13200 and now[2] == 0.0, 'paused clocks are sent at rate 0'
                assert chats(run(op, 'time pause')) == [failure('commands.time.pause.already_paused', overworld)]
                assert chats(run(op, 'time set night')) == [chat(translate('commands.time.set.time_marker', overworld, {'text': 'minecraft:night'}))]
                assert chats(run(op, 'time query time')) == [chat(translate('commands.time.query.absolute', overworld, Long(37000)))]
                assert chats(run(op, 'time add -1000')) == [chat(translate('commands.time.set.absolute', overworld, Long(36000)))]
                assert chats(run(op, 'time add 1d')) == [chat(translate('commands.time.set.absolute', overworld, Long(60000)))]
                assert chats(run(op, 'time set 60000')) == [failure('commands.time.set.already_at_time', overworld, 60000)]
                assert chats(run(op, 'time set minecraft:unknown')) == [failure('commands.time.no_time_marker_found', {'text': 'minecraft:unknown'}, overworld)]
                assert chats(run(op, 'time rate 2')) == [chat(translate('commands.time.rate', overworld, 2.0))]
                assert chats(run(op, 'time rate 2')) == [failure('commands.time.rate.already_same', overworld, 2.0)]
                packets = run(op, 'time resume')
                assert chats(packets) == [chat(translate('commands.time.resume', overworld))]
                assert clock([p for name, p in packets if name == 'set_time'][0])[2] == 2.0, 'the effective rate is sent'
                assert chats(run(op, 'time resume')) == [failure('commands.time.pause.already_running', overworld)]
                start = clock([p for name, p in run(op, 'time rate 0.5') if name == 'set_time'][0])[0]
                after = clock([p for name, p in run(op, 'time pause') if name == 'set_time'][0])[0]
                assert 0 < after - start < 40, 'a half-rate clock advances half as fast'
                # The End has its own clock, which "of" selects from any dimension.
                the_end = {'text': 'minecraft:the_end'}
                assert chats(run(op, 'time of minecraft:the_end pause')) == [chat(translate('commands.time.pause', the_end))]
                ended = chats(run(op, 'time of the_end query time'))[0]
                assert ended.startswith(chat(translate('commands.time.query.absolute', the_end, Long(0)))[:40])
                assert chats(run(op, 'time of minecraft:the_end set day')) == [failure('commands.time.no_time_marker_found', {'text': 'minecraft:day'}, the_end)]
                assert chats(run(op, 'time of minecraft:the_end resume')) == [chat(translate('commands.time.resume', the_end))]
                # The periodic sync carries only the game time.
                assert any(name == 'set_time' and len(payload) == 9 and payload[8] == 0 for name, payload in op.seen)
                gametime = chats(run(op, 'time query gametime'))[0]
                assert gametime.startswith(chat(translate('commands.time.query.gametime', Long(0)))[:12])
                traces.extend(op.trace)
            finally:
                op.close()
        with wire.server(config) as port:
            again = Probe(port, 'restart').login()
            try:
                assert chats(run(again, 'time query time')) == [chat(translate('commands.time.query.absolute', overworld, Long(after)))], 'a paused clock survives restart'
                assert chats(run(again, 'time resume')) == [chat(translate('commands.time.resume', overworld))]
                traces.extend(again.trace)
            finally:
                again.close()
        if args.output:
            args.output.write_text(json.dumps(traces))
    print('PASS: /time markers, absolute and relative time, pause, rate, clock packets and restart')


if __name__ == '__main__':
    main()
