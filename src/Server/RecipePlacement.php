<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\World\StackedContents;

/**
 * The recipe book filling a crafting grid, as ServerGamePacketListenerImpl.handlePlaceRecipe and
 * ServerPlaceRecipe do for the inventory's 2x2 grid and the crafting table's 3x3: the grid's items
 * go back to the inventory, then one (or, with shift, as many as possible) of each ingredient is
 * moved in, or the recipe is shown as a ghost when the player lacks the items. Furnace books are
 * not placed yet.
 */
trait RecipePlacement
{
    /** ServerGamePacketListenerImpl.handlePlaceRecipe */
    private function placeRecipe(Client $c, int $windowId, int $display, bool $useMaxItems): void
    {
        if ($c->gameMode === 3 || $windowId !== $c->windowId || !in_array($c->windowType, ['player', 'crafting'], true) || !$this->validWindow($c)) {
            return;
        }
        $reference = $this->recipeBookReference();
        $recipe = $reference['displays'][$display][2] ?? null;
        $placement = $reference['placements'][$recipe] ?? null;
        if ($recipe === null || !isset($c->knownRecipes[$recipe]) || $placement === null || $placement['impossible']) {
            return;
        }
        $size = $c->windowType === 'player' ? 2 : 3;
        // AbstractCraftingMenu.handlePlacement: creative players may drop what does not fit back.
        if ($c->gameMode !== 1 && !$this->testClearGrid($c, $size)) {
            return;
        }
        $ingredients = array_map(static fn (array $items): array => array_fill_keys($items, true), $placement['ingredients']);
        $available = $this->craftingStock($c, $size);
        if (!$available->tryPick($ingredients, 1)) {
            $this->clearGrid($c, $size);
            $this->syncInventory($c);
            $c->send($this->version, 'place_ghost_recipe', Binary::writeVarInt($c->windowId) . $reference['ghosts'][$display]);
            return;
        }
        $this->placeIngredients($c, $size, $recipe, $placement, $ingredients, $available, $useMaxItems);
        $this->syncInventory($c);
    }

    /** ServerPlaceRecipe.placeRecipe */
    private function placeIngredients(Client $c, int $size, string $recipe, array $placement, array $ingredients, StackedContents $available, bool $useMaxItems): void
    {
        $grid = $this->craftingGrid($c, $size);
        $placed = $this->crafting->match(array_values($grid), $size);
        $matches = $placed !== null && 'minecraft:' . $placed['name'] === $recipe;
        $biggest = $available->tryPickAll($ingredients, PHP_INT_MAX);
        if ($matches) {
            foreach ($grid as $stack) {
                if ($stack !== null && min($biggest, $this->maxStack($stack)) < $stack['count'] + 1) {
                    return;
                }
            }
        }
        // calculateAmountToCraft: all that can be made, one more than the smallest stack placed, or one.
        if ($useMaxItems) {
            $amount = $biggest;
        } elseif ($matches) {
            $counts = array_map(static fn (array $stack): int => $stack['count'], array_filter($grid));
            $amount = $counts === [] ? PHP_INT_MAX : min($counts) + 1;
        } else {
            $amount = 1;
        }
        $used = null;
        if (!$available->tryPick($ingredients, $amount, $used)) {
            return;
        }
        // clampToMaxStackSize: no more than the item that stacks least can hold in one slot.
        $adjusted = $amount;
        foreach ($used as $item) {
            $adjusted = min($adjusted, $this->version->catalog->item($item)['max_stack']);
        }
        if ($adjusted !== $amount) {
            $used = null;
            if (!$available->tryPick($ingredients, $adjusted, $used)) {
                return;
            }
        }
        $this->clearGrid($c, $size);
        [$width, $height] = isset($placement['width']) ? [$placement['width'], $placement['height']] : [$size, $size];
        foreach (self::placeRecipeSlots($size, $width, $height, $placement['slots']) as [$ingredient, $gridIndex]) {
            if ($ingredient === -1) {
                continue;
            }
            for ($remaining = $adjusted; $remaining > 0;) {
                $remaining = $this->moveItemToGrid($c, $size, $gridIndex, $used[$ingredient], $remaining);
                if ($remaining === -1) {
                    break;
                }
            }
        }
    }

    /**
     * PlaceRecipeHelper.placeRecipe: each entry's grid slot, with a recipe smaller than half the
     * grid centred in it.
     *
     * @return list<array{int, int}> entry and grid index
     */
    private static function placeRecipeSlots(int $gridWidth, int $recipeWidth, int $recipeHeight, array $entries): array
    {
        $gridHeight = $gridWidth;
        $out = [];
        $next = 0;
        $gridIndex = 0;
        for ($y = 0; $y < $gridHeight; ++$y) {
            if ($recipeHeight < $gridHeight / 2.0 && (int) floor($gridHeight / 2.0 - $recipeHeight / 2.0) > $y) {
                $gridIndex += $gridWidth;
                ++$y;
            }
            for ($x = 0; $x < $gridWidth; ++$x) {
                if ($next >= count($entries)) {
                    return $out;
                }
                $centred = $recipeWidth < $gridWidth / 2.0;
                $start = (int) floor($gridWidth / 2.0 - $recipeWidth / 2.0);
                $total = $recipeWidth;
                $add = $x < $recipeWidth;
                if ($centred) {
                    $total = $start + $recipeWidth;
                    $add = $start <= $x && $x < $start + $recipeWidth;
                }
                if ($add) {
                    $out[] = [$entries[$next++], $gridIndex];
                } elseif ($total === $x) {
                    $gridIndex += $gridWidth - $x;
                    break;
                }
                ++$gridIndex;
            }
        }
        return $out;
    }

    /** @return array<int, ?array> the grid's stacks by grid index */
    private function craftingGrid(Client $c, int $size): array
    {
        $grid = [];
        for ($i = 0; $i < $size * $size; ++$i) {
            $grid[$i] = ($size === 2 ? $c->inventory[$i + 1] ?? null : $c->craftGrid[$i + 1] ?? null);
        }
        return $grid;
    }

    private function setCraftingSlot(Client $c, int $size, int $index, ?array $stack): void
    {
        if ($size === 2) {
            if ($stack === null) {
                unset($c->inventory[$index + 1]);
            } else {
                $c->inventory[$index + 1] = $stack;
            }
        } elseif ($stack === null) {
            unset($c->craftGrid[$index + 1]);
        } else {
            $c->craftGrid[$index + 1] = $stack;
        }
    }

    /** Inventory.fillStackedContents and the grid's: stacks usable for crafting, each up to a full stack. */
    private function craftingStock(Client $c, int $size): StackedContents
    {
        $stock = new StackedContents();
        foreach ([...array_map(fn (int $slot): ?array => $c->inventory[self::inventoryWindowSlot($slot)] ?? null, range(0, 35)), ...$this->craftingGrid($c, $size)] as $stack) {
            if ($stack !== null && self::usableForCrafting($stack)) {
                $stock->account($stack['item'], min($this->maxStack($stack), $stack['count']));
            }
        }
        return $stock;
    }

    /** Inventory.items index to window slot: the hotbar is 0-8 there and 36-44 here. */
    private static function inventoryWindowSlot(int $slot): int
    {
        return $slot < 9 ? $slot + 36 : ($slot === 40 ? 45 : $slot);
    }

    /** Inventory.isUsableForCrafting: undamaged, unenchanted and unnamed. */
    private static function usableForCrafting(array $stack): bool
    {
        return ($stack['damage'] ?? 0) === 0 && ($stack['enchantments'] ?? []) === [] && !isset($stack['customName']);
    }

    private function maxStack(array $stack): int
    {
        return $this->version->catalog->item($stack['item'])['max_stack'];
    }

    private static function sameItemSameComponents(array $a, array $b): bool
    {
        unset($a['count'], $b['count']);
        return $a == $b;
    }

    /** Inventory.getSlotWithRemainingSpace: the selected slot, the offhand, then the inventory. */
    private function slotWithRemainingSpace(Client $c, array $stack): int
    {
        foreach ([$c->heldSlot, 40, ...range(0, 35)] as $slot) {
            $held = $c->inventory[self::inventoryWindowSlot($slot)] ?? null;
            if ($held !== null && self::sameItemSameComponents($held, $stack) && $this->maxStack($held) > 1 && $held['count'] < $this->maxStack($held)) {
                return $slot;
            }
        }
        return -1;
    }

    private function freeInventorySlot(Client $c): int
    {
        foreach (range(0, 35) as $slot) {
            if (!isset($c->inventory[self::inventoryWindowSlot($slot)])) {
                return $slot;
            }
        }
        return -1;
    }

    /** ServerPlaceRecipe.testClearGrid: whether the grid's items would all fit back in the inventory. */
    private function testClearGrid(Client $c, int $size): bool
    {
        $free = 0;
        foreach (range(0, 35) as $slot) {
            $free += isset($c->inventory[self::inventoryWindowSlot($slot)]) ? 0 : 1;
        }
        $freeSlots = [];
        foreach ($this->craftingGrid($c, $size) as $stack) {
            if ($stack === null) {
                continue;
            }
            $slot = $this->slotWithRemainingSpace($c, $stack);
            if ($slot === -1 && count($freeSlots) <= $free) {
                foreach ($freeSlots as &$listed) {
                    if (self::sameItemSameComponents($listed, $stack) && $listed['count'] !== $this->maxStack($listed) && $listed['count'] + $stack['count'] <= $this->maxStack($listed)) {
                        $listed['count'] += $stack['count'];
                        $stack['count'] = 0;
                        break;
                    }
                }
                unset($listed);
                if ($stack['count'] > 0) {
                    if (count($freeSlots) >= $free) {
                        return false;
                    }
                    $freeSlots[] = $stack;
                }
            } elseif ($slot === -1) {
                return false;
            }
        }
        return true;
    }

    /** ServerPlaceRecipe.clearGrid: each grid stack back into the inventory, dropping what does not fit. */
    private function clearGrid(Client $c, int $size): void
    {
        foreach ($this->craftingGrid($c, $size) as $index => $stack) {
            // Inventory.placeItemBackInInventory
            while ($stack !== null && $stack['count'] > 0) {
                $slot = $this->slotWithRemainingSpace($c, $stack);
                if ($slot === -1) {
                    $slot = $this->freeInventorySlot($c);
                }
                if ($slot === -1) {
                    $this->withDimension($c->dimension, fn () => $this->playerDrop($c, $stack, false));
                    break;
                }
                $window = self::inventoryWindowSlot($slot);
                $held = $c->inventory[$window] ?? null;
                $moved = min($stack['count'], $this->maxStack($stack) - ($held['count'] ?? 0));
                $c->inventory[$window] = $held === null ? array_replace($stack, ['count' => $moved]) : array_replace($held, ['count' => $held['count'] + $moved]);
                $stack['count'] -= $moved;
            }
            $this->setCraftingSlot($c, $size, $index, null);
        }
    }

    /** ServerPlaceRecipe.moveItemToGrid: the count still to move, or -1 when no stack is left to take from. */
    private function moveItemToGrid(Client $c, int $size, int $gridIndex, int $item, int $count): int
    {
        $target = $this->craftingGrid($c, $size)[$gridIndex];
        // Inventory.findSlotMatchingCraftingIngredient
        $found = null;
        foreach (range(0, 35) as $slot) {
            $stack = $c->inventory[self::inventoryWindowSlot($slot)] ?? null;
            if ($stack !== null && $stack['item'] === $item && self::usableForCrafting($stack) && ($target === null || self::sameItemSameComponents($target, $stack))) {
                $found = self::inventoryWindowSlot($slot);
                break;
            }
        }
        if ($found === null) {
            return -1;
        }
        $stack = $c->inventory[$found];
        $taken = min($count, $stack['count']);
        if ($taken < $stack['count']) {
            $c->inventory[$found]['count'] -= $taken;
        } else {
            unset($c->inventory[$found]);
        }
        $this->setCraftingSlot($c, $size, $gridIndex, $target === null ? array_replace($stack, ['count' => $taken]) : array_replace($target, ['count' => $target['count'] + $taken]));
        return $count - $taken;
    }
}
