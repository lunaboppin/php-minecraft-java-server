<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait ReturnCommand
{
    /**
     * ReturnCommand: outside a function a returned value or failure goes nowhere and says nothing;
     * return run still runs its command.
     */
    private function executeReturn(Client $sender, array $arguments, string $text): void
    {
        if (($arguments[0] ?? '') === 'run') {
            $this->runCommandText($sender, (string) preg_replace('/\A\/?\s*return\s+run\s+/', '', $text));
        }
    }
}
