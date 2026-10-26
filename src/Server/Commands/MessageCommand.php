<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait MessageCommand
{
    private function executeMessage(Client $sender, array $arguments): void
    {
        if (count($arguments) < 2) {
            throw new \InvalidArgumentException('Usage: /msg player message');
        }
        $target = $this->playerByName(array_shift($arguments), $sender);
        $message = implode(' ', $arguments);
        $this->tell($target, '[' . $sender->name . ' -> you] ' . $message);
        $this->tell($sender, '[you -> ' . $target->name . '] ' . $message);
        return;
    }
}
