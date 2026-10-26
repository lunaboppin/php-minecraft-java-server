<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait GameModeCommand
{
    private function executeGameMode(Client $sender, array $arguments): void
    {
        $mode = $arguments[0] ?? '';
        $mode = match($mode) {
            'survival','0' => 0,'creative','1' => 1,'adventure','2' => 2,'spectator','3' => 3,default => throw new \InvalidArgumentException('Usage: /gamemode survival|creative|adventure|spectator [player]')
        };
        $target = isset($arguments[1]) ? $this->playerByName($arguments[1], $sender) : $sender;
        $this->setGameMode($target, $mode);
        $this->tell($target, 'Game mode changed to ' . ['survival', 'creative', 'adventure', 'spectator'][$mode]);
        return;
    }
}
