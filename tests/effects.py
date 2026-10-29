#!/usr/bin/env python3
"""Status effects: eating a golden apple, absorption hearts and damage, health boost, saving and death."""
import json
from pathlib import Path
import struct
import sys
import tempfile
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire

EFFECTS = json.loads((ROOT / 'reference/26.3/mob-effects.json').read_text())['effects']
ABSORPTION_INDEX = json.loads((ROOT / 'reference/26.3/consumables.json').read_text())['absorption_index']


def run(client, text):
    start = len(client.seen)
    client.send('chat_command', wire.string(text))
    client.tick_barrier()
    return client.seen[start:]


def effects_added(packets):
    """(effect, amplifier) pairs from update_mob_effect packets."""
    names = {row['id']: name for name, row in EFFECTS.items()}
    result = []
    for packet, payload in packets:
        if packet == 'update_mob_effect':
            _, offset = wire.read_vi(payload)
            effect, offset = wire.read_vi(payload, offset)
            amplifier, _ = wire.read_vi(payload, offset)
            result.append((names[effect], amplifier))
    return result


def absorption(client, packets):
    """The last absorption value sent for the client's own entity, if any."""
    value = None
    for packet, payload in packets:
        if packet == 'set_entity_data':
            entity, offset = wire.read_vi(payload)
            if entity == client.entity_id and payload[offset] == ABSORPTION_INDEX:
                value = struct.unpack_from('>f', payload, offset + 2)[0]
    return value


with tempfile.TemporaryDirectory(prefix='php-minecraft-effects-') as tmp:
    config = {'port': 0, 'generator': 'flat', 'worldPath': str(Path(tmp) / 'world.sqlite'), 'operators': ['ParityProbe']}
    with wire.server(config) as port:
        player = Probe(port, 'operator').login()
        player.tick_barrier()
        run(player, 'gamemode survival')
        run(player, 'give @s golden_apple')
        start = len(player.seen)
        # Hold use: Consumable.consumeSeconds is 1.6 s for apples.
        player.send('use_item', b'\0' + wire.vi(1) + struct.pack('>ff', 0, 0))
        for _ in range(45):
            player.tick_barrier()
        eaten = player.seen[start:]
        assert ('minecraft:regeneration', 1) in effects_added(eaten) and ('minecraft:absorption', 0) in effects_added(eaten), effects_added(eaten)
        assert absorption(player, eaten) == 4.0, 'absorption hearts are granted'
        # Absorption takes damage first.
        packets = run(player, 'damage @s 3')
        assert absorption(player, packets) == 1.0, absorption(player, packets)
        packets = run(player, 'effect clear @s absorption')
        assert absorption(player, packets) == 0.0, 'removing absorption clears the hearts'
        # Health boost raises the maximum, and its removal clamps health back.
        run(player, 'effect give @s health_boost infinite 1')
        run(player, 'effect give @s instant_health 1 3')
        packets = run(player, 'effect clear @s health_boost')
        health = [struct.unpack_from('>f', payload)[0] for packet, payload in packets if packet == 'set_health']
        assert health and health[-1] == 20.0, health
        # Effects are saved with the player.
        run(player, 'effect give @s night_vision infinite')
        player.close()
        player = Probe(port, 'operator').login()
        assert ('minecraft:night_vision', 0) in effects_added(player.seen), 'saved effects are sent on join'
        player.tick_barrier()
        # Death ends every effect.
        run(player, 'kill @s')
        player.send('client_command', wire.vi(0))
        player.tick_barrier()
        packets = run(player, 'effect clear @s')
        assert any(packet == 'system_chat' and b'commands.effect.clear.everything.failed' in payload for packet, payload in packets)
        player.close()
print('PASS: golden apple effects, absorption damage, health boost, saved effects and death')
