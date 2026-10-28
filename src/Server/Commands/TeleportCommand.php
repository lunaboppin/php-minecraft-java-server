<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait TeleportCommand
{
    private function executeTeleport(Client $sender, array $arguments): void
    {
        [$targets, $destination] = match (count($arguments)) {
            1 => [$this->selectEntities('@s', $sender), $arguments],
            2 => [$this->selectEntities($arguments[0], $sender), [$arguments[1]]],
            3 => [$this->selectEntities('@s', $sender), $arguments],
            4 => [$this->selectEntities($arguments[0], $sender), array_slice($arguments, 1)],
            default => throw new \InvalidArgumentException('Usage: /tp [targets] destination|x y z'),
        };
        if (count($destination) === 1) {
            $to = $this->selectEntities($destination[0], $sender, false, true)[0];
            $dimension = isset($to['client']) ? $to['client']->dimension : $sender->dimension;
            foreach ($targets as $target) {
                $this->moveEntity($target, $dimension, [$to['x'], $to['y'], $to['z']]);
            }
            $message = count($targets) === 1
                ? ['translate' => 'commands.teleport.success.entity.single', 'with' => [$this->entityName($targets[0]), $this->entityName($to)]]
                : ['translate' => 'commands.teleport.success.entity.multiple', 'with' => [count($targets), $this->entityName($to)]];
            $this->sendSuccess($sender, $message, true);
            return;
        }
        // Relative coordinates are resolved from the command's sender, even for other targets.
        $position = $this->positionArguments($destination, $sender);
        foreach ($targets as $target) {
            $this->moveEntity($target, $sender->dimension, $position);
        }
        // String.format("%f"): six decimals, independent of locale.
        $coordinates = array_map(static fn (float $value): array => ['text' => sprintf('%.6F', $value)], $position);
        $message = count($targets) === 1
            ? ['translate' => 'commands.teleport.success.location.single', 'with' => [$this->entityName($targets[0]), ...$coordinates]]
            : ['translate' => 'commands.teleport.success.location.multiple', 'with' => [count($targets), ...$coordinates]];
        $this->sendSuccess($sender, $message, true);
    }

    /** Move a selected player (across dimensions if needed) or a mob in the sender's dimension. */
    private function moveEntity(array $target, string $dimension, array $position): void
    {
        if (!isset($target['client'])) {
            $this->teleportAnimal($target['mob'], $position);
            return;
        }
        $player = $target['client'];
        if ($player->dimension === $dimension) {
            $this->teleport($player, $position);
        } else {
            $this->changeDimension($player, $dimension, $position);
        }
    }
}
