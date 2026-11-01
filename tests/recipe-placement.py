#!/usr/bin/env python3
"""The recipe book fills the inventory's crafting grid, shift fills it fully, and lacking items shows a ghost recipe."""
import json
from pathlib import Path
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire

REFERENCE = json.loads((ROOT / 'reference/26.3/recipe-book.json').read_text())
DISPLAY = {entry['recipe']: index for index, entry in enumerate(REFERENCE['displays'])}
ITEMS = wire.BLOCKS['items']


def grid(client):
    """The last inventory contents: the crafting result and grid slots 0-4, as (item, count)."""
    payload = [p for n, p in client.seen if n == 'container_set_content' and p[0] == 0][-1]
    _, at = wire.read_vi(payload, 1)
    count, at = wire.read_vi(payload, at)
    slots = []
    for _ in range(count):
        size, at = wire.read_vi(payload, at)
        if size == 0:
            slots.append(None)
            continue
        item, at = wire.read_vi(payload, at)
        added, at = wire.read_vi(payload, at)
        removed, at = wire.read_vi(payload, at)
        assert added == 0 and removed == 0, 'plain stacks only'
        slots.append((item, size))
    return slots


def main():
    planks = ITEMS['oak_planks']
    with tempfile.TemporaryDirectory(prefix='php-minecraft-placement-') as tmp:
        cfg = {'port': 0, 'generator': 'flat', 'worldPath': str(Path(tmp) / 'world.sqlite'), 'operators': ['ParityProbe'], 'defaultGameMode': 0}
        with wire.server(cfg) as port:
            c = Probe(port, 'placer').login()
            c.tick_barrier()
            c.send('chat_command', wire.string('give @s oak_planks 10'))
            c.tick_barrier()
            # One crafting table's worth: a plank in each of the 2x2 grid's slots.
            c.send('place_recipe', wire.vi(0) + wire.vi(DISPLAY['minecraft:crafting_table']) + b'\x00')
            c.tick_barrier()
            slots = grid(c)
            assert slots[1:5] == [(planks, 1)] * 4 and slots[0] == (ITEMS['crafting_table'], 1), slots[:5]
            assert slots[36] == (planks, 6), slots[36]
            # Again: one more of each.
            c.send('place_recipe', wire.vi(0) + wire.vi(DISPLAY['minecraft:crafting_table']) + b'\x00')
            c.tick_barrier()
            assert grid(c)[1:5] == [(planks, 2)] * 4, grid(c)[:5]
            # With shift: as many as the planks allow, here all ten split as two each and two left.
            c.send('place_recipe', wire.vi(0) + wire.vi(DISPLAY['minecraft:crafting_table']) + b'\x01')
            c.tick_barrier()
            slots = grid(c)
            assert slots[1:5] == [(planks, 2)] * 4 and slots[36] == (planks, 2), slots[:5] + [slots[36]]
            # Without the items, the grid empties back into the inventory and the recipe is a ghost.
            mark = len(c.seen)
            c.send('chat_command', wire.string('recipe give @s minecraft:torch'))
            c.send('chat_command', wire.string('clear @s oak_planks 2'))
            c.tick_barrier()
            c.send('place_recipe', wire.vi(0) + wire.vi(DISPLAY['minecraft:torch']) + b'\x00')
            c.tick_barrier()
            ghost = [p for n, p in c.seen[mark:] if n == 'place_ghost_recipe']
            assert ghost == [b'\x00' + bytes.fromhex(REFERENCE['displays'][DISPLAY['minecraft:torch']]['display'])], ghost
            slots = grid(c)
            assert slots[1:5] == [None] * 4 and slots[36] == (planks, 8), slots[:5] + [slots[36]]
            c.close()
    print('PASS: recipe book placement into the crafting grid, with shift and as a ghost')


if __name__ == '__main__':
    main()
