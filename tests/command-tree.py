#!/usr/bin/env python3
"""The command trees sent to operators and other players: vanilla subtrees for implemented commands."""
import argparse
import json
from pathlib import Path
import sys
import tempfile
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/parity'))
from protocol import Probe, wire
from command_tree import decode, php_tree


def subtree(nodes, start):
    """A node and its descendants as nested tuples, following redirects by name only."""
    seen = set()

    def walk(index):
        node = nodes[index]
        if index in seen:
            return ('ref', node['name'])
        seen.add(index)
        children = tuple(walk(c) for c in node['children'])
        redirect = nodes[node['redirect']]['name'] if node['redirect'] is not None else None
        return (node['flags'], node['name'], node['argument'], node['raw'], node['suggestion'], redirect, children)
    return walk(start)


def roots(nodes):
    return {nodes[c]['name']: c for c in nodes[0]['children']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, help='Write PHP packet traces for the official codec verifier')
    args = parser.parse_args()
    graph = json.loads((ROOT / 'reference/26.3/commands.json').read_text())['nodes']
    vanilla = {graph[c]['name'] for c in graph[0]['network_children']}
    with tempfile.TemporaryDirectory(prefix='php-minecraft-command-tree-') as tmp:
        config = {'port': 0, 'generator': 'flat', 'worldPath': str(Path(tmp) / 'world.sqlite'), 'operators': ['ParityProbe']}
        traces = []
        with wire.server(config) as port:
            for name, level in (('ParityProbe', 4), ('Guest', 0)):
                client = Probe(port, name.lower()).login(name)
                try:
                    sent = decode(next(payload for packet, payload in client.seen if packet == 'commands'))
                    full = decode(php_tree(level))
                    sent_roots, full_roots = roots(sent), roots(full)
                    for command, index in sent_roots.items():
                        if command in vanilla:
                            # Each implemented vanilla command is the official subtree for this level.
                            assert subtree(sent, index) == subtree(full, full_roots[command]), (name, command)
                        else:
                            # This server's own commands take a greedy string.
                            literal = sent[index]
                            argument = sent[literal['children'][0]]
                            assert literal['flags'] == 1 and argument['flags'] == 6 and argument['raw'] == b'\x02', (name, command)
                    assert ('gamemode' in sent_roots) == (level == 4) and 'help' in sent_roots and 'advancement' not in sent_roots, sorted(sent_roots)
                    traces.extend(client.trace)
                finally:
                    client.close()
        if args.output:
            args.output.write_text(json.dumps(traces))
    print('PASS: operator and player command trees use the official subtrees for implemented commands')


if __name__ == '__main__':
    main()
