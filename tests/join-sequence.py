#!/usr/bin/env python3
"""The play-state join sequence in vanilla's order, with vanilla's fixed payloads."""
import argparse
import json
from pathlib import Path
import struct
import sys
import tempfile
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire

# PlayerList.placeNewPlayer and sendLevelInfo as observed from the official 26.3 server, without
# the recipe, advancement and attribute packets that need systems not implemented here (#24, #42, #23).
VANILLA_ORDER = ['login', 'change_difficulty', 'player_abilities', 'set_held_slot', 'entity_event', 'commands', 'recipe_book_settings',
                 'player_position', 'server_data', 'player_info_update', 'player_info_update', 'initialize_border', 'set_time',
                 'set_default_spawn_position', 'game_event', 'ticking_state', 'ticking_step', 'set_chunk_cache_center', 'post_effects',
                 'container_set_content', 'set_entity_data']
# Payloads captured from the official server that do not depend on the world or the player.
FIXED = {'initialize_border': '00000000000000000000000000000000418c9c3700000000418c9c370000000000f086a70e05ac02',
         'ticking_state': '41a0000000', 'ticking_step': '00', 'post_effects': '00', 'recipe_book_settings': '0000000000000000'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, help='Write PHP packet traces for the official codec verifier')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='php-minecraft-join-') as tmp:
        config = {'port': 0, 'generator': 'flat', 'worldPath': str(Path(tmp) / 'world.sqlite'), 'operators': ['ParityProbe']}
        traces = []
        with wire.server(config) as port:
            first = Probe(port, 'first').login()
            try:
                first.tick_barrier()
                names = [name for name, _ in first.seen]
                seen = [name for name in names if name in VANILLA_ORDER]
                assert seen[:len(VANILLA_ORDER)] == VANILLA_ORDER, seen[:len(VANILLA_ORDER)]
                for name, expected in FIXED.items():
                    assert next(payload for packet, payload in first.seen if packet == name).hex() == expected, name
                info = [payload for name, payload in first.seen if name == 'player_info_update']
                assert info[0] == b'\xff\x00', 'the joining player first receives the (empty) list of others'
                assert info[1][:2] == b'\xff\x01', 'then everyone receives the joining player with every action'
                assert not [p for n, p in first.seen if n == 'system_chat'], 'no join or welcome message reaches the joining player'
                assert [p for n, p in first.seen if n == 'game_event'][0] == b'\x0d\x00\x00\x00\x00', 'clear weather sends no weather events'
                # PlayerChunkSender: at most nine chunks before the first acknowledgement (the
                # probe then asks for 20 a tick), and never an empty batch.
                sizes, current = [], None
                for name, _ in first.seen:
                    if name == 'chunk_batch_start':
                        current = 0
                    elif name == 'level_chunk_with_light' and current is not None:
                        current += 1
                    elif name == 'chunk_batch_finished':
                        sizes.append(current)
                assert sizes and 1 <= sizes[0] <= 9 and all(1 <= size <= 20 for size in sizes), sizes
                # ChunkTrackingView with the border ring: view distance 2 covers vanilla's 7x7 chunks.
                loaded = {struct.unpack_from('>ii', payload) for name, payload in first.seen if name == 'level_chunk_with_light'}
                expected = {(x, z) for x in range(-5, 6) for z in range(-5, 6) if max(0, abs(x) - 2) ** 2 + max(0, abs(z) - 2) ** 2 < 4}
                assert loaded == expected and len(loaded) == 49, sorted(loaded ^ expected)

                second = Probe(port, 'second').login('Second')
                try:
                    second.tick_barrier()
                    first.tick_barrier()
                    assert [p for n, p in first.seen if n == 'system_chat' and b'multiplayer.player.joined' in p], 'others are told about the join'
                    others = [p for n, p in second.seen if n == 'player_info_update'][0]
                    assert others[:2] == b'\xff\x01' and b'ParityProbe' in others, 'a later player first receives the players already online'
                    traces.extend(second.trace)
                finally:
                    second.close()
                first.send('chat_command', wire.string('weather thunder'))
                first.tick_barrier()
                rain = Probe(port, 'rain').login('Rainy')
                try:
                    events = [p for n, p in rain.seen if n == 'game_event']
                    assert events[:4] == [b'\x01\x00\x00\x00\x00', b'\x07\x3f\x80\x00\x00', b'\x08\x3f\x80\x00\x00', b'\x0d\x00\x00\x00\x00'], events[:4]
                    traces.extend(rain.trace)
                finally:
                    rain.close()
                traces.extend(first.trace)
            finally:
                first.close()
        if args.output:
            args.output.write_text(json.dumps(traces))
    print('PASS: vanilla join order, fixed join payloads, player list initialization, join message recipients and join weather')


if __name__ == '__main__':
    main()
