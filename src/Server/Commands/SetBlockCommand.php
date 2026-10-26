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
        $position = $this->coordinates(array_slice($arguments, 0, 3), $sender);
        [$x,$y,$z] = array_map('intval', array_map('floor', $position));
        $this->setBlock($x, $y, $z, $this->blockArgument($arguments[3]));
        $this->tell($sender, 'Block updated');
        return;
    }
}
