<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Inventory;

/** CauldronInteraction: buckets and bottles filling and emptying cauldrons. */
trait Cauldrons
{
    /** The cauldron a full bucket empties into, with its level, and the sound it makes. */
    private const CAULDRON_FILLS = [
        'water_bucket' => ['water_cauldron', '3', 'item.bucket.empty'],
        'lava_bucket' => ['lava_cauldron', null, 'item.bucket.empty_lava'],
        'powder_snow_bucket' => ['powder_snow_cauldron', '3', 'item.bucket.empty_powder_snow'],
    ];

    /** The bucket an empty bucket takes from each full cauldron, and the sound it makes. */
    private const CAULDRON_EMPTIES = [
        'water_cauldron' => ['water_bucket', 'item.bucket.fill'],
        'lava_cauldron' => ['lava_bucket', 'item.bucket.fill_lava'],
        'powder_snow_cauldron' => ['powder_snow_bucket', 'item.bucket.fill_powder_snow'],
    ];

    /**
     * CauldronInteraction for the held item: true when it acted (SUCCESS), false when the click
     * falls through to the item's own use (TRY_WITH_EMPTY_HAND).
     */
    private function useCauldron(Client $c, int $x, int $y, int $z, int $slot): bool
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $block = $catalog->blockName($state);
        $stack = $c->inventory[$slot] ?? null;
        $item = $stack === null ? null : $catalog->itemName($stack['item']);
        if ($item === null) {
            return false;
        }
        $level = (int) ($catalog->properties($state)['level'] ?? 0);
        if (isset(self::CAULDRON_FILLS[$item])) {
            // emptyBucket: any cauldron takes a full bucket, whatever it held.
            [$filled, $fillLevel, $sound] = self::CAULDRON_FILLS[$item];
            $this->cauldronResult($c, $slot, 'bucket', null, 'fill_cauldron', $item);
            $this->setBlock($x, $y, $z, $catalog->state($filled, $fillLevel === null ? [] : ['level' => $fillLevel]));
            $this->blockSoundAt($x, $y, $z, $sound);
            return true;
        }
        $water = $item === 'potion' && ($stack['potion']['potion'] ?? null) === 'minecraft:water';
        if ($block === 'cauldron') {
            if (!$water) {
                return false;
            }
            $this->cauldronResult($c, $slot, 'glass_bottle', null, 'use_cauldron', $item);
            $this->setBlock($x, $y, $z, $catalog->state('water_cauldron', ['level' => '1']));
            $this->blockSoundAt($x, $y, $z, 'item.bottle.empty');
            return true;
        }
        if ($item === 'bucket' && isset(self::CAULDRON_EMPTIES[$block])) {
            // fillBucket: water and powder snow only when full; lava always is.
            if ($block !== 'lava_cauldron' && $level !== 3) {
                return false;
            }
            [$bucket, $sound] = self::CAULDRON_EMPTIES[$block];
            $this->cauldronResult($c, $slot, $bucket, null, 'use_cauldron', $item);
            $this->setBlock($x, $y, $z, $catalog->state('cauldron'));
            $this->blockSoundAt($x, $y, $z, $sound);
            return true;
        }
        if ($block !== 'water_cauldron') {
            return false;
        }
        if ($item === 'glass_bottle') {
            $this->cauldronResult($c, $slot, 'potion', ['potion' => 'minecraft:water', 'custom_color' => null, 'custom_effects' => [], 'custom_name' => null], 'use_cauldron', $item);
            // LayeredCauldronBlock.lowerFillLevel: the last level leaves an empty cauldron.
            $this->setBlock($x, $y, $z, $level > 1 ? $catalog->state('water_cauldron', ['level' => (string) ($level - 1)]) : $catalog->state('cauldron'));
            $this->blockSoundAt($x, $y, $z, 'item.bottle.fill');
            return true;
        }
        if ($water && $level !== 3) {
            $this->cauldronResult($c, $slot, 'glass_bottle', null, 'use_cauldron', $item);
            $this->setBlock($x, $y, $z, $catalog->state('water_cauldron', ['level' => (string) ($level + 1)]));
            $this->blockSoundAt($x, $y, $z, 'item.bottle.empty');
            return true;
        }
        return false;
    }

    /**
     * ItemUtils.createFilledResult, then the use and item stats: in creative the held stack stays
     * and the result is added unless the inventory has one; otherwise one is used, and the result
     * takes its place or goes into the inventory, or is dropped when there is no room.
     */
    private function cauldronResult(Client $c, int $slot, string $result, ?array $potion, string $stat, string $used): void
    {
        $catalog = $this->version->catalog;
        $stack = ['item' => $this->version->blocks['items'][$result], 'count' => 1];
        if ($potion !== null) {
            $stack['potion'] = $potion;
        }
        $hand = [36 + $c->heldSlot, 45];
        if ($c->gameMode === 1) {
            $has = false;
            foreach (array_merge($hand, $this->storageSlots()) as $index) {
                $has = $has || Inventory::same($c->inventory[$index] ?? null, $stack, $catalog);
            }
            if (!$has) {
                $this->addCauldronResult($c, $stack);
            }
        } else {
            Inventory::consume($c->inventory, $slot);
            if (!isset($c->inventory[$slot])) {
                $c->inventory[$slot] = $stack;
            } elseif (!$this->addCauldronResult($c, $stack)) {
                $this->playerDrop($c, $stack, false);
            }
        }
        $this->syncInventory($c);
        $this->awardStat($c, 'custom', $stat);
        $this->awardStat($c, 'used', $used);
    }

    /** Inventory.add for one item: a stack with room, the selected slot and offhand first, else a free slot. */
    private function addCauldronResult(Client $c, array $stack): bool
    {
        $catalog = $this->version->catalog;
        $max = $catalog->item($stack['item'])['max_stack'] ?? 64;
        foreach (array_merge([36 + $c->heldSlot, 45], $this->storageSlots()) as $index) {
            if (Inventory::same($c->inventory[$index] ?? null, $stack, $catalog) && $c->inventory[$index]['count'] < $max) {
                ++$c->inventory[$index]['count'];
                return true;
            }
        }
        foreach ($this->storageSlots() as $index) {
            if (!isset($c->inventory[$index])) {
                $c->inventory[$index] = $stack;
                return true;
            }
        }
        return false;
    }
}
