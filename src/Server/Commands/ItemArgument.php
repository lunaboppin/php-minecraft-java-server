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
            case 'minecraft:potion_contents':
                return self::potionContents($tag, $registries);
        }
        return [self::plain($tag)];
    }

    /** PotionContents.CODEC: a potion's name alone, or the full record. */
    public static function potionContents(array $tag, array $registries): array|string
    {
        $registryId = static function (array $tag, string $registry) use ($registries): string|array {
            if ($tag['type'] !== 'string') {
                return 'Not a string';
            }
            $id = str_contains($tag['value'], ':') ? $tag['value'] : 'minecraft:' . $tag['value'];
            return isset($registries[$registry][$id]) ? [$id] : 'Unknown registry key in ResourceKey[minecraft:root / ' . $registry . ']: ' . $id;
        };
        $contents = ['potion' => null, 'custom_color' => null, 'custom_effects' => [], 'custom_name' => null];
        // Codec.withAlternative: the record, then the potion's name alone.
        if ($tag['type'] !== 'compound') {
            $potion = $registryId($tag, 'minecraft:potion');
            return is_string($potion) ? 'Failed to parse either. First: Not a map: ' . Snbt::toText($tag) . '; Second: ' . ($tag['type'] === 'string' ? $potion : 'Not a string') : [['potion' => $potion[0]] + $contents];
        }
        $fields = $tag['value'];
        if (isset($fields['potion'])) {
            $potion = $registryId($fields['potion'], 'minecraft:potion');
            if (is_string($potion)) {
                return $potion;
            }
            $contents['potion'] = $potion[0];
        }
        $number = static fn (array $tag): ?int => in_array($tag['type'], ['byte', 'short', 'int', 'long', 'float', 'double'], true) ? (int) $tag['value'] : null;
        if (isset($fields['custom_color'])) {
            $contents['custom_color'] = $number($fields['custom_color']) ?? 'Not a number';
            if (is_string($contents['custom_color'])) {
                return $contents['custom_color'];
            }
        }
        foreach (isset($fields['custom_effects']) ? ($fields['custom_effects']['type'] === 'list' ? $fields['custom_effects']['value'] : null) ?? [] : [] as $effect) {
            if ($effect['type'] !== 'compound' || !isset($effect['value']['id'])) {
                return 'No key id in MapLike';
            }
            $id = $registryId($effect['value']['id'], 'minecraft:mob_effect');
            if (is_string($id)) {
                return $id;
            }
            $details = $effect['value'];
            $flag = static fn (string $name, bool $default): bool => isset($details[$name]) ? (bool) $details[$name]['value'] : $default;
            $contents['custom_effects'][] = [
                'id' => $id[0],
                'amplifier' => isset($details['amplifier']) ? ($number($details['amplifier']) ?? 0) & 0xff : 0,
                'duration' => isset($details['duration']) ? ($number($details['duration']) ?? 0) : 0,
                'ambient' => $flag('ambient', false),
                'show_particles' => $flag('show_particles', true),
                'show_icon' => $flag('show_icon', $flag('show_particles', true)),
                'hidden_effect' => null,
            ];
        }
        if (isset($fields['custom_name'])) {
            if ($fields['custom_name']['type'] !== 'string') {
                return 'Not a string: ' . Snbt::toText($fields['custom_name']);
            }
            $contents['custom_name'] = $fields['custom_name']['value'];
        }
        return [$contents];
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
