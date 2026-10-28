<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Client;

trait SpawnPointCommand
{
    private function executeSpawnPoint(Client $sender, array $arguments): void
    {
        if (!in_array(count($arguments), [0, 1, 4, 6], true)) {
            throw new \InvalidArgumentException('Usage: /spawnpoint [targets [x y z [yaw pitch]]]');
        }
        if ($sender->dimension !== 'overworld') {
            throw new \InvalidArgumentException('Personal spawn points require the Overworld');
        }
        $targets = $arguments === [] ? [$sender] : $this->selectPlayers($arguments[0], $sender);
        $position = array_map(static fn (float $value): int => (int) floor($value), count($arguments) >= 4 ? $this->coordinates(array_slice($arguments, 1, 3), $sender) : [$sender->x, $sender->y, $sender->z]);
        [$yaw, $pitch] = count($arguments) === 6 ? $this->rotationArguments($arguments[4], $arguments[5], $sender) : [0.0, 0.0];
        // RespawnData wraps the yaw and clamps the pitch, both as Java floats.
        $yaw = unpack('G', pack('G', fmod(fmod($yaw + 180.0, 360.0) + 360.0, 360.0) - 180.0))[1];
        $pitch = max(-90.0, min(90.0, $pitch));
        foreach ($targets as $target) {
            // A forced spawn point without a bed respawns at the block's bottom centre.
            $target->personalSpawn = [$position[0] + 0.5, (float) $position[1], $position[2] + 0.5];
            $this->savePlayer($target);
        }
        $common = [...$position, $yaw, $pitch, ['text' => 'minecraft:overworld']];
        $message = count($targets) === 1
            ? ['translate' => 'commands.spawnpoint.success.single', 'with' => [...$common, Packets::playerName($targets[0]->name, $targets[0]->uuid)]]
            : ['translate' => 'commands.spawnpoint.success.multiple', 'with' => [...$common, count($targets)]];
        $this->sendSuccess($sender, $message, true);
    }
}
