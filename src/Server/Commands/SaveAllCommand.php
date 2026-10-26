<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SaveAllCommand
{
    private function executeSaveAll(Client $sender, array $arguments): void
    {
        $this->saveWorld();
        $this->tell($sender, 'Saved world and player data');
        return;
    }
}
