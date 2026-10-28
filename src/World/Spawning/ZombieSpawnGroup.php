<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Generation\RandomSource;

/** Group decisions occur after shared Mob initialization and before equipment. */
final class ZombieSpawnGroup
{
    /** World effects run before the native door-breaking roll and equipment. */
    public static function initialize(
        RandomSource $worldRandom,
        ?SpawnGroupData $group,
        float $special,
        string $reason,
        bool $baby,
        bool $pickupLoot,
        ZombieSpawnEnvironment $environment
    ): array {
        $special = Float32::round($special);
        if ($reason !== 'conversion') {
            $pickupLoot = $worldRandom->nextFloat() < Float32::round(Float32::round(0.55) * $special);
        }
        $group ??= new ZombieGroupData($worldRandom->nextFloat() < Float32::round(0.05), true);
        $vehicle = null;
        $source = null;
        $breakDoors = null;
        if ($group instanceof ZombieGroupData) {
            if ($group->baby) {
                $baby = true;
                $environment->makeBaby();
                if ($group->canSpawnJockey) {
                    if ($worldRandom->nextFloat() < 0.05) {
                        $chickens = $environment->findChickens();
                        if ($chickens !== []) {
                            $source = 'existing';
                            $vehicle = $environment->rideChicken($chickens[0]) ? $chickens[0] : null;
                        }
                    } elseif ($worldRandom->nextFloat() < 0.05) {
                        $chicken = $environment->createChicken();
                        if ($chicken !== null) {
                            $source = 'new';
                            $vehicle = $environment->rideChicken($chicken) ? $chicken : null;
                            $environment->addChicken($chicken);
                        }
                    }
                }
            }
            $breakDoors = $worldRandom->nextFloat() < Float32::round($special * Float32::round(0.1));
        }
        return [
            'group' => $group,
            'baby' => $baby,
            'pickupLoot' => $pickupLoot,
            'vehicle' => $vehicle,
            'source' => $source,
            'breakDoors' => $breakDoors,
        ];
    }
}
