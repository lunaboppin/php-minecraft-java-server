#!/usr/bin/env python3
"""/give, /summon, /kill and /tp feedback, death messages, echoes and positions."""
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


def chats(packets):
    return [payload for name, payload in packets if name == 'system_chat']


def failure(key, *arguments):
    return chat({'text': '', 'color': 'red', 'extra': [translate(key, *arguments)]})


def item(name, key, color='white'):
    return {'translate': 'chat.square_brackets', 'with': [{'text': '', 'extra': [{'translate': key}]}], 'color': color,
            'hover_event': {'action': 'show_item', 'id': 'minecraft:' + name}}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, help='Write PHP packet traces for the official codec verifier')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='php-minecraft-entity-commands-') as tmp:
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

                dirt = item('dirt', 'block.minecraft.dirt')
                assert chats(run(op, 'give Witness minecraft:dirt 3')) == [chat(translate('commands.give.success.single', 3, dirt, other))]
                assert chats(run(op, 'give @a dirt 2')) == [chat(translate('commands.give.success.multiple', 2, dirt, 2))]
                # Rarity colors the name; the limit is a hundred maximum stacks.
                apple = item('enchanted_golden_apple', 'item.minecraft.enchanted_golden_apple', 'aqua')
                assert chats(run(op, 'give @s enchanted_golden_apple')) == [chat(translate('commands.give.success.single', 1, apple, me))]
                sword = item('diamond_sword', 'item.minecraft.diamond_sword')
                assert chats(run(op, 'give @s diamond_sword 101')) == [failure('commands.give.failed.toomanyitems', 100, sword)]

                packets = run(op, 'summon minecraft:pig 0 70 0')
                spawn = next(payload for name, payload in packets if name == 'add_entity')
                entity_uuid = spawn[len(wire.vi(wire.read_vi(spawn)[0])):][:16]
                dashed = '-'.join((entity_uuid.hex()[:8], entity_uuid.hex()[8:12], entity_uuid.hex()[12:16], entity_uuid.hex()[16:20], entity_uuid.hex()[20:]))
                pig = {'translate': 'entity.minecraft.pig', 'insertion': dashed,
                       'hover_event': {'action': 'show_entity', 'id': 'minecraft:pig', 'uuid': entity_uuid, 'name': {'translate': 'entity.minecraft.pig'}}}
                assert chats(packets) == [chat(translate('commands.summon.success', pig))]
                assert struct.unpack_from('>ddd', spawn, len(wire.vi(wire.read_vi(spawn)[0])) + 16 + len(wire.vi(wire.read_vi(spawn, len(wire.vi(wire.read_vi(spawn)[0])) + 16)[0])))[1] == 70.0

                coordinates = lambda *values: [{'text': '%.6f' % value} for value in values]
                assert chats(run(op, 'tp Witness 0 70 0')) == [chat(translate('commands.teleport.success.location.single', other, *coordinates(0.5, 70.0, 0.5)))]
                assert chats(run(op, 'tp 1.25 70 -3')) == [chat(translate('commands.teleport.success.location.single', me, *coordinates(1.25, 70.0, -2.5)))]
                assert chats(run(op, 'teleport @a 2 70 2')) == [chat(translate('commands.teleport.success.location.multiple', 2, *coordinates(2.5, 70.0, 2.5)))]
                # Relative coordinates come from the sender, even when another player is moved.
                assert chats(run(op, 'tp Witness ~ ~1 ~')) == [chat(translate('commands.teleport.success.location.single', other, *coordinates(2.5, 71.0, 2.5)))]
                assert chats(run(op, 'tp Witness ParityProbe')) == [chat(translate('commands.teleport.success.entity.single', other, me))]

                start = len(witness.seen)
                killed = translate('death.attack.genericKill', other)
                assert chats(run(op, 'kill Witness')) == [chat(killed), chat(translate('commands.kill.success.single', other))]
                witness.tick_barrier()
                assert chats(witness.seen[start:]) == [chat(killed)], 'everyone receives the death message'
                assert any(name == 'player_combat_kill' and payload.endswith(chat(killed)[:-1]) for name, payload in witness.seen[start:])
                assert chats(run(op, 'kill Witness')) == [chat(translate('commands.kill.success.single', other))], 'a dead player has no second death message'
            finally:
                for client in (op, witness):
                    traces.extend(client.trace)
                    client.close()
        if args.output:
            args.output.write_text(json.dumps(traces))
    print('PASS: /give, /summon, /kill and /tp feedback, death messages, rarity, limits and sender-relative positions')


if __name__ == '__main__':
    main()
