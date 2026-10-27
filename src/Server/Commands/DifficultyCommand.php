<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Client;

trait DifficultyCommand
{
    /** Native ordinal order, as Difficulty.getSerializedName() literals. */
    private const DIFFICULTIES = ['peaceful', 'easy', 'normal', 'hard'];

    private function executeDifficulty(Client $sender, array $arguments): void
    {
        $name = fn (int $difficulty): array => ['translate' => 'options.difficulty.' . self::DIFFICULTIES[$difficulty]];
        if ($arguments === []) {
            $this->sendSuccess($sender, ['translate' => 'commands.difficulty.query', 'with' => [$name($this->config->difficulty)]], false);
            return;
        }
        $difficulty = array_search($arguments[0], self::DIFFICULTIES, true);
        if ($difficulty === false || count($arguments) > 1) {
            throw new \InvalidArgumentException('Usage: /difficulty [peaceful|easy|normal|hard]');
        }
        if ($difficulty === $this->config->difficulty) {
            $this->sendFailure($sender, ['translate' => 'commands.difficulty.failure', 'with' => [$name($difficulty)]]);
            return;
        }
        // As on a dedicated server, the startup configuration is reapplied after a restart.
        $this->config = $this->config->with(['difficulty' => $difficulty]);
        foreach ($this->players() as $player) {
            $player->send($this->version, 'change_difficulty', Packets::difficulty($difficulty));
        }
        $this->sendSuccess($sender, ['translate' => 'commands.difficulty.success', 'with' => [$name($difficulty)]], true);
    }
}
