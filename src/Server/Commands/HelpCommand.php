<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait HelpCommand
{
    /** HelpCommand: brigadier's smart usage of the command tree this player was sent, one line each. */
    private function executeHelp(Client $sender, array $arguments, string $text = ''): void
    {
        $command = trim(substr(ltrim($text), 4));
        $usages = $this->commandTree($sender)->usage($command === '' ? [] : explode(' ', $command));
        if ($usages === null) {
            throw new CommandFailure(['translate' => 'commands.help.failed']);
        }
        foreach ($usages as $usage) {
            $this->sendSuccess($sender, ['text' => '/' . ($command === '' ? '' : $command . ' ') . $usage], false);
        }
    }
}
