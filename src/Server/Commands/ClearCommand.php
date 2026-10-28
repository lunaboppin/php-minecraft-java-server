<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Client;

trait ClearCommand
{
    /**
     * ClearInventoryCommands: remove (or with a maximum of 0, count) matching items from the
     * inventory, the crafting grid and the carried stack.
     */
    private function executeClear(Client $sender, array $arguments): void
    {
        $targets = $this->selectPlayers($arguments[0] ?? '@s', $sender);
        $matches = isset($arguments[1]) ? $this->itemPredicate($arguments[1]) : static fn (int $item): bool => true;
        $maximum = isset($arguments[2]) ? (int) $arguments[2] : -1;
        $total = 0;
        foreach ($targets as $player) {
            $total += $this->clearMatchingItems($player, $matches, $maximum);
        }
        if ($total === 0) {
            throw new CommandFailure(count($targets) === 1
                ? ['translate' => 'clear.failed.single', 'with' => [Packets::playerName($targets[0]->name, $targets[0]->uuid)]]
                : ['translate' => 'clear.failed.multiple', 'with' => [count($targets)]]);
        }
        $key = $maximum === 0 ? 'commands.clear.test.' : 'commands.clear.success.';
        $message = count($targets) === 1
            ? ['translate' => $key . 'single', 'with' => [$total, Packets::playerName($targets[0]->name, $targets[0]->uuid)]]
            : ['translate' => $key . 'multiple', 'with' => [$total, count($targets)]];
        $this->sendSuccess($sender, $message, true);
    }

    /**
     * Inventory.clearOrCountMatchingItems: hotbar, main, armour (feet first) and offhand, then the
     * crafting grid and the carried stack. A maximum of 0 only counts; a negative one is unlimited.
     */
    private function clearMatchingItems(Client $player, \Closure $matches, int $maximum): int
    {
        $count = 0;
        $take = function (?array $stack) use ($matches, $maximum, &$count): ?array {
            if ($stack === null || !$matches($stack['item'])) {
                return $stack;
            }
            if ($maximum === 0) {
                $count += $stack['count'];
                return $stack;
            }
            $removed = $maximum < 0 ? $stack['count'] : min($stack['count'], $maximum - $count);
            $count += $removed;
            $stack['count'] -= $removed;
            return $stack['count'] > 0 ? $stack : null;
        };
        foreach ([...range(36, 44), ...range(9, 35), 8, 7, 6, 5, 45, 1, 2, 3, 4] as $slot) {
            if ($maximum > 0 && $count >= $maximum) {
                break;
            }
            $stack = $take($player->inventory[$slot] ?? null);
            if ($stack === null) {
                unset($player->inventory[$slot]);
            } else {
                $player->inventory[$slot] = $stack;
            }
        }
        if ($maximum <= 0 || $count < $maximum) {
            $player->cursor = $take($player->cursor);
        }
        if ($maximum !== 0 && $count > 0) {
            $this->syncInventory($player);
        }
        return $count;
    }

    /** ItemPredicateArgument: an item, an item tag or "*"; component tests are not compared. */
    private function itemPredicate(string $text): \Closure
    {
        if (preg_match('/\A(\*|#?[a-z0-9_.\-:\/]+)/', $text, $match) !== 1) {
            throw new \InvalidArgumentException('Invalid item predicate');
        }
        $id = $match[1];
        if ($id === '*') {
            return static fn (int $item): bool => true;
        }
        $names = str_starts_with($id, '#') ? ($this->version->registryTags('minecraft:item')[str_contains($id, ':') ? substr($id, 1) : 'minecraft:' . substr($id, 1)] ?? []) : [preg_replace('/\Aminecraft:/', '', $id)];
        $ids = array_flip(array_map(fn (string $name): int => $this->version->blocks['items'][$name] ?? -1, $names));
        return static fn (int $item): bool => isset($ids[$item]);
    }
}
