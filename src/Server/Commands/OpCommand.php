<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait OpCommand
{
    private function executeOp(Client $sender, array $arguments): void
    {
        if (count($arguments) !== 1) {
            throw new \InvalidArgumentException('Usage: /op <targets>');
        }
        $changed = 0;
        foreach ($this->selectProfiles($arguments[0], $sender) as $name) {
            if ($this->isOperatorName($name)) {
                continue;
            }
            $this->setOperators([...$this->config->operators, $name]);
            $this->refreshPermissionLevel($name);
            $this->sendSuccess($sender, ['translate' => 'commands.op.success', 'with' => [['text' => $name]]], true);
            ++$changed;
        }
        if ($changed === 0) {
            throw new CommandFailure(['translate' => 'commands.op.failed']);
        }
    }

    private function isOperatorName(string $name): bool
    {
        foreach ($this->config->operators as $operator) {
            if (strcasecmp($operator, $name) === 0) {
                return true;
            }
        }
        return false;
    }

    /** An online player whose operator status changed gets the new permission level and commands. */
    private function refreshPermissionLevel(string $name): void
    {
        foreach ($this->players() as $player) {
            if (strcasecmp($player->name, $name) === 0) {
                $this->sendPermissionLevel($player);
            }
        }
    }
}
