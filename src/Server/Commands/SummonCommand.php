<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SummonCommand
{
    private function executeSummon(Client $sender, array $arguments): void
    {
        $kind = preg_replace('/^minecraft:/', '', array_shift($arguments) ?? '');
        if (!isset($this->version->catalog->mobs['entities'][$kind]['attributes']['max_health'])) {
            throw new \InvalidArgumentException('Unknown living entity type');
        }
        $position = $arguments === [] ? [$sender->x + 2, $sender->y, $sender->z] : $this->coordinates($arguments, $sender);
        $id = $this->spawnAnimal($kind, ...$position);
        $this->tell($sender, $id === null ? 'Could not summon ' . $kind : 'Summoned ' . $kind);
        return;
    }
}
