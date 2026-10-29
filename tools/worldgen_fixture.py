#!/usr/bin/env python3
"""Reduce ExportWorldgenChunks output to stage hashes for tests/vanilla-worldgen.php.

Each hash is the SHA-256 of the chunk's block names (namespace and properties removed), one
per line in y, z, x order, or of its quart biomes in y, z, x order.
"""
import base64
import hashlib
import json
import re
import struct
import sys


def names(stage):
    raw = base64.b64decode(stage['data'])
    values = struct.unpack('>%dH' % (len(raw) // 2), raw)
    palette = [re.sub(r'^(Block\{)?minecraft:|\}|\[.*$', '', name) for name in stage['palette']]
    return [palette[value] for value in values]


def digest(items):
    return hashlib.sha256('\n'.join(items).encode()).hexdigest()


def main():
    if len(sys.argv) < 3:
        raise SystemExit('usage: worldgen_fixture.py OUTPUT EXPORT.json [EXPORT.json ...]')
    chunks = []
    for path in sys.argv[2:]:
        data = json.load(open(path))
        for chunk in data['chunks']:
            row = {'seed': data['seed'], 'x': chunk['x'], 'z': chunk['z'], 'biomes': digest(names(chunk['biomes']))}
            if data.get('dimension', 'overworld') != 'overworld':
                row['dimension'] = data['dimension']
            for stage in ('noise', 'surface', 'carvers'):
                row[stage] = digest(names(chunk[stage]))
            chunks.append(row)
    out = {
        'version': '26.3',
        'source': 'tools/ExportWorldgenChunks.java run offline on the pinned official server, reduced by tools/worldgen_fixture.py',
        'chunks': chunks,
    }
    with open(sys.argv[1], 'w') as handle:
        json.dump(out, handle, indent=1)
        handle.write('\n')


if __name__ == '__main__':
    main()
