<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Server\Client;

trait TransferCommand
{
    /** TransferCommand: send players a ClientboundTransferPacket to another server. */
    private function executeTransfer(Client $sender, array $arguments, string $text = ''): void
    {
        // The hostname is a quoted or unquoted string argument, read from the verbatim text.
        $reader = new StringReader(ltrim($text));
        $reader->readUnquotedString();
        $reader->skipWhitespace();
        $hostname = $reader->readString();
        $rest = CommandArguments::parse('transfer ' . substr($reader->string, $reader->cursor))[1];
        $rest = $rest === [''] ? [] : $rest;
        $port = isset($rest[0]) ? (int) $rest[0] : 25565;
        $players = isset($rest[1]) ? $this->selectPlayers($rest[1], $sender) : [$sender];
        foreach ($players as $player) {
            $player->send($this->version, 'transfer', Binary::writeString($hostname) . Binary::writeVarInt($port));
        }
        if ($players === []) {
            throw new CommandFailure(['translate' => 'commands.transfer.error.no_players']);
        }
        $this->sendSuccess($sender, count($players) === 1 ? ['translate' => 'commands.transfer.success.single', 'with' => [$this->displayName($players[0]), ['text' => $hostname], $port]]
            : ['translate' => 'commands.transfer.success.multiple', 'with' => [count($players), ['text' => $hostname], $port]], true);
    }
}
