<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

/**
 * SamplerContext with caches enabled: each cache keeps its last sampled volume and its last
 * single value. A single-value request inside the cached volume returns the volume's value,
 * which can differ from a direct sample, so callers must sample in vanilla's order.
 */
final class SamplerContext
{
    /** @var array<int, array{0: ?Volume, 1: ?array, 2: ?string, 3: float}> */
    private array $cells = [];

    public function __construct(public readonly bool $caching = true)
    {
    }

    /** @return list<float> */
    public function volumeCached(int $id, Sampler $input, Volume $volume): array
    {
        if (!$this->caching) {
            return $input->volume($this, $volume);
        }
        $cell = $this->cells[$id] ?? null;
        if ($cell !== null && $cell[0] !== null && $cell[0]->key === $volume->key) {
            return $cell[1];
        }
        $buffer = $input->volume($this, $volume);
        $this->cells[$id] = [$volume, $buffer, $cell[2] ?? null, $cell[3] ?? NAN];
        return $buffer;
    }

    public function valueCached(int $id, Sampler $input, int $x, int $y, int $z): float
    {
        if (!$this->caching) {
            return $input->value($this, $x, $y, $z);
        }
        $key = "$x,$y,$z";
        $cell = $this->cells[$id] ?? null;
        if ($cell !== null) {
            if ($cell[2] === $key && !is_nan($cell[3])) {
                return $cell[3];
            }
            if ($cell[0] !== null) {
                $index = $cell[0]->indexOfBlock($x, $y, $z);
                if ($index !== -1) {
                    return $cell[1][$index];
                }
            }
        }
        $value = $input->value($this, $x, $y, $z);
        $cell = $this->cells[$id] ?? [null, null, null, NAN];
        $cell[2] = $key;
        $cell[3] = $value;
        $this->cells[$id] = $cell;
        return $value;
    }
}
