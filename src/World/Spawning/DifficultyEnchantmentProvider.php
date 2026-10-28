<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Generation\RandomSource;

/** The native difficulty-scaled cost provider used by ordinary mob equipment. */
final class DifficultyEnchantmentProvider
{
    public static function apply(
        array &$stack,
        array $item,
        array $provider,
        RandomSource $random,
        float $special,
        array $reference
    ): void {
        if (!$item['can_store']) {
            return;
        }
        $scaled = Float32::round(Float32::round($special) * Float32::round($provider['max_cost_span']));
        $span = is_nan($scaled) ? 0 : (int) max(-2147483648.0, min(2147483647.0, $scaled));
        $cost = $provider['min_cost'] + $random->nextInt($span + 1);
        $component = $item['stored_book'] ? 'storedEnchantments' : 'enchantments';
        $enchantments = $stack[$component] ?? [];
        $selectedEnchantments = EnchantmentSelection::select(
            $random,
            $item,
            $cost,
            $provider['pool'],
            $reference
        );
        foreach ($selectedEnchantments as $selected) {
            $name = $selected['name'];
            $enchantments[$name] = max($enchantments[$name] ?? 0, min(255, $selected['level']));
        }
        if ($enchantments === []) {
            unset($stack[$component]);
        } else {
            $stack[$component] = $enchantments;
        }
    }
}
