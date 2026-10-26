<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait HealCommand
{
    private function executeHeal(Client $sender, array $arguments): void
    {
        $target = isset($arguments[0]) ? $this->playerByName($arguments[0], $sender) : $sender;
        if ($target->dead) {
            $this->respawnPlayer($target);
        }
        $target->health = 20;
        $target->food = 20;
        $target->saturation = 20;
        $this->health($target);
        $this->tell($sender, 'Healed ' . $target->name);
        return;
    }
}
