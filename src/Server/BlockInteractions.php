<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\Inventory;

/**
 * Blocks used by hand: eating cake and putting a candle on it, picking sweet berries and glow
 * berries, potting and unpotting plants, blowing out candles, and lighting candles, candle
 * cakes and campfires with flint and steel.
 */
trait BlockInteractions
{
    /** Level.playSound at a block's centre for the players within its range, but $except. */
    private function blockSound(int $x, int $y, int $z, string $sound, float $pitch = 1.0, ?Client $except = null): void
    {
        $payload = Packets::sound($this->version->blocks['sounds'][$sound], 4, $x + 0.5, $y + 0.5, $z + 0.5, 1.0, $pitch, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if ($player !== $except && ($player->x - $x - 0.5) ** 2 + ($player->y - $y - 0.5) ** 2 + ($player->z - $z - 0.5) ** 2 < 256.0) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    /**
     * The block's own use with the item in the hand, then (main hand only) without it, for the
     * blocks here: Block.useItemOn and useWithoutItem. Returns null when neither does anything,
     * else whether to swing.
     */
    private function useInteractiveBlock(Client $c, int $hand, int $slot, int $x, int $y, int $z, array $cursor): ?bool
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $name = $catalog->blockName($state);
        $properties = $catalog->properties($state);
        $stack = $c->inventory[$slot] ?? null;
        $item = $stack === null ? null : $catalog->itemName($stack['item']);
        $candles = $this->version->registryTags('minecraft:item')['minecraft:candles'] ?? [];
        // useItemOn.
        if ($name === 'cake' && $item !== null && in_array($item, $candles, true) && $properties['bites'] === '0') {
            // CakeBlock.useItemOn: a candle on an uneaten cake.
            $this->consumeHeld($c, $slot);
            $this->blockSound($x, $y, $z, 'block.cake.add_candle');
            $this->setBlock($x, $y, $z, $catalog->state($item === 'candle' ? 'candle_cake' : $item . '_cake'));
            $this->awardStat($c, 'used', $item);
            return true;
        }
        if (str_ends_with($name, 'candle_cake') && in_array($item, ['flint_and_steel', 'fire_charge'], true)) {
            return null;
        }
        if ((str_ends_with($name, 'candle_cake') && $cursor[1] > 0.5 || in_array($name, $this->version->registryTags('minecraft:block')['minecraft:candles'] ?? [], true))
            && $stack === null && ($properties['lit'] ?? 'false') === 'true' && $c->gameMode !== 2) {
            // AbstractCandleBlock.extinguish by an empty hand.
            $this->setBlock($x, $y, $z, $catalog->state($name, ['lit' => 'false'] + $properties));
            $this->blockSound($x, $y, $z, 'block.candle.extinguish');
            return true;
        }
        if ($name === 'flower_pot' || str_starts_with($name, 'potted_')) {
            $potted = $item === null ? null : $this->pottedBlock($item);
            if ($potted !== null) {
                // FlowerPotBlock.useItemOn: a plant into an empty pot; a full pot takes nothing.
                if ($name === 'flower_pot') {
                    $this->setBlock($x, $y, $z, $catalog->state($potted));
                    $this->awardStat($c, 'custom', 'pot_flower');
                    $this->consumeHeld($c, $slot);
                }
                return true;
            }
        }
        if ($name === 'sweet_berry_bush' && $item === 'bone_meal' && $properties['age'] !== '3') {
            return null;
        }
        if ($hand !== 0) {
            return null;
        }
        // useWithoutItem.
        if ($name === 'cake' || str_ends_with($name, 'candle_cake')) {
            // CakeBlock.eat: a slice for a player who may eat; a candle cake becomes a bitten cake and drops its candle.
            if ($c->gameMode !== 1 && $c->food >= 20) {
                return null;
            }
            $this->awardStat($c, 'custom', 'eat_cake_slice');
            $c->food = min(20, $c->food + 2);
            $c->saturation = min((float) $c->food, $c->saturation + \PhpMinecraftJavaServer\World\Generation\Float32::round(0.4));
            $this->health($c);
            $bites = $name === 'cake' ? (int) $properties['bites'] : 0;
            $this->setBlock($x, $y, $z, $bites < 6 ? $catalog->state('cake', ['bites' => (string) ($bites + 1)]) : $catalog->state('air'));
            if ($name !== 'cake') {
                $this->dropBlockLoot($x, $y, $z, $state);
            }
            return true;
        }
        if ($name === 'sweet_berry_bush' && (int) $properties['age'] > 1) {
            // SweetBerryBushBlock.useWithoutItem with the harvest loot: one more berry from a ripe bush, and one or two.
            $berries = $this->version->blocks['items']['sweet_berries'];
            if ($properties['age'] === '3') {
                $this->popResource($x, $y, $z, ['item' => $berries, 'count' => 1]);
            }
            $this->popResource($x, $y, $z, ['item' => $berries, 'count' => random_int(1, 2)]);
            $this->blockSound($x, $y, $z, 'block.sweet_berry_bush.pick_berries', 0.8 + self::randomUnit() * 0.4);
            $this->setBlock($x, $y, $z, $catalog->state('sweet_berry_bush', ['age' => '1']));
            return true;
        }
        if (($name === 'cave_vines' || $name === 'cave_vines_plant') && $properties['berries'] === 'true') {
            // CaveVines.use: the glow berries drop.
            $this->popResource($x, $y, $z, ['item' => $this->version->blocks['items']['glow_berries'], 'count' => 1]);
            $this->blockSound($x, $y, $z, 'block.cave_vines.pick_berries', 0.8 + self::randomUnit() * 0.4);
            $this->setBlock($x, $y, $z, $catalog->state($name, ['berries' => 'false'] + $properties));
            return true;
        }
        if (str_starts_with($name, 'potted_')) {
            // FlowerPotBlock.useWithoutItem: the plant back to the player, or dropped.
            $plant = ['item' => $this->version->blocks['items'][$this->pottedPlant($name)], 'count' => 1];
            if ($this->giveStack($c, $plant) > 0) {
                $this->playerDrop($c, $plant, false);
            }
            $this->syncInventory($c);
            $this->setBlock($x, $y, $z, $catalog->state('flower_pot'));
            return true;
        }
        return null;
    }

    /** ItemStack.consume(1, player): one of the held stack, kept in creative. */
    private function consumeHeld(Client $c, int $slot): void
    {
        if ($c->gameMode !== 1) {
            Inventory::consume($c->inventory, $slot);
            $this->syncInventory($c);
        }
    }

    /** FlowerPotBlock.POTTED_BY_CONTENT: the potted block for a plant, or null. */
    private function pottedBlock(string $plant): ?string
    {
        $name = 'potted_' . match ($plant) {
            'azalea' => 'azalea_bush',
            'flowering_azalea' => 'flowering_azalea_bush',
            default => $plant,
        };
        return isset($this->version->catalog->blocks[$name]) ? $name : null;
    }

    private function pottedPlant(string $potted): string
    {
        return match ($potted) {
            'potted_azalea_bush' => 'azalea',
            'potted_flowering_azalea_bush' => 'flowering_azalea',
            default => substr($potted, strlen('potted_')),
        };
    }

    /** FlintAndSteelItem.useOn on a campfire, candle or candle cake that is out: lit, with the wear and sound. */
    private function lightWithFlint(Client $c, int $slot, int $x, int $y, int $z): bool
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $name = $catalog->blockName($state);
        $properties = $catalog->properties($state);
        $lightable = in_array($name, ['campfire', 'soul_campfire'], true) || str_ends_with($name, 'candle_cake')
            || in_array($name, $this->version->registryTags('minecraft:block')['minecraft:candles'] ?? [], true);
        if (!$lightable || ($properties['lit'] ?? 'true') !== 'false' || ($properties['waterlogged'] ?? 'false') === 'true') {
            return false;
        }
        $this->blockSound($x, $y, $z, 'item.flintandsteel.use', self::randomUnit() * 0.4 + 0.8, $c);
        $this->setBlock($x, $y, $z, $catalog->state($name, ['lit' => 'true'] + $properties));
        $stack = $c->inventory[$slot];
        if ($c->gameMode !== 1) {
            $stack['damage'] = ($stack['damage'] ?? 0) + 1;
            if ($stack['damage'] >= $catalog->item($stack['item'])['durability']) {
                $this->awardStat($c, 'broken', $catalog->itemName($stack['item']));
                unset($c->inventory[$slot]);
            } else {
                $c->inventory[$slot] = $stack;
            }
            $this->syncInventory($c);
        }
        $this->awardStat($c, 'used', 'flint_and_steel');
        return true;
    }
}
