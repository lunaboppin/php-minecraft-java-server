<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait HelpCommand
{
    private function executeHelp(Client $sender, array $arguments): void
    {
        $this->tell($sender, 'Commands: /' . implode(', /', $this->commandNames($sender)));
        return;
    }
}
