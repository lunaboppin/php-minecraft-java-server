<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait MessageCommand
{
    /**
     * MsgCommand: for each target, the sender sees msg_command_outgoing naming the target and
     * the target sees msg_command_incoming.
     */
    private function executeMessage(Client $sender, array $arguments, string $text): void
    {
        $targets = $this->selectPlayers($arguments[0], $sender);
        $content = $this->messageComponent($sender, CommandArguments::rest($text, 1));
        foreach ($targets as $target) {
            $this->log('[' . $sender->name . ' -> ' . $target->name . '] ' . CommandArguments::rest($text, 1));
            $this->sendDisguisedChat($sender, $content, 'msg_command_outgoing', $this->displayName($sender), $this->displayName($target));
            $this->sendDisguisedChat($target, $content, 'msg_command_incoming', $this->displayName($sender));
        }
    }
}
