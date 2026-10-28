<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Client;

trait KillCommand
{
    private function executeKill(Client $sender, array $arguments): void
    {
        $targets = isset($arguments[0]) ? $this->selectPlayers($arguments[0], $sender) : [$sender];
        foreach ($targets as $target) {
            // Entity.kill: generic_kill bypasses invulnerability; a dead player is still counted.
            $this->damage($target, 3.4028234663852886E38, 'generic_kill');
        }
        $message = count($targets) === 1
            ? ['translate' => 'commands.kill.success.single', 'with' => [Packets::playerName($targets[0]->name, $targets[0]->uuid)]]
            : ['translate' => 'commands.kill.success.multiple', 'with' => [count($targets)]];
        $this->sendSuccess($sender, $message, true);
    }
}
