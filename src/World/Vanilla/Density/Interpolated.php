<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Density;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Sampler;
use PhpMinecraftJavaServer\World\Vanilla\SamplerContext;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

/**
 * InterpolatedFunction: the input is sampled at cell corners. In a volume, Z then X are
 * interpolated and Y advances by repeated float addition; a single sample uses lerp3.
 */
final class Interpolated extends Sampler
{
    private readonly float $inverseXz;
    private readonly float $inverseY;

    public function __construct(private readonly Sampler $input, private readonly int $cellXz, private readonly int $cellY)
    {
        $this->inverseXz = F32::r(1.0 / $cellXz);
        $this->inverseY = F32::r(1.0 / $cellY);
    }

    private static function floorMod(int $a, int $b): int
    {
        return (($a % $b) + $b) % $b;
    }

    private static function floorDiv(int $a, int $b): int
    {
        return intdiv($a - self::floorMod($a, $b), $b);
    }

    public function volume(SamplerContext $context, Volume $volume): array
    {
        if (($volume->stepX === $this->cellXz || $volume->sizeX === 1)
            && ($volume->stepY === $this->cellY || $volume->sizeY === 1)
            && ($volume->stepZ === $this->cellXz || $volume->sizeZ === 1)
            && self::floorMod($volume->minX, $this->cellXz) === 0
            && self::floorMod($volume->minY, $this->cellY) === 0
            && self::floorMod($volume->minZ, $this->cellXz) === 0) {
            return $this->input->volume($context, $volume);
        }
        if ($volume->stepX === 1 && $volume->stepY === 1 && $volume->stepZ === 1) {
            return $this->blockStep($context, $volume);
        }
        $blocks = new Volume($volume->sizeX * $volume->stepX, $volume->sizeY * $volume->stepY, $volume->sizeZ * $volume->stepZ, $volume->minX, $volume->minY, $volume->minZ);
        $buffer = $this->blockStep($context, $blocks);
        $out = [];
        for ($z = 0; $z < $volume->sizeZ; ++$z) {
            for ($x = 0; $x < $volume->sizeX; ++$x) {
                for ($y = 0; $y < $volume->sizeY; ++$y) {
                    $out[$volume->index($x, $y, $z)] = $buffer[$blocks->index($x * $volume->stepX, $y * $volume->stepY, $z * $volume->stepZ)];
                }
            }
        }
        ksort($out);
        return $out;
    }

    private function blockStep(SamplerContext $context, Volume $volume): array
    {
        $xz = $this->cellXz;
        $cy = $this->cellY;
        $minCellX = self::floorDiv($volume->minX, $xz);
        $minCellY = self::floorDiv($volume->minY, $cy);
        $minCellZ = self::floorDiv($volume->minZ, $xz);
        $countX = self::floorDiv($volume->maxX(), $xz) - $minCellX + 1;
        $countY = self::floorDiv($volume->maxY(), $cy) - $minCellY + 1;
        $countZ = self::floorDiv($volume->maxZ(), $xz) - $minCellZ + 1;
        $cells = new Volume(
            self::floorMod($volume->maxX(), $xz) === 0 ? $countX : $countX + 1,
            self::floorMod($volume->maxY(), $cy) === 0 ? $countY : $countY + 1,
            self::floorMod($volume->maxZ(), $xz) === 0 ? $countZ : $countZ + 1,
            $minCellX * $xz,
            $minCellY * $cy,
            $minCellZ * $xz,
            $xz,
            $cy,
            $xz,
        );
        $corner = $this->input->volume($context, $cells);
        $out = array_fill(0, $volume->size, 0.0);
        for ($cellZ = 0; $cellZ < $countZ; ++$cellZ) {
            $nextZ = min($cellZ + 1, $cells->sizeZ - 1);
            for ($cellX = 0; $cellX < $countX; ++$cellX) {
                $nextX = min($cellX + 1, $cells->sizeX - 1);
                $v000 = $corner[$cells->index($cellX, 0, $cellZ)];
                $v100 = $corner[$cells->index($nextX, 0, $cellZ)];
                $v001 = $corner[$cells->index($cellX, 0, $nextZ)];
                $v101 = $corner[$cells->index($nextX, 0, $nextZ)];
                for ($cellY = 0; $cellY < $countY; ++$cellY) {
                    $nextY = min($cellY + 1, $cells->sizeY - 1);
                    $v010 = $corner[$cells->index($cellX, $nextY, $cellZ)];
                    $v110 = $corner[$cells->index($nextX, $nextY, $cellZ)];
                    $v011 = $corner[$cells->index($cellX, $nextY, $nextZ)];
                    $v111 = $corner[$cells->index($nextX, $nextY, $nextZ)];
                    $this->fillCell($out, $volume, $cells, $cellX, $cellY, $cellZ, $v000, $v100, $v010, $v110, $v001, $v101, $v011, $v111);
                    $v000 = $v010;
                    $v100 = $v110;
                    $v001 = $v011;
                    $v101 = $v111;
                }
            }
        }
        return $out;
    }

    private function fillCell(array &$out, Volume $volume, Volume $cells, int $cellX, int $cellY, int $cellZ, float $v000, float $v100, float $v010, float $v110, float $v001, float $v101, float $v011, float $v111): void
    {
        $ox = $cells->blockX($cellX) - $volume->minX;
        $oy = $cells->blockY($cellY) - $volume->minY;
        $oz = $cells->blockZ($cellZ) - $volume->minZ;
        $x0 = max(0, -$ox);
        $y0 = max(0, -$oy);
        $z0 = max(0, -$oz);
        $x1 = min($this->cellXz, $volume->sizeX - $ox) - 1;
        $y1 = min($this->cellY, $volume->sizeY - $oy) - 1;
        $z1 = min($this->cellXz, $volume->sizeZ - $oz) - 1;
        for ($z = $z0; $z <= $z1; ++$z) {
            $az = F32::r($z * $this->inverseXz);
            $v00 = F32::lerp($az, $v000, $v001);
            $v01 = F32::lerp($az, $v010, $v011);
            $v10 = F32::lerp($az, $v100, $v101);
            $v11 = F32::lerp($az, $v110, $v111);
            for ($x = $x0; $x <= $x1; ++$x) {
                $ax = F32::r($x * $this->inverseXz);
                $bottom = F32::lerp($ax, $v00, $v10);
                $top = F32::lerp($ax, $v01, $v11);
                $step = F32::r(F32::r($top - $bottom) * $this->inverseY);
                $value = F32::r($bottom + F32::r($step * $y0));
                $index = $volume->index($ox + $x, $oy + $y0, $oz + $z);
                for ($y = $y0; $y <= $y1; ++$y) {
                    $out[$index++] = $value;
                    $value = F32::r($value + $step);
                }
            }
        }
    }

    public function value(SamplerContext $context, int $x, int $y, int $z): float
    {
        $ix = self::floorMod($x, $this->cellXz);
        $iy = self::floorMod($y, $this->cellY);
        $iz = self::floorMod($z, $this->cellXz);
        if ($ix === 0 && $iy === 0 && $iz === 0) {
            return $this->input->value($context, $x, $y, $z);
        }
        $cells = new Volume(2, 2, 2, $x - $ix, $y - $iy, $z - $iz, $this->cellXz, $this->cellY, $this->cellXz);
        $c = $this->input->volume($context, $cells);
        $ax = F32::r($ix / $this->cellXz);
        $ay = F32::r($iy / $this->cellY);
        $az = F32::r($iz / $this->cellXz);
        return F32::lerp(
            $az,
            F32::lerp($ay, F32::lerp($ax, $c[$cells->index(0, 0, 0)], $c[$cells->index(1, 0, 0)]), F32::lerp($ax, $c[$cells->index(0, 1, 0)], $c[$cells->index(1, 1, 0)])),
            F32::lerp($ay, F32::lerp($ax, $c[$cells->index(0, 0, 1)], $c[$cells->index(1, 0, 1)]), F32::lerp($ax, $c[$cells->index(0, 1, 1)], $c[$cells->index(1, 1, 1)])),
        );
    }
}
