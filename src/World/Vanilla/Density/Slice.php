<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Density;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Sampler;
use PhpMinecraftJavaServer\World\Vanilla\SamplerContext;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

/** SliceFunction's X, Y, Z and combined X/Z samplers: the input is sampled on the fixed coordinate's plane or line. */
final class Slice extends Sampler
{
    public function __construct(private readonly Sampler $input, private readonly ?int $x, private readonly ?int $y, private readonly ?int $z)
    {
    }

    public function volume(SamplerContext $context, Volume $v): array
    {
        if (($this->x === null || ($v->sizeX === 1 && $v->minX === $this->x))
            && ($this->y === null || ($v->sizeY === 1 && $v->minY === $this->y))
            && ($this->z === null || ($v->sizeZ === 1 && $v->minZ === $this->z))) {
            return $this->input->volume($context, $v);
        }
        $in = new Volume(
            $this->x === null ? $v->sizeX : 1,
            $this->y === null ? $v->sizeY : 1,
            $this->z === null ? $v->sizeZ : 1,
            $this->x ?? $v->minX,
            $this->y ?? $v->minY,
            $this->z ?? $v->minZ,
            $v->stepX,
            $v->stepY,
            $v->stepZ,
        );
        $buffer = $this->input->volume($context, $in);
        $out = [];
        for ($z = 0; $z < $v->sizeZ; ++$z) {
            for ($x = 0; $x < $v->sizeX; ++$x) {
                for ($y = 0; $y < $v->sizeY; ++$y) {
                    $out[] = $buffer[$in->index($this->x === null ? $x : 0, $this->y === null ? $y : 0, $this->z === null ? $z : 0)];
                }
            }
        }
        return $out;
    }

    public function value(SamplerContext $context, int $x, int $y, int $z): float
    {
        return $this->input->value($context, $this->x ?? $x, $this->y ?? $y, $this->z ?? $z);
    }
}
