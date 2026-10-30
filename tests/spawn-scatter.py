#!/usr/bin/env python3
"""New players spread within respawn_radius of the world spawn, optionally checked against the official server.

The column each player gets is random on both servers, so the same rules are checked on each:
positions are block centres on the surface within the radius, a radius of 0 is the spawn column,
and the world border narrows the radius.
"""
import argparse
import json
import os
from pathlib import Path
import shutil
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire
from run import Server

SPAWN = (0, 64, 0)
JOINS = 12


def record(kind, output, java, jar, eula):
    server = Server(output / kind, kind, java, jar, eula)
    server.start()
    cases = {}
    try:
        operator = Probe(server.port, 'operator').login()

        def command(text):
            operator.send('chat_command', wire.string(text))
            operator.tick_barrier()

        for text in ('gamerule spawn_mobs false', 'setworldspawn %d %d %d' % SPAWN):
            command(text)
        number = [0]

        def joins(count):
            positions = []
            for _ in range(count):
                number[0] += 1
                player = Probe(server.port, 'joiner').login('Joiner%d' % number[0])
                positions.append(player.position)
                player.close()
                # The operator keeps answering keep-alives while players come and go.
                operator.barrier()
            return positions

        command('gamerule respawn_radius 3')
        cases['radius 3'] = joins(JOINS)
        command('gamerule respawn_radius 0')
        cases['radius 0'] = joins(2)
        command('gamerule respawn_radius 10')
        command('worldborder set 4')
        cases['border 4'] = joins(JOINS)
        operator.close()
    finally:
        server.stop()
    (output / f'{kind}-positions.json').write_text(json.dumps(cases, indent=1))
    return cases


def check(kind, cases):
    for name, radius in (('radius 3', 3), ('radius 0', 0), ('border 4', 2)):
        for x, y, z in cases[name]:
            assert (x - 0.5) % 1 == 0 and (z - 0.5) % 1 == 0, (kind, name, x, z)
            assert abs(x - 0.5 - SPAWN[0]) <= radius and abs(z - 0.5 - SPAWN[2]) <= radius, (kind, name, x, z)
            assert y == 64.0, (kind, name, y)
    assert len({(x, z) for x, _, z in cases['radius 3']}) >= 3, (kind, cases['radius 3'])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'tools/.cache/spawn-scatter')
    parser.add_argument('--eula-file', type=Path, help='Existing user-accepted eula.txt; also checks the official server')
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    args.output = args.output.resolve()
    shutil.rmtree(args.output, ignore_errors=True)
    args.output.mkdir(parents=True)
    check('php', record('php', args.output, args.java, args.jar, args.eula_file))
    if args.eula_file is not None:
        check('java', record('java', args.output, args.java, args.jar, args.eula_file))
        print('PASS: joining players spread by the same rules as on the official server')
    else:
        print('PASS: joining players spread within respawn_radius and the world border')


if __name__ == '__main__':
    main()
