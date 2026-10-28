#!/usr/bin/env python3
"""Vanilla death messages for each damage type, kill credit, /damage feedback and the death message rule."""
import argparse
import json
from pathlib import Path
import sys
import tempfile
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
sys.path.insert(0, str(ROOT / 'tests/support'))
from protocol import Probe, wire
from text_components import chat, component, player, translate


def run(client, text):
    start = len(client.seen)
    client.send('chat_command', wire.string(text))
    client.tick_barrier()
    return client.seen[start:]


def chats(packets):
    return [payload for name, payload in packets if name == 'system_chat']


def respawn(client):
    client.send('client_command', wire.vi(0))
    client.until('respawn')
    client.tick_barrier()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, help='Write PHP packet traces for the official codec verifier')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='php-minecraft-death-messages-') as tmp:
        config = {'port': 0, 'generator': 'flat', 'defaultGameMode': 1, 'worldPath': str(Path(tmp) / 'world.sqlite'), 'operators': ['ParityProbe']}
        traces = []
        with wire.server(config) as port:
            op = Probe(port, 'operator').login()
            victim = Probe(port, 'victim').login('Witness')
            try:
                for client in (op, victim):
                    client.tick_barrier()
                op_uuid = next(payload[2:18] for name, payload in victim.seen if name == 'player_info_update' and b'ParityProbe' in payload)
                victim_uuid = next(payload[2:18] for name, payload in op.seen if name == 'player_info_update' and b'Witness' in payload)
                me, other = player('ParityProbe', op_uuid), player('Witness', victim_uuid)
                run(op, 'gamemode survival Witness')
                victim.tick_barrier()
                damaged = lambda amount: chat(translate('commands.damage.success', amount, other))
                assert chats(run(op, 'damage Witness 2.5')) == [damaged(2.5)]

                cases = [('drown', translate('death.attack.drown', other)), ('fall', translate('death.attack.fall', other)),
                         ('out_of_world', translate('death.attack.outOfWorld', other)), ('lava', translate('death.attack.lava', other)),
                         ('on_fire', translate('death.attack.onFire', other)), ('starve', translate('death.attack.starve', other)),
                         ('generic', translate('death.attack.generic', other)), ('generic_kill', translate('death.attack.genericKill', other))]
                for damage_type, message in cases:
                    start = len(victim.seen)
                    assert chats(run(op, 'damage Witness 100 ' + damage_type)) == [chat(message), damaged(100.0)], damage_type
                    victim.tick_barrier()
                    kill = next(payload for name, payload in victim.seen[start:] if name == 'player_combat_kill')
                    assert kill.endswith(component(message)), 'the death screen shows the same message'
                    respawn(victim)

                # A direct attacker is named; a recent attacker is credited for an indirect death.
                assert chats(run(op, 'damage Witness 100 player_attack by ParityProbe'))[0] == chat(translate('death.attack.player', other, me))
                respawn(victim)
                run(op, 'damage Witness 1 player_attack by ParityProbe')
                assert chats(run(op, 'damage Witness 100 drown'))[0] == chat(translate('death.attack.drown.player', other, me))
                respawn(victim)

                invulnerable = chat({'text': '', 'color': 'red', 'extra': [translate('commands.damage.invulnerable')]})
                assert chats(run(op, 'damage ParityProbe 5')) == [invulnerable], 'creative players ignore ordinary damage'
                run(op, 'gamerule show_death_messages false')
                start = len(victim.seen)
                assert chats(run(op, 'damage Witness 100 generic')) == [damaged(100.0)], 'the rule hides the broadcast'
                victim.tick_barrier()
                kill = next(payload for name, payload in victim.seen[start:] if name == 'player_combat_kill')
                assert kill.endswith(component({'text': ''})), 'the death screen opens without a message'
            finally:
                for client in (op, victim):
                    traces.extend(client.trace)
                    client.close()
        if args.output:
            args.output.write_text(json.dumps(traces))
    print('PASS: death messages for each damage type, kill credit, /damage feedback, invulnerability and the death message rule')


if __name__ == '__main__':
    main()
