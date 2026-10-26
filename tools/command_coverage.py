#!/usr/bin/env python3
"""List the full official command inventory without treating registration as parity."""
import argparse
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]


def render():
    profiles = {}
    for filename in ['commands.json', 'commands-all-features.json']:
        data = json.loads((ROOT / 'reference/26.3' / filename).read_text())
        if data['version'] != '26.3' or data['protocol'] != 777:
            raise ValueError('Wrong official command reference version')
        nodes = data['nodes']
        if nodes[0]['kind'] != 'root':
            raise ValueError('Official command graph has no root')
        for node in nodes:
            if set(node['permissions']) != {'0', '1', '2', '3', '4'}:
                raise ValueError('Official command permission observations are incomplete')
            if any(value is not None and type(value) is not bool for value in node['permissions'].values()):
                raise ValueError('Invalid official command permission result')
            for target in [*node['children'], *([node['redirect']] if 'redirect' in node else [])]:
                if type(target) is not int or not 0 <= target < len(nodes):
                    raise ValueError('Invalid official command graph edge')
        profiles[filename] = data

    code = r'''require 'src/bootstrap.php';
    $definitions = \PhpMinecraftJavaServer\Server\Commands\CommandRegistry::definitions();
    echo json_encode(array_map(static fn ($d) => [
        'name' => $d->name, 'aliases' => $d->aliases, 'handler' => $d->handler,
    ], $definitions), JSON_THROW_ON_ERROR);'''
    definitions = json.loads(subprocess.check_output(['php', '-r', code], cwd=ROOT))
    handlers = {}
    for definition in definitions:
        for name in [definition['name'], *definition['aliases']]:
            handlers[name] = definition['handler']

    roots = {}
    for data in profiles.values():
        nodes = data['nodes']
        for index in nodes[0]['children']:
            node = nodes[index]
            redirect = nodes[node['redirect']]['name'] if 'redirect' in node else None
            roots[node['name']] = redirect
    present = len(set(roots) & set(handlers))
    lines = [
        '# Native command inventory', '',
        'Target: the checksum-pinned official Java 26.3 dedicated server. This is an implementation backlog, not a claim of command parity.', '',
        f'The exported dispatcher has **{len(roots)} roots/aliases** across the default and all-registered-feature profiles. '
        f'**{present}** have an existing PHP handler; **{len(roots) - present}** have none. '
        '**Every native acceptance row remains unverified or incomplete.** Registration alone never marks a command complete.', '',
        'The references preserve every graph node, redirect/fork, executable flag, argument type, JSON/network argument properties, '
        'suggestion-provider presence and permission-predicate results for the five official permission levels. '
        'These are structural and permission-query observations; no command is executed by the exporter.', '',
        'Literal-only sibling sets use canonical name order because gamerule registration iterates an unordered set. '
        'Argument sibling priority and mixed-node ordering are retained. Unknown permission queries are recorded as null, never as successful checks.', '',
        '| Reference profile | Flags | Graph nodes |',
        '| --- | --- | ---: |',
    ]
    for filename, data in profiles.items():
        lines.append(f'| [{filename}](../reference/26.3/{filename}) | {", ".join(data["features"])} | {len(data["nodes"])} |')
    lines.extend(['', '| Command | PHP module | Native acceptance | Redirect |', '| --- | --- | --- | --- |'])
    for name, redirect in sorted(roots.items()):
        handler = handlers.get(name)
        module = '—'
        if handler:
            filename = handler.removeprefix('execute') + 'Command.php'
            if not (ROOT / 'src/Server/Commands' / filename).is_file():
                raise ValueError('Registered command module is missing: ' + filename)
            module = f'[{filename}](../src/Server/Commands/{filename})'
        status = 'Partial handler; native behavior incomplete' if handler else 'Unimplemented'
        lines.append(f'| `/{name}` | {module} | {status} | {"`/" + redirect + "`" if redirect else "—"} |')
    extra = sorted(set(handlers) - set(roots))
    lines.extend([
        '', 'Existing additional project commands: ' + ', '.join('`/' + name + '`' for name in extra) + '.', '',
        'Completion under #10 requires the complete branch/argument inventory, native results and side effects, permissions, selectors, '
        'suggestions/errors, execution contexts and lifecycle evidence. The matrix must gain explicit behavioral evidence before any row '
        'can be accepted. Default/all-feature snapshots do not establish every runtime server configuration or execution context.', '',
        'Regenerate with `python3 tools/command_coverage.py`; verify the committed inventory with `--check`. '
        'Rebuild graph data using `tools/ExportCommands.java` through the reference exporter. '
        'No game feature flags are enabled on the hosted server by this inventory work.', '',
    ])
    return '\n'.join(lines)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    expected = render()
    target = ROOT / 'docs/command-coverage.md'
    if args.check:
        if not target.is_file() or target.read_text() != expected:
            raise SystemExit('Command inventory is stale; run tools/command_coverage.py')
        print('PASS: complete official command graph inventory matches PHP registrations')
    else:
        target.write_text(expected)
        print(target)


if __name__ == '__main__':
    main()
