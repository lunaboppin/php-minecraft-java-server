<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait BanIpCommand
{
    /**
     * BanIpCommands: an address, or an online player's address, joins the IP bans; everyone
     * connected from it is told and disconnected.
     */
    private function executeBanIp(Client $sender, array $arguments, string $text = ''): void
    {
        $target = $arguments[0];
        if (filter_var($target, FILTER_VALIDATE_IP) === false) {
            $player = null;
            foreach ($this->players() as $candidate) {
                if (strcasecmp($candidate->name, $target) === 0) {
                    $player = $candidate;
                }
            }
            if ($player === null) {
                throw new CommandFailure(['translate' => 'commands.banip.invalid']);
            }
            $target = self::clientAddress($player);
        }
        $bans = &$this->ipBanList();
        if (isset($bans[$target])) {
            throw new CommandFailure(['translate' => 'commands.banip.failed']);
        }
        $reason = count($arguments) > 1 ? self::plainText($this->messageComponent($sender, CommandArguments::rest($text, 1))) : null;
        $entry = ['ip' => $target, 'created' => gmdate('Y-m-d H:i:s') . ' +0000', 'source' => $sender->name, 'expires' => 'forever', 'reason' => $reason ?? self::DEFAULT_BAN_REASON];
        $bans[$target] = $entry;
        $this->saveIpBans();
        $this->sendSuccess($sender, ['translate' => 'commands.banip.success', 'with' => [['text' => $target], self::banReason($entry)]], true);
        $players = array_values(array_filter($this->players(), static fn (Client $player): bool => self::clientAddress($player) === $target));
        if ($players !== []) {
            $this->sendSuccess($sender, ['translate' => 'commands.banip.info', 'with' => [count($players), $this->formatList(array_map($this->displayName(...), $players))]], true);
        }
        foreach ($players as $player) {
            $this->disconnect($player, ['translate' => 'multiplayer.disconnect.ip_banned']);
        }
    }
}
