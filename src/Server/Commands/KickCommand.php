<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Client;

trait KickCommand
{
    private function executeKick(Client $sender, array $arguments, string $text = ''): void
    {
        if ($arguments === [] || $arguments[0] === '') {
            throw new \InvalidArgumentException('Usage: /kick <targets> [reason]');
        }
        // MessageArgument takes the rest of the input verbatim after one separating space.
        $parts = explode(' ', ltrim($text), 3);
        $reason = isset($parts[2]) ? ['text' => $parts[2]] : ['translate' => 'multiplayer.disconnect.kicked'];
        foreach ($this->selectPlayers($arguments[0], $sender) as $target) {
            // Like vanilla, the leave message follows once the connection closes, normally after the
            // report below (vanilla can rarely reverse the two if Netty closes the channel first).
            $this->disconnect($target, $reason);
            $this->sendSuccess($sender, ['translate' => 'commands.kick.success', 'with' => [$this->displayName($target), $reason]], true);
        }
    }
}
