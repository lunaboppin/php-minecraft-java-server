<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Generation\Int64;
use PhpMinecraftJavaServer\World\Generation\RandomSource;

/** Native enchanting candidates and weighted selection, with explicit float boundaries. */
final class EnchantmentSelection
{
    /** @return list<array{name: string, level: int}> */
    public static function available(
        int $cost,
        array $item,
        array $pool,
        array $reference
    ): array {
        $available = [];
        foreach ($pool as $name) {
            if (!$item['book'] && !in_array($name, $item['primary'], true)) {
                continue;
            }
            $definition = $reference['enchantments'][$name];
            for ($level = $definition['max_level']; $level >= $definition['min_level']; --$level) {
                [$minimum, $maximum] = $definition['costs'][$level];
                if ($cost >= $minimum && $cost <= $maximum) {
                    $available[] = ['name' => $name, 'level' => $level];
                    break;
                }
            }
        }
        return $available;
    }

    /** @return list<array{name: string, level: int}> */
    public static function select(
        RandomSource $random,
        array $item,
        int $cost,
        array $pool,
        array $reference
    ): array {
        if ($item['enchantability'] === null) {
            return [];
        }
        $bound = intdiv($item['enchantability'], 4) + 1;
        $cost = Int64::signed32($cost + 1 + $random->nextInt($bound) + $random->nextInt($bound));
        $sampleSum = Float32::round($random->nextFloat() + $random->nextFloat());
        $centeredSample = Float32::round($sampleSum - 1.0);
        $spread = Float32::round($centeredSample * Float32::round(0.15));
        $floatingCost = Float32::round($cost);
        $costAdjustment = Float32::round($floatingCost * $spread);
        $adjustedCost = Float32::round($floatingCost + $costAdjustment);
        $cost = max(1, self::roundToInt($adjustedCost));
        $available = self::available($cost, $item, $pool, $reference);
        if ($available === []) {
            return [];
        }
        $selected = [];
        $choice = self::choose($random, $available, $reference);
        if ($choice !== null) {
            $selected[] = $choice;
        }
        while ($random->nextInt(50) <= $cost) {
            if ($selected !== []) {
                $last = $selected[array_key_last($selected)]['name'];
                $available = array_values(array_filter(
                    $available,
                    static fn (array $candidate): bool => $reference['compatibility'][$last][$candidate['name']]
                ));
            }
            if ($available === []) {
                break;
            }
            $choice = self::choose($random, $available, $reference);
            if ($choice !== null) {
                $selected[] = $choice;
            }
            $cost = intdiv($cost, 2);
        }
        return $selected;
    }

    private static function choose(RandomSource $random, array $available, array $reference): ?array
    {
        $total = 0;
        foreach ($available as $candidate) {
            $total = Int64::signed32($total + $reference['enchantments'][$candidate['name']]['weight']);
        }
        if ($total < 0) {
            throw new \InvalidArgumentException('Negative total enchantment weight');
        }
        if ($total === 0) {
            return null;
        }
        $roll = $random->nextInt($total);
        foreach ($available as $candidate) {
            $roll -= $reference['enchantments'][$candidate['name']]['weight'];
            if ($roll < 0) {
                return $candidate;
            }
        }
        return null;
    }

    private static function roundToInt(float $value): int
    {
        if (is_nan($value)) {
            return 0;
        }
        if ($value >= 2147483647.0) {
            return 2147483647;
        }
        if ($value <= -2147483648.0) {
            return -2147483648;
        }
        return (int) floor($value + 0.5);
    }
}
