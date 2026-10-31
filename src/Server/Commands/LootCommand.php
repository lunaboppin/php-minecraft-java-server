<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;
use PhpMinecraftJavaServer\World\Inventory;

trait LootCommand
{
    /**
     * LootCommand: a loot table's drops, a block's mining drops, an entity's kill drops or a fishing
     * table's catch go to players, into a container, onto the ground, or into slots. Tables outside
     * the exported block and entity tables are not supported.
     */
    private function executeLoot(Client $sender, array $arguments, string $text): void
    {
        $reader = new StringReader((string) preg_replace('/\A\/?\s*loot\s+/', '', $text));
        $target = $this->lootTarget($reader, $sender);
        $reader->skipWhitespace();
        [$drops, $table] = $this->lootSource($reader, $sender);
        $received = match ($target[0]) {
            'give' => $this->lootGive($target[1], $drops),
            'insert' => $this->lootInsert($target[1], $drops),
            'spawn' => $this->lootSpawn($target[1], $drops),
            default => $this->lootReplace($target, $drops),
        };
        // LootCommand.callback: one stack is named with its count, more are counted.
        if (count($received) === 1) {
            $stack = $received[0];
            $name = $stack === null ? ['translate' => 'chat.square_brackets', 'with' => [['text' => '', 'extra' => [['translate' => 'block.minecraft.air']]]]]
                : $this->itemComponent($this->version->catalog->itemName($stack['item']), [], [], $stack['count']);
            $message = $table === null
                ? ['translate' => 'commands.drop.success.single', 'with' => [$stack['count'] ?? 0, $name]]
                : ['translate' => 'commands.drop.success.single_with_table', 'with' => [$stack['count'] ?? 0, $name, ['text' => $table]]];
        } else {
            $message = $table === null
                ? ['translate' => 'commands.drop.success.multiple', 'with' => [count($received)]]
                : ['translate' => 'commands.drop.success.multiple_with_table', 'with' => [count($received), ['text' => $table]]];
        }
        $this->sendSuccess($sender, $message, false);
    }

    private function lootWord(StringReader $reader): string
    {
        $reader->skipWhitespace();
        $start = $reader->cursor;
        $depth = 0;
        while ($reader->canRead() && ($reader->peek() !== ' ' || $depth > 0)) {
            $char = $reader->read();
            $depth += $char === '[' || $char === '{' ? 1 : ($char === ']' || $char === '}' ? -1 : 0);
        }
        return substr($reader->string, $start, $reader->cursor - $start);
    }

    private function lootPosition(StringReader $reader, Client $sender): array
    {
        return $this->loadedBlockPosition([$this->lootWord($reader), $this->lootWord($reader), $this->lootWord($reader)], $sender);
    }

    /** @return array{0: string, 1: mixed, 2?: mixed, 3?: int} */
    private function lootTarget(StringReader $reader, Client $sender): array
    {
        $kind = $this->lootWord($reader);
        switch ($kind) {
            case 'give':
                return ['give', $this->selectEntities($this->lootWord($reader), $sender, true)];
            case 'spawn':
                $position = [];
                for ($axis = 0; $axis < 3; ++$axis) {
                    $position[] = $this->lootWord($reader);
                }
                return ['spawn', $this->positionArguments($position, $sender)];
            case 'insert':
                return ['insert', $this->lootContainer($this->lootPosition($reader, $sender), $sender)];
            default:
                $holderKind = $this->lootWord($reader);
                $holder = $holderKind === 'entity'
                    ? ['entities' => $this->selectEntities($this->lootWord($reader), $sender)]
                    : ['block' => $this->lootPosition($reader, $sender), 'dimension' => $this->sourceDimension($sender)];
                $slot = (ItemSlots::range($this->lootWord($reader)) ?? throw new \InvalidArgumentException('Unknown slot'))[0];
                $reader->skipWhitespace();
                $count = 1;
                if ($reader->canRead() && ctype_digit($reader->peek())) {
                    $count = (int) $this->lootWord($reader);
                }
                return ['replace', $holder, $slot, $count];
        }
    }

    /** BlockItemAccessor.getContainer for insert: a container block, or not_a_container. */
    private function lootContainer(array $position, Client $sender): array
    {
        $dimension = $this->sourceDimension($sender);
        $name = $this->version->catalog->blockName($this->worlds[$dimension]->get(...$position));
        if (!isset(self::ITEM_CONTAINERS[$name])) {
            throw new CommandFailure(['translate' => 'commands.item.target.not_a_container', 'with' => $position]);
        }
        return ['block' => $position, 'dimension' => $dimension, 'size' => self::ITEM_CONTAINERS[$name]];
    }

    /**
     * The drops and, for mine and kill, the table they came from.
     *
     * @return array{list<array>, ?string}
     */
    private function lootSource(StringReader $reader, Client $sender): array
    {
        $kind = $this->lootWord($reader);
        switch ($kind) {
            case 'loot':
            case 'fish':
                $table = $this->lootTable($this->lootWord($reader));
                if ($kind === 'fish') {
                    $this->lootPosition($reader, $sender);
                }
                return [$this->lootStacks($table, []), null];
            case 'kill':
                $entity = $this->selectEntities($this->lootWord($reader), $sender, false, true)[0];
                $type = isset($entity['client']) ? 'player' : (isset($entity['mob']) ? $this->animals[$entity['mob']]['kind'] : null);
                if ($type === null || !$this->loot()->has('minecraft:entities/' . $type)) {
                    throw new CommandFailure(['translate' => 'commands.drop.no_loot_table.entity', 'with' => [$this->entityName($entity)]]);
                }
                $context = ['this' => ['type' => 'minecraft:' . $type, 'flags' => ['is_baby' => false, 'is_on_fire' => false]], 'damage' => ['type' => 'minecraft:magic'],
                    'attacker' => $this->lootEntity($sender), 'killed_by_player' => true];
                return [$this->lootStacks('minecraft:entities/' . $type, $context), 'minecraft:entities/' . $type];
            default:
                $position = $this->lootPosition($reader, $sender);
                $tool = $this->lootWord($reader);
                $state = $this->world->get(...$position);
                $name = 'minecraft:' . $this->version->catalog->blockName($state);
                $table = 'minecraft:blocks/' . substr($name, 10);
                if (!$this->loot()->has($table)) {
                    throw new CommandFailure(['translate' => 'commands.drop.no_loot_table.block', 'with' => [['translate' => $this->version->catalog->displayNames['blocks'][$name]['name']['translate'] ?? 'block.minecraft.' . substr($name, 10)]]]);
                }
                $held = match ($tool) {
                    'mainhand' => $sender->inventory[36 + $sender->heldSlot] ?? null,
                    'offhand' => $sender->inventory[45] ?? null,
                    '' => null,
                    default => ['item' => $this->version->blocks['items'][preg_replace('/^minecraft:|\[.*$/', '', $tool)] ?? 0],
                };
                $context = ['block' => $name, 'state' => array_map('strval', $this->version->catalog->properties($state)),
                    'tool' => $held === null ? null : ['item' => 'minecraft:' . $this->version->catalog->itemName($held['item']), 'enchantments' => $held['enchantments'] ?? []]];
                return [$this->lootStacks($table, $context), $table];
        }
    }

    /** A loot table argument: only the exported block and entity tables exist here. */
    private function lootTable(string $text): string
    {
        $id = str_contains($text, ':') ? $text : 'minecraft:' . $text;
        // The argument reader has reported unknown block and entity tables already.
        if (!$this->loot()->has($id)) {
            throw new \InvalidArgumentException('Only block and entity loot tables are supported so far');
        }
        return $id;
    }

    /** @return list<array> the table's drops as stacks */
    private function lootStacks(string $table, array $context): array
    {
        $stacks = [];
        foreach ($this->loot()->drops($table, $context) as [$drop, $count]) {
            $item = $this->version->blocks['items'][substr($drop, 10)] ?? null;
            if ($item !== null) {
                $stacks[] = ['item' => $item, 'count' => $count];
            }
        }
        return $stacks;
    }

    /** LootCommand.dropInventory for players: a stack counts once for each player who took some of it. */
    private function lootGive(array $players, array $drops): array
    {
        $received = [];
        foreach ($drops as $drop) {
            foreach ($players as $player) {
                if ($this->giveStack($player['client'], $drop) < $drop['count']) {
                    $received[] = $drop;
                }
            }
        }
        foreach ($players as $player) {
            $this->syncInventory($player['client']);
        }
        return $received;
    }

    /** LootCommand.insertItems: the stacks that changed the container. */
    private function lootInsert(array $holder, array $drops): array
    {
        $world = $this->worlds[$holder['dimension']];
        $data = $world->container($holder['block']);
        $slots = $data['slots'] ?? [];
        $received = [];
        foreach ($drops as $drop) {
            if (Inventory::add($slots, $drop, $this->version->catalog, range(0, $holder['size'] - 1)) < $drop['count']) {
                $received[] = $drop;
            }
        }
        $data['slots'] = $slots;
        $world->container($holder['block'], $data);
        $this->syncContainer($holder['block']);
        return $received;
    }

    /** LootCommand.dropInWorld: each stack as an item entity at the position. */
    private function lootSpawn(array $position, array $drops): array
    {
        foreach ($drops as $drop) {
            $this->dropStack($position[0], $position[1], $position[2], $drop);
        }
        return $drops;
    }

    /** LootCommand.setSlots: count slots from the first take the drops in turn, then empty. */
    private function lootReplace(array $target, array $drops): array
    {
        [, $holder, $first, $count] = $target;
        $received = [];
        foreach ($this->itemTargets($holder, true) as $owner) {
            for ($index = 0; $index < $count; ++$index) {
                $slot = $first + $index;
                $drop = $drops[$index] ?? null;
                if ($this->slotExists($owner, $slot) && $this->setSlotItem($owner, $slot, $drop)) {
                    $received[] = $drop;
                }
            }
            if (isset($owner['client'])) {
                $this->syncInventory($owner['client']);
            }
        }
        return $received;
    }
}
