<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Density;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Sampler;
use PhpMinecraftJavaServer\World\Vanilla\SamplerContext;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

use PhpMinecraftJavaServer\World\Vanilla\Noise\Noise;

/** ShiftNoiseFunction.ShiftB: offset noise sampled at (z/4, x/4, 0), times four. */
final class ShiftB extends Sampler
{
    public function __construct(private readonly Noise $noise)
    {
    }

    public function volume(SamplerContext $context, Volume $volume): array
    {
        $transposed = new Volume($volume->sizeZ, $volume->sizeX, 1, $volume->minZ, $volume->minX, 0, $volume->stepZ, $volume->stepX, 1);
        $buffer = array_fill(0, $transposed->size, 0.0);
        $this->noise->addToVolume($buffer, $transposed, 0.25, 0.25, 4.0);
        $out = [];
        for ($z = 0; $z < $volume->sizeZ; ++$z) {
            for ($x = 0; $x < $volume->sizeX; ++$x) {
                $value = $buffer[$transposed->index($z, $x, 0)];
                $start = $volume->index($x, 0, $z);
                for ($y = 0; $y < $volume->sizeY; ++$y) {
                    $out[$start + $y] = $value;
                }
            }
        }
        ksort($out);
        return $out;
    }

    public function value(SamplerContext $context, int $x, int $y, int $z): float
    {
        return F32::r($this->noise->get($z * 0.25, $x * 0.25, 0.0) * 4.0);
    }
}
