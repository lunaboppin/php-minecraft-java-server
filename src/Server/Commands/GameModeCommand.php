<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Client;

trait GameModeCommand
{
    private function executeGameMode(Client $sender, array $arguments): void
    {
        $names = ['survival', 'creative', 'adventure', 'spectator'];
        // GameModeArgument accepts serialized names only.
        $mode = array_search($arguments[0] ?? '', $names, true);
        if ($mode === false || count($arguments) > 2) {
            throw new \InvalidArgumentException('Usage: /gamemode survival|creative|adventure|spectator [targets]');
        }
        $modeName = ['translate' => 'gameMode.' . $names[$mode]];
        foreach (isset($arguments[1]) ? $this->selectPlayers($arguments[1], $sender) : [$sender] as $target) {
            // An unchanged mode is skipped without feedback.
            if ($target->gameMode === $mode) {
                continue;
            }
            $this->setGameMode($target, $mode);
            if ($target === $sender) {
                $this->sendSuccess($sender, ['translate' => 'commands.gamemode.success.self', 'with' => [$modeName]], true);
                continue;
            }
            // The target is told first while the sendCommandFeedback gamerule is on (its default).
            $target->send($this->version, 'system_chat', Packets::systemChat(['translate' => 'gameMode.changed', 'with' => [$modeName]]));
            $this->sendSuccess($sender, ['translate' => 'commands.gamemode.success.other', 'with' => [$this->displayName($target), $modeName]], true);
        }
    }
}
