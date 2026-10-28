#!/usr/bin/env python3
"""Compare PHP's command trees, /help usage and ask_server suggestions with the official server's.

The official operator (level 4) and non-operator (level 0) trees are captured, PHP builds the same
trees for every root with CommandTree, and both are decoded and compared node by node: flags,
names, argument types and properties, suggestion providers, redirects and child order. Gamerule's
children are compared as a set, since the official server lists them in hash order.
"""
import argparse
import json
import os
from pathlib import Path
import struct
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
from protocol import Probe, ROOT, wire
from run import Server


def varint(data, offset):
    value = shift = 0
    while True:
        byte = data[offset]; offset += 1; value |= (byte & 0x7f) << shift; shift += 7
        if not byte & 0x80:
            return value, offset


def properties(argument, data, offset):
    """Skip an argument type's properties (brigadier numbers, strings, entities, times, resources)."""
    if argument in (1, 2, 3, 4):
        flags = data[offset]; offset += 1; size = 4 if argument in (1, 3) else 8
        return offset + size * ((flags & 1) + ((flags >> 1) & 1))
    if argument == 5:
        return varint(data, offset)[1]
    if argument in (6, 31):
        return offset + 1
    if argument == 43:
        return offset + 4
    if argument in (44, 45, 46, 47, 48):
        length, offset = varint(data, offset)
        return offset + length
    return offset


def decode(data):
    count, offset = varint(data, 0)
    nodes = []
    for _ in range(count):
        flags = data[offset]; offset += 1
        size, offset = varint(data, offset)
        children = []
        for _ in range(size):
            child, offset = varint(data, offset); children.append(child)
        redirect = None
        if flags & 8:
            redirect, offset = varint(data, offset)
        name = argument = suggestion = None; raw = b''
        if flags & 3:
            length, offset = varint(data, offset); name = data[offset:offset + length].decode(); offset += length
        if flags & 3 == 2:
            argument, offset = varint(data, offset)
            end = properties(argument, data, offset); raw = data[offset:end]; offset = end
            if flags & 16:
                length, offset = varint(data, offset); suggestion = data[offset:offset + length].decode(); offset += length
        nodes.append({'flags': flags, 'children': children, 'redirect': redirect, 'name': name, 'argument': argument, 'raw': raw, 'suggestion': suggestion})
    root, offset = varint(data, offset)
    if root != 0 or offset != len(data):
        raise ValueError('Malformed command tree')
    return nodes


def paths(nodes):
    found = {0: ()}; queue = [0]
    while queue:
        node = queue.pop(0)
        for target in nodes[node]['children'] + ([nodes[node]['redirect']] if nodes[node]['redirect'] is not None else []):
            if target not in found:
                found[target] = found[node] + (nodes[target]['name'],); queue.append(target)
    return found


def difference(ours, theirs):
    mine, other = paths(ours), paths(theirs)
    seen = set()

    def walk(a, b, path):
        if (a, b) in seen:
            return None
        seen.add((a, b))
        x, y = ours[a], theirs[b]
        for field in ('flags', 'name', 'argument', 'raw', 'suggestion'):
            if x[field] != y[field]:
                return f'/{"/".join(path)}: {field} {x[field]!r} != {y[field]!r}'
        if (x['redirect'] is None) != (y['redirect'] is None) or (x['redirect'] is not None and mine[x['redirect']] != other[y['redirect']]):
            return f'/{"/".join(path)}: redirect differs'
        left, right = x['children'], y['children']
        if path == ('gamerule',):
            left = sorted(left, key=lambda child: ours[child]['name']); right = sorted(right, key=lambda child: theirs[child]['name'])
        if [ours[c]['name'] for c in left] != [theirs[c]['name'] for c in right]:
            return f'/{"/".join(path)}: children differ'
        for c, d in zip(left, right):
            found = walk(c, d, path + (ours[c]['name'],))
            if found:
                return found
        return None
    return walk(0, 0, ())


def php_usage(level, path):
    code = ('require "src/bootstrap.php"; $g = json_decode(file_get_contents("reference/26.3/commands.json"), true)["nodes"];'
            '$roots = array_map(fn ($c) => $g[$c]["name"], $g[0]["network_children"]);'
            'echo json_encode((new PhpMinecraftJavaServer\\Server\\Commands\\CommandTree($g, %d, $roots, []))->usage(json_decode($argv[1], true)));' % level)
    return json.loads(subprocess.run(['php', '-r', code, json.dumps(path)], cwd=ROOT, check=True, capture_output=True, text=True).stdout)


def help_lines(client, command):
    start = len(client.seen)
    client.send('chat_command', wire.string(command))
    client.tick_barrier()
    lines = []
    for packet, payload in client.seen[start:]:
        if packet == 'system_chat' and payload[0] == 8:
            length = struct.unpack_from('>H', payload, 1)[0]
            lines.append(payload[3:3 + length].decode())
    return lines


SUGGESTIONS = ['/op ', '/op W', '/deop ', '/deop P', '/time set ', '/time set n', '/time set minecraft:m', '/time of minecraft:overworld set d']


def official_suggestions(client):
    """command_suggestions replies for ask_server arguments, asked while the witness is online."""
    replies = []
    for number, text in enumerate(SUGGESTIONS, 1):
        client.send('command_suggestion', wire.vi(number) + wire.string(text))
        replies.append(client.until('command_suggestions', lambda payload, number=number: wire.read_vi(payload)[0] == number))
    return replies


def canonical(line):
    """Gamerule alternatives are listed in the official server's hash order."""
    if line.startswith('/gamerule ('):
        return '/gamerule (' + '|'.join(sorted(line[len('/gamerule ('):-1].split('|'))) + ')'
    return line


def php_tree(level):
    code = ('require "src/bootstrap.php"; $g = json_decode(file_get_contents("reference/26.3/commands.json"), true)["nodes"];'
            '$roots = array_map(fn ($c) => $g[$c]["name"], $g[0]["network_children"]); $v = new PhpMinecraftJavaServer\\Protocol\\Version();'
            'echo bin2hex((new PhpMinecraftJavaServer\\Server\\Commands\\CommandTree($g, %d, $roots, []))->packet($v->blocks["argument_string"]));' % level)
    return bytes.fromhex(subprocess.run(['php', '-r', code], cwd=ROOT, check=True, capture_output=True, text=True).stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--eula-file', type=Path, required=True, help='Existing user-accepted eula.txt for the isolated official server')
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    args.output = args.output.resolve()
    args.output.mkdir(parents=True, exist_ok=False)
    server = Server(args.output / 'java', 'java', args.java, args.jar, args.eula_file)
    server.start()
    try:
        captured = {}
        helps = {}
        witness = None
        for level, name in ((4, 'ParityProbe'), (0, 'Witness')):
            if level == 4:
                # A non-operator online for /op's suggestions.
                witness = Probe(server.port, 'Witness').login('Witness')
            client = Probe(server.port, name).login(name)
            client.tick_barrier()
            captured[level] = next(payload for packet, payload in client.seen if packet == 'commands')
            helps[level] = {command: help_lines(client, command) for command in ('help', 'help gamemode', 'help tp', 'help list')}
            if level == 4:
                suggestions = official_suggestions(client)
                witness.close()
            client.close()
    finally:
        server.stop()
    failures = []
    for level, theirs in captured.items():
        ours = php_tree(level)
        (args.output / f'level-{level}.json').write_text(json.dumps({'php': ours.hex(), 'official': theirs.hex()}))
        found = difference(decode(ours), decode(theirs))
        print(f'level {level}: {len(decode(ours))} nodes, {len(ours)} bytes; ' + ('identical structure' if found is None else 'differs at ' + found))
        if found:
            failures.append(found)
        for command, official in helps[level].items():
            path = command.split()[1:]
            usage = php_usage(level, path)
            ours = [] if usage is None else ['/' + ' '.join(path + [line]) for line in usage]
            same = [canonical(line) for line in ours] == [canonical(line) for line in official] or (usage is None and official and not official[0].startswith('/'))
            print(f'level {level} /{command}: {len(official)} lines, ' + ('identical' if same else 'differs'))
            if not same:
                failures.append(command)
    php = Server(args.output / 'php', 'php', args.java, args.jar, args.eula_file)
    php.start()
    try:
        witness = Probe(php.port, 'Witness').login('Witness')
        client = Probe(php.port, 'ParityProbe').login()
        client.tick_barrier()
        ours = official_suggestions(client)
        client.close(); witness.close()
    finally:
        php.stop()
    for text, a, b in zip(SUGGESTIONS, ours, suggestions):
        print(f'suggestions {text!r}: ' + ('identical' if a == b else 'differs'))
        if a != b:
            failures.append(text)
    sys.exit(1 if failures else 0)


if __name__ == '__main__':
    main()
