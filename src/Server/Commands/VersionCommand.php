<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;
use PhpMinecraftJavaServer\Protocol\Version;

trait VersionCommand
{
    private function executeVersion(Client $sender, array $arguments): void
    {
        $this->tell($sender, 'PHP Minecraft Java Server — Minecraft ' . Version::NAME . ' / protocol ' . Version::PROTOCOL);
        return;
    }
}
