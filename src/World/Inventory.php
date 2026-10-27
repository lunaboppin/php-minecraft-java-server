<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

use PhpMinecraftJavaServer\Protocol\Catalog;

final class Inventory
{
    public static function same(?array $a, ?array $b, Catalog $catalog): bool
    {
        return $a !== null && $b !== null && $catalog->itemStacks->same($a, $b);
    }

    /** Returns the amount that did not fit; updates only the provided destination slots. */
    public static function add(array &$slots, array $stack, Catalog $catalog, array $destinations): int
    {
        $max = $catalog->item($stack['item'])['max_stack'] ?? 64;
        $left = $stack['count'];
        foreach ($destinations as $slot) {
            if (self::same($slots[$slot] ?? null, $stack, $catalog)) {
                $move = min($left, $max - $slots[$slot]['count']);
                $slots[$slot]['count'] += $move;
                $left -= $move;
                if ($left === 0) {
                    return 0;
                }
            }
        }
        foreach ($destinations as $slot) {
            if (!isset($slots[$slot])) {
                $move = min($left, $max);
                $slots[$slot] = array_replace($stack, ['count' => $move]);
                $left -= $move;
                if ($left === 0) {
                    return 0;
                }
            }
        }
        return $left;
    }

    public static function consume(array &$slots, int $slot, int $count = 1): void
    {
        if (!isset($slots[$slot]) || $slots[$slot]['count'] < $count) {
            throw new \InvalidArgumentException('Insufficient items');
        }
        $slots[$slot]['count'] -= $count;
        if ($slots[$slot]['count'] === 0) {
            unset($slots[$slot]);
        }
    }

    public static function pickup(array &$slots, ?array &$cursor, int $slot, bool $right, Catalog $catalog): void
    {
        $stack = $slots[$slot] ?? null;
        if ($cursor === null) {
            if ($stack === null) {
                return;
            }$take = $right ? (int) ceil($stack['count'] / 2) : $stack['count'];
            $cursor = array_replace($stack, ['count' => $take]);
            self::consume($slots, $slot, $take);
            return;
        }
        if ($stack === null || self::same($stack, $cursor, $catalog)) {
            $max = $catalog->item($cursor['item'])['max_stack'] ?? 64;
            $move = min($cursor['count'], $right ? 1 : $max, $max - ($stack['count'] ?? 0));
            if ($move > 0) {
                $slots[$slot] = array_replace($cursor, ['count' => ($stack['count'] ?? 0) + $move]);
                $cursor['count'] -= $move;
                if ($cursor['count'] === 0) {
                    $cursor = null;
                }
            }
        } else {
            $slots[$slot] = $cursor;
            $cursor = $stack;
        }
    }
}
