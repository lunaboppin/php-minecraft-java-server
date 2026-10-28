<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Binary;

/**
 * ClientboundCommandsPacket for the official 26.3 command graph (reference/26.3/commands.json),
 * restricted to the commands this server implements, plus string-argument nodes for its own.
 */
final class CommandTree
{
    /**
     * @param list<array> $graph the exported nodes
     * @param list<string> $roots vanilla root commands to include
     * @param list<string> $extra non-vanilla commands, sent as a literal with a greedy string argument
     */
    public static function packet(array $graph, int $level, array $roots, array $extra, int $stringArgumentId): string
    {
        // Commands.fillUsableCommands: copy usable nodes depth-first in registration order. A node
        // shared by several parents is copied once, and redirects point at the copies.
        $copies = [['source' => 0, 'children' => [], 'redirect' => null]];
        $map = [0 => 0];
        $permission = (string) $level;
        $fill = static function (int $from, int $to) use (&$fill, &$copies, &$map, $graph, $permission, $roots): void {
            foreach ($graph[$from]['network_children'] as $child) {
                if (($graph[$child]['permissions'][$permission] ?? null) !== true || ($from === 0 && !in_array($graph[$child]['name'], $roots, true))) {
                    continue;
                }
                if (isset($map[$child])) {
                    $copies[$to]['children'][] = $map[$child];
                    continue;
                }
                $copy = count($copies);
                $redirect = $graph[$child]['redirect'] ?? null;
                $copies[] = ['source' => $child, 'children' => [], 'redirect' => $redirect === null ? null : ($map[$redirect] ?? null)];
                $map[$child] = $copy;
                $copies[$to]['children'][] = $copy;
                $fill($child, $copy);
            }
        };
        $fill(0, 0);
        foreach ($extra as $name) {
            $literal = count($copies);
            $copies[] = ['literal' => $name, 'children' => [$literal + 1], 'redirect' => null];
            $copies[] = ['argument' => 'arguments', 'children' => [], 'redirect' => null];
            $copies[0]['children'][] = $literal;
        }

        // CommandNode.equals: kind, name, argument type, command identity and children (as a map);
        // ClientboundCommandsPacket.enumerateNodes gives equal nodes one entry, breadth first.
        $keys = [];
        $key = static function (int $copy) use (&$key, &$keys, &$copies, $graph): string {
            if (isset($keys[$copy])) {
                return $keys[$copy];
            }
            $children = [];
            foreach ($copies[$copy]['children'] as $child) {
                $children[self::name($copies[$child], $graph)] = $key($child);
            }
            ksort($children);
            $node = isset($copies[$copy]['source']) ? $graph[$copies[$copy]['source']] : ['kind' => isset($copies[$copy]['literal']) ? 'literal' : 'argument', 'name' => self::name($copies[$copy], $graph), 'type_key' => 'php:greedy', 'command_id' => 'php:' . $copy];
            return $keys[$copy] = sha1(json_encode([$node['kind'], $node['name'], $node['type_key'] ?? null, $node['command_id'] ?? null, $children], JSON_THROW_ON_ERROR));
        };
        $ids = [];
        $order = [];
        $queue = new \SplQueue();
        $queue->enqueue(0);
        while (!$queue->isEmpty()) {
            $copy = $queue->dequeue();
            $nodeKey = $key($copy);
            if (isset($ids[$nodeKey])) {
                continue;
            }
            $ids[$nodeKey] = count($order);
            $order[] = $copy;
            foreach ($copies[$copy]['children'] as $child) {
                $queue->enqueue($child);
            }
            if ($copies[$copy]['redirect'] !== null) {
                $queue->enqueue($copies[$copy]['redirect']);
            }
        }

        $payload = Binary::writeVarInt(count($order));
        foreach ($order as $copy) {
            $entry = $copies[$copy];
            $node = isset($entry['source']) ? $graph[$entry['source']] : null;
            $kind = $node['kind'] ?? (isset($entry['literal']) ? 'literal' : 'argument');
            $flags = ['root' => 0, 'literal' => 1, 'argument' => 2][$kind]
                | ($entry['redirect'] !== null ? 8 : 0)
                | (($node['executable'] ?? !isset($entry['literal'])) ? 4 : 0)
                | (($node['restricted'] ?? false) ? 32 : 0)
                | (isset($node['suggestion_id']) ? 16 : 0);
            $payload .= chr($flags) . Binary::writeVarInt(count($entry['children']));
            foreach ($entry['children'] as $child) {
                $payload .= Binary::writeVarInt($ids[$key($child)]);
            }
            if ($entry['redirect'] !== null) {
                $payload .= Binary::writeVarInt($ids[$key($entry['redirect'])]);
            }
            if ($kind !== 'root') {
                $payload .= Binary::writeString(self::name($entry, $graph));
            }
            if ($kind === 'argument') {
                // A server-only command's argument is a greedy string (brigadier:string, mode 2).
                $payload .= $node === null ? Binary::writeVarInt($stringArgumentId) . "\2" : Binary::writeVarInt($node['argument_id']) . hex2bin($node['argument_hex']);
                if (isset($node['suggestion_id'])) {
                    $payload .= Binary::writeString($node['suggestion_id']);
                }
            }
        }
        return $payload . Binary::writeVarInt(0);
    }

    private static function name(array $copy, array $graph): string
    {
        return $copy['literal'] ?? $copy['argument'] ?? $graph[$copy['source']]['name'];
    }
}
