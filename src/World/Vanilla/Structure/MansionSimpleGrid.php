<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

/** WoodlandMansionPieces.SimpleGrid: a grid of cell values, with a fixed value outside it. */
final class MansionSimpleGrid
{
    /** @var array<int, array<int, int>> by x, then y */
    private array $cells;

    public function __construct(public readonly int $width, public readonly int $height, private readonly int $outside)
    {
        $this->cells = array_fill(0, $width, array_fill(0, $height, 0));
    }

    public function set(int $x, int $y, int $value): void
    {
        if ($x >= 0 && $x < $this->width && $y >= 0 && $y < $this->height) {
            $this->cells[$x][$y] = $value;
        }
    }

    public function fill(int $x0, int $y0, int $x1, int $y1, int $value): void
    {
        for ($y = $y0; $y <= $y1; ++$y) {
            for ($x = $x0; $x <= $x1; ++$x) {
                $this->set($x, $y, $value);
            }
        }
    }

    public function get(int $x, int $y): int
    {
        return $x >= 0 && $x < $this->width && $y >= 0 && $y < $this->height ? $this->cells[$x][$y] : $this->outside;
    }

    public function setIf(int $x, int $y, int $ifValue, int $value): void
    {
        if ($this->get($x, $y) === $ifValue) {
            $this->set($x, $y, $value);
        }
    }

    public function edgesTo(int $x, int $y, int $value): bool
    {
        return $this->get($x - 1, $y) === $value || $this->get($x + 1, $y) === $value || $this->get($x, $y + 1) === $value || $this->get($x, $y - 1) === $value;
    }
}
