<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SaveOffCommand
{
    private function executeSaveOff(Client $sender, array $arguments): void
    {
        if (!$this->autoSave) {
            throw new CommandFailure(['translate' => 'commands.save.alreadyOff']);
        }
        // Periodic saves stop. Block edits are still written as they happen, unlike vanilla's
        // chunk saving (transactional storage is #7), and shutdown always saves, as in vanilla.
        $this->autoSave = false;
        $this->sendSuccess($sender, ['translate' => 'commands.save.disabled'], true);
    }
}
