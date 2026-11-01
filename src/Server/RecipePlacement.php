<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\World\StackedContents;

/**
 * The recipe book filling a menu, as ServerGamePacketListenerImpl.handlePlaceRecipe and
 * ServerPlaceRecipe do for the inventory's 2x2 crafting grid, the crafting table's 3x3 and the
 * furnace's input: what the menu holds goes back to the inventory, then one (or, with shift, as
 * many as possible) of each ingredient is moved in, or the recipe is shown as a ghost when the
 * player lacks the items.
 */
trait RecipePlacement
{
    /**
     * RecipeBookMenu: the recipe type it places, the grid's width, its input slots, the slots
     * cleared first, and those counted as available, as menu container indices.
     *
     * @return array{type: string, width: int, input: list<int>, clear: list<int>, stock: list<int>, usable: bool}|null
     */
    private function recipeMenu(Client $c): ?array
    {
        return match ($c->windowType) {
            'player' => ['type' => 'crafting', 'width' => 2, 'input' => range(0, 3), 'clear' => range(0, 3), 'stock' => range(0, 3), 'usable' => true],
            'crafting' => ['type' => 'crafting', 'width' => 3, 'input' => range(0, 8), 'clear' => range(0, 8), 'stock' => range(0, 8), 'usable' => true],
            // AbstractFurnaceMenu: the input, cleared with the result; the block entity counts all three slots.
            'furnace' => ['type' => 'smelting', 'width' => 1, 'input' => [0], 'clear' => [0, 2], 'stock' => [0, 1, 2], 'usable' => false],
            default => null,
        };
    }

    /** ServerGamePacketListenerImpl.handlePlaceRecipe */
    private function placeRecipe(Client $c, int $windowId, int $display, bool $useMaxItems): void
    {
        $menu = $this->recipeMenu($c);
        if ($c->gameMode === 3 || $windowId !== $c->windowId || $menu === null || !$this->validWindow($c)) {
            return;
        }
        $reference = $this->recipeBookReference();
        $recipe = $reference['displays'][$display][2] ?? null;
        $placement = $reference['placements'][$recipe] ?? null;
        if ($recipe === null || !isset($c->knownRecipes[$recipe]) || $placement === null || $placement['type'] !== $menu['type'] || $placement['impossible']) {
            return;
        }
        // handlePlacement: creative players may drop what does not fit back.
        if ($c->gameMode !== 1 && !$this->testClearGrid($c, $menu)) {
            return;
        }
        $ingredients = array_map(static fn (array $items): array => array_fill_keys($items, true), $placement['ingredients']);
        $available = $this->craftingStock($c, $menu);
        if (!$available->tryPick($ingredients, 1)) {
            $this->clearGrid($c, $menu);
            $this->syncInventory($c);
            $c->send($this->version, 'place_ghost_recipe', Binary::writeVarInt($c->windowId) . $reference['ghosts'][$display]);
            return;
        }
        $this->placeIngredients($c, $menu, $recipe, $placement, $ingredients, $available, $useMaxItems);
        $this->syncInventory($c);
    }

    /** ServerPlaceRecipe.placeRecipe */
    private function placeIngredients(Client $c, array $menu, string $recipe, array $placement, array $ingredients, StackedContents $available, bool $useMaxItems): void
    {
        $grid = array_map(fn (int $slot): ?array => $this->menuSlot($c, $slot), $menu['input']);
        $matches = $this->menuRecipe($c, $menu, $grid) === $recipe;
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
        $this->clearGrid($c, $menu);
        [$width, $height] = isset($placement['width']) ? [$placement['width'], $placement['height']] : [$menu['width'], $menu['width']];
        foreach (self::placeRecipeSlots($menu['width'], $width, $height, $placement['slots']) as [$ingredient, $gridIndex]) {
            if ($ingredient === -1) {
                continue;
            }
            for ($remaining = $adjusted; $remaining > 0;) {
                $remaining = $this->moveItemToGrid($c, $menu['input'][$gridIndex], $used[$ingredient], $remaining);
                if ($remaining === -1) {
                    break;
                }
            }
        }
    }

    /** CraftingMenuAccess.recipeMatches: the recipe the menu's input makes now, if any. */
    private function menuRecipe(Client $c, array $menu, array $grid): ?string
    {
        if ($menu['type'] === 'smelting') {
            $name = $grid[0] === null ? null : $this->crafting->smelt($grid[0]['item'])['name'] ?? null;
        } else {
            $name = $this->crafting->match($grid, $menu['width'])['name'] ?? null;
        }
        return $name === null ? null : 'minecraft:' . $name;
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

    /** A recipe menu's slot: the inventory's crafting grid, the crafting table's, or the furnace's. */
    private function menuSlot(Client $c, int $slot): ?array
    {
        return match ($c->windowType) {
            'player' => $c->inventory[$slot + 1] ?? null,
            'crafting' => $c->craftGrid[$slot + 1] ?? null,
            default => $this->windowContainerSlots($c)[$slot] ?? null,
        };
    }

    private function setMenuSlot(Client $c, int $slot, ?array $stack): void
    {
        if ($c->windowType === 'player' || $c->windowType === 'crafting') {
            $slots = $c->windowType === 'player' ? $c->inventory : $c->craftGrid;
            if ($stack === null) {
                unset($slots[$slot + 1]);
            } else {
                $slots[$slot + 1] = $stack;
            }
            if ($c->windowType === 'player') {
                $c->inventory = $slots;
            } else {
                $c->craftGrid = $slots;
            }
            return;
        }
        $slots = $this->windowContainerSlots($c);
        if ($stack === null) {
            unset($slots[$slot]);
        } else {
            $slots[$slot] = $stack;
        }
        $this->storeWindowContainerSlots($c, $slots);
    }

    /**
     * Inventory.fillStackedContents and the menu's: inventory stacks usable for crafting, then the
     * grid's usable stacks or all of a furnace's, each up to a full stack.
     */
    private function craftingStock(Client $c, array $menu): StackedContents
    {
        $stock = new StackedContents();
        foreach (range(0, 35) as $slot) {
            $stack = $c->inventory[self::inventoryWindowSlot($slot)] ?? null;
            if ($stack !== null && self::usableForCrafting($stack)) {
                $stock->account($stack['item'], min($this->maxStack($stack), $stack['count']));
            }
        }
        foreach ($menu['stock'] as $slot) {
            $stack = $this->menuSlot($c, $slot);
            if ($stack !== null && (!$menu['usable'] || self::usableForCrafting($stack))) {
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

    /** ServerPlaceRecipe.testClearGrid: whether the input slots' items would all fit back in the inventory. */
    private function testClearGrid(Client $c, array $menu): bool
    {
        $free = 0;
        foreach (range(0, 35) as $slot) {
            $free += isset($c->inventory[self::inventoryWindowSlot($slot)]) ? 0 : 1;
        }
        $freeSlots = [];
        foreach ($menu['input'] as $input) {
            $stack = $this->menuSlot($c, $input);
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

    /** ServerPlaceRecipe.clearGrid: each slot's stack back into the inventory, dropping what does not fit. */
    private function clearGrid(Client $c, array $menu): void
    {
        foreach ($menu['clear'] as $menuSlot) {
            $stack = $this->menuSlot($c, $menuSlot);
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
            // clearCraftingContent
            $this->setMenuSlot($c, $menuSlot, null);
        }
    }

    /** ServerPlaceRecipe.moveItemToGrid: the count still to move, or -1 when no stack is left to take from. */
    private function moveItemToGrid(Client $c, int $menuSlot, int $item, int $count): int
    {
        $target = $this->menuSlot($c, $menuSlot);
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
        $this->setMenuSlot($c, $menuSlot, $target === null ? array_replace($stack, ['count' => $taken]) : array_replace($target, ['count' => $target['count'] + $taken]));
        return $count - $taken;
    }
}
