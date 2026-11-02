#!/usr/bin/env python3
"""Statistics: counted as vanilla counts them, sent when asked, only those changed since, and kept across a restart."""
import json
from pathlib import Path
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire

BLOCKS = json.loads((ROOT / 'reference/26.3/blocks.json').read_text())
TYPES = {v: k for k, v in BLOCKS['stat_types'].items()}
CUSTOM = {v: k for k, v in BLOCKS['custom_stats'].items()}
ITEMS = {v: k for k, v in BLOCKS['items'].items()}


def request(client):
    """Ask for the statistics: those of interest by type:value, and how many entries came."""
    mark = len(client.seen)
    client.send('client_command', wire.vi(1))
    client.tick_barrier()
    [payload] = [p for n, p in client.seen[mark:] if n == 'award_stats']
    count, at = wire.read_vi(payload, 0)
    found = {}
    for _ in range(count):
        kind, at = wire.read_vi(payload, at)
        value, at = wire.read_vi(payload, at)
        amount, at = wire.read_vi(payload, at)
        kind = TYPES[kind]
        found[kind + ':' + (CUSTOM[value] if kind == 'custom' else ITEMS.get(value, str(value)))] = amount
    return found, count


def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-stats-') as tmp:
        cfg = {'port': 0, 'generator': 'flat', 'worldPath': str(Path(tmp) / 'world.sqlite'), 'operators': ['ParityProbe'], 'defaultGameMode': 0}
        with wire.server(cfg) as port:
            c = Probe(port, 'stats').login()
            c.tick_barrier()
            c.send('chat_command', wire.string('give @s dirt 3'))
            c.tick_barrier()
            # Throwing the selected stack: the whole stack, as player_action 4 drops in 26.3.
            c.send('player_action', wire.vi(4) + wire.block_position(0, 0, 0) + b'\0' + wire.vi(0))
            c.tick_barrier()
            found, _ = request(c)
            assert found['dropped:dirt'] == 3 and found['custom:drop'] == 1, found
            assert found['custom:play_time'] > 0 and found['custom:play_time'] == found['custom:total_world_time'], found
            # Only what changed since: the clocks, no longer the drop.
            again, _ = request(c)
            assert 'dropped:dirt' not in again and 'custom:play_time' in again, again
            c.close()
        with wire.server(cfg) as port:
            c = Probe(port, 'stats').login()
            c.tick_barrier()
            found, _ = request(c)
            assert found['dropped:dirt'] == 3 and found['custom:leave_game'] == 1, found
            c.close()
    print('PASS: statistics counted, sent when changed, and kept across a restart')


if __name__ == '__main__':
    main()
