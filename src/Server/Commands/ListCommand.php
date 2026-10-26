<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait ListCommand
{
    private function executeList(Client $sender, array $arguments): void
    {
        $this->tell($sender, 'Players: ' . implode(', ', array_map(static fn (Client $p): string => $p->name, $this->players())));
        return;
    }
}
