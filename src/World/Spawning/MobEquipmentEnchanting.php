<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Generation\RandomSource;

/** Shared mob enchanting; metadata describes each stack's current components. */
final class MobEquipmentEnchanting
{
    public static function apply(
        array &$equipment,
        array $metadataBySlot,
        RandomSource $random,
        float $special,
        array $reference
    ): void {
        $special = Float32::round($special);
        foreach ($reference['mob_slots'] as $entry) {
            $slot = $entry['slot'];
            $stack = $equipment[$slot] ?? null;
            if ($stack === null || $stack['count'] <= 0 || $stack['item'] === $reference['air_item']) {
                continue;
            }
            $chance = Float32::round(Float32::round($entry['chance']) * $special);
            if ($random->nextFloat() < $chance) {
                $metadata = $metadataBySlot[$slot] ?? throw new \InvalidArgumentException('Missing current item component metadata');
                DifficultyEnchantmentProvider::apply(
                    $equipment[$slot],
                    $metadata,
                    $reference['provider'],
                    $random,
                    $special,
                    $reference
                );
            }
        }
    }
}
