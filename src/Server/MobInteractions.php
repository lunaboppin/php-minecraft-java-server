<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\Inventory;

/**
 * Items used on mobs: shears take a sheep's wool (Sheep.mobInteract and shear), and a bucket
 * milks a cow, mooshroom or goat (AbstractCow.mobInteract and Goat.mobInteract).
 */
trait MobInteractions
{
    /** DyeColor ids, as Sheep.DATA_WOOL_ID keeps them in its low four bits. */
    private const WOOL_COLORS = ['white', 'orange', 'magenta', 'light_blue', 'yellow', 'lime', 'pink', 'gray', 'light_gray', 'cyan', 'purple', 'blue', 'brown', 'green', 'red', 'black'];

    /** Sheep.DATA_WOOL_ID (index 17): the colour, and 16 once sheared. */
    private static function woolByte(array $sheep): int
    {
        return (int) array_search($sheep['color'] ?? 'white', self::WOOL_COLORS, true) | (($sheep['sheared'] ?? false) ? 16 : 0);
    }

    /** Returns whether the held item did something to the mob, with its hand swung. */
    private function useItemOnMob(Client $player, int $id, int $hand): bool
    {
        $slot = $hand === 0 ? 36 + $player->heldSlot : 45;
        $held = $player->inventory[$slot] ?? null;
        if ($held === null) {
            return false;
        }
        $mob = $this->animals[$id];
        $item = $this->version->catalog->itemName($held['item']);
        if ($item === 'shears' && $mob['kind'] === 'sheep' && !($mob['sheared'] ?? false) && !($mob['baby'] ?? false)) {
            $this->shearSheep($player, $id, $slot);
            return true;
        }
        if ($item === 'bowl' && $mob['kind'] === 'mooshroom' && !($mob['baby'] ?? false)) {
            // MushroomCow.mobInteract: a bowl of mushroom stew, the mooshroom's own sound for everyone near.
            $payload = Packets::sound($this->version->blocks['sounds']['entity.mooshroom.milk'], 6, $mob['x'], $mob['y'], $mob['z'], 1.0, 1.0, unpack('J', random_bytes(8))[1]);
            foreach ($this->localPlayers() as $other) {
                if (($other->x - $mob['x']) ** 2 + ($other->y - $mob['y']) ** 2 + ($other->z - $mob['z']) ** 2 < 256.0) {
                    $other->send($this->version, 'sound', $payload);
                }
            }
            $this->filledResult($player, $slot, ['item' => $this->version->blocks['items']['mushroom_stew'], 'count' => 1], false);
            $this->useSwing($player, $hand);
            return true;
        }
        if ($item === 'bucket' && in_array($mob['kind'], ['cow', 'mooshroom', 'goat'], true) && !($mob['baby'] ?? false)) {
            // Player.playSound: everyone near but the player hears the milking.
            $sound = $mob['kind'] === 'goat' ? (($mob['screaming'] ?? false) ? 'entity.goat.screaming.milk' : 'entity.goat.milk') : 'entity.cow.milk';
            $payload = Packets::sound($this->version->blocks['sounds'][$sound], 7, $player->x, $player->y, $player->z, 1.0, 1.0, unpack('J', random_bytes(8))[1]);
            foreach ($this->localPlayers() as $other) {
                if ($other !== $player && ($other->x - $player->x) ** 2 + ($other->y - $player->y) ** 2 + ($other->z - $player->z) ** 2 < 256.0) {
                    $other->send($this->version, 'sound', $payload);
                }
            }
            $this->filledResult($player, $slot, ['item' => $this->version->blocks['items']['milk_bucket'], 'count' => 1]);
            $this->useSwing($player, $hand);
            return true;
        }
        return false;
    }

    /** Sheep.shear: the sound, the sheared wool, the wool dropped from the shearing loot, and the shears worn. */
    private function shearSheep(Client $player, int $id, int $slot): void
    {
        $sheep = &$this->animals[$id];
        $payload = Packets::sound($this->version->blocks['sounds']['entity.sheep.shear'], 7, $sheep['x'], $sheep['y'], $sheep['z'], 1.0, 1.0, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $other) {
            if (($other->x - $sheep['x']) ** 2 + ($other->y - $sheep['y']) ** 2 + ($other->z - $sheep['z']) ** 2 < 256.0) {
                $other->send($this->version, 'sound', $payload);
            }
        }
        $context = ['this' => ['type' => 'minecraft:sheep', 'components' => ['minecraft:sheep/color' => $sheep['color'] ?? 'white'], 'type_specific' => ['sheep' => ['sheared' => false]]]];
        $sheep['sheared'] = true;
        $metadata = Binary::writeVarInt($id) . "\x11" . Binary::writeVarInt(0) . chr(self::woolByte($sheep)) . "\xff";
        [$x, $y, $z] = [$sheep['x'], $sheep['y'], $sheep['z']];
        unset($sheep);
        foreach ($this->localPlayers() as $viewer) {
            if (isset($viewer->entities[$id])) {
                $viewer->send($this->version, 'set_entity_data', $metadata);
            }
        }
        foreach ($this->loot()->drops('minecraft:shearing/sheep', $context) as [$drop, $count]) {
            // Sheep.shear: each wool on its own from a block up, with an extra push.
            for ($index = 0; $index < $count; ++$index) {
                $velocity = [self::randomUnit() * 0.2 - 0.1 + (self::randomUnit() - self::randomUnit()) * 0.1, 0.2 + self::randomUnit() * 0.05, self::randomUnit() * 0.2 - 0.1 + (self::randomUnit() - self::randomUnit()) * 0.1];
                $this->dropStack($x, $y + 1.0, $z, ['item' => $this->version->blocks['items'][substr($drop, 10)], 'count' => 1], 0, $velocity, 10);
            }
        }
        $this->wearHeldTool($player, $slot);
        $this->useSwing($player, $slot === 45 ? 1 : 0);
    }

    /** ItemStack.hurtAndBreak by one, none in creative; Unbreaking may spare it. */
    private function wearHeldTool(Client $player, int $slot): void
    {
        $stack = $player->inventory[$slot] ?? null;
        $max = $stack === null ? 0 : ($this->version->catalog->item($stack['item'])['durability'] ?? 0);
        if ($stack === null || $max <= 0 || $player->gameMode === 1) {
            return;
        }
        $unbreaking = $stack['enchantments']['minecraft:unbreaking'] ?? 0;
        if ($unbreaking > 0 && random_int(0, $unbreaking) !== 0) {
            return;
        }
        $stack['damage'] = ($stack['damage'] ?? 0) + 1;
        if ($stack['damage'] >= $max) {
            $this->awardStat($player, 'broken', $this->version->catalog->itemName($stack['item']));
            unset($player->inventory[$slot]);
        } else {
            $player->inventory[$slot] = $stack;
        }
        $this->syncInventory($player);
    }

    /** ItemUtils.createFilledResult: creative keeps the held item and adds the result unless it has one; else one is used for it. */
    private function filledResult(Client $c, int $slot, array $result, bool $limitCreative = true): void
    {
        $catalog = $this->version->catalog;
        if ($c->gameMode === 1 && $limitCreative) {
            $has = false;
            foreach (array_merge([36 + $c->heldSlot, 45], $this->storageSlots()) as $index) {
                $has = $has || Inventory::same($c->inventory[$index] ?? null, $result, $catalog);
            }
            if (!$has) {
                $this->addCauldronResult($c, $result);
            }
        } else {
            if ($c->gameMode !== 1) {
                Inventory::consume($c->inventory, $slot);
            }
            if (!isset($c->inventory[$slot])) {
                $c->inventory[$slot] = $result;
            } elseif (!$this->addCauldronResult($c, $result)) {
                $this->playerDrop($c, $result, false);
            }
        }
        $this->syncInventory($c);
    }
}
