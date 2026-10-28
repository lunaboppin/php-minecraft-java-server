<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

use PhpMinecraftJavaServer\World\Generation\RandomSource;

/** Species equipment methods share the native phase inputs. */
interface EquipmentInitializer
{
    /** @param array<int, array{item: int, count: int, damage?: int}|null> $equipment */
    public static function populate(
        array &$equipment,
        RandomSource $random,
        int $worldDifficulty,
        float $special,
        array $reference
    ): void;
}
