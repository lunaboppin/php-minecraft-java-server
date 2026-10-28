<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

/** Native zombie spawn-group choices are shared, immutable group data. */
class ZombieGroupData implements SpawnGroupData
{
    public function __construct(public readonly bool $baby, public readonly bool $canSpawnJockey)
    {
    }
}
