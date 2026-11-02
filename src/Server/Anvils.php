<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\World\Inventory;

/**
 * Anvils (AnvilMenu): an item and an addition make a result, repaired by its repair material or by
 * a second item of its kind, and given the addition's enchantments where they fit. The cost in
 * levels grows with every enchantment and with the items' repair costs; taking the result spends
 * it, uses up the inputs and sometimes wears the anvil down. Renaming is not supported yet.
 */
trait Anvils
{
    private const ANVILS = ['anvil', 'chipped_anvil', 'damaged_anvil'];

    /** @return array<string, string|list<string>> */
    private function repairableItems(): array
    {
        static $repairable = null;
        return $repairable ??= json_decode((string) file_get_contents($this->version->directory . '/repairable.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** ItemStack.isValidRepairItem: the item's repairable component lists the addition. */
    private function repairsWith(array $stack, array $addition): bool
    {
        $set = $this->repairableItems()['minecraft:' . $this->version->catalog->itemName($stack['item'])] ?? null;
        return $set !== null && in_array('minecraft:' . $this->version->catalog->itemName($addition['item']), $this->enchantmentIds($set, 'minecraft:item'), true);
    }

    /** EnchantmentHelper.getEnchantmentsForCrafting: an enchanted book's stored enchantments, otherwise its own. */
    private function craftingEnchantmentsField(array $stack): string
    {
        return $this->version->catalog->itemStacks->hasComponent($stack, 'minecraft:stored_enchantments') ? 'storedEnchantments' : 'enchantments';
    }

    /**
     * AnvilMenu.createResult: the result, its cost in levels, how many repair items it uses, and
     * whether it is only a renaming, as vanilla works them out for the two inputs.
     *
     * @return array{result: ?array, cost: int, repairCount: int}
     */
    private function anvilResult(Client $c): array
    {
        $input = $c->anvilSlots[0] ?? null;
        $addition = $c->anvilSlots[1] ?? null;
        $stacks = $this->version->catalog->itemStacks;
        $none = ['result' => null, 'cost' => 0, 'repairCount' => 0];
        if ($input === null || (!$stacks->hasComponent($input, 'minecraft:enchantments') && !$stacks->hasComponent($input, 'minecraft:stored_enchantments'))) {
            return $none;
        }
        $result = $input;
        $field = $this->craftingEnchantmentsField($result);
        $enchantments = $result[$field] ?? [];
        $price = 0;
        $tax = ($input['repairCost'] ?? 0) + ($addition['repairCost'] ?? 0);
        $repairCount = 0;
        $maximum = $this->version->catalog->item($input['item'])['durability'] ?? 0;
        $damageable = $maximum > 0;
        if ($addition !== null) {
            $usingBook = $stacks->hasComponent($addition, 'minecraft:stored_enchantments');
            if ($damageable && $this->repairsWith($input, $addition)) {
                $repair = min($result['damage'] ?? 0, intdiv($maximum, 4));
                if ($repair <= 0) {
                    return $none;
                }
                for ($count = 0; $repair > 0 && $count < $addition['count']; ++$count) {
                    $result['damage'] = ($result['damage'] ?? 0) - $repair;
                    ++$price;
                    $repair = min($result['damage'], intdiv($maximum, 4));
                }
                $repairCount = $count;
            } else {
                if (!$usingBook && ($result['item'] !== $addition['item'] || !$damageable)) {
                    return $none;
                }
                if ($damageable && !$usingBook) {
                    // Combining two of an item: both durabilities and a twelfth bonus.
                    $remaining = $maximum - ($input['damage'] ?? 0) + ($maximum - ($addition['damage'] ?? 0)) + intdiv($maximum * 12, 100);
                    $damage = max(0, $maximum - $remaining);
                    if ($damage < ($result['damage'] ?? 0)) {
                        $result['damage'] = $damage;
                        $price += 2;
                    }
                }
                $definitions = $this->enchantmentDefinitions();
                $item = 'minecraft:' . $this->version->catalog->itemName($input['item']);
                $anyCompatible = $anyIncompatible = false;
                foreach ($addition[$this->craftingEnchantmentsField($addition)] ?? [] as $id => $level) {
                    $current = $enchantments[$id] ?? 0;
                    $level = $current === $level ? $level + 1 : max($level, $current);
                    $compatible = $c->gameMode === 1 || $item === 'minecraft:enchanted_book' || in_array($item, $this->enchantmentIds($definitions[$id]['supported_items'], 'minecraft:item'), true);
                    foreach (array_keys($enchantments) as $other) {
                        if ($other !== $id && !$this->enchantmentsCompatible($id, $other)) {
                            $compatible = false;
                            ++$price;
                        }
                    }
                    if (!$compatible) {
                        $anyIncompatible = true;
                        continue;
                    }
                    $anyCompatible = true;
                    $enchantments[$id] = min($level, $definitions[$id]['max_level']);
                    $fee = $definitions[$id]['anvil_cost'];
                    if ($usingBook) {
                        $fee = max(1, intdiv($fee, 2));
                    }
                    $price += $fee * $enchantments[$id];
                    if ($input['count'] > 1) {
                        $price = 40;
                    }
                }
                if ($anyIncompatible && !$anyCompatible) {
                    return $none;
                }
            }
        }
        $cost = $price <= 0 ? 0 : (int) min($tax + $price, 2147483647);
        if ($price <= 0 || ($cost >= 40 && $c->gameMode !== 1)) {
            return ['result' => null, 'cost' => $cost, 'repairCount' => $repairCount];
        }
        // calculateIncreasedRepairCost on the higher of the two repair costs.
        $result['repairCost'] = (int) min(max($result['repairCost'] ?? 0, $addition['repairCost'] ?? 0) * 2 + 1, 2147483647);
        $result[$field] = $enchantments;
        if ($enchantments === []) {
            unset($result[$field]);
        }
        return ['result' => $stacks->canonical($result), 'cost' => $cost, 'repairCount' => $repairCount];
    }

    /** ItemCombinerMenu.slotsChanged: the result again, and its cost when that changed. */
    private function updateAnvil(Client $c): void
    {
        $c->anvilResult = $this->anvilResult($c);
        if ($c->anvilCost !== $c->anvilResult['cost']) {
            $c->anvilCost = $c->anvilResult['cost'];
            $c->send($this->version, 'container_set_data', Binary::writeVarInt($c->windowId) . pack('n', 0) . pack('n', $c->anvilCost & 0xffff));
        }
    }

    /**
     * The anvil's slots: its result is taken whole when the player can pay for it, shift-clicking
     * moves it into the inventory from the hotbar's end, and shift-clicking from the inventory fills
     * the two inputs. Returns whether the click was one of these.
     */
    private function anvilClick(Client $c, array &$items, int $slot, int $mode): bool
    {
        if ($slot === 2) {
            $result = $c->anvilResult['result'] ?? null;
            // AnvilMenu.mayPickup: a cost, and the levels for it unless in creative.
            if ($result === null || $c->anvilCost <= 0 || ($c->gameMode !== 1 && $c->experienceLevel < $c->anvilCost)) {
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
            $this->takeAnvilResult($c, $items);
            return true;
        }
        if ($mode === 1 && $slot >= 3 && $items[$slot] !== null) {
            $stack = $items[$slot];
            $items[$slot] = null;
            $left = Inventory::add($items, $stack, $this->version->catalog, [0, 1]);
            $items[$slot] = $left > 0 ? array_replace($stack, ['count' => $left]) : null;
            return true;
        }
        return false;
    }

    /**
     * AnvilMenu.onTake: the levels are spent outside creative, the repair items or the addition
     * and the input are used up, and the anvil may wear down a step (12%), or break when it was
     * damaged already.
     */
    private function takeAnvilResult(Client $c, array &$items): void
    {
        if ($c->gameMode !== 1) {
            $c->experienceLevel -= $c->anvilCost;
            if ($c->experienceLevel < 0) {
                [$c->experienceLevel, $c->experienceProgress, $c->experience] = [0, 0.0, 0];
            }
            $this->syncExperience($c);
        }
        $repairCount = $c->anvilResult['repairCount'];
        if ($repairCount > 0 && ($items[1]['count'] ?? 0) > $repairCount) {
            $items[1]['count'] -= $repairCount;
        } else {
            $items[1] = null;
        }
        $items[0] = null;
        $items[2] = null;
        [$x, $y, $z] = $c->windowPosition;
        $state = $this->world->get($x, $y, $z);
        $name = $this->version->catalog->blockName($state);
        if ($c->gameMode !== 1 && in_array($name, self::ANVILS, true) && self::randomUnit() < 0.12) {
            if ($name === 'damaged_anvil') {
                $this->setBlock($x, $y, $z, $this->version->catalog->state('air'));
                $this->levelEvent(1029, [$x, $y, $z], 0);
                return;
            }
            $next = $name === 'anvil' ? 'chipped_anvil' : 'damaged_anvil';
            $facing = $this->version->catalog->properties($state)['facing'] ?? 'north';
            $this->setBlock($x, $y, $z, $this->version->catalog->state($next, ['facing' => $facing]));
        }
        $this->levelEvent(1030, [$x, $y, $z], 0);
    }

    /** ItemCombinerMenu.removed: the inputs go back to the player. */
    private function returnAnvilItems(Client $c): void
    {
        foreach ($c->anvilSlots as $stack) {
            $left = $this->giveStack($c, $stack);
            if ($left > 0) {
                $this->playerDrop($c, array_replace($stack, ['count' => $left]), false);
            }
        }
        $c->anvilSlots = [];
        $c->anvilResult = [];
        $c->anvilCost = 0;
    }
}
