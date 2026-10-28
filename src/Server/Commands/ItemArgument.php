<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

/**
 * ItemParser: an item id and an optional [component=value, !component, ...] patch. Values are
 * SNBT; the components this server stores (damage and enchantments) are validated as their
 * codecs do, the others are kept as plain values.
 */
final class ItemArgument
{
    /**
     * @param array<string, array> $registries ArgumentReader's registries
     * @param \Closure(string): bool $damageable whether an item has a default maximum damage
     * @return array{item: string, components: array<string, mixed>, removed: list<string>}
     */
    public static function read(StringReader $reader, array $registries, \Closure $damageable): array
    {
        $start = $reader->cursor;
        $id = $reader->readIdentifier();
        if (!isset($registries['item'][$id])) {
            $reader->cursor = $start;
            throw $reader->error('argument.item.id.invalid', [$id]);
        }
        $result = ['item' => $id, 'components' => [], 'removed' => []];
        if (!$reader->canRead() || $reader->peek() !== '[') {
            return $result;
        }
        $reader->skip();
        $seen = [];
        while ($reader->canRead() && $reader->peek() !== ']') {
            $reader->skipWhitespace();
            $removed = $reader->canRead() && $reader->peek() === '!';
            if ($removed) {
                $reader->skip();
            }
            $componentStart = $reader->cursor;
            $component = $reader->readIdentifier();
            if (!isset($registries['minecraft:data_component_type'][$component])) {
                $reader->cursor = $componentStart;
                throw $reader->error('arguments.item.component.unknown', [$component]);
            }
            if (isset($seen[$component])) {
                // Raised without context, so no context line follows.
                throw new CommandSyntaxException(['translate' => 'arguments.item.component.repeated', 'with' => [['text' => $component]]], $reader->string, -1);
            }
            $seen[$component] = true;
            if ($removed) {
                $result['removed'][] = $component;
            } else {
                $reader->skipWhitespace();
                self::expect($reader, '=');
                $reader->skipWhitespace();
                $valueStart = $reader->cursor;
                $value = self::decode($component, Snbt::read($reader), $registries);
                if (is_string($value)) {
                    $reader->cursor = $valueStart;
                    throw $reader->error('arguments.item.component.malformed', [$component, $value]);
                }
                $result['components'][$component] = $value[0];
            }
            $reader->skipWhitespace();
            if (!$reader->canRead() || $reader->peek() !== ',') {
                break;
            }
            $reader->skip();
        }
        self::expect($reader, ']');
        // ItemStack.validateComponents
        $maxDamage = ($damageable)($id) && !in_array('minecraft:max_damage', $result['removed'], true) || isset($result['components']['minecraft:max_damage']);
        if ($maxDamage && ($result['components']['minecraft:max_stack_size'] ?? 1) > 1) {
            throw new CommandSyntaxException(['translate' => 'arguments.item.malformed', 'with' => [['text' => 'Item cannot be both damageable and stackable']]], $reader->string, -1);
        }
        return $result;
    }

    /** @return array{mixed}|string the decoded value, or the codec's error */
    private static function decode(string $component, array $tag, array $registries): array|string
    {
        switch ($component) {
            case 'minecraft:damage':
            case 'minecraft:max_damage':
            case 'minecraft:max_stack_size':
                if (!in_array($tag['type'], ['byte', 'short', 'int', 'long', 'float', 'double'], true)) {
                    return 'Not a number';
                }
                $value = (int) $tag['value'];
                if ($component === 'minecraft:damage' && $value < 0) {
                    return 'Value must be non-negative: ' . $value;
                }
                return [$value];
            case 'minecraft:enchantments':
            case 'minecraft:stored_enchantments':
                if ($tag['type'] !== 'compound') {
                    return 'Not a map: ' . Snbt::toText($tag);
                }
                $levels = [];
                foreach ($tag['value'] as $name => $level) {
                    $id = str_contains($name, ':') ? $name : 'minecraft:' . $name;
                    if (!isset($registries['minecraft:enchantment'][$id])) {
                        return 'Failed to get element ' . $id . ' from registry minecraft:enchantment missed input: ' . Snbt::toText($tag);
                    }
                    $levels[$id] = (int) $level['value'];
                }
                return [$levels];
        }
        return [self::plain($tag)];
    }

    private static function plain(array $tag): mixed
    {
        return match ($tag['type']) {
            'compound' => array_map(self::plain(...), $tag['value']),
            'list' => array_map(self::plain(...), $tag['value']),
            default => $tag['value'],
        };
    }

    private static function expect(StringReader $reader, string $char): void
    {
        if (!$reader->canRead() || $reader->peek() !== $char) {
            throw $reader->error('parsing.expected', [$char]);
        }
        $reader->skip();
    }
}
