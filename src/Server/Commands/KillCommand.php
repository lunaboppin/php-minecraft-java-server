<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait KillCommand
{
    private function executeKill(Client $sender, array $arguments): void
    {
        $target = isset($arguments[0]) ? $this->playerByName($arguments[0], $sender) : $sender;
        $this->damage($target, 1000, 'was killed', true);
        return;
    }
}
