<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait GiveCommand
{
    private function executeGive(Client $sender, array $arguments): void
    {
        if (count($arguments) < 2) {
            throw new \InvalidArgumentException('Usage: /give player item [count]');
        }
        $target = $this->playerByName($arguments[0], $sender);
        $itemName = preg_replace('/^minecraft:/', '', $arguments[1]);
        $item = $this->version->blocks['items'][$itemName] ?? throw new \InvalidArgumentException('Unknown item');
        $count = isset($arguments[2]) ? filter_var($arguments[2], FILTER_VALIDATE_INT) : 1;
        if ($count === false || $count < 1 || $count > 2304) {
            throw new \InvalidArgumentException('Count must be 1–2304');
        }
        $left = $this->giveStack($target, ['item' => $item, 'count' => $count]);
        $this->syncInventory($target);
        if ($left > 0) {
            $this->withDimension(
                $target->dimension,
                fn () => $this->dropStack(
                    $target->x,
                    $target->y + 0.5,
                    $target->z,
                    ['item' => $item, 'count' => $left]
                )
            );
        }
        $this->tell($sender, 'Gave ' . $count . ' ' . $itemName . ' to ' . $target->name);
        return;
    }
}
