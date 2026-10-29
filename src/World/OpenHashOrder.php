<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

/**
 * The key order of a fastutil Object2ObjectOpenHashMap<String, ?> (expected 16, load factor 0.5):
 * open addressing with HashCommon.mix, shiftKeys on removal, and iteration from the last slot.
 */
final class OpenHashOrder
{
    /** @var list<?string> */
    private array $table;

    public function __construct()
    {
        $this->table = array_fill(0, 32, null);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_values(array_filter(array_reverse($this->table), static fn (?string $key): bool => $key !== null));
    }

    public function insert(string $key): void
    {
        $mask = count($this->table) - 1;
        $position = self::mix(self::hash($key)) & $mask;
        while ($this->table[$position] !== null) {
            if ($this->table[$position] === $key) {
                return;
            }
            $position = ($position + 1) & $mask;
        }
        $this->table[$position] = $key;
        // insert: if (size++ >= maxFill) rehash, with maxFill(n, 0.5) = n / 2.
        if ($this->size() > intdiv(count($this->table), 2)) {
            $this->rehash(count($this->table) * 2);
        }
    }

    private function size(): int
    {
        return count(array_filter($this->table, static fn (?string $name): bool => $name !== null));
    }

    /** rehash walks the old table from its last slot, placing each key in the new one. */
    private function rehash(int $size): void
    {
        $keys = $this->keys();
        $this->table = array_fill(0, $size, null);
        foreach ($keys as $existing) {
            $this->place($existing);
        }
    }

    private function place(string $key): void
    {
        $mask = count($this->table) - 1;
        $position = self::mix(self::hash($key)) & $mask;
        while ($this->table[$position] !== null) {
            $position = ($position + 1) & $mask;
        }
        $this->table[$position] = $key;
    }

    /** Removal with fastutil's shiftKeys, which moves later entries of the run back. */
    public function delete(string $key): void
    {
        $mask = count($this->table) - 1;
        $position = self::mix(self::hash($key)) & $mask;
        while ($this->table[$position] !== $key) {
            if ($this->table[$position] === null) {
                return;
            }
            $position = ($position + 1) & $mask;
        }
        while (true) {
            $last = $position;
            $position = ($position + 1) & $mask;
            while (true) {
                $current = $this->table[$position];
                if ($current === null) {
                    $this->table[$last] = null;
                    // removeEntry: shrink by half once under a quarter of maxFill, never below the initial 32.
                    if (count($this->table) > 32 && $this->size() < intdiv(intdiv(count($this->table), 2), 4)) {
                        $this->rehash(intdiv(count($this->table), 2));
                    }
                    return;
                }
                $slot = self::mix(self::hash($current)) & $mask;
                if ($last <= $position ? ($last >= $slot || $slot > $position) : ($last >= $slot && $slot > $position)) {
                    break;
                }
                $position = ($position + 1) & $mask;
            }
            $this->table[$last] = $this->table[$position];
        }
    }

    /** String.hashCode */
    private static function hash(string $key): int
    {
        $hash = 0;
        foreach (unpack('n*', iconv('UTF-8', 'UTF-16BE', $key)) ?: [] as $unit) {
            $hash = (31 * $hash + $unit) & 0xffffffff;
        }
        return $hash;
    }

    /** HashCommon.mix */
    private static function mix(int $value): int
    {
        // 32-bit multiplication in halves, as the 64-bit product could overflow PHP's integers.
        $phi = 0x9E3779B9;
        $product = (($value * ($phi & 0xffff)) + ((($value * ($phi >> 16)) & 0xffff) << 16)) & 0xffffffff;
        return ($product ^ ($product >> 16)) & 0xffffffff;
    }
}
