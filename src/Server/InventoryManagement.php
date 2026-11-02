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
    /** The furnace blocks, with the recipe type each cooks (AbstractFurnaceBlockEntity's recipeType). */
    private const FURNACES = ['furnace' => 'smelting', 'blast_furnace' => 'blasting', 'smoker' => 'smoking'];

    private Crafting $crafting;

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
                'crafting' => 10,'furnace','blast_furnace','smoker' => 3,default => $c->windowPartner === null ? 27 : 54
            };
            $items = array_fill(0, $count + 36, null);
            $slots = match ($c->windowType) {
                'crafting' => $c->craftGrid, 'ender' => $c->enderItems, default => $this->windowContainerSlots($c)
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
                'crafting' => 10,'furnace','blast_furnace','smoker' => 3,default => $c->windowPartner === null ? 27 : 54
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
                $this->storeWindowContainerSlots($c, $slots);
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

    /** The open container's slots; a double chest's right half fills 0-26 and its left half 27-53. */
    private function windowContainerSlots(Client $c): array
    {
        $slots = $this->world->container($c->windowPosition)['slots'];
        if ($c->windowPartner !== null) {
            foreach ($this->world->container($c->windowPartner)['slots'] as $slot => $stack) {
                $slots[$slot + 27] = $stack;
            }
        }
        return $slots;
    }

    private function storeWindowContainerSlots(Client $c, array $slots): void
    {
        $halves = $c->windowPartner === null ? [[$c->windowPosition, 0]] : [[$c->windowPosition, 0], [$c->windowPartner, 27]];
        foreach ($halves as [$position, $offset]) {
            $data = $this->world->container($position);
            // FurnaceResultSlot.checkTakeAchievements: taking any of the output pops the experience.
            $taken = isset(self::FURNACES[$c->windowType]) && ($slots[2]['count'] ?? 0) < ($data['slots'][2]['count'] ?? 0);
            $data['slots'] = [];
            foreach ($slots as $slot => $stack) {
                if ($slot >= $offset && $slot < $offset + 27) {
                    $data['slots'][$slot - $offset] = $stack;
                }
            }
            if ($taken) {
                $this->popFurnaceExperience($data, $c->x, $c->y, $c->z);
            }
            $this->world->container($position, $data);
        }
    }

    private function syncContainer(array $position): void
    {
        foreach ($this->localPlayers() as $player) {
            if ($player->windowPosition === $position || $player->windowPartner === $position) {
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
            if ($c->windowPartner !== null) {
                $this->containerClosed($c, $c->windowPartner);
            }
        }
        $c->dragSlots = [];
        $c->dragType = -1;
        $c->windowType = 'player';
        $c->windowPosition = null;
        $c->windowPartner = null;
        $c->windowId = 0;
    }

    /** A double chest's window holds the right half's slots, then the left half's. */
    private function openWindow(Client $c, string $type, array $position, ?array $partner = null): void
    {
        // ServerPlayer.openMenu closes an open container first.
        $this->closeWindow($c, $c->windowType !== 'player');
        // ServerPlayer.nextContainerCounter: window IDs run from 1 to 100.
        $c->windowId = $c->containerCounter = $c->containerCounter % 100 + 1;
        // CompoundContainer for a double chest: the right half first.
        if ($partner !== null && $this->version->catalog->properties($this->world->get(...$position))['type'] === 'left') {
            [$position, $partner] = [$partner, $position];
        }
        $c->windowType = $type;
        $c->windowPosition = $position;
        $c->windowPartner = $partner;
        $c->menuRevision = 0;
        $menu = match ($type) {
            'crafting' => 'crafting','furnace','blast_furnace','smoker' => $type,default => $partner === null ? 'generic_9x3' : 'generic_9x6'
        };
        // The block entity's default name, or the crafting table's.
        $title = match ($type) {
            'crafting' => 'container.crafting','furnace','blast_furnace','smoker' => 'container.' . $type,'ender' => 'container.enderchest',
            default => $partner !== null ? 'container.chestDouble' : ($this->version->catalog->blockName($this->world->get(...$position)) === 'barrel' ? 'container.barrel' : 'container.chest')
        };
        // The menu's constructor opens the container before the screen is sent.
        if ($type === 'chest' || $type === 'ender') {
            $this->containerOpened($c, $position);
            if ($partner !== null) {
                $this->containerOpened($c, $partner);
            }
        }
        $c->send($this->version, 'open_screen', Binary::writeVarInt($c->windowId) . Binary::writeVarInt($this->version->blocks['menus'][$menu]) . \PhpMinecraftJavaServer\Protocol\Nbt::component(['translate' => $title]));
        $this->syncInventory($c);
        if (isset(self::FURNACES[$type])) {
            // AbstractContainerMenu.sendAllDataToRemote: every data slot when the menu opens.
            $c->furnaceData = [];
            $this->sendFurnaceData($c, $this->world->container($position));
        }
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
        // A double chest stays open while both halves are still joined.
        if ($c->windowPartner !== null && $this->chestPartner($c->windowPosition) !== $c->windowPartner) {
            return false;
        }
        return match ($c->windowType) {
            'crafting' => $name === 'crafting_table','furnace','blast_furnace','smoker' => $name === $c->windowType,'chest' => in_array($name, ['chest', 'trapped_chest', 'barrel'], true),'ender' => $name === 'ender_chest',default => true
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
                $output = isset(self::FURNACES[$c->windowType]) && $slot === 2;
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
        if (isset(self::FURNACES[$c->windowType]) && $slot === 2 && $c->cursor !== null && $mode === 0) {
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
            if (isset(self::FURNACES[$c->windowType]) && $slot >= 3) {
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

    /** AbstractFurnaceBlockEntity.getBurnDuration: the ticks a fuel burns for in a furnace, from its cooking_fuel component. */
    private function fuelTicks(int $item): int
    {
        return $this->version->catalog->furnaceFuel['furnace'][$this->version->catalog->itemName($item) ?? ''][0] ?? 0;
    }

    /**
     * AbstractFurnaceBlockEntity.serverTick for each furnace in a ticking chunk: fuel burns down,
     * a new fuel item is lit only when the input can be smelted, the input cooks while lit and cools
     * twice as fast when not, and the block's lit state follows.
     */
    private function tickFurnaces(): void
    {
        $catalog = $this->version->catalog;
        foreach ($this->world->containerKeys() as $key) {
            $position = array_map('intval', explode(':', $key));
            [$x, $y, $z] = $position;
            $type = $catalog->blockName($this->world->get($x, $y, $z));
            if (!isset(self::FURNACES[$type]) || !$this->mobIsTicking(['x' => $x + 0.5, 'z' => $z + 0.5])) {
                continue;
            }
            $data = $this->world->container($position);
            $before = $data;
            $slots = $data['slots'];
            $data['cookTotal'] ??= 0;
            $data['speed'] ??= 1.0;
            // AbstractFurnaceBlockEntity.setItem: a different input starts cooking over.
            $input = $slots[0] ?? null;
            $signature = $input === null ? null : json_encode(array_diff_key($input, ['count' => true]));
            if ($signature !== ($data['input'] ?? null)) {
                $data['input'] = $signature;
                if ($signature !== null) {
                    $recipe = $this->crafting->smelt($input['item'], self::FURNACES[$type]);
                    $data['cookTotal'] = $recipe === null ? 200 : $this->furnaceCookTime($recipe, $data['speed']);
                } else {
                    $data['cookTotal'] = 200;
                }
                $data['cook'] = 0;
            }
            $wasLit = $data['burn'] > 0;
            $lit = false;
            if ($wasLit) {
                --$data['burn'];
                $lit = $data['burn'] > 0;
            }
            $fuel = $slots[1] ?? null;
            if ($lit || ($fuel !== null && $input !== null)) {
                $recipe = $input === null ? null : $this->crafting->smelt($input['item'], self::FURNACES[$type]);
                if ($input === null) {
                    $data['cook'] = 0;
                } elseif ($recipe !== null) {
                    $output = $slots[2] ?? null;
                    $result = $recipe['result'];
                    $max = min($catalog->item($result['item'])['max_stack'], 99);
                    if ($output === null || (Inventory::same($output, $result, $catalog) && $output['count'] + $result['count'] <= $max)) {
                        if (!$lit) {
                            $name = $fuel === null ? '' : ($catalog->itemName($fuel['item']) ?? '');
                            [$burn, $speed] = $catalog->furnaceFuel[$type][$name] ?? [0, 1.0];
                            $data['burn'] = $data['fuelTotal'] = $burn;
                            $data['speed'] = $speed;
                            if ($data['cookTotal'] > 0 && $data['cook'] < $data['cookTotal']) {
                                $ratio = self::f32($data['cook'] / $data['cookTotal']);
                                $data['cookTotal'] = $this->furnaceCookTime($recipe, $speed);
                                $data['cook'] = (int) ceil(self::f32($ratio * $data['cookTotal']));
                            }
                            if ($burn > 0) {
                                // AbstractFurnaceBlockEntity.consumeFuel: a bucket of lava leaves its bucket.
                                Inventory::consume($slots, 1);
                                if ($name === 'lava_bucket') {
                                    if (isset($slots[1])) {
                                        $this->dropContainerStack($x, $y, $z, ['item' => $this->version->blocks['items']['bucket'], 'count' => 1]);
                                    } else {
                                        $slots[1] = ['item' => $this->version->blocks['items']['bucket'], 'count' => 1];
                                    }
                                }
                                $lit = true;
                            }
                        }
                        if ($lit) {
                            if (++$data['cook'] >= $data['cookTotal']) {
                                $data['cook'] = 0;
                                $data['cookTotal'] = $this->furnaceCookTime($recipe, $data['speed']);
                                Inventory::consume($slots, 0);
                                $slots[2] = array_replace($result, ['count' => ($output['count'] ?? 0) + $result['count']]);
                                // AbstractFurnaceBlockEntity.setRecipeUsed: counted until the output is taken.
                                $data['recipesUsed'][$recipe['name']] = ($data['recipesUsed'][$recipe['name']] ?? 0) + 1;
                            }
                        } else {
                            $data['cook'] = 0;
                        }
                    } else {
                        $data['cook'] = 0;
                    }
                }
            } elseif ($data['cook'] > 0) {
                $data['cook'] = max(0, min($data['cookTotal'], $data['cook'] - 2));
            }
            $data['slots'] = $slots;
            $newInput = $slots[0] ?? null;
            $data['input'] = $newInput === null ? null : json_encode(array_diff_key($newInput, ['count' => true]));
            if ($data !== $before) {
                $this->world->container($position, $data);
            }
            if ($wasLit !== $lit) {
                $props = $catalog->properties($this->world->get($x, $y, $z));
                $props['lit'] = $lit ? 'true' : 'false';
                $this->setBlock($x, $y, $z, $catalog->state($type, $props));
            }
            $this->syncFurnaceViewers($position, $data, $slots !== $before['slots']);
        }
    }

    /** AbstractFurnaceBlockEntity.getTotalCookTime: the recipe's time, divided by the fuel's speed. */
    private function furnaceCookTime(array $recipe, float $speed): int
    {
        return $speed > 0.0 ? (int) ceil($recipe['ticks'] / self::f32($speed)) : $recipe['ticks'];
    }

    /** AbstractContainerMenu.broadcastChanges: the slots, then each data slot that changed, to those with it open. */
    private function syncFurnaceViewers(array $position, array $data, bool $slotsChanged): void
    {
        foreach ($this->localPlayers() as $p) {
            if (!isset(self::FURNACES[$p->windowType]) || $p->windowPosition !== $position) {
                continue;
            }
            if ($slotsChanged) {
                $this->syncInventory($p);
            }
            $this->sendFurnaceData($p, $data);
        }
    }

    /** The furnace's lit time, fuel time, cooking time and total cooking time that changed since last sent. */
    private function sendFurnaceData(Client $c, array $data): void
    {
        foreach ([$data['burn'] ?? 0, $data['fuelTotal'] ?? 0, $data['cook'] ?? 0, $data['cookTotal'] ?? 0] as $property => $value) {
            if (($c->furnaceData[$property] ?? null) !== $value) {
                $c->furnaceData[$property] = $value;
                $c->send($this->version, 'container_set_data', Binary::writeVarInt($c->windowId) . pack('nn', $property, $value & 0xFFFF));
            }
        }
    }

    /**
     * AbstractFurnaceBlockEntity.getRecipesToAwardAndPopExperience: for each recipe smelted since,
     * its experience times the count, the fraction a chance of one more point, then none are counted.
     */
    private function popFurnaceExperience(array &$data, float $x, float $y, float $z): void
    {
        foreach ($data['recipesUsed'] ?? [] as $name => $count) {
            $recipe = $this->crafting->recipe((string) $name);
            if ($recipe === null) {
                continue;
            }
            $total = unpack('g', pack('g', $count * unpack('g', pack('g', $recipe['experience']))[1]))[1];
            $points = (int) floor($total);
            $fraction = $total - $points;
            if ($fraction !== 0.0 && mt_rand() / (mt_getrandmax() + 1) < $fraction) {
                ++$points;
            }
            $this->awardExperience($x, $y, $z, $points);
        }
        $data['recipesUsed'] = [];
    }
}
