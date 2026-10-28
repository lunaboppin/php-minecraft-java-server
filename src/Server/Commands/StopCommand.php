<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait StopCommand
{
    private function executeStop(Client $sender, array $arguments): void
    {
        $this->sendSuccess($sender, ['translate' => 'commands.stop.stopping'], true);
        // MinecraftServer.halt(false): finish the tick, save, and disconnect everyone.
        $this->stopReason = ['translate' => 'multiplayer.disconnect.server_shutdown'];
        $this->stop();
    }
}
