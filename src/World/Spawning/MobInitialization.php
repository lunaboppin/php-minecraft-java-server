<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Generation\RandomSource;

final class MobInitialization
{
    /** Return handedness after the native shared initialization draws. */
    public static function initialize(SpawnAttributes $attributes, RandomSource $worldRandom): bool
    {
        if (!$attributes->hasModifier('follow_range', 'minecraft:random_spawn_bonus')) {
            $amount = 0.0 + 0.11485000000000001 * ($worldRandom->nextDouble() - $worldRandom->nextDouble());
            $attributes->add('follow_range', 'minecraft:random_spawn_bonus', $amount, 1);
        }
        return $worldRandom->nextFloat() < Float32::round(0.05);
    }
}
