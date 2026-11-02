<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\World\Inventory;

/**
 * Smithing tables (SmithingMenu): a template, a base item and an addition make the transform
 * recipe's result, which keeps the base item's components, as for netherite gear. Trims are
 * recognised for the slots but give no result yet, since stacks do not carry trims.
 */
trait SmithingTables
{
    /** @return list<array> the smithing recipes, transforms and trims, in the recipe manager's order */
    private function smithingRecipes(): array
    {
        static $recipes = null;
        return $recipes ??= array_values(array_filter(
            json_decode((string) file_get_contents($this->version->directory . '/recipes.json'), true, 512, JSON_THROW_ON_ERROR),
            static fn (array $recipe): bool => str_starts_with($recipe['type'], 'smithing_')
        ));
    }

    /** RecipePropertySet.SMITHING_TEMPLATE, _BASE and _ADDITION: whether any smithing recipe takes the item in that slot. */
    private function smithingSlotAccepts(int $slot, int $item): bool
    {
        foreach ($this->smithingRecipes() as $recipe) {
            if (in_array($item, $recipe['ingredients'][$slot], true)) {
                return true;
            }
        }
        return false;
    }

    /** SmithingMenu.createResult: the first transform recipe the inputs match, with the base item's components. */
    private function smithingResult(Client $c): ?array
    {
        $inputs = [$c->smithingSlots[0] ?? null, $c->smithingSlots[1] ?? null, $c->smithingSlots[2] ?? null];
        foreach ($this->smithingRecipes() as $recipe) {
            foreach ($inputs as $slot => $stack) {
                // An empty ingredient matches an empty slot only.
                if ($recipe['ingredients'][$slot] === [] ? $stack !== null : ($stack === null || !in_array($stack['item'], $recipe['ingredients'][$slot], true))) {
                    continue 2;
                }
            }
            if ($recipe['type'] !== 'smithing_transform') {
                return null;
            }
            // TransmuteRecipe.createWithOriginalComponents: the base item's changes on the new item.
            return $this->version->catalog->itemStacks->canonical(['item' => $recipe['result']['item'], 'count' => $recipe['result']['count']] + array_diff_key($inputs[1], ['item' => true, 'count' => true]));
        }
        return null;
    }

    /** SmithingMenu.slotsChanged: the result, then whether all three inputs make nothing (data slot 0). */
    private function updateSmithing(Client $c): void
    {
        $c->smithingResult = $this->smithingResult($c);
        $error = isset($c->smithingSlots[0], $c->smithingSlots[1], $c->smithingSlots[2]) && $c->smithingResult === null ? 1 : 0;
        if ($c->smithingError !== $error) {
            $c->smithingError = $error;
            $c->send($this->version, 'container_set_data', Binary::writeVarInt($c->windowId) . pack('n', 0) . pack('n', $error));
        }
    }

    /**
     * The smithing table's slots: each input takes only what some recipe takes there, the result is
     * taken as it is, and shift-clicking from the inventory fills the inputs. Returns whether the
     * click was one of these.
     */
    private function smithingClick(Client $c, array &$items, int $slot, int $mode, int $button): bool
    {
        if ($slot === 3) {
            $result = $c->smithingResult;
            if ($result === null) {
                return true;
            }
            if ($mode === 0 && $c->cursor === null) {
                $c->cursor = $result;
            } elseif ($mode === 1) {
                $items[3] = null;
                if (Inventory::add($items, $result, $this->version->catalog, range(39, 4, -1)) > 0) {
                    return true;
                }
            } else {
                return true;
            }
            $this->takeSmithingResult($c, $items, $result);
            return true;
        }
        if ($slot <= 2 && $mode === 0 && $c->cursor !== null && !$this->smithingSlotAccepts($slot, $c->cursor['item'])) {
            return true;
        }
        if ($slot <= 2 && $mode === 2) {
            $swapped = $c->inventory[$button === 40 ? 45 : 36 + $button] ?? null;
            return $swapped !== null && !$this->smithingSlotAccepts($slot, $swapped['item']);
        }
        if ($mode === 1 && $slot >= 4 && $items[$slot] !== null) {
            // ItemCombinerMenu.quickMoveStack: into the inputs that take it, otherwise between the inventory and the hotbar.
            $stack = $items[$slot];
            $targets = array_values(array_filter([0, 1, 2], fn (int $input): bool => $this->smithingSlotAccepts($input, $stack['item'])));
            $fits = ($this->smithingSlotAccepts(0, $stack['item']) && $items[0] === null) || ($this->smithingSlotAccepts(1, $stack['item']) && $items[1] === null) || ($this->smithingSlotAccepts(2, $stack['item']) && $items[2] === null);
            if (!$fits) {
                $targets = $slot < 31 ? range(31, 39) : range(4, 30);
            }
            $items[$slot] = null;
            $left = Inventory::add($items, $stack, $this->version->catalog, $targets);
            $items[$slot] = $left > 0 ? array_replace($stack, ['count' => $left]) : null;
            return true;
        }
        return false;
    }

    /** SmithingMenu.onTake: the crafted statistic, one of each input used up, and the smithing sound. */
    private function takeSmithingResult(Client $c, array &$items, array $result): void
    {
        $this->awardStat($c, 'crafted', $this->version->catalog->itemName($result['item']), $result['count']);
        for ($slot = 0; $slot <= 2; ++$slot) {
            if ($items[$slot] !== null && --$items[$slot]['count'] <= 0) {
                $items[$slot] = null;
            }
        }
        $items[3] = null;
        $this->levelEvent(1044, $c->windowPosition, 0);
    }

    /** ItemCombinerMenu.removed: the inputs go back to the player. */
    private function returnSmithingItems(Client $c): void
    {
        foreach ($c->smithingSlots as $stack) {
            $left = $this->giveStack($c, $stack);
            if ($left > 0) {
                $this->playerDrop($c, array_replace($stack, ['count' => $left]), false);
            }
        }
        $c->smithingSlots = [];
        $c->smithingResult = null;
        $c->smithingError = 0;
    }
}
