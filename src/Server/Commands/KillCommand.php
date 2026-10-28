<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait KillCommand
{
    private function executeKill(Client $sender, array $arguments): void
    {
        $targets = isset($arguments[0]) ? $this->selectEntities($arguments[0], $sender) : $this->selectEntities('@s', $sender);
        foreach ($targets as $target) {
            // Entity.kill: generic_kill bypasses invulnerability; a dead player is still counted.
            if (isset($target['client'])) {
                $this->damage($target['client'], 3.4028234663852886E38, 'generic_kill');
            } else {
                $this->killAnimal($target['mob']);
            }
        }
        $message = count($targets) === 1
            ? ['translate' => 'commands.kill.success.single', 'with' => [$this->entityName($targets[0])]]
            : ['translate' => 'commands.kill.success.multiple', 'with' => [count($targets)]];
        $this->sendSuccess($sender, $message, true);
    }
}
