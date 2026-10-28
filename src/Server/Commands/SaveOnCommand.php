<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SaveOnCommand
{
    private function executeSaveOn(Client $sender, array $arguments): void
    {
        if ($this->autoSave) {
            throw new CommandFailure(['translate' => 'commands.save.alreadyOn']);
        }
        $this->autoSave = true;
        $this->sendSuccess($sender, ['translate' => 'commands.save.enabled'], true);
    }
}
