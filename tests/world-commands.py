#!/usr/bin/env python3
"""/gamemode, /setworldspawn, /spawnpoint, /setblock and /fill feedback, echoes and effects."""
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
from text_components import chat, player, translate


def run(client, text):
    start = len(client.seen)
    client.send('chat_command', wire.string(text))
    client.tick_barrier()
    return client.seen[start:]


def named(packets, name):
    return [payload for packet, payload in packets if packet == name]


def failure(key, *arguments):
    return chat({'text': '', 'color': 'red', 'extra': [translate(key, *arguments)]})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, help='Write PHP packet traces for the official codec verifier')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='php-minecraft-world-commands-') as tmp:
        config = {'port': 0, 'generator': 'flat', 'defaultGameMode': 1, 'worldPath': str(Path(tmp) / 'world.sqlite'), 'operators': ['ParityProbe']}
        traces = []
        with wire.server(config) as port:
            op = Probe(port, 'operator').login()
            witness = Probe(port, 'witness').login('Witness')
            try:
                for client in (op, witness):
                    client.tick_barrier()
                op_uuid = next(payload[2:18] for name, payload in witness.seen if name == 'player_info_update' and b'ParityProbe' in payload)
                witness_uuid = next(payload[2:18] for name, payload in op.seen if name == 'player_info_update' and b'Witness' in payload)
                me, other = player('ParityProbe', op_uuid), player('Witness', witness_uuid)
                creative, survival = translate('gameMode.creative'), translate('gameMode.survival')

                assert named(run(op, 'gamemode creative'), 'system_chat') == [], 'an unchanged mode is silent'
                assert named(run(op, 'gamemode survival'), 'system_chat') == [chat(translate('commands.gamemode.success.self', survival))]
                start = len(witness.seen)
                assert named(run(op, 'gamemode survival Witness'), 'system_chat') == [chat(translate('commands.gamemode.success.other', other, survival))]
                witness.tick_barrier()
                assert named(witness.seen[start:], 'system_chat') == [chat(translate('gameMode.changed', survival))]
                assert named(run(op, 'gamemode creative @a'), 'system_chat') == [chat(translate('commands.gamemode.success.self', creative)), chat(translate('commands.gamemode.success.other', other, creative))]
                assert [p for p in named(run(op, 'gamemode 1'), 'system_chat') if b'Usage' in p], 'numeric modes are not GameModeArgument input'

                overworld = {'text': 'minecraft:overworld'}
                packets = run(op, 'setworldspawn 1 64 2 90 10')
                assert named(packets, 'system_chat') == [chat(translate('commands.setworldspawn.success', 1, 64, 2, 90.0, 10.0, overworld))]
                spawn = named(packets, 'set_default_spawn_position')[0]
                assert spawn.endswith(struct.pack('>ff', 90.0, 10.0)), 'the spawn packet carries the rotation'
                assert named(run(op, 'setworldspawn 1.7 64 -2.2'), 'system_chat') == [chat(translate('commands.setworldspawn.success', 1, 64, -3, 0.0, 0.0, overworld))]

                assert named(run(op, 'spawnpoint Witness 3 64 4'), 'system_chat') == [chat(translate('commands.spawnpoint.success.single', 3, 64, 4, 0.0, 0.0, overworld, other))]
                # The yaw wraps to [-180, 180) and the pitch clamps to [-90, 90].
                assert named(run(op, 'spawnpoint @a 3 64 4 270 100'), 'system_chat') == [chat(translate('commands.spawnpoint.success.multiple', 3, 64, 4, -90.0, 90.0, overworld, 2))]

                assert named(run(op, 'setblock 5 64 5 minecraft:stone'), 'system_chat') == [chat(translate('commands.setblock.success', 5, 64, 5))]
                assert named(run(op, 'setblock 5 64 5 minecraft:stone'), 'system_chat') == [failure('commands.setblock.failed')]
                assert named(run(op, 'fill 6 64 6 7 64 7 minecraft:dirt'), 'system_chat') == [chat(translate('commands.fill.success', 4))]
                assert named(run(op, 'fill 6 64 6 7 64 7 minecraft:dirt'), 'system_chat') == [failure('commands.fill.failed')]
                assert named(run(op, 'fill 6 64 6 7 65 7 minecraft:dirt'), 'system_chat') == [chat(translate('commands.fill.success', 4))], 'only changed blocks are counted'
                assert named(run(op, 'fill 0 0 0 40 40 40 minecraft:dirt'), 'system_chat') == [failure('commands.fill.toobig', 32768, 68921)]
            finally:
                for client in (op, witness):
                    traces.extend(client.trace)
                    client.close()
        if args.output:
            args.output.write_text(json.dumps(traces))
    print('PASS: /gamemode, /setworldspawn, /spawnpoint, /setblock and /fill feedback, echoes, rotation and failures')


if __name__ == '__main__':
    main()
