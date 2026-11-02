#!/usr/bin/env python3
"""The recipe book: the crafting table recipe on the first tick, /recipe, seen recipes and settings, kept across a restart."""
import json
from pathlib import Path
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire

REFERENCE = json.loads((ROOT / 'reference/26.3/recipe-book.json').read_text())
DISPLAY = {entry['recipe']: (index, bytes.fromhex(entry['entry'])) for index, entry in enumerate(REFERENCE['displays'])}


def payloads(client, name, since=0):
    return [payload for packet, payload in client.seen[since:] if packet == name]


def main():
    boat, table = DISPLAY['minecraft:acacia_boat'], DISPLAY['minecraft:crafting_table']
    with tempfile.TemporaryDirectory(prefix='php-minecraft-recipes-') as tmp:
        cfg = {'port': 0, 'generator': 'flat', 'worldPath': str(Path(tmp) / 'world.sqlite'), 'operators': ['ParityProbe']}
        with wire.server(cfg) as port:
            c = Probe(port, 'before').login()
            c.tick_barrier()
            added = payloads(c, 'recipe_book_add')
            assert added[0] == b'\x00\x01', 'a new player starts with an empty book'
            # RecipeBookAdd: one entry, highlighted, without a toast, not replacing the book.
            assert added[1] == b'\x01' + table[1] + b'\x02\x00', 'the crafting table recipe unlocks on the first tick'
            mark = len(c.seen)
            c.send('chat_command', wire.string('recipe give @s acacia_boat'))
            c.tick_barrier()
            assert payloads(c, 'recipe_book_add', mark) == [b'\x01' + boat[1] + b'\x03\x00'], 'a new recipe shows a toast and is highlighted'
            c.send('recipe_book_seen_recipe', wire.vi(boat[0]))
            c.send('recipe_book_change_settings', wire.vi(1) + b'\x01\x00')
            mark = len(c.seen)
            c.send('chat_command', wire.string('recipe take @s crafting_table'))
            c.tick_barrier()
            assert payloads(c, 'recipe_book_remove', mark) == [b'\x01' + wire.vi(table[0])]
            c.close()
        with wire.server(cfg) as port:
            c = Probe(port, 'after').login()
            c.tick_barrier()
            c.tick_barrier()
            assert payloads(c, 'recipe_book_settings') == [b'\x00\x00\x01\x00\x00\x00\x00\x00'], 'the furnace book stays open'
            # The boat recipe was seen; the crafting table advancement is done, so it is not given again.
            assert payloads(c, 'recipe_book_add') == [b'\x01' + boat[1] + b'\x00\x01'], payloads(c, 'recipe_book_add')
            c.close()
    print('PASS: recipe book unlocks, /recipe, seen recipes and settings persist across a restart')


if __name__ == '__main__':
    main()
