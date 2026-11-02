<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait ItemCommand
{
    /** LivingEntity.getEquipmentSlot: slot ID => protocol equipment slot and the equippable slot it holds. */
    private const ITEM_EQUIPMENT_SLOTS = [98 => [0, 'mainhand'], 99 => [1, 'offhand'], 100 => [2, 'feet'], 101 => [3, 'legs'], 102 => [4, 'chest'], 103 => [5, 'head'], 105 => [6, 'body'], 106 => [7, 'saddle']];

    /** Player window slots for the armor slot IDs. */
    private const ITEM_ARMOR_WINDOW = [100 => 8, 101 => 7, 102 => 6, 103 => 5];

    /** Block containers with stored slots, by their container sizes. */
    private const ITEM_CONTAINERS = ['chest' => 27, 'barrel' => 27, 'trapped_chest' => 27, 'furnace' => 3, 'blast_furnace' => 3, 'smoker' => 3];

    /**
     * ItemCommands replace, fill and override: copies of one item, or of a source's slots, into
     * each target's slots, or modify takes the slots' items through an inline modifier. Modifiers by ID
     * and slot sources other than slot names are not supported.
     */
    private function executeItem(Client $sender, array $arguments): void
    {
        $action = array_shift($arguments);
        $target = $this->itemHolder($sender, $arguments);
        $slotName = array_shift($arguments);
        $slots = ItemSlots::range($slotName) ?? throw new \InvalidArgumentException('Only slot names are supported as slot sources');
        if ($action === 'modify') {
            $this->modifyItems($sender, $target, $slots, $slotName, $this->itemModifier($arguments));
            return;
        }
        $known = null;
        if (array_shift($arguments) === 'with') {
            $parsed = ItemArgument::read(new StringReader($arguments[0]), ArgumentReader::registries($this->version, array_keys(self::CLOCKS)), static fn (string $item): bool => false);
            $itemName = substr($parsed['item'], 10);
            $count = (int) ($arguments[1] ?? 1);
            $maxStack = $this->version->catalog->items[$itemName]['max_stack'];
            // ItemInput.createItemStack: no more than a stack.
            if ($count > $maxStack) {
                throw new CommandFailure(['translate' => 'arguments.item.overstacked', 'with' => [['text' => $parsed['item']], $maxStack]]);
            }
            $stack = ['item' => $this->version->blocks['items'][$itemName], 'count' => $count] + array_filter([
                'damage' => $parsed['components']['minecraft:damage'] ?? null,
                'enchantments' => $parsed['components']['minecraft:enchantments'] ?? null,
                'storedEnchantments' => $parsed['components']['minecraft:stored_enchantments'] ?? null,
                'removedComponents' => $parsed['removed'] === [] ? null : $parsed['removed'],
            ], static fn (mixed $value): bool => $value !== null);
            // ItemStack.isEmpty: an air stack empties the slot.
            $items = [$itemName === 'air' ? null : $stack];
            $known = $this->itemComponent($itemName, $parsed['components'], $parsed['removed'], $count);
            // ERROR_TARGET_NO_CHANGES_KNOWN_ITEM escapes the stack itself: its toString, count then ID.
            $knownText = ['text' => $count . ' ' . $parsed['item']];
        } else {
            $source = $this->itemHolder($sender, $arguments);
            $sourceName = array_shift($arguments);
            $modifier = $arguments === [] ? null : $this->itemModifier($arguments);
            $sourceSlots = ItemSlots::range($sourceName) ?? throw new \InvalidArgumentException('Only slot names are supported as slot sources');
            $items = [];
            foreach ($this->itemTargets($source, false) as $holder) {
                foreach ($sourceSlots as $slot) {
                    if ($this->slotExists($holder, $slot)) {
                        $item = $this->slotItem($holder, $slot);
                        $items[] = $modifier === null ? $item : ItemModifier::apply($modifier, $item, $this->version);
                    }
                }
            }
            if ($items === []) {
                throw new CommandFailure(['translate' => 'commands.item.source.no_such_slot', 'with' => [['text' => $sourceName]]]);
            }
        }
        $selected = 0;
        $changed = [];
        foreach ($this->itemTargets($target, true) as $holder) {
            // ItemProvider.restart: each target takes the items from the first again.
            $next = 0;
            $count = 0;
            foreach ($slots as $slot) {
                if ($action === 'replace' && $next >= count($items)) {
                    break;
                }
                if (!$this->slotExists($holder, $slot)) {
                    continue;
                }
                ++$selected;
                $item = $next < count($items) ? $items[$next] : null;
                $next = $action === 'fill' ? ($next + 1) % count($items) : $next + 1;
                $count += (int) $this->setSlotItem($holder, $slot, $item);
            }
            if ($count > 0) {
                $changed[] = [$holder, $count];
                if (isset($holder['client'])) {
                    $this->syncInventory($holder['client']);
                }
            }
        }
        if ($selected === 0) {
            throw new CommandFailure(['translate' => 'commands.item.target.no_such_slot', 'with' => [['text' => $slotName]]]);
        }
        $this->itemReplaceFeedback($sender, $target, $changed, $known, $knownText ?? null);
    }

    /** The inline modifier the remaining arguments spell, already checked by the argument reader. */
    private function itemModifier(array $arguments): array
    {
        return ItemModifier::decode(Snbt::read(new StringReader(implode(' ', $arguments))), ArgumentReader::registries($this->version, array_keys(self::CLOCKS)));
    }

    /**
     * ItemCommands.modifyItem: each target's filled slots in the range take the modifier's result,
     * counted per target; empty slots are not selected.
     */
    private function modifyItems(Client $sender, array $target, array $slots, string $slotName, array $modifier): void
    {
        $selected = 0;
        $changed = [];
        foreach ($this->itemTargets($target, true) as $holder) {
            $count = 0;
            foreach ($slots as $slot) {
                if (!$this->slotExists($holder, $slot) || ($item = $this->slotItem($holder, $slot)) === null) {
                    continue;
                }
                ++$selected;
                $count += (int) $this->setSlotItem($holder, $slot, ItemModifier::apply($modifier, $item, $this->version));
            }
            if ($count > 0) {
                $changed[] = [$holder, $count];
                if (isset($holder['client'])) {
                    $this->syncInventory($holder['client']);
                }
            }
        }
        if ($selected === 0) {
            throw new CommandFailure(['translate' => 'commands.item.target.no_such_slot', 'with' => [['text' => $slotName]]]);
        }
        if ($changed === []) {
            throw new CommandFailure(['translate' => 'commands.item.target.failed']);
        }
        if (isset($target['block'])) {
            $this->sendSuccess($sender, ['translate' => 'commands.item.block.modify.success', 'with' => [$changed[0][1], ...$target['block']]], true);
        } elseif (count($changed) === 1) {
            $this->sendSuccess($sender, ['translate' => 'commands.item.entity.modify.success.single', 'with' => [$changed[0][1], $this->entityName($changed[0][0])]], true);
        } else {
            $this->sendSuccess($sender, ['translate' => 'commands.item.entity.modify.success.multiple', 'with' => [count($changed)]], true);
        }
    }

    /** The entities or block an item accessor names, taking its arguments. */
    private function itemHolder(Client $sender, array &$arguments): array
    {
        if (array_shift($arguments) === 'entity') {
            return ['entities' => $this->selectEntities(array_shift($arguments), $sender)];
        }
        $position = $this->loadedBlockPosition(array_splice($arguments, 0, 3), $sender);
        return ['block' => $position, 'dimension' => $this->sourceDimension($sender)];
    }

    /** @return list<array> each target holder; a block that is not a container fails as the target or the source. */
    private function itemTargets(array $holder, bool $target): array
    {
        if (isset($holder['entities'])) {
            return $holder['entities'];
        }
        $position = $holder['block'];
        $name = $this->version->catalog->blockName($this->worlds[$holder['dimension']]->get(...$position));
        if (!isset(self::ITEM_CONTAINERS[$name])) {
            throw new CommandFailure(['translate' => $target ? 'commands.item.target.not_a_container' : 'commands.item.source.not_a_container', 'with' => $position]);
        }
        return [['block' => $position, 'dimension' => $holder['dimension'], 'size' => self::ITEM_CONTAINERS[$name]]];
    }

    private function slotExists(array $holder, int $slot): bool
    {
        if (isset($holder['block'])) {
            return $slot < $holder['size'];
        }
        if (isset($holder['client'])) {
            return $slot === 499 || ($slot >= 500 && $slot < 504) || ($slot >= 0 && $slot < 36) || ($slot >= 200 && $slot < 227) || isset(self::ITEM_EQUIPMENT_SLOTS[$slot]);
        }
        // ItemEntity.getSlot: an item entity's stack is its slot 0 (contents).
        if (isset($holder['item'])) {
            return $slot === 0 && isset($this->itemEntities[$holder['item']]);
        }
        return isset($holder['mob']) && isset(self::ITEM_EQUIPMENT_SLOTS[$slot]);
    }

    private function playerWindowSlot(Client $player, int $slot): int
    {
        return match (true) {
            $slot < 9 => 36 + $slot,
            $slot < 36 => $slot,
            $slot === 98 => 36 + $player->heldSlot,
            $slot === 99 => 45,
            $slot >= 500 => $slot - 499,
            default => self::ITEM_ARMOR_WINDOW[$slot],
        };
    }

    private function slotItem(array $holder, int $slot): ?array
    {
        if (isset($holder['block'])) {
            return $this->worlds[$holder['dimension']]->container($holder['block'])['slots'][$slot] ?? null;
        }
        if (isset($holder['item'])) {
            return $this->itemEntities[$holder['item']]['stack'];
        }
        if (isset($holder['client'])) {
            if ($slot === 105 || $slot === 106) {
                return null;
            }
            if ($slot >= 200 && $slot < 227) {
                return $holder['client']->enderItems[$slot - 200] ?? null;
            }
            return $slot === 499 ? $holder['client']->cursor : ($holder['client']->inventory[$this->playerWindowSlot($holder['client'], $slot)] ?? null);
        }
        return $this->animals[$holder['mob']]['equipment'][self::ITEM_EQUIPMENT_SLOTS[$slot][0]] ?? null;
    }

    /** SlotAccess.set: armor, body and saddle slots only take items equippable there. */
    private function setSlotItem(array $holder, int $slot, ?array $stack): bool
    {
        if (isset($holder['block'])) {
            $world = $this->worlds[$holder['dimension']];
            $data = $world->container($holder['block']);
            $data['slots'][$slot] = $stack;
            if ($stack === null) {
                unset($data['slots'][$slot]);
            }
            $world->container($holder['block'], $data);
            $this->syncContainer($holder['block']);
            return true;
        }
        if (isset($holder['item'])) {
            // ItemEntity.setItem: trackers see the new stack; an emptied item is discarded on its next tick.
            if ($stack === null) {
                $this->itemEntities[$holder['item']]['discard'] = true;
            } else {
                $this->itemEntities[$holder['item']]['stack'] = $stack;
                $this->itemEntities[$holder['item']]['dirty'] = true;
            }
            return true;
        }
        if (isset(self::ITEM_EQUIPMENT_SLOTS[$slot]) && !in_array($slot, [98, 99, 103], true) && $stack !== null
            && ($this->version->catalog->items[$this->version->catalog->itemName($stack['item'])]['equip_slot'] ?? 'mainhand') !== self::ITEM_EQUIPMENT_SLOTS[$slot][1]) {
            return false;
        }
        if (isset($holder['client'])) {
            $player = $holder['client'];
            // A player can use neither the body nor the saddle slot, so only emptying them succeeds.
            if ($slot === 105 || $slot === 106) {
                return $stack === null;
            }
            if ($slot >= 200 && $slot < 227) {
                $player->enderItems[$slot - 200] = $stack;
                if ($stack === null) {
                    unset($player->enderItems[$slot - 200]);
                }
            } elseif ($slot === 499) {
                $player->cursor = $stack;
            } elseif ($stack === null) {
                unset($player->inventory[$this->playerWindowSlot($player, $slot)]);
            } else {
                $player->inventory[$this->playerWindowSlot($player, $slot)] = $stack;
            }
            return true;
        }
        $equipment = self::ITEM_EQUIPMENT_SLOTS[$slot][0];
        $mob = &$this->animals[$holder['mob']];
        // LivingEntity.detectEquipmentUpdates sends the changed slots on the mob's next tick.
        if (($mob['equipment'][$equipment] ?? null) !== $stack) {
            $mob['equipmentChanged'][$equipment] = true;
        }
        if ($stack === null) {
            unset($mob['equipment'][$equipment]);
        } else {
            $mob['equipment'][$equipment] = $stack;
        }
        unset($mob);
        return true;
    }

    /** EntityItemAccessor and BlockItemAccessor replies through CommandResponseTracker. */
    private function itemReplaceFeedback(Client $sender, array $target, array $changed, ?array $known, ?array $knownText): void
    {
        if ($changed === []) {
            throw new CommandFailure($known === null ? ['translate' => 'commands.item.target.failed'] : ['translate' => 'commands.item.target.failed.known_item', 'with' => [$knownText]]);
        }
        if (isset($target['block'])) {
            [$holder, $count] = $changed[0];
            $this->sendSuccess($sender, $known === null ? ['translate' => 'commands.item.block.replace.success', 'with' => [$count, ...$target['block']]]
                : ['translate' => 'commands.item.block.replace.success.known_item', 'with' => [$count, ...$target['block'], $known]], true);
            return;
        }
        if (count($changed) === 1) {
            [$holder, $count] = $changed[0];
            $this->sendSuccess($sender, $known === null ? ['translate' => 'commands.item.entity.replace.success.single', 'with' => [$count, $this->entityName($holder)]]
                : ['translate' => 'commands.item.entity.replace.success.single.known_item', 'with' => [$count, $this->entityName($holder), $known]], true);
            return;
        }
        $this->sendSuccess($sender, $known === null ? ['translate' => 'commands.item.entity.replace.success.multiple', 'with' => [count($changed)]]
            : ['translate' => 'commands.item.entity.replace.success.multiple.known_item', 'with' => [count($changed), $known]], true);
    }
}
