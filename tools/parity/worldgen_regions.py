#!/usr/bin/env python3
"""Generate an area with the official 26.3 server and decode its saved chunks (the Overworld unless
--dimension says otherwise).

The isolated reference server runs with the given seed. Structures are off unless
--structures is passed. It force-loads a rectangle of chunks, saves and stops; the region files
are then decoded into JSON: block names (namespace and properties removed) in y, z, x order,
and quart biomes, for every fully generated chunk in the rectangle. This needs an existing
acknowledgement of the Minecraft EULA (--eula-file); the tool never accepts it on the user's
behalf.
"""
import argparse
import base64
import gzip
import json
import os
import re
import shutil
import socket
import struct
import subprocess
import sys
import time
import zlib
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from java_logging import plain_reference_logging  # noqa: E402
from nbt import Nbt  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]


def unpack_indices(longs, count, bits):
    per_long = 64 // bits
    mask = (1 << bits) - 1
    out = []
    for value in longs:
        value &= (1 << 64) - 1
        for i in range(per_long):
            if len(out) == count:
                return out
            out.append((value >> (i * bits)) & mask)
    return out


def container(states, count, minimum_bits):
    palette = states['palette']
    if 'data' not in states:
        return [0] * count, palette
    bits = max(minimum_bits, (len(palette) - 1).bit_length())
    return unpack_indices(states['data'], count, bits), palette


def state_name(entry):
    """A palette entry as name[key=value,...] with sorted properties and no namespace.

    26.3 saves a default state as its id, or as a compound under the empty key or id with
    optional properties.
    """
    if isinstance(entry, str):
        name, properties = entry, {}
    else:
        name = entry.get('Name', entry.get('id', entry.get('')))
        properties = entry.get('Properties', entry.get('properties', {}))
    name = re.sub(r'^minecraft:', '', name)
    if properties:
        name += '[' + ','.join(f'{key}={properties[key]}' for key in sorted(properties)) + ']'
    return name


# The section range of each dimension's build height.
SECTIONS = {'overworld': (-4, 19), 'the_nether': (0, 7), 'the_end': (0, 15)}


def decode_chunk(nbt, dimension='overworld'):
    low, high = SECTIONS[dimension]
    sections = sorted(nbt['sections'], key=lambda section: section['Y'])
    blocks = []
    biomes = []
    for section in sections:
        if section['Y'] < low or section['Y'] > high:
            continue
        indices, palette = container(section['block_states'], 4096, 4)
        names = [state_name(entry) for entry in palette]
        blocks.extend(names[i] for i in indices)
        indices, palette = container(section['biomes'], 64, 1)
        names = [re.sub(r'^minecraft:', '', entry) for entry in palette]
        biomes.extend(names[i] for i in indices)
    return blocks, biomes


def read_regions(world, chunks, dimension='overworld'):
    out = {}
    for region in sorted((world / 'region').glob('r.*.mca')):
        data = region.read_bytes()
        if len(data) < 8192:
            continue
        for index in range(1024):
            location = struct.unpack('>I', data[index * 4:index * 4 + 4])[0]
            if location == 0:
                continue
            offset = (location >> 8) * 4096
            length, compression = struct.unpack('>IB', data[offset:offset + 5])
            payload = data[offset + 5:offset + 4 + length]
            raw = zlib.decompress(payload) if compression == 2 else gzip.decompress(payload) if compression == 1 else payload
            nbt = Nbt(raw).root()
            key = (nbt['xPos'], nbt['zPos'])
            if key in chunks and nbt.get('Status') == 'minecraft:full':
                out[key] = decode_chunk(nbt, dimension)
    return out


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--seed', required=True)
    parser.add_argument('--from', dest='start', required=True, help='chunk x:z of one corner')
    parser.add_argument('--to', dest='end', required=True, help='chunk x:z of the other corner')
    parser.add_argument('--margin', type=int, default=0, help='extra force-loaded chunks around the rectangle')
    parser.add_argument('--structures', action='store_true')
    parser.add_argument('--dimension', choices=sorted(SECTIONS), default='overworld')
    parser.add_argument('--threads', type=int, help='limit the official server to this many background (generation) threads')
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--work', type=Path, default=ROOT / 'tools/.cache/worldgen-reference')
    parser.add_argument('--eula-file', type=Path, required=True)
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    if not args.eula_file.is_file() or not re.search(r'^\s*eula\s*=\s*true\s*$', args.eula_file.read_text(), re.M | re.I):
        parser.error('Provide --eula-file pointing to an existing acknowledgement of https://www.minecraft.net/eula.')
    x1, z1 = map(int, args.start.split(':'))
    x2, z2 = map(int, args.end.split(':'))
    x1, x2 = sorted((x1, x2))
    z1, z2 = sorted((z1, z2))
    work = args.work / f'seed-{args.seed}{"-structures" if args.structures else ""}'
    shutil.rmtree(work, ignore_errors=True)
    work.mkdir(parents=True)
    shutil.copyfile(args.eula_file, work / 'eula.txt')
    plain_reference_logging(args.jar.resolve(), work / 'log4j2-plain.xml')
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    props = {
        'server-ip': '127.0.0.1', 'server-port': port, 'online-mode': 'false', 'level-seed': args.seed,
        'generate-structures': 'true' if args.structures else 'false', 'view-distance': 2, 'simulation-distance': 2,
        'spawn-animals': 'false', 'spawn-monsters': 'false', 'pause-when-empty-seconds': 0, 'sync-chunk-writes': 'true',
    }
    (work / 'server.properties').write_text(''.join(f'{k}={v}\n' for k, v in props.items()))
    log = (work / 'server.log').open('w')
    process = subprocess.Popen(
        [args.java, '-Xms512M', '-Xmx2G', *([f'-Dmax.bg.threads={args.threads}'] if args.threads else []),
         '-Dlog4j.configurationFile=' + str(work / 'log4j2-plain.xml'), '-jar', str(args.jar.resolve()), 'nogui'],
        cwd=work, stdin=subprocess.PIPE, stdout=log, stderr=subprocess.STDOUT, text=True,
    )

    def wait_for(pattern, timeout):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            text = (work / 'server.log').read_text(errors='replace')
            if re.search(pattern, text):
                return text
            if process.poll() is not None:
                raise RuntimeError('Reference server stopped:\n' + text[-4000:])
            time.sleep(0.5)
        raise TimeoutError(pattern)

    def command(text):
        process.stdin.write(text + '\n')
        process.stdin.flush()

    try:
        wait_for(r'Done \([\d.]+s\)!', 300)
        margin = args.margin
        # forceload accepts at most 256 chunks per command.
        for bx in range(x1 - margin, x2 + margin + 1, 16):
            for bz in range(z1 - margin, z2 + margin + 1, 16):
                ex = min(bx + 15, x2 + margin)
                ez = min(bz + 15, z2 + margin)
                command(f'execute in minecraft:{args.dimension} run forceload add {bx * 16} {bz * 16} {ex * 16} {ez * 16}')
        # Give generation time, then save everything to disk.
        total = (x2 - x1 + 1 + 2 * margin) * (z2 - z1 + 1 + 2 * margin)
        time.sleep(min(600, 5 + total * 0.05))
        command('save-all flush')
        wait_for(r'Saved the game', 600)
        command('stop')
        process.wait(timeout=120)
    finally:
        if process.poll() is None:
            process.kill()
        log.close()
    wanted = {(x, z) for x in range(x1, x2 + 1) for z in range(z1, z2 + 1)}
    chunks = read_regions(work / f'world/dimensions/minecraft/{args.dimension}', wanted, args.dimension)
    out = {'version': '26.3', 'seed': args.seed, 'dimension': args.dimension, 'structures': args.structures, 'chunks': []}
    for (x, z), (blocks, biomes) in sorted(chunks.items()):
        out['chunks'].append({'x': x, 'z': z, 'blocks': base64.b64encode(zlib.compress('\n'.join(blocks).encode(), 9)).decode(),
                              'biomes': base64.b64encode(zlib.compress('\n'.join(biomes).encode(), 9)).decode()})
    args.output.write_text(json.dumps(out) + '\n')
    print(f'{len(out["chunks"])} of {len(wanted)} chunks decoded to {args.output}')


if __name__ == '__main__':
    main()
