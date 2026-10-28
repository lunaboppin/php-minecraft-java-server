<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait TriggerCommand
{
    /** TriggerCommand: a player changes their own enabled trigger score once, which locks it again. */
    private function executeTrigger(Client $sender, array $arguments): void
    {
        $objective = $this->objective($arguments[0] ?? '');
        $scoreboard = $this->scoreboard();
        if ($scoreboard->objectives[$objective]['criteria'] !== 'trigger') {
            throw new CommandFailure(['translate' => 'commands.trigger.failed.invalid']);
        }
        $score = $scoreboard->scores[$sender->name][$objective] ?? null;
        if ($score === null || $score['locked']) {
            throw new CommandFailure(['translate' => 'commands.trigger.failed.unprimed']);
        }
        $scoreboard->scores[$sender->name][$objective]['locked'] = true;
        $action = $arguments[1] ?? null;
        $value = (int) ($arguments[2] ?? 1);
        $this->setScore($sender->name, $objective, $action === 'set' ? $value : self::int32($score['value'] + $value));
        $this->saveScoreboard();
        $this->sendSuccess($sender, $action === null
            ? ['translate' => 'commands.trigger.simple.success', 'with' => [$this->objectiveComponent($objective)]]
            : ['translate' => 'commands.trigger.' . $action . '.success', 'with' => [$this->objectiveComponent($objective), $value]], true);
    }
}
