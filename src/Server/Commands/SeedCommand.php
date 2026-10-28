<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SeedCommand
{
    private function executeSeed(Client $sender, array $arguments): void
    {
        if ($arguments !== [] && $arguments !== ['']) {
            throw new \InvalidArgumentException('Usage: /seed');
        }
        // ComponentUtils.copyOnClickText: a green, bracketed value that copies itself when clicked.
        $seed = (string) $this->world->seed;
        $copyable = ['translate' => 'chat.square_brackets', 'with' => [[
            'text' => $seed,
            'color' => 'green',
            'click_event' => ['action' => 'copy_to_clipboard', 'value' => $seed],
            'hover_event' => ['action' => 'show_text', 'value' => ['translate' => 'chat.copy.click']],
            'insertion' => $seed,
        ]]];
        $this->sendSuccess($sender, ['translate' => 'commands.seed.success', 'with' => [$copyable]], false);
    }
}
