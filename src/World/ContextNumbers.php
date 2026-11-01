<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

use PhpMinecraftJavaServer\Server\Commands\Snbt;
use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Trees;

/**
 * ContextIntProvider and ContextFloatProvider: numbers computed in a loot context, decoded from
 * SNBT as their codecs do and evaluated with Java's int and float arithmetic.
 *
 * A context holds:
 * - random: \Closure(): float uniform in [0, 1), and randomInt: \Closure(int): int below a bound;
 * - holders: entity target name => score holder (this, target_entity, ...);
 * - score: \Closure(string holder, string objective): ?int;
 * - storage: \Closure(string id, string path): int|float|null;
 * - condition: \Closure(array): bool, a loot condition's test.
 */
final class ContextNumbers
{
    private const TYPES = [
        'int' => ['abs', 'avg', 'binomial', 'conditional', 'constant', 'sub', 'environment_attribute', 'from_float', 'max', 'min', 'floor_mod', 'floor_div', 'mod', 'div', 'negate', 'number_dispatcher', 'pow', 'mul', 'score', 'storage', 'add', 'uniform', 'weighted_list'],
        'float' => ['abs', 'avg', 'ceil', 'conditional', 'constant', 'cos', 'sub', 'enchantment_level', 'environment_attribute', 'floor', 'from_int', 'length', 'max', 'min', 'mod', 'negate', 'number_dispatcher', 'pow', 'mul', 'div', 'round', 'sin', 'sqrt', 'storage', 'add', 'truncate', 'uniform', 'weighted_list'],
    ];

    /** LootContext.EntityTarget names a score's context target may give. */
    private const ENTITY_TARGETS = ['this', 'attacker', 'direct_attacker', 'attacking_player', 'target_entity', 'interacting_entity'];

    private const FAST_FURNACE = ['type' => 'minecraft:match_block', 'blocks' => ['minecraft:smoker', 'minecraft:blast_furnace']];

    /** @return array<string, array> ContextFloatProviders.bootstrap */
    public static function namedFloats(): array
    {
        $normal = ['constant', 1.0];
        $fast = ['constant', 2.0];
        return [
            'minecraft:cooking/normal_speed_multiplier' => $normal,
            'minecraft:cooking/fast_speed_multiplier' => $fast,
            'minecraft:brewing/speed_default' => ['constant', 1.0],
            'minecraft:cooking/speed_default' => ['conditional', self::FAST_FURNACE, $fast, $normal],
        ];
    }

    /** @return array<string, array> ContextIntProviders.bootstrap */
    public static function namedInts(): array
    {
        $normal = ['constant', 1];
        $fast = ['constant', 2];
        $named = [];
        foreach (['low' => 30, 'low_medium' => 50, 'medium' => 65, 'medium_high' => 85, 'always_add_one' => 100] as $name => $chance) {
            // An empty composter always gains a layer; otherwise one in the chance.
            $named['minecraft:compostable/' . $name] = $chance >= 100 ? ['constant', 1] : ['number_dispatcher', [[
                ['type' => 'minecraft:match_block', 'blocks' => ['minecraft:composter'], 'state' => ['level' => '0']], ['constant', 1],
            ]], ['weighted_list', [[['constant', 1], $chance], [['constant', 0], 100 - $chance]]]];
        }
        foreach (['bamboo' => 50, 'wool_slabs' => 50, 'wool_carpets' => 67, 'dry_plants' => 100, 'wood_items_extra_small' => 100, 'wool' => 100, 'wood_slabs' => 150,
            'wood_items_large' => 200, 'roots' => 300, 'wood_blocks' => 300, 'wood_items_small' => 300, 'hanging_signs' => 800, 'boats' => 1200, 'coal' => 1600,
            'blaze_rod' => 2400, 'dried_kelp_block' => 4001, 'coal_block' => 16000, 'lava_bucket' => 20000] as $name => $seconds) {
            $named['minecraft:cooking/time_' . $name] = ['div', ['constant', $seconds], ['conditional', self::FAST_FURNACE, $fast, $normal]];
        }
        $named['minecraft:cooking/normal_burn_time_reduction_factor'] = $normal;
        $named['minecraft:cooking/fast_burn_time_reduction_factor'] = $fast;
        $named['minecraft:brewing/uses_default'] = ['constant', 20];
        ksort($named);
        return $named;
    }

    /**
     * ContextIntProviders/ContextFloatProviders.DIRECT_CODEC: an inline number or a typed compound.
     *
     * @param array{type: string, value: mixed} $tag
     * @throws ContextNumberError with the codec's message
     */
    public static function decode(array $tag, string $kind): array
    {
        if (in_array($tag['type'], ['byte', 'short', 'int', 'long', 'float', 'double'], true)) {
            return ['constant', $kind === 'int' ? self::javaInt($tag['value']) : Float32::round((float) $tag['value'])];
        }
        try {
            return self::typed($tag, $kind);
        } catch (ContextNumberError $error) {
            if ($error->partial) {
                throw $error;
            }
            throw new ContextNumberError('Failed to parse either. First: Not a number; Second: ' . $error->getMessage());
        }
    }

    /** ContextIntProviders/ContextFloatProviders.CODEC: a registry reference or a direct value. */
    private static function holder(array $tag, string $kind): array
    {
        if ($tag['type'] === 'string') {
            $id = str_contains($tag['value'], ':') ? $tag['value'] : 'minecraft:' . $tag['value'];
            $named = $kind === 'int' ? self::namedInts() : self::namedFloats();
            return $named[$id] ?? throw new ContextNumberError('Failed to get element ResourceKey[minecraft:context_' . $kind . '_provider / ' . $id . ']');
        }
        return self::decode($tag, $kind);
    }

    private static function typed(array $tag, string $kind): array
    {
        if ($tag['type'] !== 'compound') {
            throw new ContextNumberError('Not a map: ' . Snbt::toText($tag));
        }
        $fields = $tag['value'];
        $mapLike = 'MapLike[' . Snbt::toText($tag) . ']';
        if (!isset($fields['type'])) {
            throw new ContextNumberError('No key type in ' . $mapLike);
        }
        $type = (string) $fields['type']['value'];
        $type = str_starts_with($type, 'minecraft:') ? substr($type, 10) : $type;
        if (!in_array($type, self::TYPES[$kind], true)) {
            throw new ContextNumberError('Unknown registry key in ResourceKey[minecraft:root / minecraft:context_' . $kind . '_provider_type]: ' . (str_contains($type, ':') ? $type : 'minecraft:' . $type));
        }
        $field = static fn (string $name): array => $fields[$name] ?? throw new ContextNumberError('No key ' . $name . ' in ' . $mapLike);
        $value = static fn (string $name, ?string $as = null): array => self::holder($field($name), $as ?? $kind);
        $inputs = static fn (): array => array_map(static fn (array $input): array => self::listElement($input, $kind), self::listOf($field('inputs')));
        $condition = static fn (array $tag): array => self::condition($tag);
        return match ($type) {
            'constant' => ['constant', $kind === 'int' ? self::javaInt(self::number($field('value'))) : Float32::round((float) self::number($field('value')))],
            'abs', 'negate', 'ceil', 'cos', 'floor', 'round', 'sin', 'sqrt', 'truncate' => [$type, $value('input')],
            'from_int' => [$type, $value('input', 'int')],
            'from_float' => [$type, $value('input', 'float')],
            'sub', 'mod', 'floor_mod', 'floor_div', 'div' => [$type, $value('left'), $value('right')],
            'pow' => [$type, $value('base'), $value('exponent')],
            'add', 'mul', 'max', 'min', 'avg', 'length' => [$type, $inputs()],
            'uniform' => [$type, $value('min'), $value('max')],
            'binomial' => [$type, $value('n', 'int'), $value('p', 'float')],
            'weighted_list' => [$type, array_map(static fn (array $entry): array => [
                self::holder($entry['value']['data'] ?? throw new ContextNumberError('No key data in MapLike[' . Snbt::toText($entry) . ']'), $kind),
                (int) self::number($entry['value']['weight'] ?? throw new ContextNumberError('No key weight in MapLike[' . Snbt::toText($entry) . ']')),
            ], self::listOf($field('distribution')))],
            'conditional' => [$type, $condition($field('condition')), $value('on_true'), isset($fields['on_false']) ? $value('on_false') : ['constant', $kind === 'int' ? 0 : 0.0]],
            'number_dispatcher' => [$type, array_map(static function (array $case) use ($kind): array {
                try {
                    return [self::condition($case['value']['condition'] ?? throw new ContextNumberError('No key condition in MapLike[' . Snbt::toText($case) . ']')),
                        self::holder($case['value']['value'] ?? throw new ContextNumberError('No key value in MapLike[' . Snbt::toText($case) . ']'), $kind)];
                } catch (ContextNumberError $error) {
                    // A list keeps the elements it could read, so its error is reported alone.
                    throw new ContextNumberError($error->getMessage(), true);
                }
            }, self::listOf($field('cases'))), isset($fields['default']) ? $value('default') : ['constant', $kind === 'int' ? 0 : 0.0]],
            'score' => [$type, self::scoreTarget($field('target')), (string) $field('score')['value'], isset($fields['fallback']) ? $value('fallback') : ['constant', 0]],
            'storage' => [$type, (string) $field('storage')['value'], (string) $field('path')['value'], isset($fields['fallback']) ? $value('fallback') : ['constant', $kind === 'int' ? 0 : 0.0]],
            'enchantment_level' => [$type, $field('amount')],
            'environment_attribute' => throw new ContextNumberError($field('attribute')['value'] . ' cannot be converted to ' . ($kind === 'int' ? 'an integer' : 'a float')),
        };
    }

    private static function listElement(array $tag, string $kind): array
    {
        try {
            return self::holder($tag, $kind);
        } catch (ContextNumberError $error) {
            throw new ContextNumberError($error->getMessage(), true);
        }
    }

    /** @return list<array> */
    private static function listOf(array $tag): array
    {
        if ($tag['type'] === 'list') {
            return $tag['value'];
        }
        if (in_array($tag['type'], ['byte_array', 'int_array', 'long_array'], true)) {
            $type = substr($tag['type'], 0, -6);
            return array_map(static fn (int $value): array => ['type' => $type, 'value' => $value], $tag['value']);
        }
        throw new ContextNumberError('Not a list: ' . Snbt::toText($tag));
    }

    private static function number(array $tag): int|float
    {
        if (!in_array($tag['type'], ['byte', 'short', 'int', 'long', 'float', 'double'], true)) {
            throw new ContextNumberError('Not a number: ' . Snbt::toText($tag));
        }
        return $tag['value'];
    }

    /** ScoreboardNameProviders.CODEC: an entity target's name, or a fixed or context provider. */
    private static function scoreTarget(array $tag): array
    {
        if ($tag['type'] === 'string') {
            if (!in_array($tag['value'], self::ENTITY_TARGETS, true)) {
                throw new ContextNumberError('Failed to parse either. First: Unknown element name:' . $tag['value'] . '; Second: Not a map: ' . Snbt::toText($tag));
            }
            return ['context', $tag['value']];
        }
        $fields = $tag['value'];
        $type = preg_replace('/^minecraft:/', '', (string) ($fields['type']['value'] ?? ''));
        return $type === 'fixed' ? ['fixed', (string) ($fields['name']['value'] ?? '')] : ['context', (string) ($fields['target']['value'] ?? 'this')];
    }

    /** A loot condition as the loot evaluator reads it: plain values, with namespaced types. */
    private static function condition(array $tag): array|string
    {
        if ($tag['type'] === 'string') {
            return str_contains($tag['value'], ':') ? $tag['value'] : 'minecraft:' . $tag['value'];
        }
        if ($tag['type'] === 'compound' && !isset($tag['value']['type'])) {
            throw new ContextNumberError('No key type in MapLike[' . Snbt::toText($tag) . ']');
        }
        $plain = self::plain($tag);
        if (is_array($plain) && isset($plain['type']) && !str_contains($plain['type'], ':')) {
            $plain['type'] = 'minecraft:' . $plain['type'];
        }
        return $plain;
    }

    private static function plain(array $tag): mixed
    {
        return match ($tag['type']) {
            'compound' => array_map(self::plain(...), $tag['value']),
            'list' => array_map(self::plain(...), $tag['value']),
            'byte' => $tag['value'] === 0 || $tag['value'] === 1 ? (bool) $tag['value'] : $tag['value'],
            default => $tag['value'],
        };
    }

    /**
     * getIntUnsafe / getFloatUnsafe.
     *
     * @throws \ArithmeticError with Java's ArithmeticException message
     */
    public static function evaluate(array $node, string $kind, array $context): int|float
    {
        return $kind === 'int' ? self::int($node, $context) : self::float($node, $context);
    }

    private static function int(array $node, array $context): int
    {
        $int = static fn (array $child): int => self::int($child, $context);
        return match ($node[0]) {
            'constant' => $node[1],
            'abs' => ($value = $int($node[1])) === -2147483648 ? throw new \ArithmeticError('Overflow to represent absolute value of Integer.MIN_VALUE') : abs($value),
            'negate' => ($value = $int($node[1])) === -2147483648 ? throw new \ArithmeticError('integer overflow') : -$value,
            'sub' => self::exact($int($node[1]) - $int($node[2])),
            'add' => self::longToInt(array_sum(array_map($int, $node[1]))),
            'mul' => self::longToInt(array_product(array_map($int, $node[1]))),
            'max' => array_reduce($node[1], static fn (int $carry, array $child): int => max($carry, $int($child)), -2147483648),
            'min' => array_reduce($node[1], static fn (int $carry, array $child): int => min($carry, $int($child)), 2147483647),
            'avg' => $node[1] === [] ? throw new \ArithmeticError('/ by zero') : self::longToInt(intdiv(array_sum(array_map($int, $node[1])), count($node[1]))),
            'div' => self::divide($int($node[1]), $int($node[2])),
            'mod' => ($right = $int($node[2])) === 0 ? throw new \ArithmeticError('/ by zero') : $int($node[1]) % $right,
            'floor_div' => self::floorDivide($int($node[1]), $int($node[2])),
            'floor_mod' => self::floorMod($int($node[1]), $int($node[2])),
            'pow' => self::power($int($node[1]), $int($node[2])),
            'from_float' => self::floatToInt(self::float($node[1], $context)),
            'uniform' => self::nextInt($context, $int($node[1]), $int($node[2])),
            'binomial' => self::binomial($int($node[1]), self::finiteFloat(self::float($node[2], $context)), $context),
            'weighted_list' => $int(self::weighted($node[1], $context)),
            'conditional' => $int(($context['condition'])($node[1]) ? $node[2] : $node[3]),
            'number_dispatcher' => $int(self::dispatch($node, $context)),
            'score' => self::score($node, $context) ?? $int($node[3]),
            'storage' => ($stored = ($context['storage'])($node[1], $node[2])) !== null ? self::javaInt($stored) : $int($node[3]),
        };
    }

    private static function float(array $node, array $context): float
    {
        $float = static fn (array $child): float => self::float($child, $context);
        $f = static fn (float $value): float => Float32::round($value);
        return match ($node[0]) {
            'constant' => $node[1],
            'abs' => abs($float($node[1])),
            'negate' => -$float($node[1]),
            'ceil' => (float) self::ceil($float($node[1])),
            'floor' => (float) floor($float($node[1])),
            'truncate' => ($value = $float($node[1])) > 0.0 ? floor($value) : ceil($value),
            // Math.round(float): the nearest int, halves rounding up.
            'round' => (float) self::saturate(floor(Float32::round($float($node[1]) + 0.5))),
            'sin' => Trees::mthSin($float($node[1])),
            'cos' => Trees::mthCos($float($node[1])),
            'sqrt' => $f(sqrt($float($node[1]))),
            'sub' => $f($float($node[1]) - $float($node[2])),
            'add' => array_reduce($node[1], static fn (float $carry, array $child): float => $f($carry + $float($child)), 0.0),
            'mul' => array_reduce($node[1], static fn (float $carry, array $child): float => $f($carry * $float($child)), 1.0),
            'max' => array_reduce($node[1], static fn (float $carry, array $child): float => max($carry, $float($child)), -3.4028234663852886E+38),
            'min' => array_reduce($node[1], static fn (float $carry, array $child): float => min($carry, $float($child)), 3.4028234663852886E+38),
            'avg' => self::average($node[1], $context),
            'length' => $f(sqrt(array_reduce($node[1], static fn (float $carry, array $child): float => $f($carry + $f(($value = $float($child)) * $value)), 0.0))),
            'div' => $f(fdiv($float($node[1]), $float($node[2]))),
            'mod' => ($right = $float($node[2])) === 0.0 ? NAN : $f(fmod($float($node[1]), $right)),
            'pow' => self::floatPower($float($node[1]), $float($node[2])),
            'from_int' => (float) self::int($node[1], $context),
            'uniform' => self::nextFloat($context, $float($node[1]), $float($node[2])),
            'weighted_list' => $float(self::weighted($node[1], $context)),
            'conditional' => $float(($context['condition'])($node[1]) ? $node[2] : $node[3]),
            'number_dispatcher' => $float(self::dispatch($node, $context)),
            // No enchantment is in a command's context, so the level is 0.
            'enchantment_level' => self::levelBased($node[1], 0),
            'storage' => ($stored = ($context['storage'])($node[1], $node[2])) !== null ? $f((float) $stored) : $float($node[3]),
        };
    }

    private static function floatPower(float $base, float $exponent): float
    {
        return $base === 0.0 && $exponent === 0.0 ? NAN : Float32::round($base ** $exponent);
    }

    private static function average(array $inputs, array $context): float
    {
        $sum = 0.0;
        foreach ($inputs as $input) {
            $sum = Float32::round($sum + self::float($input, $context));
        }
        return Float32::round(fdiv($sum, (float) count($inputs)));
    }

    /** LevelBasedValue.calculate for the level: a constant, or linear, clamped, fraction, levels_squared or lookup value. */
    private static function levelBased(array $tag, int $level): float
    {
        if ($tag['type'] !== 'compound') {
            return Float32::round((float) self::number($tag));
        }
        $fields = $tag['value'];
        $type = preg_replace('/^minecraft:/', '', (string) ($fields['type']['value'] ?? ''));
        $value = static fn (string $name): float => self::levelBased($fields[$name], $level);
        return Float32::round(match ($type) {
            'linear' => $value('base') + $value('per_level_above_first') * ($level - 1),
            'levels_squared' => $level * $level + $value('added'),
            'clamped' => max($value('min'), min($value('max'), $value('value'))),
            'fraction' => fdiv($value('numerator'), $value('denominator')),
            'lookup' => isset($fields['values']['value'][$level - 1]) ? self::levelBased($fields['values']['value'][$level - 1], $level) : $value('fallback'),
            default => 0.0,
        });
    }

    private static function score(array $node, array $context): ?int
    {
        $holder = $node[1][0] === 'fixed' ? $node[1][1] : ($context['holders'][$node[1][1]] ?? null);
        return $holder === null ? null : ($context['score'])($holder, $node[2]);
    }

    private static function dispatch(array $node, array $context): array
    {
        foreach ($node[1] as [$condition, $value]) {
            if (($context['condition'])($condition)) {
                return $value;
            }
        }
        return $node[2];
    }

    /** WeightedList.getRandomOrThrow: a weight drawn below the total picks its entry. */
    private static function weighted(array $entries, array $context): array
    {
        $total = array_sum(array_column($entries, 1));
        $pick = ($context['randomInt'])($total);
        foreach ($entries as [$value, $weight]) {
            $pick -= $weight;
            if ($pick < 0) {
                return $value;
            }
        }
        return $entries[array_key_last($entries)][0];
    }

    private static function binomial(int $n, float $p, array $context): int
    {
        $result = 0;
        for ($i = 0; $i < $n; ++$i) {
            if (Float32::round(($context['random'])()) < $p) {
                ++$result;
            }
        }
        return $result;
    }

    /** Mth.nextInt: the minimum when the range is empty. */
    private static function nextInt(array $context, int $min, int $max): int
    {
        return $min >= $max ? $min : ($context['randomInt'])($max - $min + 1) + $min;
    }

    /** Mth.nextFloat: the minimum when the range is empty. */
    private static function nextFloat(array $context, float $min, float $max): float
    {
        return $min >= $max ? $min : Float32::round(Float32::round(($context['random'])()) * ($max - $min) + $min);
    }

    /** ContextFloatProvider.getFloatOrThrow: a finite value. */
    private static function finiteFloat(float $value): float
    {
        return is_finite($value) ? $value : throw new \ArithmeticError('Value ' . self::floatText($value) . ' is not finite');
    }

    private static function exact(int $value): int
    {
        return $value < -2147483648 || $value > 2147483647 ? throw new \ArithmeticError('integer overflow') : $value;
    }

    private static function divide(int $left, int $right): int
    {
        if ($right === 0) {
            throw new \ArithmeticError('/ by zero');
        }
        // Integer.MIN_VALUE / -1 overflows back to itself in Java.
        return $left === -2147483648 && $right === -1 ? $left : intdiv($left, $right);
    }

    private static function floorDivide(int $left, int $right): int
    {
        if ($right === 0) {
            throw new \ArithmeticError('/ by zero');
        }
        $quotient = intdiv($left, $right);
        if ($left % $right !== 0 && (($left < 0) !== ($right < 0))) {
            --$quotient;
        }
        return self::exact($quotient);
    }

    private static function floorMod(int $left, int $right): int
    {
        if ($right === 0) {
            throw new \ArithmeticError('/ by zero');
        }
        $mod = $left % $right;
        return $mod !== 0 && (($mod < 0) !== ($right < 0)) ? $mod + $right : $mod;
    }

    /** Math.powExact for ints. */
    private static function power(int $base, int $exponent): int
    {
        if ($base === 0 && $exponent === 0) {
            throw new \ArithmeticError('Result of 0 to the power of 0 is undefined');
        }
        if ($exponent < 0) {
            throw new \ArithmeticError('negative exponent');
        }
        $result = 1;
        for ($i = 0; $i < $exponent; ++$i) {
            $result = self::exact($result * $base);
            if ($result === 0 || $result === 1) {
                break;
            }
        }
        return $result;
    }

    /** ContextIntProvider.longToIntSafe. */
    private static function longToInt(int|float $value): int
    {
        if (is_float($value) || $value < -2147483648 || $value > 2147483647) {
            throw new \ArithmeticError('Value ' . (is_float($value) ? number_format($value, 0, '.', '') : $value) . " can't be safely converted to int");
        }
        return $value;
    }

    /** ContextIntProvider.floatToIntSafe. */
    private static function floatToInt(float $value): int
    {
        if (!is_finite($value)) {
            throw new \ArithmeticError('Value ' . self::floatText($value) . " can't be safely converted to int");
        }
        return self::longToInt((int) ($value < 0 ? ceil($value) : floor($value)));
    }

    /** Mth.ceil(float). */
    private static function ceil(float $value): int
    {
        return self::saturate(ceil($value));
    }

    /** A float cast to int, saturating at the int range as Java does. */
    public static function saturate(float $value): int
    {
        return is_nan($value) ? 0 : (int) max(-2147483648.0, min(2147483647.0, $value));
    }

    /** Number.intValue for a stored number. */
    private static function javaInt(int|float $value): int
    {
        if (is_float($value)) {
            return self::saturate($value < 0 ? ceil($value) : floor($value));
        }
        return (($value + 2147483648) & 0xFFFFFFFF) - 2147483648;
    }

    /** Float.toString. */
    public static function floatText(float $value): string
    {
        return match (true) {
            is_nan($value) => 'NaN',
            $value === INF => 'Infinity',
            $value === -INF => '-Infinity',
            default => (string) $value,
        };
    }
}
