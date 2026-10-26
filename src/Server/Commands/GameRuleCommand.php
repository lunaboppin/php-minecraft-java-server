<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait GameRuleCommand
{
    private function executeGameRule(Client $sender, array $arguments): void
    {
        $map = ['doDaylightCycle' => 'daylightCycle', 'keepInventory' => 'keepInventory', 'naturalRegeneration' => 'naturalRegeneration', 'spawn_mobs' => 'spawnMobs', 'minecraft:spawn_mobs' => 'spawnMobs', 'doMobSpawning' => 'spawnMobs'];
        $field = $map[$arguments[0] ?? ''] ?? throw new \InvalidArgumentException('Supported gamerules: ' . implode(', ', array_keys($map)));
        if (!isset($arguments[1])) {
            $this->tell($sender, $arguments[0] . ' = ' . ($this->$field ? 'true' : 'false'));
            return;
        }
        if (!in_array($arguments[1], ['true', 'false'], true)) {
            throw new \InvalidArgumentException('Expected true or false');
        }
        $this->$field = $arguments[1] === 'true';
        $this->saveWorld();
        foreach ($this->players() as $p) {
            $this->sendClock($p);
        }
        $this->tell($sender, $arguments[0] . ' = ' . $arguments[1]);
        return;
    }
}
