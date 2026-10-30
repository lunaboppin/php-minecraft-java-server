<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Nbt;

/**
 * The whitelist and player bans, kept in vanilla's whitelist.json and banned-players.json beside
 * server.json, and checked as PlayerList.canPlayerLogin does.
 */
trait PlayerLists
{
    /** @var array<string, array{uuid: ?string, name: string}>|null keyed by lowercase name */
    private ?array $whitelist = null;

    /** @var array<string, array{uuid: ?string, name: string, created: string, source: string, expires: string, reason: ?string}>|null */
    private ?array $bans = null;

    private const DEFAULT_BAN_REASON = 'Banned by an operator.';

    /** @return array<string, array> */
    private function &playerList(string $file): array
    {
        $property = $file === 'whitelist.json' ? 'whitelist' : 'bans';
        if ($this->$property === null) {
            $this->$property = [];
            $path = $this->playerListPath($file);
            $entries = $path !== null && is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
            foreach ($entries as $entry) {
                $this->$property[strtolower($entry['name'])] = $entry + ['uuid' => null];
            }
        }
        return $this->$property;
    }

    private function playerListPath(string $file): ?string
    {
        return $this->configPath === null ? null : dirname($this->configPath) . '/' . $file;
    }

    private function savePlayerList(string $file): void
    {
        $path = $this->playerListPath($file);
        if ($path === null) {
            return;
        }
        $entries = array_values($this->playerList($file));
        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $temporary = $path . '.tmp';
        if (file_put_contents($temporary, $json) === false || !rename($temporary, $path)) {
            throw new \RuntimeException('Cannot save ' . $path);
        }
    }

    /** @param array{string, ?string} $profile name and binary UUID */
    private function listed(string $file, array $profile): bool
    {
        $entry = $this->playerList($file)[strtolower($profile[0])] ?? null;
        foreach ($this->playerList($file) as $candidate) {
            if ($profile[1] !== null && $candidate['uuid'] === Binary::formatUuid($profile[1])) {
                return true;
            }
        }
        return $entry !== null;
    }

    /** Add or remove a profile; false when nothing changed. */
    private function setListed(string $file, array $profile, bool $listed, array $fields = []): bool
    {
        if ($this->listed($file, $profile) === $listed) {
            return false;
        }
        $list = &$this->playerList($file);
        if ($listed) {
            $list[strtolower($profile[0])] = ['uuid' => $profile[1] === null ? null : Binary::formatUuid($profile[1]), 'name' => $profile[0]] + $fields;
        } else {
            foreach ($list as $key => $entry) {
                if ($key === strtolower($profile[0]) || ($profile[1] !== null && $entry['uuid'] === Binary::formatUuid($profile[1]))) {
                    unset($list[$key]);
                }
            }
        }
        $this->savePlayerList($file);
        return true;
    }

    /** Entries in vanilla's HashMap order, keyed by UUID string. */
    private function orderedEntries(string $file): array
    {
        $byKey = [];
        foreach ($this->playerList($file) as $entry) {
            $byKey[$entry['uuid'] ?? strtolower($entry['name'])] = $entry;
        }
        return array_map(static fn (string $key): array => $byKey[$key], Nbt::hashMapOrder(array_map('strval', array_keys($byKey))));
    }

    /** BanListEntry.getReasonMessage */
    private static function banReason(array $entry): array
    {
        $reason = $entry['reason'] ?? null;
        return $reason === null || $reason === self::DEFAULT_BAN_REASON ? ['translate' => 'multiplayer.disconnect.banned.reason.default'] : ['text' => $reason];
    }

    /** @var array<string, array{ip: string, created: string, source: string, expires: string, reason: ?string}>|null keyed by address */
    private ?array $ipBans = null;

    /** IpBanList: banned-ips.json beside server.json. */
    private function &ipBanList(): array
    {
        if ($this->ipBans === null) {
            $this->ipBans = [];
            $path = $this->playerListPath('banned-ips.json');
            foreach ($path !== null && is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [] as $entry) {
                $this->ipBans[$entry['ip']] = $entry;
            }
        }
        return $this->ipBans;
    }

    private function saveIpBans(): void
    {
        $path = $this->playerListPath('banned-ips.json');
        if ($path === null) {
            return;
        }
        $json = json_encode(array_values($this->ipBanList()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($path . '.tmp', $json) === false || !rename($path . '.tmp', $path)) {
            throw new \RuntimeException('Cannot save ' . $path);
        }
    }

    /** IP bans in vanilla's HashMap order, keyed by address. */
    private function orderedIpBans(): array
    {
        $bans = $this->ipBanList();
        return array_map(static fn (string $ip): array => $bans[$ip], Nbt::hashMapOrder(array_map('strval', array_keys($bans))));
    }

    /** ServerPlayer.getIpAddress: the connection's address without its port. */
    private static function clientAddress(Client $c): string
    {
        $address = preg_replace('/:\d+$/', '', $c->peer) ?? $c->peer;
        return trim($address, '[]');
    }

    /** PlayerList.canPlayerLogin: name bans, IP bans, then the whitelist (operators pass), then capacity. */
    private function loginRejection(Client $c): ?array
    {
        $profile = [$c->name, $c->uuid];
        if ($this->listed('banned-players.json', $profile)) {
            $entry = $this->playerList('banned-players.json')[strtolower($c->name)] ?? array_values(array_filter($this->playerList('banned-players.json'), static fn (array $entry): bool => $entry['uuid'] === Binary::formatUuid($c->uuid)))[0];
            return ['translate' => 'multiplayer.disconnect.banned.reason', 'with' => [self::banReason($entry)]];
        }
        $ipBan = $this->ipBanList()[self::clientAddress($c)] ?? null;
        if ($ipBan !== null) {
            return ['translate' => 'multiplayer.disconnect.banned_ip.reason', 'with' => [self::banReason($ipBan)]];
        }
        if ($this->config->whiteList && !$this->operatorName($c->name) && !$this->listed('whitelist.json', $profile)) {
            return ['translate' => 'multiplayer.disconnect.not_whitelisted'];
        }
        if (count($this->players()) >= $this->config->maxPlayers) {
            return ['translate' => 'multiplayer.disconnect.server_full'];
        }
        return null;
    }

    private function operatorName(string $name): bool
    {
        foreach ($this->config->operators as $operator) {
            if (strcasecmp($operator, $name) === 0) {
                return true;
            }
        }
        return false;
    }
}
