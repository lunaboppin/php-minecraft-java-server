<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

/** DensityVolume: a box of sample points, indexed y fastest, then x, then z. */
final class Volume
{
    public readonly int $size;

    public readonly string $key;

    public function __construct(
        public readonly int $sizeX,
        public readonly int $sizeY,
        public readonly int $sizeZ,
        public readonly int $minX,
        public readonly int $minY,
        public readonly int $minZ,
        public readonly int $stepX = 1,
        public readonly int $stepY = 1,
        public readonly int $stepZ = 1,
    ) {
        if ($sizeX <= 0 || $sizeY <= 0 || $sizeZ <= 0 || $stepX <= 0 || $stepY <= 0 || $stepZ <= 0) {
            throw new \InvalidArgumentException('Invalid density volume');
        }
        $this->size = $sizeX * $sizeY * $sizeZ;
        $this->key = "$sizeX,$sizeY,$sizeZ,$minX,$minY,$minZ,$stepX,$stepY,$stepZ";
    }

    public function index(int $x, int $y, int $z): int
    {
        return $y + ($x + $z * $this->sizeX) * $this->sizeY;
    }

    public function blockX(int $x): int
    {
        return $this->minX + $x * $this->stepX;
    }

    public function blockY(int $y): int
    {
        return $this->minY + $y * $this->stepY;
    }

    public function blockZ(int $z): int
    {
        return $this->minZ + $z * $this->stepZ;
    }

    public function maxX(): int
    {
        return $this->minX + $this->sizeX * $this->stepX - 1;
    }

    public function maxY(): int
    {
        return $this->minY + $this->sizeY * $this->stepY - 1;
    }

    public function maxZ(): int
    {
        return $this->minZ + $this->sizeZ * $this->stepZ - 1;
    }

    /** DensityVolume.indexOfBlock, or -1 outside the volume's sample points. */
    public function indexOfBlock(int $x, int $y, int $z): int
    {
        $rx = $x - $this->minX;
        $ry = $y - $this->minY;
        $rz = $z - $this->minZ;
        if ($rx < 0 || $ry < 0 || $rz < 0
            || $rx >= $this->sizeX * $this->stepX || $ry >= $this->sizeY * $this->stepY || $rz >= $this->sizeZ * $this->stepZ
            || $rx % $this->stepX !== 0 || $ry % $this->stepY !== 0 || $rz % $this->stepZ !== 0) {
            return -1;
        }
        return $this->index(intdiv($rx, $this->stepX), intdiv($ry, $this->stepY), intdiv($rz, $this->stepZ));
    }
}
