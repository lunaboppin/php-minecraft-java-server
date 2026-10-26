<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SpawnPointCommand
{
    private function executeSpawnPoint(Client $sender, array $arguments): void
    {
        if ($sender->dimension !== 'overworld') {
            throw new \InvalidArgumentException('Personal spawn points require the Overworld');
        }
        $sender->personalSpawn = $arguments === [] ? [$sender->x, $sender->y, $sender->z] : $this->coordinates($arguments, $sender);
        $this->savePlayer($sender);
        $this->tell($sender, 'Respawn point updated');
        return;
    }
}
