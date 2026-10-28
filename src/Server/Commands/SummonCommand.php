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
        // Without a position the entity appears at the sender's position, as in vanilla.
        $position = $arguments === [] ? [$sender->x, $sender->y, $sender->z] : $this->positionArguments($arguments, $sender);
        $id = $this->spawnAnimal($kind, ...$position);
        if ($id === null) {
            throw new CommandFailure(['translate' => 'commands.summon.failed']);
        }
        $this->sendSuccess($sender, ['translate' => 'commands.summon.success', 'with' => [$this->entityComponent($kind, hex2bin($this->animals[$id]['uuid']))]], true);
    }
}
