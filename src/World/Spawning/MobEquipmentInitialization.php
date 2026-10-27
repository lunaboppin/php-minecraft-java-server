<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Generation\RandomSource;

/** Native common armor selection; enchanting is a subsequent phase. */
final class MobEquipmentInitialization implements EquipmentInitializer
{
    public static function populate(
        array &$equipment,
        RandomSource $random,
        int $worldDifficulty,
        float $special,
        array $reference
    ): void {
        $chance = Float32::round(Float32::round(0.15) * Float32::round($special));
        if (!($random->nextFloat() < $chance)) {
            return;
        }
        $tier = $random->nextInt(3);
        for ($roll = 0; $roll < 3; ++$roll) {
            if ($random->nextFloat() < Float32::round(0.1087)) {
                ++$tier;
            }
        }
        $stopChance = Float32::round($worldDifficulty === 3 ? 0.1 : 0.25);
        $first = true;
        foreach ($reference['population_order'] as $slot) {
            if (!$first && $random->nextFloat() < $stopChance) {
                break;
            }
            $first = false;
            $stack = $equipment[$slot] ?? null;
            $empty = $stack === null || $stack['count'] <= 0 || $stack['item'] === $reference['items']['air'];
            if ($empty) {
                $item = $reference['armor'][$tier][$slot] ?? null;
                if ($item !== null) {
                    $equipment[$slot] = ['item' => $item, 'count' => 1];
                }
            }
        }
    }
}
