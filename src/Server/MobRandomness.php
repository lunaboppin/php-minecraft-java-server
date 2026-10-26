<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Generation\LegacyRandom;

/** One entity-local stream for movement, knockback and voice randomness. */
trait MobRandomness
{
    private array $mobRandomSources = [];

    private function mobRandom(int $id): LegacyRandom
    {
        return $this->mobRandomSources[$id] ??= new LegacyRandom(unpack('J', random_bytes(8))[1]);
    }
}
