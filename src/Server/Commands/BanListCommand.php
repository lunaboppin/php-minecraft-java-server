<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait BanListCommand
{
    /** BanListCommands: player bans, IP bans, or both in that order. */
    private function executeBanList(Client $sender, array $arguments): void
    {
        $ips = array_map(static fn (array $entry): array => ['name' => $entry['ip']] + $entry, $this->orderedIpBans());
        $entries = match ($arguments[0] ?? null) {
            'players' => $this->orderedEntries('banned-players.json'),
            'ips' => $ips,
            default => [...$this->orderedEntries('banned-players.json'), ...$ips],
        };
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
