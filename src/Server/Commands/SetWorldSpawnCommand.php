<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;
use PhpMinecraftJavaServer\Protocol\Packets;

trait SetWorldSpawnCommand
{
    private function executeSetWorldSpawn(Client $sender, array $arguments): void
    {
        if ($sender->dimension !== 'overworld') {
            throw new \InvalidArgumentException('World spawn requires the Overworld');
        }
        $position = $arguments === [] ? [$sender->x, $sender->y, $sender->z] : $this->coordinates($arguments, $sender);
        $this->world->setting('spawn', $position);
        foreach ($this->players() as $p) {
            $p->send($this->version, 'set_default_spawn_position', Packets::spawn($position));
        }
        $this->tell($sender, 'World spawn updated');
        return;
    }
}
