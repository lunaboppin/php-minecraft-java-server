<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Protocol\ProtocolException;
use PhpMinecraftJavaServer\World\Inventory;
use PhpMinecraftJavaServer\World\Crafting;

trait InventoryManagement
{
    private Crafting $crafting;

    private array $activeFurnaces = [];

    private function storageSlots(): array
    {
        return array_merge(range(36, 44), range(9, 35));
    }

    /** Main hand, offhand, feet, legs, chest, head: player menu slots differ from equipment ordinals. */
    private function playerEquipment(Client $c): array
    {
        return array_map(static fn (int $slot): ?array => $c->inventory[$slot] ?? null, [36 + $c->heldSlot, 45, 8, 7, 6, 5]);
    }

    private function syncEquipment(Client $c): void
    {
        $equipment = $this->playerEquipment($c);
        $changed = [];
        foreach ($equipment as $slot => $stack) {
            $encoded = Packets::stack($stack, $this->version->blocks['component_damage'], $this->version->catalog->itemStacks);
            if (($c->lastEquipment[$slot] ?? null) !== $encoded) {
                $changed[$slot] = $stack;
                $c->lastEquipment[$slot] = $encoded;
            }
        }
        if ($changed === []) {
            return;
        }
        $this->applyEquipmentAttributes($c, $changed);
        $payload = Packets::equipment($c->entityId, $changed, $this->version->blocks['component_damage'], $this->version->catalog->itemStacks);
        foreach ($this->players() as $viewer) {
            if (isset($viewer->entities[$c->entityId])) {
                $viewer->send($this->version, 'set_equipment', $payload);
            }
        }
    }

    private function giveStack(Client $c, array $stack): int
    {
        return Inventory::add($c->inventory, $stack, $this->version->catalog, $this->storageSlots());
    }

    private function menuItems(Client $c): array
    {
        if ($c->windowType === 'player') {
            $items = array_fill(0, 46, null);
            foreach ($c->inventory as $slot => $stack) {
                if ($slot > 0 && $slot <= 45) {
                    $items[$slot] = $stack;
                }
            }
        } else {
            $count = match ($c->windowType) {
                'crafting' => 10,'furnace' => 3,default => 27
            };
            $items = array_fill(0, $count + 36, null);
            $slots = match ($c->windowType) {
                'crafting' => $c->craftGrid, 'ender' => $c->enderItems, default => $this->world->container($c->windowPosition)['slots']
            };
            foreach ($slots as $slot => $stack) {
                if ($slot >= 0 && $slot < $count) {
                    $items[$slot] = $stack;
                }
            }
            for ($i = 0; $i < 36; ++$i) {
                $items[$count + $i] = $c->inventory[$i + 9] ?? null;
            }
        }
        if (in_array($c->windowType, ['player', 'crafting'], true)) {
            $size = $c->windowType === 'player' ? 2 : 3;
            $grid = [];
            for ($i = 1; $i <= $size * $size; ++$i) {
                $grid[] = $items[$i] ?? null;
            }
            $items[0] = $this->crafting->match($grid, $size)['result'] ?? null;
        }
        return $items;
    }

    private function writeMenu(Client $c, array $items): void
    {
        if ($c->windowType === 'player') {
            for ($i = 1; $i <= 45; ++$i) {
                if (isset($items[$i])) {
                    $c->inventory[$i] = $items[$i];
                } else {
                    unset($c->inventory[$i]);
                }
            }
        } else {
            $count = match ($c->windowType) {
                'crafting' => 10,'furnace' => 3,default => 27
            };
            $slots = [];
            for ($i = 0; $i < $count; ++$i) {
                if (isset($items[$i]) && !($c->windowType === 'crafting' && $i === 0)) {
                    $slots[$i] = $items[$i];
                }
            }
            if ($c->windowType === 'crafting') {
                $c->craftGrid = $slots;
            } elseif ($c->windowType === 'ender') {
                $c->enderItems = $slots;
            } else {
                $data = $this->world->container($c->windowPosition);
                $data['slots'] = $slots;
                $this->world->container($c->windowPosition, $data);
            }
            for ($i = 0; $i < 36; ++$i) {
                if (isset($items[$count + $i])) {
                    $c->inventory[$i + 9] = $items[$count + $i];
                } else {
                    unset($c->inventory[$i + 9]);
                }
            }
        }
    }

    private function syncInventory(Client $c): void
    {
        if ($c->dimension !== $this->world->dimension) {
            $this->withDimension($c->dimension, fn () => $this->syncInventory($c));
            return;
        }
        $revision = $c->windowType === 'player' ? ++$c->inventoryRevision : ++$c->menuRevision;
        $c->send($this->version, 'container_set_content', Packets::inventory($c->windowId, $revision, $this->menuItems($c), $c->cursor, $this->version->blocks['component_damage'], $this->version->catalog->itemStacks));
    }

    private function syncContainer(array $position): void
    {
        foreach ($this->localPlayers() as $player) {
            if ($player->windowPosition === $position) {
                $this->syncInventory($player);
            }
        }
    }

    private function closeWindow(Client $c, bool $notify = false): void
    {
        if ($c->dimension !== $this->world->dimension) {
            $this->withDimension($c->dimension, fn () => $this->closeWindow($c, $notify));
            return;
        }
        if ($c->windowType === 'crafting') {
            foreach ($c->craftGrid as $stack) {
                $left = $this->giveStack($c, $stack);
                if ($left > 0) {
                    $this->playerDrop($c, array_replace($stack, ['count' => $left]), false);
                }
            }
            $c->craftGrid = [];
        }
        if ($c->cursor !== null) {
            $left = $this->giveStack($c, $c->cursor);
            if ($left > 0) {
                $this->playerDrop($c, array_replace($c->cursor, ['count' => $left]), false);
            }$c->cursor = null;
        }
        if ($notify) {
            $c->send($this->version, 'container_close', Binary::writeVarInt($c->windowId));
        }
        if ($c->windowPosition !== null && ($c->windowType === 'chest' || $c->windowType === 'ender')) {
            $this->containerClosed($c, $c->windowPosition);
        }
        $c->dragSlots = [];
        $c->dragType = -1;
        $c->windowType = 'player';
        $c->windowPosition = null;
        $c->windowId = 0;
    }

    private function openWindow(Client $c, string $type, array $position): void
    {
        // ServerPlayer.openMenu closes an open container first.
        $this->closeWindow($c, $c->windowType !== 'player');
        // ServerPlayer.nextContainerCounter: window IDs run from 1 to 100.
        $c->windowId = $c->containerCounter = $c->containerCounter % 100 + 1;
        $c->windowType = $type;
        $c->windowPosition = $position;
        $c->menuRevision = 0;
        $menu = match ($type) {
            'crafting' => 'crafting','furnace' => 'furnace',default => 'generic_9x3'
        };
        // The block entity's default name, or the crafting table's.
        $title = match ($type) {
            'crafting' => 'container.crafting','furnace' => 'container.furnace','ender' => 'container.enderchest',
            default => $this->version->catalog->blockName($this->world->get(...$position)) === 'barrel' ? 'container.barrel' : 'container.chest'
        };
        // The menu's constructor opens the container before the screen is sent.
        if ($type === 'chest' || $type === 'ender') {
            $this->containerOpened($c, $position);
        }
        $c->send($this->version, 'open_screen', Binary::writeVarInt($c->windowId) . Binary::writeVarInt($this->version->blocks['menus'][$menu]) . \PhpMinecraftJavaServer\Protocol\Nbt::component(['translate' => $title]));
        if ($type === 'furnace') {
            $this->activeFurnaces[implode(':', $position)] = $position;
        }
        $this->syncInventory($c);
    }

    private function validWindow(Client $c): bool
    {
        if ($c->windowPosition === null) {
            return true;
        }[$x, $y, $z] = $c->windowPosition;
        if (($c->x - $x) ** 2 + ($c->y - $y) ** 2 + ($c->z - $z) ** 2 > 64) {
            return false;
        }
        $name = $this->version->catalog->blockName($this->world->get($x, $y, $z));
        return match ($c->windowType) {
            'crafting' => $name === 'crafting_table','furnace' => $name === 'furnace','chest' => in_array($name, ['chest', 'barrel'], true),'ender' => $name === 'ender_chest',default => true
        };
    }

    private function inventoryClick(Client $c, Binary $r): void
    {
        if (!$this->validWindow($c)) {
            $this->closeWindow($c, true);
            $this->syncInventory($c);
            return;
        }
        $window = $r->varInt();
        $revision = $r->varInt();
        $slot = $r->short();
        $button = $r->byte();
        $mode = $r->varInt();
        // Prediction hashes are deliberately not a source of authoritative item stacks.
        $r->bytes($r->remaining());
        if ($window !== $c->windowId || $c->gameMode === 3 || $c->dead) {
            $this->syncInventory($c);
            return;
        }
        if ($revision < 0) {
            throw new ProtocolException('Invalid inventory revision');
        }
        $items = $this->menuItems($c);
        $count = count($items);
        if ($mode === 5) {
            $stage = $button & 3;
            $type = $button >> 2;
            if ($stage === 0) {
                $c->dragSlots = [];
                $c->dragType = $type <= 1 || ($type === 2 && $c->gameMode === 1) ? $type : -1;
                return;
            }
            if ($stage === 1 && $c->cursor !== null && $c->dragType === $type && $slot >= 0 && $slot < $count) {
                $result = in_array($c->windowType, ['player', 'crafting'], true) && $slot === 0;
                $output = $c->windowType === 'furnace' && $slot === 2;
                $stack = $items[$slot] ?? null;
                $max = $this->version->catalog->item($c->cursor['item'])['max_stack'];
                if (!$result && !$output && ($stack === null || Inventory::same($stack, $c->cursor, $this->version->catalog)) && ($stack['count'] ?? 0) < $max && ($type === 2 || count($c->dragSlots) < $c->cursor['count'])) {
                    $c->dragSlots[$slot] = true;
                }return;
            }
            if ($stage === 2 && $c->cursor !== null && $c->dragType === $type && $c->dragSlots !== []) {
                $max = $this->version->catalog->item($c->cursor['item'])['max_stack'];
                $each = $type === 0 ? intdiv($c->cursor['count'], count($c->dragSlots)) : ($type === 1 ? 1 : $max);
                foreach ($c->dragSlots as $target => $_) {
                    $existing = $items[$target] ?? null;
                    if ($existing !== null && !Inventory::same($existing, $c->cursor, $this->version->catalog)) {
                        continue;
                    }$move = min($each, $max - ($existing['count'] ?? 0), $type === 2 ? $max : $c->cursor['count']);
                    if ($move > 0) {
                        $items[$target] = array_replace($c->cursor, ['count' => ($existing['count'] ?? 0) + $move]);
                        if ($type !== 2) {
                            $c->cursor['count'] -= $move;
                        }
                    }
                }
                if ($c->cursor['count'] === 0) {
                    $c->cursor = null;
                }$this->writeMenu($c, $items);
            }
            $c->dragSlots = [];
            $c->dragType = -1;
            $this->syncInventory($c);
            if ($c->windowPosition !== null && $c->windowType !== 'crafting') {
                $this->syncContainer($c->windowPosition);
            }return;
        }
        $c->dragSlots = [];
        $c->dragType = -1;
        if ($slot === -999) {
            if ($c->cursor !== null) {
                $n = $button === 1 ? 1 : $c->cursor['count'];
                $this->playerDrop($c, array_replace($c->cursor, ['count' => $n]), false);
                $c->cursor['count'] -= $n;
                if ($c->cursor['count'] === 0) {
                    $c->cursor = null;
                }
            }
            $this->syncInventory($c);
            return;
        }
        if ($slot === -1 && $mode === 0) {
            $this->syncInventory($c);
            return;
        }
        if ($slot < 0 || $slot >= $count || $mode < 0 || $mode > 6) {
            throw new ProtocolException('Invalid inventory click');
        }
        $crafting = in_array($c->windowType, ['player', 'crafting'], true);
        if ($crafting && $slot === 0) {
            $this->takeCraftResult($c, $items, $mode === 1);
            $this->writeMenu($c, $items);
            $this->syncInventory($c);
            return;
        }
        if ($c->windowType === 'furnace' && $slot === 2 && $c->cursor !== null && $mode === 0) {
            $output = $items[2];
            if (Inventory::same($output, $c->cursor, $this->version->catalog)) {
                $max = $this->version->catalog->item($output['item'])['max_stack'];
                $take = min($max - $c->cursor['count'], $output['count']);
                $c->cursor['count'] += $take;
                Inventory::consume($items, 2, $take);
            }
        } elseif ($mode === 0 && $button <= 1) {
            Inventory::pickup($items, $c->cursor, $slot, $button === 1, $this->version->catalog);
        } elseif ($mode === 1 && $items[$slot] !== null) {
            $containerSize = $c->windowType === 'player' ? 9 : $count - 36;
            $targets = $slot < $containerSize ? range($containerSize, $count - 1) : ($c->windowType === 'player' ? ($slot < 36 ? range(36, 44) : range(9, 35)) : range(0, $containerSize - 1));
            if ($c->windowType === 'furnace' && $slot >= 3) {
                $targets = $this->fuelTicks($items[$slot]['item']) > 0 ? [1] : [0];
            }
            if ($c->windowType === 'crafting') {
                $targets = $slot < 10 ? range(10, 45) : range(1, 9);
            }
            $stack = $items[$slot];
            $items[$slot] = null;
            $left = Inventory::add($items, $stack, $this->version->catalog, $targets);
            if ($left > 0) {
                $items[$slot] = array_replace($stack, ['count' => $left]);
            }
        } elseif ($mode === 2 && ($button <= 8 || $button === 40)) {
            $hotbar = $button === 40 ? 45 : 36 + $button;
            $target = $c->windowType === 'player' ? $hotbar : ($hotbar === 45 ? -1 : $count - 36 + $hotbar - 9);
            if ($target >= 0 && $target < $count) {
                [$items[$slot], $items[$target]] = [$items[$target], $items[$slot]];
            }
        } elseif ($mode === 3 && $c->gameMode === 1 && $items[$slot] !== null) {
            $c->cursor = array_replace($items[$slot], ['count' => $this->version->catalog->item($items[$slot]['item'])['max_stack']]);
        } elseif ($mode === 4 && $items[$slot] !== null) {
            $n = $button === 0 ? 1 : $items[$slot]['count'];
            $this->playerDrop($c, array_replace($items[$slot], ['count' => $n]), false);
            Inventory::consume($items, $slot, $n);
        } elseif ($mode === 6 && $c->cursor !== null) {
            $max = $this->version->catalog->item($c->cursor['item'])['max_stack'];
            foreach ($items as $i => $stack) {
                if (($crafting && $i === 0) || !Inventory::same($stack, $c->cursor, $this->version->catalog)) {
                    continue;
                }$take = min($max - $c->cursor['count'], $stack['count']);
                if ($take > 0) {
                    $c->cursor['count'] += $take;
                    Inventory::consume($items, $i, $take);
                }
            }
        }
        $this->writeMenu($c, $items);
        $this->syncInventory($c);
        if ($c->windowPosition !== null && $c->windowType !== 'crafting') {
            $this->syncContainer($c->windowPosition);
        }
    }

    private function takeCraftResult(Client $c, array &$items, bool $shift): void
    {
        $size = $c->windowType === 'player' ? 2 : 3;
        for ($attempt = 0; $attempt < ($shift ? 64 : 1); ++$attempt) {
            $grid = [];
            for ($i = 1; $i <= $size * $size; ++$i) {
                $grid[] = $items[$i] ?? null;
            }
            $recipe = $this->crafting->match($grid, $size);
            if ($recipe === null) {
                break;
            }$result = $recipe['result'];
            if ($shift) {
                $copy = $items;
                $targets = $c->windowType === 'player' ? range(9, 44) : range(10, 45);
                if (Inventory::add($copy, $result, $this->version->catalog, $targets) > 0) {
                    break;
                }$items = $copy;
            } else {
                if ($c->cursor !== null && (!Inventory::same($c->cursor, $result, $this->version->catalog) || $c->cursor['count'] + $result['count'] > $this->version->catalog->item($result['item'])['max_stack'])) {
                    break;
                }
                $c->cursor = array_replace($result, ['count' => ($c->cursor['count'] ?? 0) + $result['count']]);
            }
            foreach ($recipe['used'] as $slot) {
                $itemName = $this->version->catalog->itemName($items[$slot + 1]['item']);
                Inventory::consume($items, $slot + 1);
                $remainder = in_array($itemName, ['milk_bucket', 'water_bucket', 'lava_bucket'], true) ? 'bucket' : ($itemName === 'honey_bottle' ? 'glass_bottle' : null);
                if ($remainder !== null) {
                    Inventory::add($items, ['item' => $this->version->blocks['items'][$remainder], 'count' => 1], $this->version->catalog, $c->windowType === 'player' ? range(9, 44) : range(10, 45));
                }
            }
        }
    }

    private function fuelTicks(int $item): int
    {
        $name = $this->version->catalog->itemName($item) ?? '';
        return match (true) {
            $name === 'coal_block' => 16000,in_array($name, ['coal', 'charcoal'], true) => 1600, $name === 'lava_bucket' => 20000, $name === 'blaze_rod' => 2400, $name === 'stick' => 100,str_ends_with($name, '_planks') || str_ends_with($name, '_log') || str_ends_with($name, '_wood') => 300,str_starts_with($name, 'wooden_') => 200,default => 0
        };
    }

    private function tickFurnaces(): void
    {
        if ($this->ticks % 10 !== 0) {
            return;
        }
        foreach ($this->activeFurnaces as $key => $position) {
            [$x, $y, $z] = $position;
            $near = false;
            foreach ($this->localPlayers() as $p) {
                if (($p->x - $x) ** 2 + ($p->z - $z) ** 2 < 128 ** 2) {
                    $near = true;
                    break;
                }
            }
            if (!$near || $this->version->catalog->blockName($this->world->get($x, $y, $z)) !== 'furnace') {
                unset($this->activeFurnaces[$key]);
                continue;
            }
            $data = $this->world->container($position);
            $slots = $data['slots'];
            $input = $slots[0] ?? null;
            $recipe = $input === null ? null : $this->crafting->smelt($input['item']);
            $output = $slots[2] ?? null;
            $ready = $recipe !== null && ($output === null || (Inventory::same($output, $recipe['result'], $this->version->catalog) && $output['count'] + $recipe['result']['count'] <= $this->version->catalog->item($output['item'])['max_stack']));
            $wasLit = $data['burn'] > 0;
            $data['burn'] = max(0, $data['burn'] - 10);
            if ($data['burn'] === 0 && $ready && isset($slots[1])) {
                $fuel = $this->fuelTicks($slots[1]['item']);
                if ($fuel > 0) {
                    $name = $this->version->catalog->itemName($slots[1]['item']);
                    Inventory::consume($slots, 1);
                    if ($name === 'lava_bucket') {
                        $slots[1] = ['item' => $this->version->blocks['items']['bucket'], 'count' => 1];
                    }$data['burn'] = $data['fuelTotal'] = $fuel;
                }
            }
            if ($ready && $data['burn'] > 0) {
                $data['cook'] += 10;
                if ($data['cook'] >= $recipe['ticks']) {
                    Inventory::consume($slots, 0);
                    $slots[2] = array_replace($recipe['result'], ['count' => ($output['count'] ?? 0) + $recipe['result']['count']]);
                    $data['cook'] = 0;
                }
            } else {
                $data['cook'] = 0;
            }
            $data['slots'] = $slots;
            $this->world->container($position, $data);
            if ($wasLit !== ($data['burn'] > 0)) {
                $state = $this->world->get($x, $y, $z);
                $props = $this->version->catalog->properties($state);
                $props['lit'] = $data['burn'] > 0 ? 'true' : 'false';
                $this->setBlock($x, $y, $z, $this->version->catalog->state('furnace', $props));
            }
            foreach ($this->localPlayers() as $p) {
                if ($p->windowType === 'furnace' && $p->windowPosition === $position) {
                    $this->syncInventory($p);
                    foreach ([0 => $data['burn'], 1 => $data['fuelTotal'], 2 => $data['cook'], 3 => $recipe['ticks'] ?? 200] as $property => $value) {
                        $p->send($this->version, 'container_set_data', Binary::writeVarInt($p->windowId) . pack('nn', $property, min(32767, $value)));
                    }
                }
            }
        }
    }
}
