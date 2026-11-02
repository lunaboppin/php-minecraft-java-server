<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\World\Inventory;

/**
 * Brewing stands (BrewingStandBlockEntity and BrewingStandMenu): blaze powder gives twenty
 * brews; with a reagent that some recipe takes for one of the bottles, a brew runs for 400 ticks
 * and turns each bottle it has a recipe for. The bottles show on the block, hoppers fill and
 * empty it by its faces, and its menu keeps each slot to what may go there.
 */
trait BrewingStands
{
    private ?array $brewing = null;

    /** The brewing recipes and fuels, with the items any recipe takes as input or reagent. */
    private function brewingData(): array
    {
        if ($this->brewing === null) {
            $data = json_decode((string) file_get_contents($this->version->directory . '/brewing.json'), true, 512, JSON_THROW_ON_ERROR);
            $data['inputs'] = array_fill_keys(array_column($data['recipes'], 0), true);
            $data['reagents'] = array_fill_keys(array_column($data['recipes'], 2), true);
            $this->brewing = $data;
        }
        return $this->brewing;
    }

    private function brewingItem(array $stack): string
    {
        return 'minecraft:' . $this->version->catalog->itemName($stack['item']);
    }

    /** BrewingFuel: the fuel's uses and speed, or null when the item is no brewing fuel. */
    private function brewingFuel(?array $stack): ?array
    {
        return $stack === null ? null : ($this->brewingData()['fuels'][$this->brewingItem($stack)] ?? null);
    }

    /** RecipePropertySet.BREWING_REAGENTS. */
    private function brewingReagent(?array $stack): bool
    {
        return $stack !== null && isset($this->brewingData()['reagents'][$this->brewingItem($stack)]);
    }

    /** PotionIngredient.isPotionInput: a recipe's input item, or one tagged brewing_potion_inputs. */
    private function potionInput(?array $stack): bool
    {
        if ($stack === null) {
            return false;
        }
        $name = $this->brewingItem($stack);
        return isset($this->brewingData()['inputs'][$name]) || in_array(substr($name, 10), $this->version->registryTags('minecraft:item')['minecraft:brewing_potion_inputs'] ?? [], true);
    }

    /** The brewing recipe for the bottle and reagent, as [output item, output potion], or null. */
    private function brewingRecipe(?array $bottle, ?array $reagent): ?array
    {
        if ($bottle === null || $reagent === null) {
            return null;
        }
        $item = $this->brewingItem($bottle);
        $potion = $bottle['potion']['potion'] ?? null;
        $with = $this->brewingItem($reagent);
        foreach ($this->brewingData()['recipes'] as [$input, $inputPotion, $reagentItem, $output, $outputPotion]) {
            if ($input === $item && $inputPotion === $potion && $reagentItem === $with) {
                return [$output, $outputPotion];
            }
        }
        return null;
    }

    /** BrewingStandBlockEntity.isBrewable: a reagent, and a recipe for at least one bottle. */
    private function brewable(array $slots): bool
    {
        if (!$this->brewingReagent($slots[3] ?? null)) {
            return false;
        }
        for ($slot = 0; $slot < 3; ++$slot) {
            if ($this->brewingRecipe($slots[$slot] ?? null, $slots[3]) !== null) {
                return true;
            }
        }
        return false;
    }

    /** BrewingStandBlockEntity.serverTick for every brewing stand with contents. */
    private function tickBrewingStands(): void
    {
        $catalog = $this->version->catalog;
        foreach ($this->world->containerKeys() as $key) {
            $position = array_map('intval', explode(':', $key));
            [$x, $y, $z] = $position;
            if ($catalog->blockName($this->world->get($x, $y, $z)) !== 'brewing_stand' || !$this->mobIsTicking(['x' => $x + 0.5, 'z' => $z + 0.5])) {
                continue;
            }
            $data = $this->world->container($position);
            $before = $data;
            $data += ['brewTime' => 0, 'fuel' => 0, 'totalBrewTime' => 400, 'totalFuel' => 20, 'speed' => 1.0, 'ingredient' => null, 'bits' => null];
            $slots = &$data['slots'];
            $fuel = $this->brewingFuel($slots[4] ?? null);
            if ($data['fuel'] <= 0 && $fuel !== null) {
                $data['fuel'] = $data['totalFuel'] = $fuel['uses'];
                $data['speed'] = (float) $fuel['speed_multiplier'];
                Inventory::consume($slots, 4);
            }
            $brewable = $this->brewable($slots);
            $ingredient = $slots[3] ?? null;
            if ($data['brewTime'] > 0) {
                --$data['brewTime'];
                if ($data['brewTime'] === 0 && $brewable) {
                    $this->doBrew($position, $slots);
                } elseif (!$brewable || $ingredient === null || $ingredient['item'] !== $data['ingredient']) {
                    $data['brewTime'] = 0;
                }
            } elseif ($brewable && $data['fuel'] > 0) {
                $speed = $data['speed'] > 0.0 ? $data['speed'] : 1.0;
                --$data['fuel'];
                $data['brewTime'] = $data['totalBrewTime'] = (int) ceil(\PhpMinecraftJavaServer\World\Generation\Float32::round(400.0 / $speed));
                $data['ingredient'] = $ingredient['item'];
            }
            unset($slots);
            // The block shows a bottle in each filled bottle slot.
            $bits = [isset($data['slots'][0]), isset($data['slots'][1]), isset($data['slots'][2])];
            $changedBits = $bits !== $data['bits'];
            $data['bits'] = $bits;
            if ($data !== $before) {
                $this->world->container($position, $data);
            }
            if ($changedBits) {
                $properties = ['has_bottle_0' => $bits[0] ? 'true' : 'false', 'has_bottle_1' => $bits[1] ? 'true' : 'false', 'has_bottle_2' => $bits[2] ? 'true' : 'false'];
                $this->setBlock($x, $y, $z, $catalog->state('brewing_stand', $properties));
            }
            $slotsChanged = ($before['slots'] ?? []) !== $data['slots'];
            foreach ($this->localPlayers() as $player) {
                if ($player->windowType === 'brewing_stand' && $player->windowPosition === $position) {
                    if ($slotsChanged) {
                        $this->syncInventory($player);
                    }
                    $this->sendBrewingData($player, $data);
                }
            }
            if ($slotsChanged) {
                $this->containerContentsChanged($position);
            }
        }
    }

    /** BrewingStandBlockEntity.doBrew: each bottle with a recipe becomes its output, one reagent is used, and level event 1035 plays. */
    private function doBrew(array $position, array &$slots): void
    {
        for ($slot = 0; $slot < 3; ++$slot) {
            $recipe = $this->brewingRecipe($slots[$slot] ?? null, $slots[3]);
            if ($recipe !== null) {
                $slots[$slot] = $this->version->catalog->itemStacks->canonical([
                    'item' => $this->version->blocks['items'][substr($recipe[0], 10)], 'count' => 1,
                    'potion' => ['potion' => $recipe[1], 'custom_color' => null, 'custom_effects' => [], 'custom_name' => null],
                ]);
            }
        }
        // Item.getCraftingRemainder: dragon's breath leaves its bottle.
        $remainder = $this->version->catalog->itemName($slots[3]['item']) === 'dragon_breath' ? ['item' => $this->version->blocks['items']['glass_bottle'], 'count' => 1] : null;
        Inventory::consume($slots, 3);
        if ($remainder !== null) {
            if (!isset($slots[3])) {
                $slots[3] = $remainder;
            } else {
                $this->dropContainerStack($position[0], $position[1], $position[2], $remainder);
            }
        }
        $this->levelEvent(1035, $position, 0);
    }

    /** The brew time, fuel, total brew time and total fuel that changed since last sent. */
    private function sendBrewingData(Client $c, array $data): void
    {
        foreach ([$data['brewTime'] ?? 0, $data['fuel'] ?? 0, $data['totalBrewTime'] ?? 400, $data['totalFuel'] ?? 20] as $property => $value) {
            if (($c->furnaceData[$property] ?? null) !== $value) {
                $c->furnaceData[$property] = $value;
                $c->send($this->version, 'container_set_data', Binary::writeVarInt($c->windowId) . pack('nn', $property, $value & 0xFFFF));
            }
        }
    }

    /** BrewingStandBlockEntity.canPlaceItem: fuel, a reagent, or a potion input into an empty bottle slot. */
    private function brewingCanPlace(array $slots, int $slot, array $stack): bool
    {
        return match ($slot) {
            4 => $this->brewingFuel($stack) !== null,
            3 => $this->brewingReagent($stack),
            default => $this->potionInput($stack) && !isset($slots[$slot]),
        };
    }

    /** BrewingStandMenu's slots: what each may hold (a single bottle each), and quickMoveStack between them and the inventory. */
    private function brewingClick(Client $c, array &$items, int $slot, int $mode, int $button): bool
    {
        $catalog = $this->version->catalog;
        $mayPlace = fn (int $target, array $stack): bool => match ($target) {
            4 => $this->brewingFuel($stack) !== null,
            3 => $this->brewingReagent($stack),
            default => $this->potionInput($stack),
        };
        if ($slot <= 4 && $mode === 0 && $c->cursor !== null) {
            // AbstractContainerMenu.doClick with a carried stack on a slot that limits what it takes.
            if (!$mayPlace($slot, $c->cursor)) {
                return true;
            }
            $max = $slot <= 2 ? 1 : $catalog->item($c->cursor['item'])['max_stack'];
            $current = $items[$slot];
            if ($current === null) {
                $move = min($button === 1 ? 1 : $c->cursor['count'], $max);
                $items[$slot] = array_replace($c->cursor, ['count' => $move]);
                $c->cursor['count'] -= $move;
                if ($c->cursor['count'] <= 0) {
                    $c->cursor = null;
                }
            } elseif (Inventory::same($current, $c->cursor, $catalog)) {
                $move = min($button === 1 ? 1 : $c->cursor['count'], $max - $current['count']);
                if ($move > 0) {
                    $items[$slot]['count'] += $move;
                    $c->cursor['count'] -= $move;
                    if ($c->cursor['count'] <= 0) {
                        $c->cursor = null;
                    }
                }
            } elseif ($c->cursor['count'] <= $max) {
                [$items[$slot], $c->cursor] = [$c->cursor, $current];
            }
            return true;
        }
        if ($slot <= 4 && $mode === 2) {
            $swapped = $c->inventory[$button === 40 ? 45 : 36 + $button] ?? null;
            return $swapped !== null && (!$mayPlace($slot, $swapped) || ($slot <= 2 && $swapped['count'] > 1));
        }
        if ($mode !== 1 || $items[$slot] === null) {
            return false;
        }
        $original = $items[$slot];
        // AbstractContainerMenu.doClick repeats quickMoveStack while it moves the same item.
        while ($this->brewingQuickMove($items, $slot, $mayPlace) && $items[$slot] !== null && $items[$slot]['item'] === $original['item']) {
        }
        return true;
    }

    /** BrewingStandMenu.quickMoveStack once; false where it returns nothing, which ends the repeats. */
    private function brewingQuickMove(array &$items, int $slot, callable $mayPlace): bool
    {
        $stack = $items[$slot];
        $count = $stack['count'];
        $move = function (int $from, int $to, bool $reverse) use (&$items, &$stack, $mayPlace): bool {
            return $this->moveItemStackTo($items, $stack, $from, $to, $reverse, $mayPlace);
        };
        if ($slot > 4) {
            if ($this->brewingFuel($stack) !== null) {
                $done = $move(4, 5, false) || ($this->brewingReagent($stack) && !$move(3, 4, false));
            } elseif ($this->brewingReagent($stack)) {
                $done = !$move(3, 4, false);
            } elseif ($this->potionInput($stack)) {
                $done = !$move(0, 3, false);
            } elseif ($slot < 32) {
                $done = !$move(32, 41, false);
            } else {
                $done = !$move(5, 32, false);
            }
        } else {
            $done = !$move(5, 41, true);
        }
        $items[$slot] = $stack;
        return !$done && ($stack['count'] ?? 0) !== $count;
    }

    /**
     * AbstractContainerMenu.moveItemStackTo: merge into matching stacks, then put the rest in the
     * first empty slot that may take it (a bottle slot takes one). Returns whether anything moved.
     */
    private function moveItemStackTo(array &$items, ?array &$stack, int $from, int $to, bool $reverse, callable $mayPlace): bool
    {
        $catalog = $this->version->catalog;
        $moved = false;
        $order = $reverse ? range($to - 1, $from, -1) : range($from, $to - 1);
        $limit = static fn (int $target, array $item): int => $target <= 2 ? 1 : $catalog->item($item['item'])['max_stack'];
        foreach ($order as $target) {
            if ($stack === null) {
                break;
            }
            $current = $items[$target];
            if ($current !== null && Inventory::same($current, $stack, $catalog)) {
                $room = $limit($target, $stack) - $current['count'];
                if ($room > 0) {
                    $take = min($room, $stack['count']);
                    $items[$target]['count'] += $take;
                    $stack['count'] -= $take;
                    $moved = true;
                    if ($stack['count'] <= 0) {
                        $stack = null;
                    }
                }
            }
        }
        if ($stack !== null) {
            foreach ($order as $target) {
                if ($items[$target] === null && ($target > 4 || $mayPlace($target, $stack))) {
                    $take = min($stack['count'], $limit($target, $stack));
                    $items[$target] = array_replace($stack, ['count' => $take]);
                    $stack['count'] -= $take;
                    if ($stack['count'] <= 0) {
                        $stack = null;
                    }
                    $moved = true;
                    break;
                }
            }
        }
        return $moved;
    }
}
