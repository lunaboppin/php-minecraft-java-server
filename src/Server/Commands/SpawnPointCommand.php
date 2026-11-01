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
        if ($this->sourceDimension($sender) !== 'overworld') {
            throw new \InvalidArgumentException('Personal spawn points require the Overworld');
        }
        $targets = $arguments === [] ? [$sender] : $this->selectPlayers($arguments[0], $sender);
        $position = array_map(static fn (float $value): int => (int) floor($value), count($arguments) >= 4 ? $this->coordinates(array_slice($arguments, 1, 3), $sender) : [$this->sourcePosition($sender)[0], $this->sourcePosition($sender)[1], $this->sourcePosition($sender)[2]]);
        [$yaw, $pitch] = count($arguments) === 6 ? $this->rotationArguments($arguments[4], $arguments[5], $sender) : [0.0, 0.0];
        // RespawnData wraps the yaw and clamps the pitch, both as Java floats.
        $yaw = unpack('G', pack('G', fmod(fmod($yaw + 180.0, 360.0) + 360.0, 360.0) - 180.0))[1];
        $pitch = max(-90.0, min(90.0, $pitch));
        foreach ($targets as $target) {
            // SpawnPointCommand: a forced RespawnConfig, without the "Respawn point set" message.
            $this->setRespawnPoint($target, ['dimension' => 'overworld', 'pos' => $position, 'yaw' => (float) $yaw, 'pitch' => (float) $pitch, 'forced' => true], false);
        }
        $common = [...$position, $yaw, $pitch, ['text' => 'minecraft:overworld']];
        $message = count($targets) === 1
            ? ['translate' => 'commands.spawnpoint.success.single', 'with' => [...$common, $this->displayName($targets[0])]]
            : ['translate' => 'commands.spawnpoint.success.multiple', 'with' => [...$common, count($targets)]];
        $this->sendSuccess($sender, $message, true);
    }
}
