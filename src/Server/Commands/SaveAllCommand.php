<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SaveAllCommand
{
    private function executeSaveAll(Client $sender, array $arguments): void
    {
        // "flush" also waits for storage in vanilla; every save here is already synchronous.
        if ($arguments !== [] && $arguments !== [''] && $arguments !== ['flush']) {
            throw new \InvalidArgumentException('Usage: /save-all [flush]');
        }
        $this->sendSuccess($sender, ['translate' => 'commands.save.saving'], false);
        try {
            $this->saveWorld();
        } catch (\Throwable $error) {
            $this->log('Save failed: ' . $error->getMessage());
            throw new CommandFailure(['translate' => 'commands.save.failed']);
        }
        $this->sendSuccess($sender, ['translate' => 'commands.save.success'], true);
    }
}
