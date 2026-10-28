<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SetWorldSpawnCommand
{
    private function executeSetWorldSpawn(Client $sender, array $arguments): void
    {
        if (!in_array(count($arguments), [0, 3, 5], true)) {
            throw new \InvalidArgumentException('Usage: /setworldspawn [x y z [yaw pitch]]');
        }
        if ($sender->dimension !== 'overworld') {
            // Vanilla records the dimension with the spawn; spawns here are Overworld-only.
            throw new \InvalidArgumentException('World spawn requires the Overworld');
        }
        $position = array_map(static fn (float $value): int => (int) floor($value), $arguments === [] ? [$sender->x, $sender->y, $sender->z] : $this->coordinates(array_slice($arguments, 0, 3), $sender));
        [$yaw, $pitch] = count($arguments) === 5 ? $this->rotationArguments($arguments[3], $arguments[4], $sender) : [0.0, 0.0];
        // Players are placed at the spawn block's centre; the spawn packet sends the block itself.
        $this->world->setting('spawn', [$position[0] + 0.5, (float) $position[1], $position[2] + 0.5]);
        $this->world->setting('spawnRotation', [$yaw, $pitch]);
        foreach ($this->players() as $player) {
            $player->send($this->version, 'set_default_spawn_position', $this->worldSpawnPacket());
        }
        $this->sendSuccess($sender, ['translate' => 'commands.setworldspawn.success', 'with' => [...$position, $yaw, $pitch, ['text' => 'minecraft:overworld']]], true);
    }
}
