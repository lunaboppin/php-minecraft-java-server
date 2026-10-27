<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

use PhpMinecraftJavaServer\World\Generation\RandomSource;

final class ZombifiedPiglinEquipmentInitialization implements EquipmentInitializer
{
    public static function populate(
        array &$equipment,
        RandomSource $random,
        int $worldDifficulty,
        float $special,
        array $reference
    ): void {
        $weapon = $random->nextInt(20) === 0 ? 'golden_spear' : 'golden_sword';
        $equipment[$reference['slots']['mainhand']] = ['item' => $reference['items'][$weapon], 'count' => 1];
    }
}
