<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait DimensionCommand
{
    private function executeDimension(Client $sender, array $arguments): void
    {
        $dimension = preg_replace('/^minecraft:/', '', $arguments[0] ?? '');
        $this->changeDimension($sender, $dimension);
        $this->tell($sender, 'Entered minecraft:' . $dimension);
        return;
    }
}
