<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * Inline item modifiers for /item: LootItemFunctions decoded from SNBT and applied to stacks.
 * set_count, limit_count, set_damage, set_item, set_enchantments and sequence are supported,
 * with constant and uniform number providers and a random_chance condition. A list is a sequence.
 */
final class ItemModifier
{
    /**
     * LootItemFunctions.ROOT_CODEC: an inline function, or a list of them.
     *
     * @return list<array> the decoded functions
     *
     * @throws \UnexpectedValueException with DataResult's message
     */
    public static function decode(array $tag, array $registries): array
    {
        if ($tag['type'] === 'list') {
            $functions = [];
            $errors = [];
            foreach ($tag['value'] as $element) {
                try {
                    $functions[] = self::function($element, $registries);
                } catch (\UnexpectedValueException|\DomainException $error) {
                    array_unshift($errors, $error->getMessage());
                }
            }
            if ($errors !== []) {
                throw new \UnexpectedValueException(implode('; ', $errors));
            }
            return $functions;
        }
        try {
            return [self::function($tag, $registries)];
        } catch (\DomainException $error) {
            throw new \UnexpectedValueException($error->getMessage());
        } catch (\UnexpectedValueException $error) {
            $first = $error->getMessage();
            throw new \UnexpectedValueException('Failed to parse either. First: ' . $first . '; Second: Failed to parse either. First: Not a string; Second: Failed to parse either. First: Not a list: '
                . Snbt::toText($tag) . '; Second: ' . $first);
        }
    }

    /** One function, dispatched on its type. */
    private static function function(array $tag, array $registries): array
    {
        if ($tag['type'] !== 'compound') {
            throw new \UnexpectedValueException('Not a map: ' . Snbt::toText($tag));
        }
        $fields = $tag['value'];
        $type = self::identifier($fields['type'] ?? null) ?? throw new \UnexpectedValueException('No key type in MapLike[' . Snbt::toText($tag) . ']');
        $required = static fn (string $key): array => $fields[$key] ?? throw new \UnexpectedValueException('No key ' . $key . ' in MapLike[' . Snbt::toText($tag) . ']');
        $function = match ($type) {
            'minecraft:set_count' => ['count' => self::intProvider($required('count')), 'add' => self::boolean($fields['add'] ?? null)],
            'minecraft:limit_count' => ['limit' => self::limit($required('limit'))],
            'minecraft:set_damage' => ['damage' => self::floatProvider($required('damage')), 'add' => self::boolean($fields['add'] ?? null)],
            'minecraft:set_item' => ['item' => self::item($required('item'), $registries)],
            'minecraft:set_enchantments' => ['enchantments' => self::enchantments($fields['enchantments'] ?? ['type' => 'compound', 'value' => []], $registries), 'add' => self::boolean($fields['add'] ?? null)],
            'minecraft:sequence' => ['functions' => self::sequence($required('functions'), $registries)],
            default => throw new \UnexpectedValueException('Unknown registry key in ResourceKey[minecraft:root / minecraft:loot_function_type]: ' . $type),
        };
        // LootItemConditionalFunction: an optional condition guards the function.
        // Its optional field keeps the function as a partial result, so the error is not wrapped.
        if (isset($fields['condition']) && $type !== 'minecraft:sequence') {
            try {
                $function['condition'] = self::condition($fields['condition']);
            } catch (\UnexpectedValueException $error) {
                throw new \DomainException($error->getMessage());
            }
        }
        return ['type' => $type] + $function;
    }

    private static function identifier(?array $tag): ?string
    {
        if ($tag === null || $tag['type'] !== 'string') {
            return null;
        }
        return str_contains($tag['value'], ':') ? $tag['value'] : 'minecraft:' . $tag['value'];
    }

    private static function boolean(?array $tag): bool
    {
        return $tag !== null && in_array($tag['type'], ['byte', 'short', 'int', 'long', 'float', 'double'], true) && $tag['value'] != 0;
    }

    private static function number(array $tag): ?float
    {
        return in_array($tag['type'], ['byte', 'short', 'int', 'long', 'float', 'double'], true) ? (float) $tag['value'] : null;
    }

    /** ContextIntProviders.CODEC: an inline constant, a registered provider's ID, or a typed provider. */
    private static function intProvider(array $tag): array
    {
        return self::provider($tag, 'int');
    }

    private static function floatProvider(array $tag): array
    {
        return self::provider($tag, 'float');
    }

    private static function provider(array $tag, string $kind): array
    {
        $number = self::number($tag);
        if ($number !== null) {
            return ['constant' => $kind === 'int' ? (int) $tag['value'] : $number];
        }
        if ($tag['type'] === 'string') {
            $id = str_contains($tag['value'], ':') ? $tag['value'] : 'minecraft:' . $tag['value'];
            throw new \UnexpectedValueException('Failed to get element ' . $id . ' from registry minecraft:context_' . $kind . '_provider');
        }
        if ($tag['type'] !== 'compound') {
            throw new \UnexpectedValueException('Failed to parse either. First: Not a number; Second: Not a map: ' . Snbt::toText($tag));
        }
        $fields = $tag['value'];
        $type = self::identifier($fields['type'] ?? null) ?? throw new \UnexpectedValueException('Failed to parse either. First: Not a number; Second: No key type in MapLike[' . Snbt::toText($tag) . ']');
        $required = static fn (string $key): array => $fields[$key] ?? throw new \UnexpectedValueException('No key ' . $key . ' in MapLike[' . Snbt::toText($tag) . ']');
        return match ($type) {
            'minecraft:constant' => ['constant' => $kind === 'int' ? (int) ($required('value')['value']) : (float) $required('value')['value']],
            'minecraft:uniform' => ['uniform' => [self::provider($required('min'), $kind), self::provider($required('max'), $kind)], 'kind' => $kind],
            default => throw new \UnexpectedValueException('Unknown registry key in ResourceKey[minecraft:root / minecraft:context_' . $kind . '_provider_type]: ' . $type),
        };
    }

    /** IntLimit.CODEC: optional min and max. */
    private static function limit(array $tag): array
    {
        if ($tag['type'] !== 'compound') {
            throw new \UnexpectedValueException('Not a map: ' . Snbt::toText($tag));
        }
        return ['min' => isset($tag['value']['min']) ? self::intProvider($tag['value']['min']) : null, 'max' => isset($tag['value']['max']) ? self::intProvider($tag['value']['max']) : null];
    }

    private static function item(array $tag, array $registries): string
    {
        $id = self::identifier($tag) ?? throw new \UnexpectedValueException('Not a string: ' . Snbt::toText($tag));
        if (!isset($registries['item'][$id])) {
            throw new \UnexpectedValueException('Unknown registry key in ResourceKey[minecraft:root / minecraft:item]: ' . $id);
        }
        return substr($id, 10);
    }

    private static function enchantments(array $tag, array $registries): array
    {
        if ($tag['type'] !== 'compound') {
            throw new \UnexpectedValueException('Not a map: ' . Snbt::toText($tag));
        }
        $levels = [];
        foreach ($tag['value'] as $name => $level) {
            $id = str_contains((string) $name, ':') ? (string) $name : 'minecraft:' . $name;
            if (!isset($registries['minecraft:enchantment'][$id])) {
                throw new \UnexpectedValueException('Failed to get element ' . $id . ' from registry minecraft:enchantment');
            }
            $levels[$id] = self::intProvider($level);
        }
        return $levels;
    }

    private static function sequence(array $tag, array $registries): array
    {
        if ($tag['type'] !== 'list') {
            throw new \UnexpectedValueException('Not a list: ' . Snbt::toText($tag));
        }
        return array_map(static fn (array $element): array => self::function($element, $registries), $tag['value']);
    }

    /** LootItemCondition: random_chance only. */
    private static function condition(array $tag): array
    {
        $fields = $tag['type'] === 'compound' ? $tag['value'] : throw new \UnexpectedValueException('Not a map: ' . Snbt::toText($tag));
        $type = self::identifier($fields['type'] ?? null) ?? throw new \UnexpectedValueException('No key type in MapLike[' . Snbt::toText($tag) . ']');
        if ($type !== 'minecraft:random_chance') {
            throw new \UnexpectedValueException('Unknown registry key in ResourceKey[minecraft:root / minecraft:loot_condition_type]: ' . $type);
        }
        return ['chance' => self::floatProvider($fields['chance'] ?? throw new \UnexpectedValueException('No key chance in MapLike[' . Snbt::toText($tag) . ']'))];
    }

    private static function value(array $provider): int|float
    {
        if (isset($provider['constant'])) {
            return $provider['constant'];
        }
        [$min, $max] = [self::value($provider['uniform'][0]), self::value($provider['uniform'][1])];
        if ($provider['kind'] === 'int') {
            return $max < $min ? $min : random_int((int) $min, (int) $max);
        }
        return Float32::round($min + (mt_rand() / mt_getrandmax()) * ($max - $min));
    }

    /**
     * Each function's run on a copy of the stack, then ItemStack.limitSize to its maximum stack;
     * a count of zero or less empties it.
     */
    public static function apply(array $functions, ?array $stack, Version $version): ?array
    {
        foreach ($functions as $function) {
            $stack = self::run($function, $stack, $version);
        }
        if ($stack !== null) {
            $stack['count'] = min($stack['count'], $version->catalog->item($stack['item'])['max_stack'] ?? 64);
        }
        return $stack;
    }

    private static function run(array $function, ?array $stack, Version $version): ?array
    {
        if ($stack === null) {
            return null;
        }
        if ($function['type'] === 'minecraft:sequence') {
            foreach ($function['functions'] as $inner) {
                $stack = self::run($inner, $stack, $version);
            }
            return $stack;
        }
        if (isset($function['condition']) && !(Float32::round(mt_rand() / (mt_getrandmax() + 1)) < self::value($function['condition']['chance']))) {
            return $stack;
        }
        switch ($function['type']) {
            case 'minecraft:set_count':
                $stack['count'] = ($function['add'] ? $stack['count'] : 0) + (int) self::value($function['count']);
                break;
            case 'minecraft:limit_count':
                // IntLimit.clamp: up to the minimum, then down to the maximum.
                if ($function['limit']['min'] !== null) {
                    $stack['count'] = max($stack['count'], (int) self::value($function['limit']['min']));
                }
                if ($function['limit']['max'] !== null) {
                    $stack['count'] = min($stack['count'], (int) self::value($function['limit']['max']));
                }
                break;
            case 'minecraft:set_damage':
                $maximum = $version->catalog->item($stack['item'])['durability'] ?? 0;
                if ($maximum > 0) {
                    $base = $function['add'] ? Float32::round(1.0 - Float32::round(($stack['damage'] ?? 0) / $maximum)) : 0.0;
                    $percent = Float32::round(1.0 - max(0.0, min(1.0, Float32::round(self::value($function['damage']) + $base))));
                    $stack['damage'] = max(0, min($maximum, (int) floor(Float32::round($percent * $maximum))));
                    if ($stack['damage'] === 0) {
                        unset($stack['damage']);
                    }
                }
                break;
            case 'minecraft:set_item':
                // ItemStack.transmuteCopy: the new item keeps the count and component changes.
                $stack['item'] = $version->blocks['items'][$function['item']];
                break;
            case 'minecraft:set_enchantments':
                if ($version->catalog->itemName($stack['item']) === 'book') {
                    $stack['item'] = $version->blocks['items']['enchanted_book'];
                }
                $field = $version->catalog->itemName($stack['item']) === 'enchanted_book' ? 'storedEnchantments' : 'enchantments';
                $levels = $stack[$field] ?? [];
                foreach ($function['enchantments'] as $id => $provider) {
                    $level = max(0, min(255, ($function['add'] ? ($levels[$id] ?? 0) : 0) + (int) self::value($provider)));
                    if ($level === 0) {
                        unset($levels[$id]);
                    } else {
                        $levels[$id] = $level;
                    }
                }
                $stack[$field] = $levels;
                if ($levels === []) {
                    unset($stack[$field]);
                }
                break;
        }
        return $stack['count'] > 0 ? $stack : null;
    }
}
