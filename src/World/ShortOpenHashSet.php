<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

/**
 * The iteration order of a fastutil ShortOpenHashSet (expected 16, load factor 0.75): open
 * addressing on HashCommon.mix, zero kept apart and returned first, then the table from its
 * last slot down.
 */
final class ShortOpenHashSet
{
    /** @var list<int> zero marks an empty slot */
    private array $table;

    private bool $containsZero = false;

    private int $size = 0;

    public function __construct()
    {
        $this->table = array_fill(0, 32, 0);
    }

    public function count(): int
    {
        return $this->size;
    }

    public function add(int $key): void
    {
        $key = (($key & 0xffff) ^ 0x8000) - 0x8000;
        if ($key === 0) {
            if (!$this->containsZero) {
                $this->containsZero = true;
                $this->grow();
            }
            return;
        }
        $mask = count($this->table) - 1;
        $position = self::mix($key) & $mask;
        while ($this->table[$position] !== 0) {
            if ($this->table[$position] === $key) {
                return;
            }
            $position = ($position + 1) & $mask;
        }
        $this->table[$position] = $key;
        $this->grow();
    }

    /** @return list<int> */
    public function values(): array
    {
        $values = $this->containsZero ? [0] : [];
        for ($i = count($this->table) - 1; $i >= 0; --$i) {
            if ($this->table[$i] !== 0) {
                $values[] = $this->table[$i];
            }
        }
        return $values;
    }

    /** add: if (size++ >= maxFill) rehash(arraySize(size + 1, f)). */
    private function grow(): void
    {
        $length = count($this->table);
        if ($this->size++ < min((int) ceil($length * 0.75), $length - 1)) {
            return;
        }
        $needed = (int) ceil(($this->size + 1) / 0.75);
        $capacity = 2;
        while ($capacity < $needed) {
            $capacity *= 2;
        }
        $old = $this->table;
        $this->table = array_fill(0, $capacity, 0);
        $mask = $capacity - 1;
        // rehash moves the keys from the old table's last slot down.
        for ($i = $length - 1; $i >= 0; --$i) {
            if ($old[$i] === 0) {
                continue;
            }
            $position = self::mix($old[$i]) & $mask;
            while ($this->table[$position] !== 0) {
                $position = ($position + 1) & $mask;
            }
            $this->table[$position] = $old[$i];
        }
    }

    /** HashCommon.mix for an int. */
    private static function mix(int $x): int
    {
        $h = ($x * 0x9E3779B9) & 0xffffffff;
        return ($h ^ ($h >> 16)) & 0xffffffff;
    }
}
