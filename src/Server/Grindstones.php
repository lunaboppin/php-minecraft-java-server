<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Inventory;

/**
 * Grindstones (GrindstoneMenu): one enchanted item loses its enchantments but curses, and two of
 * an item combine their durability with a twentieth more, keeping only curses; taking the result
 * gives back experience for the enchantments ground off.
 */
trait Grindstones
{
    /** The grindstone's input slots take damageable or enchanted items. */
    private function grindable(array $stack): bool
    {
        return ($this->version->catalog->item($stack['item'])['durability'] ?? 0) > 0 || ($stack[$this->craftingEnchantmentsField($stack)] ?? []) !== [];
    }

    private function isCurse(string $id): bool
    {
        return in_array(substr($id, 10), $this->version->registryTags('minecraft:enchantment')['minecraft:curse'] ?? [], true);
    }

    /** GrindstoneMenu.computeResult: a lone enchanted item, or two of an item, each one of them. */
    private function grindstoneResult(Client $c): ?array
    {
        $input = $c->grindstoneSlots[0] ?? null;
        $other = $c->grindstoneSlots[1] ?? null;
        if (($input === null && $other === null) || ($input['count'] ?? 0) > 1 || ($other['count'] ?? 0) > 1) {
            return null;
        }
        if ($input === null || $other === null) {
            $item = $input ?? $other;
            return ($item[$this->craftingEnchantmentsField($item)] ?? []) === [] ? null : $this->removeNonCurses($item);
        }
        if ($input['item'] !== $other['item']) {
            return null;
        }
        // mergeItems: both durabilities and a twentieth of the maximum.
        $maximum = $this->version->catalog->item($input['item'])['durability'] ?? 0;
        $result = $input;
        if ($maximum > 0) {
            $remaining = $maximum - ($input['damage'] ?? 0) + $maximum - ($other['damage'] ?? 0) + intdiv($maximum * 5, 100);
            $result['damage'] = max($maximum - $remaining, 0);
        } else {
            if ($this->version->catalog->item($input['item'])['max_stack'] < 2 || !$this->version->catalog->itemStacks->same($input, $other)) {
                return null;
            }
            $result['count'] = 2;
        }
        // mergeEnchantsFrom: the other's enchantments upgrade these, its curses only where absent.
        $field = $this->craftingEnchantmentsField($result);
        foreach ($other[$this->craftingEnchantmentsField($other)] ?? [] as $id => $level) {
            if (!$this->isCurse($id) || !isset($result[$field][$id])) {
                $result[$field][$id] = max($result[$field][$id] ?? 0, $level);
            }
        }
        return $this->removeNonCurses($result);
    }

    /**
     * GrindstoneMenu.removeNonCursesFrom: only curses stay (an enchanted book with none left is a
     * book again), and the repair cost is that of as many anvil uses as curses.
     */
    private function removeNonCurses(array $stack): array
    {
        $field = $this->craftingEnchantmentsField($stack);
        $curses = array_filter($stack[$field] ?? [], fn (string $id): bool => $this->isCurse($id), ARRAY_FILTER_USE_KEY);
        unset($stack[$field]);
        if ($curses !== []) {
            $stack[$field] = $curses;
        } elseif ($this->version->catalog->itemName($stack['item']) === 'enchanted_book') {
            $stack['item'] = $this->version->blocks['items']['book'];
        }
        $stack['repairCost'] = (1 << count($curses)) - 1;
        return $this->version->catalog->itemStacks->canonical($stack);
    }

    /** The result slot's getExperienceFromItem: each non-curse enchantment's minimum cost. */
    private function grindstoneExperience(?array $stack): int
    {
        $amount = 0;
        $definitions = $this->enchantingReference()['enchantments'];
        foreach ($stack === null ? [] : $stack[$this->craftingEnchantmentsField($stack)] ?? [] as $id => $level) {
            if (!$this->isCurse($id)) {
                // Enchantment.getMinCost: the level's cost, linear past the levels listed.
                $costs = $definitions[$id]['costs'];
                $first = $costs['1'][0];
                $step = isset($costs['2']) ? $costs['2'][0] - $first : 0;
                $amount += $costs[(string) $level][0] ?? $first + $step * ($level - 1);
            }
        }
        return $amount;
    }

    /**
     * The grindstone's slots: its inputs take only damageable or enchanted items, its result is
     * taken as it is, and shift-clicking moves items between the inventory and the inputs as
     * GrindstoneMenu.quickMoveStack does. Returns whether the click was one of these.
     */
    private function grindstoneClick(Client $c, array &$items, int $slot, int $mode, int $button): bool
    {
        if ($mode === 2 && $slot <= 1) {
            // A hotbar swap puts nothing in that the input may not hold.
            $swapped = $c->inventory[$button === 40 ? 45 : 36 + $button] ?? null;
            return $swapped !== null && !$this->grindable($swapped);
        }
        if ($slot === 2) {
            $result = $c->grindstoneResult;
            if ($result === null) {
                return true;
            }
            if ($mode === 0 && $c->cursor === null) {
                $c->cursor = $result;
            } elseif ($mode === 1) {
                $items[2] = null;
                if (Inventory::add($items, $result, $this->version->catalog, range(38, 3, -1)) > 0) {
                    return true;
                }
            } else {
                return true;
            }
            $this->takeGrindstoneResult($c, $items);
            return true;
        }
        if ($slot <= 1 && $mode === 0 && $c->cursor !== null && !$this->grindable($c->cursor)) {
            return true;
        }
        if ($mode === 1 && $slot >= 3 && $items[$slot] !== null) {
            $stack = $items[$slot];
            $items[$slot] = null;
            if ($items[0] !== null && $items[1] !== null) {
                $targets = $slot < 30 ? range(30, 38) : range(3, 29);
            } else {
                $targets = $this->grindable($stack) ? [0, 1] : [];
            }
            $left = Inventory::add($items, $stack, $this->version->catalog, $targets);
            $items[$slot] = $left > 0 ? array_replace($stack, ['count' => $left]) : null;
            return true;
        }
        return false;
    }

    /** The result slot's onTake: experience at the grindstone's centre, the grind sound, and both inputs gone. */
    private function takeGrindstoneResult(Client $c, array &$items): void
    {
        [$x, $y, $z] = $c->windowPosition;
        $amount = $this->grindstoneExperience($items[0]) + $this->grindstoneExperience($items[1]);
        if ($amount > 0) {
            $half = (int) ceil($amount / 2.0);
            $this->awardExperience($x + 0.5, $y + 0.5, $z + 0.5, $half + random_int(0, $half - 1));
        }
        $this->levelEvent(1042, [$x, $y, $z], 0);
        $items[0] = $items[1] = $items[2] = null;
    }

    private function updateGrindstone(Client $c): void
    {
        $c->grindstoneResult = $this->grindstoneResult($c);
    }

    /** GrindstoneMenu.removed: the inputs go back to the player. */
    private function returnGrindstoneItems(Client $c): void
    {
        foreach ($c->grindstoneSlots as $stack) {
            $left = $this->giveStack($c, $stack);
            if ($left > 0) {
                $this->playerDrop($c, array_replace($stack, ['count' => $left]), false);
            }
        }
        $c->grindstoneSlots = [];
        $c->grindstoneResult = null;
    }
}
