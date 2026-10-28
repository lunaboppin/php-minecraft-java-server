<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Density;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Sampler;
use PhpMinecraftJavaServer\World\Vanilla\SamplerContext;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

/** FindTopSurfaceFunction: the highest cell-aligned Y with positive density, probed by single samples. */
final class FindTopSurface extends Sampler
{
    public function __construct(
        private readonly Sampler $density,
        private readonly Sampler $upperBound,
        private readonly int $lowerBound,
        private readonly int $cellHeight,
    ) {
    }

    private function find(SamplerContext $context, int $x, int $z, float $upper): float
    {
        $top = (int) floor(F32::r($upper / $this->cellHeight)) * $this->cellHeight;
        if ($top <= $this->lowerBound) {
            return (float) $this->lowerBound;
        }
        for ($y = $top; $y >= $this->lowerBound; $y -= $this->cellHeight) {
            if ($this->density->value($context, $x, $y, $z) > 0.0) {
                return (float) $y;
            }
        }
        return (float) $this->lowerBound;
    }

    public function volume(SamplerContext $context, Volume $volume): array
    {
        if ($volume->sizeY !== 1) {
            throw new \InvalidArgumentException('Cannot sample the top surface with sizeY=' . $volume->sizeY);
        }
        $out = $this->upperBound->volume($context, $volume);
        $index = 0;
        for ($z = 0; $z < $volume->sizeZ; ++$z) {
            for ($x = 0; $x < $volume->sizeX; ++$x) {
                $out[$index] = $this->find($context, $volume->blockX($x), $volume->blockZ($z), $out[$index]);
                ++$index;
            }
        }
        return $out;
    }

    public function value(SamplerContext $context, int $x, int $y, int $z): float
    {
        return $this->find($context, $x, $z, $this->upperBound->value($context, $x, $y, $z));
    }
}
