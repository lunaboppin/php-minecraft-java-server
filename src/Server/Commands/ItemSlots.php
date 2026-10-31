<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

/** SlotRanges: the slot names /item and loot slot sources accept, and the slot IDs each covers. */
final class ItemSlots
{
    /** @var array<string, list<int>>|null */
    private static ?array $ranges = null;

    /** @return list<int>|null */
    public static function range(string $name): ?array
    {
        return self::ranges()[$name] ?? null;
    }

    /** @return array<string, list<int>> */
    private static function ranges(): array
    {
        if (self::$ranges !== null) {
            return self::$ranges;
        }
        $ranges = ['contents' => [0]];
        foreach ([['container.', 0, 54], ['hotbar.', 0, 9], ['inventory.', 9, 27], ['enderchest.', 200, 27], ['mob.inventory.', 300, 8], ['horse.', 500, 15]] as [$prefix, $offset, $size]) {
            for ($i = 0; $i < $size; ++$i) {
                $ranges[$prefix . $i] = [$offset + $i];
            }
            $ranges[$prefix . '*'] = range($offset, $offset + $size - 1);
        }
        // EquipmentSlot.getIndex: hands from 98, armor from 100 (feet first), body 105, saddle 106.
        $ranges += [
            'weapon' => [98], 'weapon.mainhand' => [98], 'weapon.offhand' => [99], 'weapon.*' => [98, 99],
            'armor.head' => [103], 'armor.chest' => [102], 'armor.legs' => [101], 'armor.feet' => [100], 'armor.body' => [105],
            'armor.*' => [103, 102, 101, 100, 105], 'saddle' => [106], 'horse.chest' => [499], 'player.cursor' => [499],
        ];
        for ($i = 0; $i < 4; ++$i) {
            $ranges['player.crafting.' . $i] = [500 + $i];
        }
        $ranges['player.crafting.*'] = [500, 501, 502, 503];
        return self::$ranges = $ranges;
    }
}
