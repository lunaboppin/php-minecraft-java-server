<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait MeCommand
{
    private function executeMe(Client $sender, array $arguments): void
    {
        $this->broadcast('* ' . $sender->name . ' ' . implode(' ', $arguments));
        return;
    }
}
