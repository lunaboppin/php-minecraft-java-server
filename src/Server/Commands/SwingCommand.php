<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SwingCommand
{
    /** SwingCommand: living targets swing a hand, shown to their trackers and themselves. */
    private function executeSwing(Client $sender, array $arguments): void
    {
        $targets = isset($arguments[0]) ? $this->selectEntities($arguments[0], $sender) : [['client' => $sender, 'id' => $sender->entityId, 'type' => 'minecraft:player']];
        $hand = ($arguments[1] ?? 'mainhand') === 'offhand' ? 1 : 0;
        $type = $arguments[2] ?? 'whack';
        if (!isset(self::SWING_TYPES[$type])) {
            throw new \InvalidArgumentException('Unknown swing animation: ' . $type);
        }
        $duration = isset($arguments[3]) ? $this->timeArgument($arguments[3], 1) : 6;
        // Dropped items are not living entities.
        $living = array_values(array_filter($targets, static fn (array $entity): bool => !isset($entity['item'])));
        foreach ($living as $entity) {
            $this->swing($entity['id'], $hand, $type, $duration, $entity['client'] ?? null, true);
        }
        if ($living === []) {
            throw new CommandFailure(['translate' => 'commands.swing.failed.notliving']);
        }
        $this->sendSuccess($sender, count($living) === 1 ? ['translate' => 'commands.swing.success.single', 'with' => [$this->entityName($living[0])]]
            : ['translate' => 'commands.swing.success.multiple', 'with' => [count($living)]], true);
    }
}
