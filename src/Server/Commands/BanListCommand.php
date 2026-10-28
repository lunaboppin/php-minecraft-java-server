<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait BanListCommand
{
    /** BanListCommands: the player bans (IP bans are not supported, so that list is empty). */
    private function executeBanList(Client $sender, array $arguments): void
    {
        $entries = ($arguments[0] ?? 'players') === 'ips' ? [] : $this->orderedEntries('banned-players.json');
        if ($entries === []) {
            $this->sendSuccess($sender, ['translate' => 'commands.banlist.none'], false);
            return;
        }
        $this->sendSuccess($sender, ['translate' => 'commands.banlist.list', 'with' => [count($entries)]], false);
        foreach ($entries as $entry) {
            $this->sendSuccess($sender, ['translate' => 'commands.banlist.entry', 'with' => [['text' => $entry['name']], ['text' => $entry['source']], self::banReason($entry)]], false);
        }
    }
}
