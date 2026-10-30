#!/usr/bin/env python3
"""What an observer sees of another player walking, turning, teleporting and coming back into range.

With --eula-file (and the pinned JAR), the same steps run against the official 26.3 server and the
observer's pairing and movement packets for the moving player must match byte for byte.
"""
import argparse
import json
import os
from pathlib import Path
import shutil
import struct
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire
from run import Server
from command_feedback import normalize_attribute_order

TRACKED = ('add_entity', 'remove_entities', 'set_entity_data', 'update_attributes', 'set_equipment', 'move_entity_pos',
           'move_entity_pos_rot', 'move_entity_rot', 'entity_position_sync', 'teleport_entity', 'rotate_head')


def heartbeat(name, payload):
    """ServerEntity's 60-tick position resend: a relative move of zero, timed from when tracking began."""
    if name not in ('move_entity_pos', 'move_entity_pos_rot'):
        return None
    entity, at = wire.read_vi(payload)
    flags, at = wire.read_vi(payload, at)
    deltas = []
    for _ in range(max(1, flags >> 1)):
        if flags >> 1:
            _, at = wire.read_vi(payload, at)
        deltas += struct.unpack_from('>hhh', payload, at)
        at += 6
    if any(deltas):
        return None
    # A heartbeat that carries a turn is the rotation-only packet the turn would otherwise send.
    return ('move_entity_rot', wire.vi(entity) + bytes([flags & 1]) + payload[at:at + 2]) if name == 'move_entity_pos_rot' else ()


class Held:
    """A socket whose writes wait for release() and then go out together."""

    def __init__(self, sock):
        self.sock = sock
        self.data = b''

    def sendall(self, data):
        self.data += data

    def release(self):
        self.sock.sendall(self.data)
        return self.sock

    def __getattr__(self, name):
        return getattr(self.sock, name)


def concerns(name, payload, entity):
    if name == 'remove_entities':
        count, at = wire.read_vi(payload)
        ids = []
        for _ in range(count):
            value, at = wire.read_vi(payload, at)
            ids.append(value)
        return entity in ids
    return wire.read_vi(payload)[0] == entity


def record(kind, output, java, jar, eula):
    server = Server(output / kind, kind, java, jar, eula)
    server.start()
    steps = []
    try:
        observer = Probe(server.port, 'observer').login()
        mover = Probe(server.port, 'mover').login('Mover')
        clients = (observer, mover)
        for client in clients:
            client.tick_barrier()
        position = [2.5, 100.0, 0.5]
        rotation = [0.0, 0.0]

        def move(packet, payload):
            # A client ends each tick it moved in; vanilla allows one position per client tick.
            mover.send(packet, payload)
            mover.send('client_tick_end')

        def settle():
            for client in clients:
                client.tick_barrier()

        def command(text):
            observer.send('chat_command', wire.string(text))
            settle()

        def teleport(text, destination):
            # Like a client, the mover confirms the teleport and reports its new position in the same
            # tick; one write keeps PHP, which handles packets as they arrive, from ticking in between.
            mover.sock = Held(mover.sock)
            observer.send('chat_command', wire.string(text))
            mover.until('player_position')
            position[:] = destination
            move('move_player_pos_rot', struct.pack('>dddffB', *position, *rotation, 1))
            mover.sock = mover.sock.release()
            settle()

        # Joining players are spread around the spawn; the observer moves first so the floor's chunks are loaded.
        for text in ('gamerule spawn_mobs false', 'tp @s 0.5 100 0.5', 'fill -8 99 -2 48 99 2 minecraft:stone'):
            command(text)
        teleport('tp Mover 2.5 100 0.5', [2.5, 100.0, 0.5])

        def step(name, action):
            start = len(observer.seen)
            action()
            seen = []
            for packet, payload in observer.seen[start:]:
                if packet not in TRACKED or not concerns(packet, payload, mover.entity_id):
                    continue
                replaced = heartbeat(packet, payload)
                if replaced == ():
                    continue
                packet, payload = replaced or (packet, payload)
                # Entity IDs follow each server's earlier spawns; the mover's own ID stands in as 0.
                if packet != 'remove_entities':
                    payload = wire.vi(0) + payload[len(wire.vi(mover.entity_id)):]
                else:
                    payload = b'\0'
                seen.append({'packet': packet, 'hex': payload.hex()})
            steps.append({'step': name, 'packets': normalize_attribute_order(seen)})

        def walk(dx, dz=0.0):
            def action():
                position[0] += dx
                position[2] += dz
                move('move_player_pos', struct.pack('>dddB', *position, 1))
                settle()
            return action

        def turn(yaw, pitch, dx=0.0):
            def action():
                rotation[:] = [yaw, pitch]
                if dx:
                    position[0] += dx
                    move('move_player_pos_rot', struct.pack('>dddffB', *position, *rotation, 1))
                else:
                    move('move_player_rot', struct.pack('>ffB', *rotation, 1))
                settle()
            return action

        step('walk 0.25', walk(0.25))
        step('walk 0.3 diagonally', walk(0.3, dz=0.3))
        step('turn', turn(90.0, 10.0))
        step('walk and turn', turn(-45.0, -20.0, dx=0.5))
        step('walk 9 blocks', walk(9.0))
        step('teleport nearby', lambda: teleport('tp Mover 6.5 100 0.5', [6.5, 100.0, 0.5]))
        step('teleport out of range', lambda: teleport('tp Mover 40.5 100 0.5', [40.5, 100.0, 0.5]))
        step('teleport back', lambda: teleport('tp Mover 3.5 100 0.5', [3.5, 100.0, 0.5]))
        step('walk after pairing', walk(0.5))
        observer.close()
        mover.close()
    finally:
        server.stop()
    (output / f'{kind}-steps.json').write_text(json.dumps(steps, indent=1))
    return steps


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'tools/.cache/player-motion')
    parser.add_argument('--eula-file', type=Path, help='Existing user-accepted eula.txt; compares with the official server')
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    args.output = args.output.resolve()
    # Each run's servers start from empty directories.
    shutil.rmtree(args.output, ignore_errors=True)
    args.output.mkdir(parents=True)
    php = record('php', args.output, args.java, args.jar, args.eula_file)
    by_step = {entry['step']: [p['packet'] for p in entry['packets']] for entry in php}
    assert 'move_entity_pos' in by_step['walk 0.25'], by_step
    assert by_step['teleport out of range'] == ['remove_entities'], by_step
    assert by_step['teleport back'][0] == 'add_entity', by_step
    if args.eula_file is not None:
        java = record('java', args.output, args.java, args.jar, args.eula_file)
        differences = [(a['step'], a['packets'], b['packets']) for a, b in zip(java, php) if a != b]
        assert not differences, differences
        print(f'PASS: {len(php)} player movement steps identical to the official server')
    else:
        print(f'PASS: {len(php)} player movement steps (relative moves, syncs, unpairing and pairing)')


if __name__ == '__main__':
    main()
