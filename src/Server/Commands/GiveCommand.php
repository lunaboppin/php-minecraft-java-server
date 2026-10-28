<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Client;

trait GiveCommand
{
    private function executeGive(Client $sender, array $arguments): void
    {
        if (count($arguments) < 2 || count($arguments) > 3) {
            throw new \InvalidArgumentException('Usage: /give targets item [count]');
        }
        $targets = $this->selectPlayers($arguments[0], $sender);
        $parsed = ItemArgument::read(new StringReader($arguments[1]), ArgumentReader::registries($this->version, array_keys(self::CLOCKS)), static fn (string $item): bool => false);
        $itemName = substr($parsed['item'], 10);
        $item = $this->version->blocks['items'][$itemName] ?? throw new \InvalidArgumentException('Unknown item');
        $count = isset($arguments[2]) ? filter_var($arguments[2], FILTER_VALIDATE_INT) : 1;
        if ($count === false || $count < 1) {
            throw new \InvalidArgumentException('Count must be at least 1');
        }
        // The stored components; others are shown in the reply but not kept on the stack.
        $patch = array_filter([
            'damage' => $parsed['components']['minecraft:damage'] ?? null,
            'enchantments' => $parsed['components']['minecraft:enchantments'] ?? null,
            'storedEnchantments' => $parsed['components']['minecraft:stored_enchantments'] ?? null,
            'removedComponents' => $parsed['removed'] === [] ? null : $parsed['removed'],
        ], static fn (mixed $value): bool => $value !== null);
        $display = $this->itemComponent($itemName, $parsed['components'], $parsed['removed']);
        $limit = $this->version->catalog->items[$itemName]['max_stack'] * 100;
        if ($count > $limit) {
            throw new CommandFailure(['translate' => 'commands.give.failed.toomanyitems', 'with' => [$limit, $display]]);
        }
        foreach ($targets as $target) {
            $left = $this->giveStack($target, ['item' => $item, 'count' => $count] + $patch);
            $this->syncInventory($target);
            if ($left > 0) {
                $this->withDimension($target->dimension, fn () => $this->dropStack($target->x, $target->y + 0.5, $target->z, ['item' => $item, 'count' => $left] + $patch));
            }
        }
        $message = count($targets) === 1
            ? ['translate' => 'commands.give.success.single', 'with' => [$count, $display, $this->displayName($targets[0])]]
            : ['translate' => 'commands.give.success.multiple', 'with' => [$count, $display, count($targets)]];
        $this->sendSuccess($sender, $message, true);
    }
}
