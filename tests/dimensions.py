#!/usr/bin/env python3
"""Wire-level dimension transitions, isolation, portals and restart persistence."""
import json
import os
from pathlib import Path
import sqlite3
import struct
import tempfile
import time
import integration as net
from terrain import command, inventory


def ping(c):
    c.send('ping_request', struct.pack('>q', 26326))
    c.until('pong_response')


def wait_cooldown(c):
    # Use game ticks rather than wall time while chunks are being generated.
    ping(c)
    start = struct.unpack_from('>q', next(p for n, p in reversed(c.seen) if n == 'set_time'))[0]
    c.until('set_time', lambda p: struct.unpack_from('>q', p)[0] >= start + 120)


def position(c):
    p = next(p for n, p in reversed(c.seen) if n == 'player_position')
    _, off = net.read_vi(p)
    return struct.unpack_from('>ddd', p, off)


def travel(c, dimension):
    command(c, 'dimension ' + dimension)
    c.until('respawn')
    c.until('player_position')
    c.until('chunk_batch_finished')
    ping(c)
    net.check(c.dimension == dimension, 'dimension key in respawn')
    return position(c)


def use(c, x, y, z, seq=501):
    payload = net.vi(0) + net.block_position(x, y, z) + net.vi(1) + struct.pack('>fff', .5, 1, .5) + b'\0\0' + net.vi(seq)
    c.send('use_item_on', payload)
    c.until('block_changed_ack', lambda p: net.read_vi(p)[0] == seq)


def edit(c, x, y, z, state):
    # Setting a block to its current state reports commands.setblock.failed, as in vanilla.
    command(c, f'setblock {x} {y} {z} {state}', 'commands.setblock.')


def move(c, xyz, wait=True):
    c.send('move_player_pos', struct.pack('>dddB', *xyz, 1))
    if wait:
        ping(c)


def main():
    with tempfile.TemporaryDirectory(prefix='php-minecraft-dimensions-') as temp:
        world = str(Path(temp) / 'world.sqlite')
        config = {'port': 0, 'worldPath': world, 'generator': 'flat', 'seed': 42,
                  'defaultGameMode': 1, 'operators': ['Admin']}
        with net.server(config) as port:
            a = net.Client(port, terrain=True).login('Admin')
            b = net.Client(port, terrain=True).login('Witness')
            try:
                edit(a, 0, 70, 0, 'diamond_block')
                first = len(b.seen)
                xyz = travel(a, 'the_nether')
                net.check(1 <= xyz[1] <= 120, 'safe Nether arrival below ceiling')
                ping(b)
                net.check(any(n == 'remove_entities' and net.read_vi(p, 1)[0] == a.entity_id for n, p in b.seen[first:]),
                          'old dimension removes departing player')
                first = len(b.seen)
                edit(a, 0, 70, 0, 'emerald_block')
                move(a, (xyz[0] + .1, xyz[1], xyz[2]))
                ping(b)
                net.check(not any(n in ('block_update', 'teleport_entity') for n, _ in b.seen[first:]),
                          'blocks and player movement do not leak between dimensions')
                command(a, 'give @s diamond 3', 'Gave')
                ping(a)
                net.check(any(s and s['item'] == net.BLOCKS['items']['diamond'] and s['count'] == 3 for s in a.inventory),
                          'inventory available in Nether')
                with sqlite3.connect(world) as db:
                    net.check(db.execute('SELECT state FROM blocks WHERE x=0 AND y=70 AND z=0').fetchone()[0] == net.BLOCKS['states']['diamond_block'], 'Overworld block unchanged')
                with sqlite3.connect(world + '.the_nether.sqlite') as db:
                    net.check(db.execute('SELECT state FROM blocks WHERE x=0 AND y=70 AND z=0').fetchone()[0] == net.BLOCKS['states']['emerald_block'], 'Nether block saved separately')
                travel(a, 'the_end')
                net.check(position(a) == (100.5, 65., .5), 'End arrival platform coordinates')
                command(b, 'dimension the_end', 'insufficient permission')
                a.close()
                time.sleep(.1)
                a = net.Client(port, terrain=True).login('Admin')
                net.check(a.dimension == 'the_end', 'rejoining restores saved dimension')
                net.check(any(s and s['item'] == net.BLOCKS['items']['diamond'] for s in a.inventory), 'dimension travel preserves inventory')
                command(a, 'kill', 'Admin was killed')
                a.send('client_command', net.vi(0))
                a.until('respawn')
                a.until('player_position')
                a.until('chunk_batch_finished')
                ping(a)
                net.check(a.dimension == 'overworld' and position(a) == (.5, 64., .5), 'death in End returns to Overworld')
                # Construct a 2x3 interior obsidian frame, ignite it with flint and steel.
                for x in range(2, 6):
                    for y in range(63, 68):
                        edit(a, x, y, 2, 'obsidian' if x in (2, 5) or y in (63, 67) else 'air')
                a.send('set_creative_mode_slot', struct.pack('>h', 36) + net.vi(1) + net.vi(net.BLOCKS['items']['flint_and_steel']) + b'\0\0')
                a.until('container_set_slot')
                use(a, 3, 63, 2)
                with sqlite3.connect(world) as db:
                    states = [s for s, in db.execute('SELECT state FROM blocks WHERE x IN (3,4) AND y BETWEEN 64 AND 66 AND z=2')]
                    net.check(len(states) == 6 and len(set(states)) == 1 and states[0] == net.BLOCKS['states']['nether_portal'], 'obsidian frame ignites six portal blocks')
                wait_cooldown(a)
                move(a, (3.5, 64., 2.5), wait=False)
                a.until('respawn')
                a.until('player_position')
                a.until('chunk_batch_finished')
                ping(a)
                net.check(a.dimension == 'the_nether', 'standing in ignited portal travels to Nether')
                arrival = position(a)
                move(a, (arrival[0], arrival[1], arrival[2] + 2))
                wait_cooldown(a)
                move(a, arrival, wait=False)
                a.until('respawn'); a.until('player_position'); a.until('chunk_batch_finished'); ping(a)
                net.check(a.dimension == 'overworld' and position(a) == (3.5, 64., 2.5),
                          'Nether portal reuses original Overworld return link')
                # Portal block removal collapses the connected portal.
                a.send('player_action', net.vi(0) + net.block_position(3, 64, 2) + b'\1' + net.vi(502))
                a.until('block_changed_ack', lambda p: net.read_vi(p)[0] == 502)
                with sqlite3.connect(world) as db:
                    net.check(all(s == 0 for s, in db.execute('SELECT state FROM blocks WHERE x IN (3,4) AND y BETWEEN 64 AND 66 AND z=2')), 'breaking portal collapses its interior')
                # An inward-facing twelve-frame ring activates after the final eye.
                command(a, 'tp 10.5 64 10.5')
                a.until('player_position'); a.until('chunk_batch_finished'); ping(a)
                for dx, dz, facing in [(-2, 0, 'east'), (2, 0, 'west'), (0, -2, 'south'), (0, 2, 'north')]:
                    for i in range(-1, 2):
                        x, z = 10 + dx + (i if dx == 0 else 0), 10 + dz + (i if dz == 0 else 0)
                        edit(a, x, 63, z, f'end_portal_frame[facing={facing},eye={"false" if (x,z)==(8,10) else "true"}]')
                a.send('set_creative_mode_slot', struct.pack('>h', 36) + net.vi(1) + net.vi(net.BLOCKS['items']['ender_eye']) + b'\0\0')
                a.until('container_set_slot'); use(a, 8, 63, 10, 503)
                with sqlite3.connect(world) as db:
                    states = [s for s, in db.execute('SELECT state FROM blocks WHERE x BETWEEN 9 AND 11 AND y=63 AND z BETWEEN 9 AND 11')]
                    net.check(len(states) == 9 and all(s == net.BLOCKS['states']['end_portal'] for s in states), 'last eye activates End portal ring')
                wait_cooldown(a)
                move(a, (10.5, 63., 10.5), wait=False); a.until('respawn'); a.until('player_position'); a.until('chunk_batch_finished'); ping(a)
                net.check(a.dimension == 'the_end', 'End portal enters End')
                travel(a, 'the_nether')
            finally:
                a.close(); b.close()
        with net.server(config) as port:
            a = net.Client(port, terrain=True).login('Admin')
            try:
                net.check(a.dimension == 'the_nether', 'dimension survives server restart')
                with sqlite3.connect(world + '.the_nether.sqlite') as db:
                    net.check(db.execute('SELECT state FROM blocks WHERE x=0 AND y=70 AND z=0').fetchone()[0] == net.BLOCKS['states']['emerald_block'], 'dimension edits survive restart')
                travel(a, 'the_end')
                command(a, 'tp 1.5 64 0.5'); a.until('player_position'); a.until('chunk_batch_finished'); ping(a)
                wait_cooldown(a)
                move(a, (1.5, 63., .5), wait=False); a.until('respawn'); a.until('player_position'); a.until('chunk_batch_finished'); ping(a)
                net.check(a.dimension == 'overworld', 'End exit fountain returns to Overworld')
            finally:
                a.close()
    capture = os.environ.get('PHP_MINECRAFT_CAPTURE')
    if capture:
        path = Path(capture)
        previous = json.loads(path.read_text()) if path.exists() else []
        path.write_text(json.dumps(previous + net.CAPTURE))
    print(f'PASS: dimension travel, isolation, portal frames, End portals and restart ({net.CHECKS} checks)')


if __name__ == '__main__':
    main()
