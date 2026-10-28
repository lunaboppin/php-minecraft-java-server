<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SetBlockCommand
{
    private function executeSetBlock(Client $sender, array $arguments): void
    {
        if (count($arguments) !== 4) {
            throw new \InvalidArgumentException('Usage: /setblock x y z block[properties]');
        }
        [$x, $y, $z] = array_map(static fn (float $value): int => (int) floor($value), $this->coordinates(array_slice($arguments, 0, 3), $sender));
        $state = $this->blockArgument($arguments[3]);
        if ($this->world->get($x, $y, $z) === $state) {
            throw new CommandFailure(['translate' => 'commands.setblock.failed']);
        }
        $this->setBlock($x, $y, $z, $state);
        $this->sendSuccess($sender, ['translate' => 'commands.setblock.success', 'with' => [$x, $y, $z]], true);
    }
}
