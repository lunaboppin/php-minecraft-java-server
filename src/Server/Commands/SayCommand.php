<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SayCommand
{
    /** SayCommand: the message to everyone as say_command chat from the sender. */
    private function executeSay(Client $sender, array $arguments, string $text): void
    {
        $content = $this->messageComponent($sender, CommandArguments::rest($text, 0));
        $this->log('[' . $sender->name . '] ' . CommandArguments::rest($text, 0));
        foreach ($this->players() as $player) {
            $this->sendDisguisedChat($player, $content, 'say_command', $this->displayName($sender));
        }
    }
}
