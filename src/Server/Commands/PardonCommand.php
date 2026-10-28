<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait PardonCommand
{
    /** PardonCommand: lift each banned profile's ban. */
    private function executePardon(Client $sender, array $arguments): void
    {
        $pardoned = 0;
        foreach ($this->gameProfiles($arguments[0] ?? '', $sender) as $profile) {
            if ($this->setListed('banned-players.json', $profile, false)) {
                ++$pardoned;
                $this->sendSuccess($sender, ['translate' => 'commands.pardon.success', 'with' => [['text' => $profile[0]]]], true);
            }
        }
        if ($pardoned === 0) {
            throw new CommandFailure(['translate' => 'commands.pardon.failed']);
        }
    }
}
