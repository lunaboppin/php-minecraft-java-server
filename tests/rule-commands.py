#!/usr/bin/env python3
"""/gamerule with vanilla rule names, stored values and limits, and the /version report."""
import argparse
import json
from pathlib import Path
import sys
import tempfile
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
sys.path.insert(0, str(ROOT / 'tests/support'))
from protocol import Probe, wire
from text_components import chat, translate


def run(client, text):
    start = len(client.seen)
    client.send('chat_command', wire.string(text))
    client.tick_barrier()
    return client.seen[start:]


def chats(packets):
    return [payload for name, payload in packets if name == 'system_chat']


def rule(key, name, value):
    return chat(translate(key, {'text': name}, {'text': value}))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, help='Write PHP packet traces for the official codec verifier')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='php-minecraft-rule-commands-') as tmp:
        config = {'port': 0, 'generator': 'flat', 'worldPath': str(Path(tmp) / 'world.sqlite'), 'operators': ['ParityProbe']}
        traces = []
        with wire.server(config) as port:
            op = Probe(port, 'operator').login()
            guest = Probe(port, 'guest').login('Guest')
            try:
                for client in (op, guest):
                    client.tick_barrier()
                version = json.loads((ROOT / 'reference/26.3/version.json').read_text())
                assert chats(run(op, 'version')) == [chat(line) for line in [
                    translate('commands.version.header'),
                    translate('commands.version.id', {'text': '26.3'}), translate('commands.version.name', {'text': '26.3'}),
                    translate('commands.version.data', 5023), translate('commands.version.series', {'text': 'main'}),
                    translate('commands.version.protocol', 777, {'text': '0x309'}),
                    translate('commands.version.build_time', {'text': 'Tue Sep 15 11:20:48 UTC 2026'}),
                    translate('commands.version.pack.resource', {'text': version['resource_pack']}),
                    translate('commands.version.pack.data', {'text': version['data_pack']}),
                    translate('commands.version.stable.yes')]]
                assert [p for p in chats(run(guest, 'version')) if b'Unknown command' in p], 'a dedicated server requires operator permission'

                assert chats(run(op, 'gamerule keep_inventory')) == [rule('commands.gamerule.query', 'keep_inventory', 'false')]
                assert chats(run(op, 'gamerule minecraft:keep_inventory true')) == [rule('commands.gamerule.set', 'keep_inventory', 'true')]
                not_set = chat({'text': '', 'color': 'red', 'extra': [translate('commands.gamerule.not_set', {'text': 'keep_inventory'}, {'text': 'true'})]})
                assert chats(run(op, 'gamerule keep_inventory true')) == [not_set]
                # A rule without behavior here is stored and reported like any other.
                assert chats(run(op, 'gamerule random_tick_speed 5')) == [rule('commands.gamerule.set', 'random_tick_speed', '5')]
                assert chats(run(op, 'gamerule random_tick_speed')) == [rule('commands.gamerule.query', 'random_tick_speed', '5')]
                assert [p for p in chats(run(op, 'gamerule keepInventory true')) if b'Usage' in p], 'pre-26 rule names are unknown'
                assert [p for p in chats(run(op, 'gamerule random_tick_speed -1')) if b'between' in p]
                assert chats(run(op, 'gamerule max_block_modifications 3')) == [rule('commands.gamerule.set', 'max_block_modifications', '3')]
                too_big = chat({'text': '', 'color': 'red', 'extra': [translate('commands.fill.toobig', 3, 4)]})
                assert chats(run(op, 'fill 0 70 0 1 70 1 minecraft:stone')) == [too_big], 'the fill limit follows the game rule'
                traces.extend(op.trace)
            finally:
                for client in (op, guest):
                    client.close()
        # Stored and implemented rules survive a restart.
        with wire.server(config) as port:
            again = Probe(port, 'restart').login()
            try:
                assert chats(run(again, 'gamerule random_tick_speed')) == [rule('commands.gamerule.query', 'random_tick_speed', '5')]
                assert chats(run(again, 'gamerule keep_inventory')) == [rule('commands.gamerule.query', 'keep_inventory', 'true')]
                traces.extend(again.trace)
            finally:
                again.close()
        if args.output:
            args.output.write_text(json.dumps(traces))
    print('PASS: /gamerule vanilla names, stored rules, limits and restart, and the /version report')


if __name__ == '__main__':
    main()
