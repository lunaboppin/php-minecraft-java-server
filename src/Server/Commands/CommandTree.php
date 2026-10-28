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
    /** @var list<array{source?: int, literal?: string, argument?: string, children: list<int>, redirect: ?int}> */
    private array $copies = [];

    /**
     * @param list<array> $graph the exported nodes
     * @param list<string> $roots vanilla root commands to include
     * @param list<string> $extra non-vanilla commands, sent as an executable literal with a greedy string argument
     */
    public function __construct(private readonly array $graph, int $level, array $roots, array $extra)
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
        $this->copies = $copies;
    }

    public function packet(int $stringArgumentId): string
    {
        $copies = $this->copies;
        $graph = $this->graph;
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
                | (($node['executable'] ?? true) ? 4 : 0)
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

    /**
     * CommandDispatcher.getSmartUsage for the root's or a command's children, as /help prints
     * them; null when the path does not name a command node.
     *
     * @param list<string> $path literal names or argument values from the root
     * @return list<string>|null
     */
    public function usage(array $path): ?array
    {
        $node = 0;
        foreach ($path as $position => $token) {
            // A redirect is followed only by further input, as parsing continues at its target.
            if ($position > 0 && $this->copies[$node]['redirect'] !== null) {
                $node = $this->copies[$node]['redirect'];
            }
            $next = null;
            foreach ($this->copies[$node]['children'] as $child) {
                // Literals must match; an argument accepts the token (its parser is not modelled).
                if ($this->kind($child) === 'argument' ? $next === null : self::name($this->copies[$child], $this->graph) === $token) {
                    $next = $child;
                    if ($this->kind($child) === 'literal') {
                        break;
                    }
                }
            }
            if ($next === null) {
                return null;
            }
            $node = $next;
        }
        $optional = $this->executable($node);
        return array_values(array_filter(array_map(fn (int $child): ?string => $this->smartUsage($child, $optional, false), $this->copies[$node]['children']), static fn (?string $usage): bool => $usage !== null));
    }

    private function smartUsage(int $node, bool $optional, bool $deep): ?string
    {
        $self = $optional ? '[' . $this->usageText($node) . ']' : $this->usageText($node);
        $childOptional = $this->executable($node);
        if ($deep) {
            return $self;
        }
        $redirect = $this->copies[$node]['redirect'];
        if ($redirect !== null) {
            return $self . ' ' . ($redirect === 0 ? '...' : '-> ' . $this->usageText($redirect));
        }
        $children = $this->copies[$node]['children'];
        if (count($children) === 1) {
            $usage = $this->smartUsage($children[0], $childOptional, $childOptional);
            return $usage === null ? $self : $self . ' ' . $usage;
        }
        if (count($children) > 1) {
            $usages = array_values(array_unique(array_map(fn (int $child): string => $this->smartUsage($child, $childOptional, true), $children)));
            if (count($usages) === 1) {
                return $self . ' ' . ($childOptional ? '[' . $usages[0] . ']' : $usages[0]);
            }
            [$open, $close] = $childOptional ? ['[', ']'] : ['(', ')'];
            return $self . ' ' . $open . implode('|', array_map(fn (int $child): string => $this->usageText($child), $children)) . $close;
        }
        return $self;
    }

    private function usageText(int $node): string
    {
        $name = self::name($this->copies[$node], $this->graph);
        return $this->kind($node) === 'argument' ? '<' . $name . '>' : $name;
    }

    private function kind(int $node): string
    {
        $copy = $this->copies[$node];
        return isset($copy['source']) ? $this->graph[$copy['source']]['kind'] : (isset($copy['literal']) ? 'literal' : 'argument');
    }

    private function executable(int $node): bool
    {
        $copy = $this->copies[$node];
        return isset($copy['source']) ? $this->graph[$copy['source']]['executable'] : true;
    }

    private static function name(array $copy, array $graph): string
    {
        return $copy['literal'] ?? $copy['argument'] ?? $graph[$copy['source']]['name'];
    }
}
