<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Client;

trait TeleportCommand
{
    private function executeTeleport(Client $sender, array $arguments): void
    {
        [$targets, $destination] = match (count($arguments)) {
            1 => [[$sender], $arguments],
            2 => [$this->selectPlayers($arguments[0], $sender), [$arguments[1]]],
            3 => [[$sender], $arguments],
            4 => [$this->selectPlayers($arguments[0], $sender), array_slice($arguments, 1)],
            default => throw new \InvalidArgumentException('Usage: /tp [targets] destination|x y z'),
        };
        if (count($destination) === 1) {
            $players = $this->selectPlayers($destination[0], $sender);
            if (count($players) !== 1) {
                throw new CommandFailure(['translate' => 'argument.entity.toomany']);
            }
            $to = $players[0];
            foreach ($targets as $target) {
                $this->changeDimension($target, $to->dimension, [$to->x, $to->y, $to->z]);
            }
            $label = Packets::playerName($to->name, $to->uuid);
            $message = count($targets) === 1
                ? ['translate' => 'commands.teleport.success.entity.single', 'with' => [Packets::playerName($targets[0]->name, $targets[0]->uuid), $label]]
                : ['translate' => 'commands.teleport.success.entity.multiple', 'with' => [count($targets), $label]];
            $this->sendSuccess($sender, $message, true);
            return;
        }
        // Relative coordinates are resolved from the command's sender, even for other targets.
        $position = $this->positionArguments($destination, $sender);
        foreach ($targets as $target) {
            if ($target->dimension === $sender->dimension) {
                $this->teleport($target, $position);
            } else {
                $this->changeDimension($target, $sender->dimension, $position);
            }
        }
        // String.format("%f"): six decimals, independent of locale.
        $coordinates = array_map(static fn (float $value): array => ['text' => sprintf('%.6F', $value)], $position);
        $message = count($targets) === 1
            ? ['translate' => 'commands.teleport.success.location.single', 'with' => [Packets::playerName($targets[0]->name, $targets[0]->uuid), ...$coordinates]]
            : ['translate' => 'commands.teleport.success.location.multiple', 'with' => [count($targets), ...$coordinates]];
        $this->sendSuccess($sender, $message, true);
    }
}
