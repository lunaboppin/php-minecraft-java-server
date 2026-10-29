#!/usr/bin/env python3
"""Movement packet checks: one position per client tick, invalid values, moving too quickly and teleport confirmations.

With --eula-file (and the pinned JAR), the same packets go to the official 26.3 server and each case's
disconnect or teleport must match byte for byte.
"""
import argparse
import json
import math
import os
from pathlib import Path
import shutil
import struct
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire
from run import Server

ON_FLOOR = (2.5, 100.0, 0.5)


def raw(client, name, payload=b''):
    # Bypasses the test client's automatic client_tick_end after a move.
    client.sock.sendall(client.frame(name, payload))


class Held:
    """A socket whose writes are kept back; dropping it discards them."""

    def __init__(self, sock):
        self.sock = sock

    def sendall(self, data):
        pass

    def __getattr__(self, name):
        return getattr(self.sock, name)


def outcome(client):
    """The packets that answer the case: a teleport back, a disconnect, or neither within two clock updates."""
    # The main thread handles movement; a ping is answered on the network thread, so wait for game time.
    seen = []
    clocks = 0
    try:
        for _ in range(5000):
            name, payload = client.receive()
            if name in ('player_position', 'disconnect'):
                seen.append({'packet': name, 'hex': payload.hex()})
            clocks += name == 'set_time'
            if name == 'disconnect' or clocks == 2:
                break
    except (EOFError, OSError):
        pass
    return seen


def record(kind, output, java, jar, eula):
    server = Server(output / kind, kind, java, jar, eula)
    server.start()
    cases = []
    try:
        operator = Probe(server.port, 'operator').login()
        for text in ('gamerule spawn_mobs false', 'fill -8 99 -2 8 99 2 minecraft:stone'):
            operator.send('chat_command', wire.string(text))
            operator.tick_barrier()

        def case(name, action):
            mover = Probe(server.port, 'mover').login('Mover')
            operator.send('chat_command', wire.string('tp Mover %s %s %s' % ON_FLOOR))
            mover.until('player_position')
            mover.send('move_player_pos', struct.pack('>dddB', *ON_FLOOR, 1))
            mover.tick_barrier()
            action(mover)
            cases.append({'case': name, 'packets': outcome(mover)})
            mover.close()
            operator.tick_barrier()

        position = lambda x, y, z, flags=1: struct.pack('>dddB', x, y, z, flags)
        case('two positions in one client tick', lambda m: (raw(m, 'move_player_pos', position(2.6, 100, 0.5)), raw(m, 'move_player_pos', position(2.7, 100, 0.5))))
        case('positions in separate client ticks', lambda m: (m.send('move_player_pos', position(2.6, 100, 0.5)), m.send('move_player_pos', position(2.7, 100, 0.5))))
        case('rotations in one client tick', lambda m: (raw(m, 'move_player_rot', struct.pack('>ffB', 10, 0, 1)), raw(m, 'move_player_rot', struct.pack('>ffB', 20, 0, 1))))
        case('NaN position', lambda m: m.send('move_player_pos', position(math.nan, 100, 0.5)))
        case('infinite rotation', lambda m: m.send('move_player_rot', struct.pack('>ffB', math.inf, 0, 1)))
        case('infinite position is clamped', lambda m: m.send('move_player_pos', position(math.inf, 100, 0.5)))
        case('moved too quickly', lambda m: m.send('move_player_pos', position(13.0, 100, 0.5)))
        case('just under ten blocks', lambda m: m.send('move_player_pos', position(12.4, 100, 0.5)))
        case('unknown flag bits', lambda m: m.send('move_player_pos', position(2.6, 100, 0.5, 0x7d)))

        def confirm(mover, teleports):
            operator.send('chat_command', wire.string('tp Mover 3.5 100 0.5'))
            payload = mover.until('player_position')
            teleport, _ = wire.read_vi(payload)
            for id in teleports(teleport):
                raw(mover, 'accept_teleportation', wire.vi(id) + struct.pack('>dddff', 3.5, 100, 0.5, 0, 0))

        # The client confirms each teleport as it arrives; these send extra confirmations.
        case('repeated confirmation', lambda m: confirm(m, lambda id: [id]))
        case('earlier confirmation', lambda m: confirm(m, lambda id: [id - 1]))

        def unconfirmed(mover, wait):
            # The test client's automatic confirmation of this teleport is dropped.
            mover.sock = Held(mover.sock)
            operator.send('chat_command', wire.string('tp Mover 3.5 100 0.5'))
            mover.until('player_position')
            mover.sock = mover.sock.sock
            for _ in range(wait):
                mover.tick_barrier()
            mover.send('move_player_rot', struct.pack('>ffB', 400, -100, 1))

        case('unconfirmed teleport within twenty ticks', lambda m: unconfirmed(m, 0))
        case('unconfirmed teleport after twenty ticks', lambda m: unconfirmed(m, 2))
        operator.close()
    finally:
        server.stop()
    (output / f'{kind}-cases.json').write_text(json.dumps(cases, indent=1))
    return cases


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'tools/.cache/movement-checks')
    parser.add_argument('--eula-file', type=Path, help='Existing user-accepted eula.txt; compares with the official server')
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    args.output = args.output.resolve()
    # Each run's servers start from empty directories.
    shutil.rmtree(args.output, ignore_errors=True)
    args.output.mkdir(parents=True)
    php = record('php', args.output, args.java, args.jar, args.eula_file)
    invalid = b'multiplayer.disconnect.invalid_player_movement'.hex()
    by_case = {entry['case']: entry['packets'] for entry in php}
    for name in ('two positions in one client tick', 'NaN position', 'infinite rotation', 'repeated confirmation'):
        assert [p['packet'] for p in by_case[name]] == ['disconnect'] and invalid in by_case[name][0]['hex'], (name, by_case[name])
    for name in ('positions in separate client ticks', 'rotations in one client tick', 'just under ten blocks', 'unknown flag bits', 'earlier confirmation',
                 'unconfirmed teleport within twenty ticks'):
        assert by_case[name] == [], (name, by_case[name])
    for name in ('moved too quickly', 'infinite position is clamped', 'unconfirmed teleport after twenty ticks'):
        assert [p['packet'] for p in by_case[name]] == ['player_position'], (name, by_case[name])
    if args.eula_file is not None:
        java = record('java', args.output, args.java, args.jar, args.eula_file)
        differences = [(a['case'], a['packets'], b['packets']) for a, b in zip(java, php) if a != b]
        assert not differences, differences
        print(f'PASS: {len(php)} movement checks identical to the official server')
    else:
        print(f'PASS: {len(php)} movement checks (tick-end positions, invalid values, too quick, confirmations)')


if __name__ == '__main__':
    main()
