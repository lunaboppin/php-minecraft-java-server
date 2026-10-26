<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait TeleportCommand
{
    private function executeTeleport(Client $sender, array $arguments): void
    {
        if (count($arguments) === 1) {
            $target = $this->playerByName($arguments[0], $sender);
            $this->changeDimension($sender, $target->dimension, [$target->x, $target->y, $target->z]);
        } elseif (count($arguments) === 3) {
            $this->teleport($sender, $this->coordinates($arguments, $sender));
        } elseif (count($arguments) === 4) {
            $target = $this->playerByName(array_shift($arguments), $sender);
            $this->teleport($target, $this->coordinates($arguments, $target));
        } else {
            throw new \InvalidArgumentException('Usage: /tp player OR /tp [player] x y z');
        }
        return;
    }
}
