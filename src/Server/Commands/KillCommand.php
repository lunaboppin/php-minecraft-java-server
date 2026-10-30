<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait KillCommand
{
    private function executeKill(Client $sender, array $arguments): void
    {
        $targets = isset($arguments[0]) ? $this->selectEntities($arguments[0], $sender) : $this->selectEntities('@s', $sender);
        // The reply names the entity as it was, before a killed item disappears.
        $message = count($targets) === 1
            ? ['translate' => 'commands.kill.success.single', 'with' => [$this->entityName($targets[0])]]
            : ['translate' => 'commands.kill.success.multiple', 'with' => [count($targets)]];
        foreach ($targets as $target) {
            // Entity.kill: generic_kill bypasses invulnerability; a dead player is still counted.
            if (isset($target['client'])) {
                $this->damage($target['client'], 3.4028234663852886E38, 'generic_kill');
            } elseif (isset($target['item'])) {
                $this->removeItem($target['item']);
            } elseif (isset($target['orb'])) {
                $this->removeOrb($target['orb']);
            } else {
                $this->killAnimal($target['mob']);
            }
        }
        $this->sendSuccess($sender, $message, true);
    }
}
