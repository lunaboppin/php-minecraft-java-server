<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Pushing;

use PhpMinecraftJavaServer\World\LivingEntityPushPhase;

final class WorldPushContext
{
    public readonly LivingEntityPushPhase $phase;

    /** @var array<int, string> */
    public array $members = [];

    public function __construct()
    {
        $this->phase = new LivingEntityPushPhase();
    }
}
