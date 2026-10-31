<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait ReloadCommand
{
    /** ReloadCommand: the server's only data pack, the built-in one, reloads unchanged. */
    private function executeReload(Client $sender, array $arguments): void
    {
        $this->sendSuccess($sender, ['translate' => 'commands.reload.success'], true);
    }
}
