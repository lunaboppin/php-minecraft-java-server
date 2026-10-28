<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait BanCommand
{
    /** BanPlayerCommands: ban each profile not yet banned, then disconnect it if online. */
    private function executeBan(Client $sender, array $arguments, string $text = ''): void
    {
        // MessageArgument takes the rest of the input verbatim after one separating space.
        $parts = explode(' ', ltrim($text), 3);
        $reason = $parts[2] ?? null;
        $banned = 0;
        foreach ($this->gameProfiles($arguments[0] ?? '', $sender) as $profile) {
            $entry = ['created' => gmdate('Y-m-d H:i:s') . ' +0000', 'source' => $sender->name, 'expires' => 'forever', 'reason' => $reason ?? self::DEFAULT_BAN_REASON];
            if (!$this->setListed('banned-players.json', $profile, true, $entry)) {
                continue;
            }
            ++$banned;
            $this->sendSuccess($sender, ['translate' => 'commands.ban.success', 'with' => [['text' => $profile[0]], self::banReason($entry)]], true);
            foreach ($this->players() as $player) {
                if ($player->uuid === $profile[1]) {
                    $this->disconnect($player, ['translate' => 'multiplayer.disconnect.banned']);
                }
            }
        }
        if ($banned === 0) {
            throw new CommandFailure(['translate' => 'commands.ban.failed']);
        }
    }

    /**
     * GameProfileArgument: online players by name or selector, otherwise a name; offline-mode
     * servers derive its UUID, and online-mode servers keep the name without a profile lookup.
     *
     * @return list<array{string, ?string}> names and binary UUIDs
     */
    private function gameProfiles(string $selector, Client $sender): array
    {
        $profiles = [];
        foreach ($this->selectProfiles($selector, $sender) as $name) {
            $online = array_values(array_filter($this->players(), static fn (Client $player): bool => strcasecmp($player->name, $name) === 0));
            $profiles[] = $online !== [] ? [$online[0]->name, $online[0]->uuid] : [$name, $this->config->onlineMode ? null : \PhpMinecraftJavaServer\Protocol\Binary::offlineUuid($name)];
        }
        return $profiles;
    }
}
