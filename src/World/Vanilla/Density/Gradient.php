<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Density;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Sampler;
use PhpMinecraftJavaServer\World\Vanilla\SamplerContext;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

/** GradientFunction: a float gradient along one axis, clamped or tiled. */
final class Gradient extends Sampler
{
    private readonly float $factor;

    public function __construct(
        private readonly string $axis,
        private readonly string $tiling,
        private readonly int $from,
        private readonly int $to,
        private readonly float $fromValue,
        float $toValue,
    ) {
        $this->factor = F32::r(F32::r($toValue - $fromValue) / ($to - $from));
    }

    public function compute(int $coordinate): float
    {
        $range = $this->to - $this->from;
        switch ($this->tiling) {
            case 'repeat':
                $relative = $coordinate - $this->from;
                return F32::r($this->fromValue + F32::r((($relative % $range) + $range) % $range * $this->factor));
            case 'mirrored_repeat':
                $relative = $coordinate - $this->from;
                $tile = intdiv($relative - (($relative % $range) + $range) % $range, $range);
                $local = $relative - $tile * $range;
                return F32::r($this->fromValue + F32::r((($tile & 1) === 0 ? $local : $range - $local) * $this->factor));
        }
        $clamped = min(max($coordinate, min($this->from, $this->to)), max($this->from, $this->to));
        return F32::r($this->fromValue + F32::r(F32::r((float) ($clamped - $this->from)) * $this->factor));
    }

    public function volume(SamplerContext $context, Volume $volume): array
    {
        $out = [];
        for ($z = 0; $z < $volume->sizeZ; ++$z) {
            for ($x = 0; $x < $volume->sizeX; ++$x) {
                for ($y = 0; $y < $volume->sizeY; ++$y) {
                    $out[] = $this->compute(match ($this->axis) {
                        'x' => $volume->blockX($x),
                        'y' => $volume->blockY($y),
                        'z' => $volume->blockZ($z),
                    });
                }
            }
        }
        return $out;
    }

    public function value(SamplerContext $context, int $x, int $y, int $z): float
    {
        return $this->compute(match ($this->axis) {
            'x' => $x,
            'y' => $y,
            'z' => $z,
        });
    }
}
