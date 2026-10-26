<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SpawnCommand
{
    private function executeSpawn(Client $sender, array $arguments): void
    {
        $this->teleport($sender);
        $this->tell($sender, 'Returned to spawn.');
        return;
    }
}
