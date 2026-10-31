#!/usr/bin/env python3
"""Normal-world progression tests against the real PHP TCP server."""
import json
import os
from pathlib import Path
import sqlite3
import struct
import tempfile
import time
import integration as net

ITEMS = net.BLOCKS['items']


def inventory(c, predicate=lambda: True):
    return c.until('container_set_content', lambda payload: predicate(), limit=1000)


def command(c, text, expected=None):
    c.send('chat_command', net.string(text))
    if expected:
        c.until('system_chat', lambda payload: expected.encode() in payload)


def click(c, slot, button=0, mode=0):
    before = c.revision
    payload = net.vi(c.window) + net.vi(before) + struct.pack('>hB', slot, button) + net.vi(mode) + b'\0\0'
    c.send('container_click', payload)
    inventory(c, lambda: c.revision > before)


def find(c, item, start=0):
    for slot, stack in enumerate(c.inventory):
        if slot >= start and stack and stack['item'] == ITEMS[item]:
            return slot
    raise AssertionError('item not found: ' + item)


def total(c, item):
    return sum(stack['count'] for stack in c.inventory if stack and stack['item'] == ITEMS[item])


def use_block(c, x, y, z, sequence=20):
    payload = net.vi(0) + net.block_position(x, y, z) + net.vi(1) + struct.pack('>fff', .5, 1, .5) + b'\0\0' + net.vi(sequence)
    c.send('use_item_on', payload)
    c.until('open_screen')
    # Window IDs count up with each menu opened, as ServerPlayer.nextContainerCounter does.
    inventory(c, lambda: c.window != 0)


def close(c):
    c.send('container_close', net.vi(c.window))
    # handleContainerClose sends nothing back, so the client falls back to its own inventory.
    c.window = 0
    c.send('ping_request', b'terrain!')
    c.until('pong_response', lambda payload: payload == b'terrain!')


def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-progression-') as temp:
        world = str(Path(temp) / 'normal.sqlite')
        config = {'port': 0, 'worldPath': world, 'generator': 'terrain', 'seed': 12345,
                  'defaultGameMode': 0, 'operators': ['Admin']}
        with net.server(config) as port:
            a = net.Client(port, terrain=True).login('Admin')
            b = net.Client(port, terrain=True).login('Visitor')
            try:
                position = next(p for name, p in a.seen if name == 'player_position')
                _, off = net.read_vi(position)
                net.check(struct.unpack_from('>ddd', position, off) == (.5, 65., .5), 'normal world spawns above terrain')
                with sqlite3.connect(world) as db:
                    settings = dict(db.execute('SELECT key,value FROM metadata'))
                    net.check(json.loads(settings['generator']) == 'terrain' and json.loads(settings['seed']) == 12345,
                              'generator and seed stored with world')
                command(b, 'gamemode creative', 'command.unknown.command')
                command(a, 'gamemode creative')
                a.until('game_event', lambda p: p[0] == 3 and struct.unpack('>f', p[1:])[0] == 1)
                command(a, 'give @s oak_log 2', 'commands.give.success.single')
                net.check(total(a, 'oak_log') == 2, 'give updates authoritative inventory')
                # Craft two logs into eight planks in the player's actual 2x2 menu.
                source = find(a, 'oak_log')
                click(a, source)
                net.check(a.cursor and a.cursor['count'] == 2, 'inventory pickup on cursor')
                click(a, 1)
                net.check(a.inventory[0] == {'item': ITEMS['oak_planks'], 'count': 4}, 'craft result synchronized')
                click(a, 0)
                net.check(a.cursor == {'item': ITEMS['oak_planks'], 'count': 4}, 'craft consumes one ingredient')
                click(a, source)
                click(a, 0, mode=1)
                net.check(total(a, 'oak_planks') == 8 and total(a, 'oak_log') == 0, 'shift crafting conserves ingredients')
                # Native QUICK_CRAFT start/add/end uses outside slot -999 without dropping items.
                source = find(a, 'oak_planks')
                click(a, source)
                before = a.revision
                for slot, button in [(-999, 0), (1, 1), (2, 1), (-999, 2)]:
                    a.send('container_click', net.vi(a.window) + net.vi(before) + struct.pack('>hB', slot, button) + net.vi(5) + b'\0\0')
                inventory(a, lambda: a.revision > before)
                net.check(a.cursor is None and a.inventory[1]['count'] == 4 and a.inventory[2]['count'] == 4, 'left drag distributes stacks without item loss')
                for slot in [1, 2]:
                    click(a, slot)
                    click(a, source)
                # Persistent chest contents, observed concurrently by another player.
                command(a, 'setblock 2 65 0 chest', 'commands.setblock.success')
                use_block(a, 2, 65, 0)
                net.check(len(a.inventory) == 63, 'single chest menu has 27 storage and 36 player slots')
                click(a, find(a, 'oak_planks', 27), mode=1)
                net.check(a.inventory[0] and a.inventory[0]['item'] == ITEMS['oak_planks'], 'shift moves player stack into chest')
                use_block(b, 2, 65, 0)
                net.check(total(b, 'oak_planks') == 8, 'chest shared between players')
                with sqlite3.connect(world) as db:
                    chest = json.loads(db.execute('SELECT data FROM containers WHERE position=?', ('2:65:0',)).fetchone()[0])
                    net.check(sum(s['count'] for s in (chest['slots'].values() if isinstance(chest['slots'], dict) else chest['slots']) if s) == 8, 'chest writes persisted')
                close(b)
                # Recover planks, then craft a wooden pickaxe in a 3x3 crafting table.
                click(a, 0, mode=1)
                close(a)
                command(a, 'give @s stick 2', 'commands.give.success.single')
                command(a, 'setblock 2 65 1 crafting_table', 'commands.setblock.success')
                use_block(a, 2, 65, 1)
                source = find(a, 'oak_planks', 10)
                click(a, source)
                for slot in [1, 2, 3]:
                    click(a, slot, button=1)
                click(a, source)
                source = find(a, 'stick', 10)
                click(a, source)
                for slot in [5, 8]:
                    click(a, slot, button=1)
                net.check(a.cursor is None, 'recipe distribution consumes cursor stack')
                net.check(a.inventory[0] and a.inventory[0]['item'] == ITEMS['wooden_pickaxe'], '3x3 tagged vanilla tool recipe')
                click(a, 0)
                click(a, 45)
                close(a)
                net.check(total(a, 'wooden_pickaxe') == 1, 'crafted tool placed into player inventory')
                # A real 200-tick furnace recipe with real fuel consumption.
                command(a, 'give @s raw_iron 1', 'commands.give.success.single')
                command(a, 'give @s coal 1', 'commands.give.success.single')
                command(a, 'setblock 2 65 2 furnace', 'commands.setblock.success')
                use_block(a, 2, 65, 2)
                click(a, find(a, 'raw_iron', 3), mode=1)
                click(a, find(a, 'coal', 3), mode=1)
                inventory(a, lambda: a.inventory[2] is not None and a.inventory[2]['item'] == ITEMS['iron_ingot'])
                net.check(a.inventory[2]['count'] == 1, 'furnace smelts iron using fuel')
                click(a, 2, mode=1)
                close(a)
                net.check(total(a, 'iron_ingot') == 1, 'furnace output collected')
                # Death, respawn, and the extended 26.3 teleport acknowledgement.
                command(a, 'gamemode survival')
                a.until('game_event', lambda p: p[0] == 3 and struct.unpack('>f', p[1:])[0] == 0)
                # Timed survival mining, drops, pickup and tool durability.
                a.send('set_carried_item', struct.pack('>h', 8))
                command(a, 'setblock 1 65 1 stone', 'commands.setblock.success')
                a.send('player_action', net.vi(0) + net.block_position(1, 65, 1) + b'\1' + net.vi(91))
                a.until('block_changed_ack', lambda p: net.read_vi(p)[0] == 91)
                with sqlite3.connect(world) as db:
                    net.check(db.execute('SELECT state FROM blocks WHERE x=1 AND y=65 AND z=1').fetchone() == (net.BLOCKS['states']['stone'],),
                              'survival mining does not break stone instantly')
                # Like a client, stop digging once the wooden pickaxe's 23 ticks have passed.
                time.sleep(1.5)
                a.send('player_action', net.vi(3) + net.block_position(1, 65, 1) + b'\1' + net.vi(92))
                a.until('block_update', lambda p: p[:8] == net.block_position(1, 65, 1) and net.read_vi(p, 8)[0] == 0)
                # The drop pops out at random near the block's centre; standing on it picks it up.
                command(a, 'tp @s 1.5 65 1.5', 'commands.teleport.success.location.single')
                inventory(a, lambda: total(a, 'cobblestone') > 0)
                net.check(a.inventory[44].get('damage') == 1, 'tool wear and dropped-block pickup')
                # Player combat follows the server's health state.
                a.send('attack', net.vi(b.entity_id))
                # The idle second client has queued block updates from random ticks since it joined.
                health = b.until('set_health', lambda p: struct.unpack_from('>f', p)[0] < 20, limit=100000)
                net.check(struct.unpack_from('>f', health)[0] == 19, 'player combat applies health damage')
                # Summoned passive animals use the same validated entity protocol and drop food.
                command(a, 'give @s iron_sword 1', 'commands.give.success.single')
                sword = find(a, 'iron_sword')
                a.send('set_carried_item', struct.pack('>h', sword - 36))
                command(a, 'summon cow 1.5 65 0.5', 'commands.summon.success')
                cow_id = None
                for name, payload in reversed(a.seen):
                    if name == 'add_entity':
                        entity, off = net.read_vi(payload)
                        kind, _ = net.read_vi(payload, off + 16)
                        if kind == net.BLOCKS['entity_types']['cow']:
                            cow_id = entity
                            break
                net.check(cow_id is not None, 'animal spawn packet received')
                time.sleep(.6)
                a.send('attack', net.vi(cow_id))
                time.sleep(.6)
                a.send('attack', net.vi(cow_id))
                a.until('entity_event', lambda p: struct.unpack_from('>i', p)[0] == cow_id and p[4] == 3)
                # The idle second player stands beside the spawn and could pick up the drops brought
                # there (it also stops the cow's knockback, so it only leaves once the cow is dead).
                command(a, 'tp Visitor 0.5 120 24.5', 'commands.teleport.success.location.single')
                # Knockback can carry the cow out of pickup reach before it dies, so bring any drops over,
                # again until the beef arrives: on a slow runner the drops may not exist yet.
                for _ in range(30):
                    time.sleep(.5)
                    command(a, 'tp @e[type=item] @s')
                    try:
                        # Read whatever arrives for half a second; the client tracks its inventory.
                        a.until('no_such_packet', limit=100000, timeout=.5)
                    except AssertionError:
                        pass
                    if total(a, 'beef') > 0:
                        break
                net.check(total(a, 'beef') > 0, 'animal combat drops collectible food')
                command(a, 'kill Visitor')
                b.until('player_combat_kill')
                b.send('client_command', b'\0')
                b.until('respawn')
                b.until('player_position')
                nonce = struct.pack('>q', 777)
                b.send('ping_request', nonce)
                net.check(b.until('pong_response') == nonce, 'play continues after death and respawn')
                # Stop the clock first, so no tick passes between setting and stopping it.
                command(a, 'gamerule advance_time false', 'commands.gamerule.set')
                command(a, 'time set night', 'commands.time.set.time_marker')
                command(a, 'weather rain', 'commands.weather.set.rain')
                a.send('move_player_pos', struct.pack('>dddB', 1.5, 65, .5, 1))
                command(a, 'save-all', 'commands.save.success')
                with sqlite3.connect(world) as db:
                    profile = json.loads(db.execute('SELECT data FROM players WHERE uuid=?', (a.uuid.hex(),)).fetchone()[0])
                    net.check(profile['gameMode'] == 0 and profile['x'] == 1.5, 'player position and mode persisted')
                    net.check(any(s['item'] == ITEMS['wooden_pickaxe'] for s in profile['inventory'].values()), 'crafted inventory persisted')
            finally:
                a.close()
                b.close()
        with sqlite3.connect(world) as db:
            row = db.execute('SELECT uuid,data FROM players WHERE uuid=?', (a.uuid.hex(),)).fetchone()
            profile = json.loads(row[1])
            profile['food'] = 10
            profile['saturation'] = 0
            db.execute('UPDATE players SET data=? WHERE uuid=?', (json.dumps(profile), row[0]))
        with net.server(config) as port:
            a = net.Client(port, terrain=True).login('Admin')
            try:
                pos = next(p for name, p in a.seen if name == 'player_position')
                _, off = net.read_vi(pos)
                net.check(struct.unpack_from('>ddd', pos, off) == (1.5, 65., .5), 'position survives process restart')
                net.check(total(a, 'wooden_pickaxe') == 1 and total(a, 'iron_ingot') == 1, 'inventory survives process restart')
                command(a, 'time query time', 'commands.time.query.absolute')
                reply = [p for name, p in a.seen if name == 'system_chat' and b'commands.time.query.absolute' in p][-1]
                net.check(struct.pack('>q', 13000) in reply, 'the stopped clock survives process restart')
                command(a, 'give @s bread 1', 'commands.give.success.single')
                bread = find(a, 'bread')
                net.check(36 <= bread <= 44, 'food placed in hotbar')
                a.send('set_carried_item', struct.pack('>h', bread - 36))
                a.send('use_item', b'\0' + net.vi(101) + struct.pack('>ff', 0, 0))
                health = a.until('set_health', lambda p: net.read_vi(p, 4)[0] == 15)
                net.check(net.read_vi(health, 4)[0] == 15, 'eating restores nutrition after use duration')

                use_block(a, 2, 65, 2)
                net.check(a.inventory[0] is None and a.inventory[1] is None, 'furnace consumed input and fuel persistently')
            finally:
                a.close()
    capture = os.environ.get('PHP_MINECRAFT_CAPTURE')
    if capture:
        path = Path(capture)
        prior = json.loads(path.read_text()) if path.exists() else []
        path.write_text(json.dumps(prior + net.CAPTURE))
    print(f'PASS: terrain progression, 2x2/3x3 crafting, shared chest, furnace, death/respawn and restart ({net.CHECKS} checks)')


if __name__ == '__main__':
    main()
