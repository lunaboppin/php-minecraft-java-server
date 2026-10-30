<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait PardonIpCommand
{
    /** PardonIpCommand: lift the ban on an address. */
    private function executePardonIp(Client $sender, array $arguments): void
    {
        $ip = $arguments[0];
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new CommandFailure(['translate' => 'commands.pardonip.invalid']);
        }
        $bans = &$this->ipBanList();
        if (!isset($bans[$ip])) {
            throw new CommandFailure(['translate' => 'commands.pardonip.failed']);
        }
        unset($bans[$ip]);
        $this->saveIpBans();
        $this->sendSuccess($sender, ['translate' => 'commands.pardonip.success', 'with' => [['text' => $ip]]], true);
    }
}
