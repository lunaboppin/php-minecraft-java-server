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
            // An already dead player is counted without a second death message.
            $this->damage($target, 1000, ['translate' => 'death.attack.genericKill', 'with' => [Packets::playerName($target->name, $target->uuid)]], true);
        }
        $message = count($targets) === 1
            ? ['translate' => 'commands.kill.success.single', 'with' => [Packets::playerName($targets[0]->name, $targets[0]->uuid)]]
            : ['translate' => 'commands.kill.success.multiple', 'with' => [count($targets)]];
        $this->sendSuccess($sender, $message, true);
    }
}
