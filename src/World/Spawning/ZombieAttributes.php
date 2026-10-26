<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Generation\RandomSource;

/** The attribute phase runs after group, mount and equipment initialization. */
final class ZombieAttributes
{
    public static function age(SpawnAttributes $attributes, bool $baby): void
    {
        $attributes->remove('movement_speed', 'minecraft:baby');
        if ($baby) {
            $attributes->add('movement_speed', 'minecraft:baby', 0.5, 1, false);
        }
    }

    /** Return whether this call rolled the leader branch, independently of navigation support. */
    public static function initialize(
        SpawnAttributes $attributes,
        RandomSource $entityRandom,
        string $kind,
        float $special,
        string $reason,
        float &$health
    ): bool {
        $special = Float32::round($special);
        $reinforcements = $kind === 'zombified_piglin' ? 0.0 : $entityRandom->nextDouble() * 0.10000000149011612;
        $attributes->setBase('spawn_reinforcements', $reinforcements);
        $attributes->replace('knockback_resistance', 'minecraft:random_spawn_bonus', $entityRandom->nextDouble() * 0.05000000074505806, 0);
        $followBonus = $entityRandom->nextDouble() * 1.5 * $special;
        if ($followBonus > 1.0) {
            $attributes->replace('follow_range', 'minecraft:zombie_random_spawn_bonus', $followBonus, 2);
        }
        $leader = $entityRandom->nextFloat() < Float32::round($special * Float32::round(0.05));
        if ($leader) {
            $attributes->replace('spawn_reinforcements', 'minecraft:leader_zombie_bonus', $entityRandom->nextDouble() * 0.25 + 0.5, 0);
            $attributes->replace('max_health', 'minecraft:leader_zombie_bonus', $entityRandom->nextDouble() * 3.0 + 1.0, 2);
            if (!in_array($reason, ['conversion', 'load', 'dimension_travel'], true)) {
                $health = Float32::round($attributes->value('max_health'));
            }
        }
        return $leader;
    }
}
