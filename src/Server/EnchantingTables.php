<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Inventory;
use PhpMinecraftJavaServer\World\Spawning\EnchantmentSelection;

/**
 * Enchanting tables (EnchantmentMenu): an item to enchant and lapis lazuli, three offers whose
 * level costs grow with the bookshelves around the table, each with a clue to one enchantment it
 * gives, all drawn from the player's enchantment seed; taking an offer spends levels and lapis
 * and gives the player a new seed.
 */
trait EnchantingTables
{
    private function enchantingReference(): array
    {
        static $reference = null;
        if ($reference === null) {
            $reference = json_decode((string) file_get_contents($this->version->directory . '/enchanting.json'), true, 512, JSON_THROW_ON_ERROR);
            $reference['byItem'] = [];
            foreach ($reference['items'] as $item) {
                if ($item['mode'] === 'default') {
                    $reference['byItem'][$item['id']] = $item;
                }
            }
        }
        return $reference;
    }

    /** ItemStack.isEnchantable: an item with an enchantability and no enchantments yet. */
    private function enchantableItem(?array $stack): ?array
    {
        if ($stack === null || ($stack['enchantments'] ?? []) !== []) {
            return null;
        }
        $item = $this->enchantingReference()['byItem'][$stack['item']] ?? null;
        return $item === null || $item['enchantability'] === null ? null : $item;
    }

    /** EnchantingTableBlock.isValidBookShelf over BOOKSHELF_OFFSETS: bookshelves two away with only air-like blocks between. */
    private function enchantingBookshelves(array $position): int
    {
        $tags = $this->version->registryTags('minecraft:block');
        $providers = array_flip($tags['minecraft:enchantment_power_provider'] ?? []);
        $transmitters = array_flip($tags['minecraft:enchantment_power_transmitter'] ?? []);
        [$x, $y, $z] = $position;
        $count = 0;
        for ($dz = -2; $dz <= 2; ++$dz) {
            for ($dy = 0; $dy <= 1; ++$dy) {
                for ($dx = -2; $dx <= 2; ++$dx) {
                    if (abs($dx) !== 2 && abs($dz) !== 2) {
                        continue;
                    }
                    if (isset($providers[$this->version->catalog->blockName($this->world->get($x + $dx, $y + $dy, $z + $dz))])
                        && isset($transmitters[$this->version->catalog->blockName($this->world->get($x + intdiv($dx, 2), $y + $dy, $z + intdiv($dz, 2)))])) {
                        ++$count;
                    }
                }
            }
        }
        return $count;
    }

    /**
     * EnchantmentMenu.slotsChanged: with an enchantable item in, three costs from the seed and the
     * bookshelves (EnchantmentHelper.getEnchantmentCost), each with a clue drawn from its
     * enchantments; otherwise no offers. The changed data slots go to the player.
     */
    private function enchantingSlotsChanged(Client $c): void
    {
        $stack = $c->enchantSlots[0] ?? null;
        $item = $this->enchantableItem($stack);
        $data = [0, 0, 0, $c->enchantmentSeed, -1, -1, -1, -1, -1, -1];
        if ($item !== null && $c->windowPosition !== null) {
            $bookshelves = min(15, $this->enchantingBookshelves($c->windowPosition));
            $random = new LegacyRandom($c->enchantmentSeed);
            for ($i = 0; $i < 3; ++$i) {
                $selected = $random->nextInt(8) + 1 + ($bookshelves >> 1) + $random->nextInt($bookshelves + 1);
                $cost = match ($i) {
                    0 => max(intdiv($selected, 3), 1),
                    1 => intdiv($selected * 2, 3) + 1,
                    default => max($selected, $bookshelves * 2),
                };
                $data[$i] = $cost < $i + 1 ? 0 : $cost;
            }
            $ids = $this->enchantingReference()['enchantments'];
            for ($i = 0; $i < 3; ++$i) {
                if ($data[$i] > 0) {
                    $list = $this->enchantingOffer($c, $item, $random, $i, $data[$i]);
                    if ($list !== []) {
                        $clue = $list[$random->nextInt(count($list))];
                        $data[4 + $i] = $ids[$clue['name']]['id'];
                        $data[7 + $i] = $clue['level'];
                    }
                }
            }
        }
        $this->sendEnchantingData($c, $data);
    }

    /** EnchantmentMenu.getEnchantmentList: the offer's enchantments from the seed plus its slot; a book loses one of several. */
    private function enchantingOffer(Client $c, array $item, LegacyRandom $random, int $slot, int $cost): array
    {
        $reference = $this->enchantingReference();
        $random->setSeed($c->enchantmentSeed + $slot);
        $list = EnchantmentSelection::select($random, $item, $cost, $reference['pools']['mob'], $reference);
        if ($item['name'] === 'minecraft:book' && count($list) > 1) {
            array_splice($list, $random->nextInt(count($list)), 1);
        }
        return $list;
    }

    /** AbstractContainerMenu.broadcastChanges for the data slots: each changed value, as a short. */
    private function sendEnchantingData(Client $c, array $data): void
    {
        foreach ($data as $index => $value) {
            if (($c->enchantData[$index] ?? null) !== $value) {
                $c->send($this->version, 'container_set_data', Binary::writeVarInt($c->windowId) . pack('n', $index) . pack('n', $value & 0xffff));
            }
        }
        $c->enchantData = $data;
    }

    /**
     * EnchantmentMenu.clickMenuButton: an offer with its cost in levels, and as many lapis as its
     * number, enchants the item (a book becomes an enchanted book), takes the levels and lapis
     * (Player.onEnchantmentPerformed draws a new seed), and sounds at the table.
     */
    private function enchantButton(Client $c, int $button): void
    {
        if ($c->windowType !== 'enchanting' || $button < 0 || $button > 2 || !$this->validWindow($c)) {
            return;
        }
        $stack = $c->enchantSlots[0] ?? null;
        $lapis = $c->enchantSlots[1] ?? null;
        $cost = $c->enchantData[$button] ?? 0;
        $levels = $button + 1;
        $creative = $c->gameMode === 1;
        if ((($lapis['count'] ?? 0) < $levels && !$creative) || $cost <= 0 || $stack === null || (($c->experienceLevel < $levels || $c->experienceLevel < $cost) && !$creative)) {
            return;
        }
        $item = $this->enchantableItem($stack);
        if ($item === null) {
            return;
        }
        $list = $this->enchantingOffer($c, $item, new LegacyRandom(0), $button, $cost);
        if ($list === []) {
            return;
        }
        $c->experienceLevel -= $levels;
        if ($c->experienceLevel < 0) {
            [$c->experienceLevel, $c->experienceProgress, $c->experience] = [0, 0.0, 0];
        }
        $c->enchantmentSeed = random_int(-2147483648, 2147483647);
        $this->syncExperience($c);
        $field = 'enchantments';
        if ($item['name'] === 'minecraft:book') {
            $stack['item'] = $this->version->blocks['items']['enchanted_book'];
            $field = 'storedEnchantments';
        }
        foreach ($list as $enchantment) {
            $stack[$field][$enchantment['name']] = $enchantment['level'];
        }
        $c->enchantSlots[0] = $stack;
        if (!$creative) {
            if (($c->enchantSlots[1]['count'] -= $levels) <= 0) {
                unset($c->enchantSlots[1]);
            }
        }
        $this->awardStat($c, 'custom', 'enchant_item');
        $this->simpleTrigger($c, 'minecraft:enchanted_item', []);
        $this->syncInventory($c);
        $this->enchantingSlotsChanged($c);
        [$x, $y, $z] = $c->windowPosition;
        $payload = Packets::sound($this->version->blocks['sounds']['block.enchantment_table.use'], 4, $x + 0.5, $y + 0.5, $z + 0.5, 1.0, self::randomUnit() * 0.1 + 0.9, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $x - 0.5) ** 2 + ($player->y - $y - 0.5) ** 2 + ($player->z - $z - 0.5) ** 2 < 16 ** 2) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    /**
     * EnchantmentMenu's slots: the item slot holds one, the second only lapis lazuli, and shift-
     * clicking sends lapis to its slot and one of anything else to an empty item slot. Returns
     * whether the click was one of these.
     */
    private function enchantingClick(Client $c, array &$items, int $slot, int $button, int $mode): bool
    {
        $lapisItem = $this->version->blocks['items']['lapis_lazuli'];
        if ($mode === 0 && $slot <= 1 && $c->cursor !== null) {
            $held = $items[$slot];
            if ($slot === 1 && $c->cursor['item'] !== $lapisItem) {
                // Slot.mayPlace refuses it, and a click swaps nothing.
                return true;
            }
            $limit = $slot === 0 ? 1 : $this->version->catalog->item($lapisItem)['max_stack'];
            if ($held === null) {
                $move = min($button === 1 ? 1 : $c->cursor['count'], $limit);
                $items[$slot] = array_replace($c->cursor, ['count' => $move]);
                if (($c->cursor['count'] -= $move) <= 0) {
                    $c->cursor = null;
                }
            } elseif (Inventory::same($held, $c->cursor, $this->version->catalog)) {
                $move = min($button === 1 ? 1 : $c->cursor['count'], $limit - $held['count']);
                $items[$slot]['count'] += max(0, $move);
                if (($c->cursor['count'] -= max(0, $move)) <= 0) {
                    $c->cursor = null;
                }
            } elseif ($c->cursor['count'] <= $limit) {
                [$items[$slot], $c->cursor] = [$c->cursor, $held];
            }
            return true;
        }
        if ($mode === 1 && $items[$slot] !== null) {
            $stack = $items[$slot];
            if ($slot <= 1) {
                // moveItemStackTo(2, 38, reversed): the hotbar's end first.
                $items[$slot] = null;
                $left = Inventory::add($items, $stack, $this->version->catalog, range(37, 2, -1));
                $items[$slot] = $left > 0 ? array_replace($stack, ['count' => $left]) : null;
            } elseif ($stack['item'] === $lapisItem) {
                $items[$slot] = null;
                $left = Inventory::add($items, $stack, $this->version->catalog, [1]);
                $items[$slot] = $left > 0 ? array_replace($stack, ['count' => $left]) : null;
            } elseif ($items[0] === null) {
                $items[0] = array_replace($stack, ['count' => 1]);
                Inventory::consume($items, $slot, 1);
            }
            return true;
        }
        if (($mode === 2 || $mode === 5) && $slot <= 1) {
            // Swapping or dragging into the table's slots only takes what they may hold.
            return $mode === 5 || ($slot === 1 && ($c->inventory[$button === 40 ? 45 : 36 + $button]['item'] ?? $lapisItem) !== $lapisItem) || ($slot === 0 && ($c->inventory[$button === 40 ? 45 : 36 + $button]['count'] ?? 1) > 1);
        }
        return false;
    }

    /** AbstractContainerMenu.clearContainer when the menu closes: the item and lapis go back to the player. */
    private function returnEnchantingItems(Client $c): void
    {
        foreach ($c->enchantSlots as $stack) {
            $left = $this->giveStack($c, $stack);
            if ($left > 0) {
                $this->playerDrop($c, array_replace($stack, ['count' => $left]), false);
            }
        }
        $c->enchantSlots = [];
        $c->enchantData = [];
    }
}
