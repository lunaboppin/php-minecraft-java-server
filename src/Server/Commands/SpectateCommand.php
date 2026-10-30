<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SpectateCommand
{
    /** SpectateCommand: a spectator views through an entity, or stops with no target. */
    private function executeSpectate(Client $sender, array $arguments): void
    {
        $target = isset($arguments[0]) ? $this->selectEntities($arguments[0], $sender, single: true)[0] : null;
        $player = isset($arguments[1]) ? $this->selectPlayers($arguments[1], $sender, true)[0] : $sender;
        if (($target['client'] ?? null) === $player) {
            throw new CommandFailure(['translate' => 'commands.spectate.self']);
        }
        if ($player->gameMode !== 3) {
            throw new CommandFailure(['translate' => 'commands.spectate.not_spectator', 'with' => [$this->displayName($player)]]);
        }
        $this->setCamera($player, $target);
        $this->sendSuccess($sender, $target === null ? ['translate' => 'commands.spectate.success.stopped']
            : ['translate' => 'commands.spectate.success.started', 'with' => [$this->entityName($target)]], false);
    }
}
