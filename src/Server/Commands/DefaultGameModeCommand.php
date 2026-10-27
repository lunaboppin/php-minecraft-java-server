<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait DefaultGameModeCommand
{
    private function executeDefaultGameMode(Client $sender, array $arguments): void
    {
        // GameModeArgument accepts serialized names only, unlike the legacy numeric /gamemode input here.
        $mode = array_search($arguments[0] ?? '', ['survival', 'creative', 'adventure', 'spectator'], true);
        if ($mode === false || count($arguments) !== 1) {
            throw new \InvalidArgumentException('Usage: /defaultgamemode survival|creative|adventure|spectator');
        }
        // New players receive it; existing players keep theirs because force-gamemode is not supported.
        $this->config = $this->config->with(['defaultGameMode' => $mode]);
        $this->sendSuccess($sender, ['translate' => 'commands.defaultgamemode.success', 'with' => [['translate' => 'gameMode.' . $arguments[0]]]], true);
    }
}
