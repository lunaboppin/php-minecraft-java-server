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
            } elseif (isset($target['projectile'])) {
                $this->removeProjectile($target['projectile']);
            } elseif (isset($target['minecart'])) {
                // AbstractMinecartContainer.remove: a killed container minecart spills what it held.
                $cart = $this->minecarts[$target['minecart']];
                foreach ($cart['items'] ?? [] as $stack) {
                    $this->dropContainerStack((int) floor($cart['x']), (int) floor($cart['y']), (int) floor($cart['z']), $stack);
                }
                $this->removeMinecart($target['minecart']);
            } else {
                $this->killAnimal($target['mob']);
            }
        }
        $this->sendSuccess($sender, $message, true);
    }
}
