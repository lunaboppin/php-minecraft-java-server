<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Generation\RandomSource;

/** Pure voice/range arithmetic compared with the pinned server's actual getters. */
final class MobAudio
{
    public static function variant(array $info, RandomSource $random): ?string
    {
        $variants = array_keys($info['variants'] ?? []);
        return $variants === [] ? null : $variants[$random->nextInt(count($variants))];
    }

    public static function pitch(RandomSource $random, string $method, bool $baby = false): float
    {
        if ($method === 'HappyGhast') {
            return 1.0;
        }
        if (!in_array($method, ['LivingEntity', 'Bat', 'Parrot', 'ZombieVillager'], true)) {
            throw new \InvalidArgumentException('Unknown mob voice method');
        }
        $base = 1.0;
        if ($baby && $method !== 'Parrot') {
            $base = $method === 'ZombieVillager' ? 2.0 : 1.5;
        }
        $difference = Float32::round($random->nextFloat() - $random->nextFloat());
        $pitch = Float32::round(Float32::round($difference * Float32::round(0.2)) + $base);
        return $method === 'Bat' ? Float32::round($pitch * Float32::round(0.95)) : $pitch;
    }

    public static function range(array $sound, float $volume): float
    {
        return $sound['fixed_range'] ?? ($volume > 1 ? Float32::round($volume * 16) : 16.0);
    }
}
