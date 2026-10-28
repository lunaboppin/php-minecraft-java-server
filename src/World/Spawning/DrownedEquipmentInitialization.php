<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

use PhpMinecraftJavaServer\World\Generation\RandomSource;

/** Drowned use their own weapon draw instead of the common zombie armor phase. */
final class DrownedEquipmentInitialization implements EquipmentInitializer
{
    public static function populate(
        array &$equipment,
        RandomSource $random,
        int $worldDifficulty,
        float $special,
        array $reference
    ): void {
        if ($random->nextFloat() > 0.9) {
            $weapon = $random->nextInt(16) < 10 ? 'trident' : 'fishing_rod';
            $equipment[$reference['slots']['mainhand']] = ['item' => $reference['items'][$weapon], 'count' => 1];
        }
    }
}
