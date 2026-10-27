<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Generation\RandomSource;

/** The shared zombie, husk and zombie-villager equipment method. */
final class ZombieEquipmentInitialization implements EquipmentInitializer
{
    public static function populate(
        array &$equipment,
        RandomSource $random,
        int $worldDifficulty,
        float $special,
        array $reference
    ): void {
        MobEquipmentInitialization::populate($equipment, $random, $worldDifficulty, $special, $reference);
        $chance = Float32::round($worldDifficulty === 3 ? 0.05 : 0.01);
        if ($random->nextFloat() < $chance) {
            $weapon = match ($random->nextInt(6)) {
                0 => 'iron_sword',
                1 => 'iron_spear',
                default => 'iron_shovel',
            };
            $equipment[$reference['slots']['mainhand']] = ['item' => $reference['items'][$weapon], 'count' => 1];
        }
    }
}
