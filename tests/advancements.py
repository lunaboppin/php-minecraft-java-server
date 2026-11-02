#!/usr/bin/env python3
"""Advancement progress: shown on the first tick, met by items arriving, kept across a restart, and its tab selected on request."""
import json
from pathlib import Path
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire

ENTRIES = {entry['id']: bytes.fromhex(entry['entry']) for entry in json.loads((ROOT / 'reference/26.3/advancements.json').read_text())['advancements']}


def updates(client, since=0):
    """Each update_advancements as (reset, added IDs, removed IDs, {ID: {criterion: met}}, show)."""
    found = []
    for name, data in client.seen[since:]:
        if name != 'update_advancements':
            continue
        at = 1
        count, at = wire.read_vi(data, at)
        added = []
        for _ in range(count):
            length, start = wire.read_vi(data, at)
            added.append(data[start:start + length].decode())
            at += len(ENTRIES[added[-1]])
        count, at = wire.read_vi(data, at)
        removed = []
        for _ in range(count):
            length, at = wire.read_vi(data, at)
            removed.append(data[at:at + length].decode())
            at += length
        count, at = wire.read_vi(data, at)
        progress = {}
        for _ in range(count):
            length, at = wire.read_vi(data, at)
            advancement = data[at:at + length].decode()
            at += length
            criteria, at = wire.read_vi(data, at)
            progress[advancement] = {}
            for _ in range(criteria):
                length, at = wire.read_vi(data, at)
                criterion = data[at:at + length].decode()
                at += length
                progress[advancement][criterion] = bool(data[at])
                at += 9 if data[at] else 1
        assert at == len(data) - 1, 'the whole packet was read'
        found.append((bool(data[0]), added, removed, progress, bool(data[at])))
    return found


def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-advancements-') as tmp:
        cfg = {'port': 0, 'generator': 'flat', 'worldPath': str(Path(tmp) / 'world.sqlite'), 'operators': ['ParityProbe']}
        with wire.server(cfg) as port:
            c = Probe(port, 'before').login()
            c.tick_barrier()
            # The crafting table recipe advancement completes on the first tick: done, it is sent
            # though it has no display, with its tree's root. Done, it stops listening before its
            # reward unlocks the recipe, so has_the_recipe stays unmet.
            first = updates(c)
            assert len(first) == 1 and first[0][0], first
            assert set(first[0][1]) == {'minecraft:recipes/root', 'minecraft:recipes/decorations/crafting_table'}, first
            assert first[0][3]['minecraft:recipes/decorations/crafting_table'] == {'has_the_recipe': False, 'unlock_right_away': True}, first
            mark = len(c.seen)
            c.send('chat_command', wire.string('advancement grant @s only minecraft:story/root'))
            c.tick_barrier()
            [(reset, added, removed, progress, show)] = updates(c, mark)
            # A done root shows its children and grandchildren.
            assert not reset and show and removed == [] and set(added) == {'minecraft:story/root', 'minecraft:story/mine_stone', 'minecraft:story/upgrade_tools'}, added
            assert progress['minecraft:story/root'] == {'crafting_table': True} and progress['minecraft:story/mine_stone'] == {'get_stone': False}, progress
            # inventory_changed: cobblestone arriving meets Stone Age, which shows the next level.
            mark = len(c.seen)
            c.send('chat_command', wire.string('give @s cobblestone'))
            c.tick_barrier()
            [(reset, added, removed, progress, show)] = updates(c, mark)
            assert progress['minecraft:story/mine_stone'] == {'get_stone': True} and 'minecraft:story/smelt_iron' in added, (added, progress)
            assert any(b'chat.type.advancement.task' in p for n, p in c.seen[mark:] if n == 'system_chat'), 'Stone Age is announced'
            c.send('seen_advancements', wire.vi(0) + wire.string('minecraft:story/root'))
            c.send('seen_advancements', wire.vi(0) + wire.string('minecraft:story/root'))
            c.tick_barrier()
            assert [p for n, p in c.seen[mark:] if n == 'select_advancements_tab'] == [b'\x01' + wire.string('minecraft:story/root')], 'a shown root is selected once'
            c.close()
        with wire.server(cfg) as port:
            c = Probe(port, 'after').login()
            c.tick_barrier()
            [(reset, added, removed, progress, show)] = updates(c)
            assert reset and {'minecraft:story/root', 'minecraft:story/mine_stone', 'minecraft:story/upgrade_tools', 'minecraft:story/smelt_iron', 'minecraft:recipes/decorations/crafting_table'} <= set(added), added
            assert progress['minecraft:story/root'] == {'crafting_table': True}, progress
            c.close()
    print('PASS: advancement progress, visibility and the selected tab across a restart')


if __name__ == '__main__':
    main()
