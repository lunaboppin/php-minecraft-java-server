<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Client;

trait ExperienceCommand
{
    /** ExperienceCommand: add, set or query points or levels. */
    private function executeExperience(Client $sender, array $arguments): void
    {
        [$action, $target, $amount, $type] = $arguments + [null, null, null, null];
        if ($action === 'query') {
            $player = $this->selectPlayers((string) $target, $sender, true)[0];
            $value = $amount === 'levels' ? $player->experienceLevel : $this->experiencePoints($player);
            $this->sendSuccess($sender, ['translate' => 'commands.experience.query.' . $amount, 'with' => [$this->displayName($player), $value]], false);
            return;
        }
        if (!in_array($action, ['add', 'set'], true) || $amount === null) {
            throw new \InvalidArgumentException('Usage: /experience add|set|query <targets> <amount> [points|levels]');
        }
        $type ??= 'points';
        $amount = (int) $amount;
        $targets = $this->selectPlayers((string) $target, $sender);
        $changed = 0;
        foreach ($targets as $player) {
            if ($action === 'add') {
                $type === 'levels' ? $this->giveExperienceLevels($player, $amount) : $this->giveExperiencePoints($player, $amount);
                ++$changed;
            } elseif ($type === 'levels') {
                $this->setExperienceLevels($player, $amount);
                ++$changed;
            } elseif ($amount < self::xpNeededForNextLevel($player->experienceLevel)) {
                // Points must fit within the current level.
                $this->setExperiencePoints($player, $amount);
                ++$changed;
            }
        }
        if ($changed === 0) {
            throw new CommandFailure(['translate' => 'commands.experience.set.points.invalid']);
        }
        $key = 'commands.experience.' . $action . '.' . $type . '.success.';
        $message = count($targets) === 1
            ? ['translate' => $key . 'single', 'with' => [$amount, $this->displayName($targets[0])]]
            : ['translate' => $key . 'multiple', 'with' => [$amount, count($targets)]];
        $this->sendSuccess($sender, $message, true);
    }
}
