<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SeedCommand
{
    private function executeSeed(Client $sender, array $arguments): void
    {
        $this->tell($sender, 'Seed: ' . $this->world->seed . ' (' . $this->world->generator . ')');
        return;
    }
}
