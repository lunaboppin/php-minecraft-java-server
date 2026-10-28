<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait DeOpCommand
{
    private function executeDeOp(Client $sender, array $arguments): void
    {
        if (count($arguments) !== 1) {
            throw new \InvalidArgumentException('Usage: /deop <targets>');
        }
        $changed = 0;
        foreach ($this->selectProfiles($arguments[0], $sender) as $name) {
            if (!$this->isOperatorName($name)) {
                continue;
            }
            $this->setOperators(array_filter($this->config->operators, static fn (string $operator): bool => strcasecmp($operator, $name) !== 0));
            $this->refreshPermissionLevel($name);
            $this->sendSuccess($sender, ['translate' => 'commands.deop.success', 'with' => [['text' => $name]]], true);
            ++$changed;
        }
        if ($changed === 0) {
            throw new CommandFailure(['translate' => 'commands.deop.failed']);
        }
        // Vanilla then kicks players missing from an enforced whitelist; there is no whitelist yet.
    }
}
