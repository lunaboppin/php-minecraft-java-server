<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SetIdleTimeoutCommand
{
    /** SetPlayerIdleTimeoutCommand: minutes before idle players are kicked, or 0 for never. */
    private function executeSetIdleTimeout(Client $sender, array $arguments): void
    {
        $this->playerIdleTimeout = (int) $arguments[0];
        $this->sendSuccess($sender, $this->playerIdleTimeout > 0
            ? ['translate' => 'commands.setidletimeout.success', 'with' => [$this->playerIdleTimeout]]
            : ['translate' => 'commands.setidletimeout.success.disabled'], true);
    }
}
