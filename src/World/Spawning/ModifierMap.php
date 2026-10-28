<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

/** Identifier-keyed modifier buckets retain the native fastutil iteration order. */
final class ModifierMap
{
    private array $slots = [];

    private int $capacity = 32;

    private int $size = 0;

    private static function hash(string $identifier): int
    {
        [$namespace, $path] = explode(':', $identifier, 2);
        $hashes = [];
        foreach ([$namespace, $path] as $part) {
            $hash = 0;
            for ($index = 0, $length = strlen($part); $index < $length; ++$index) {
                $hash = (31 * $hash + ord($part[$index])) & 0xffffffff;
            }
            $hashes[] = $hash;
        }
        $hash = (31 * $hashes[0] + $hashes[1]) & 0xffffffff;
        if ($hash >= 0x80000000) {
            $hash -= 0x100000000;
        }
        $mixed = ($hash * -1640531527) & 0xffffffff;
        return $mixed ^ ($mixed >> 16);
    }

    public function put(string $identifier, array $modifier): void
    {
        $mask = $this->capacity - 1;
        $slot = self::hash($identifier) & $mask;
        while (isset($this->slots[$slot])) {
            if ($this->slots[$slot]['id'] === $identifier) {
                $this->slots[$slot] = $modifier;
                return;
            }
            $slot = ($slot + 1) & $mask;
        }
        $this->slots[$slot] = $modifier;
        $previousSize = $this->size++;
        if ($previousSize >= (int) ceil($this->capacity * 0.75)) {
            $this->rehash($this->capacity * 2);
        }
    }

    public function remove(string $identifier): void
    {
        $mask = $this->capacity - 1;
        $slot = self::hash($identifier) & $mask;
        while (isset($this->slots[$slot]) && $this->slots[$slot]['id'] !== $identifier) {
            $slot = ($slot + 1) & $mask;
        }
        if (!isset($this->slots[$slot])) {
            return;
        }
        --$this->size;
        while (true) {
            $last = $slot;
            $slot = ($slot + 1) & $mask;
            while (isset($this->slots[$slot])) {
                $home = self::hash($this->slots[$slot]['id']) & $mask;
                $moves = $last <= $slot ? $last >= $home || $home > $slot : $last >= $home && $home > $slot;
                if ($moves) {
                    break;
                }
                $slot = ($slot + 1) & $mask;
            }
            if (!isset($this->slots[$slot])) {
                unset($this->slots[$last]);
                break;
            }
            $this->slots[$last] = $this->slots[$slot];
        }
        if ($this->capacity > 32 && $this->size < ceil($this->capacity * 0.75) / 4) {
            $this->rehash(intdiv($this->capacity, 2));
        }
    }

    /** @return list<array> */
    public function values(): array
    {
        $values = [];
        for ($slot = $this->capacity - 1; $slot >= 0; --$slot) {
            if (isset($this->slots[$slot])) {
                $values[] = $this->slots[$slot];
            }
        }
        return $values;
    }

    private function rehash(int $capacity): void
    {
        $values = $this->values();
        $this->capacity = $capacity;
        $this->slots = [];
        $this->size = 0;
        foreach ($values as $modifier) {
            $this->put($modifier['id'], $modifier);
        }
    }
}
