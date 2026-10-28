<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Density;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Sampler;
use PhpMinecraftJavaServer\World\Vanilla\SamplerContext;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

/** ConstantFunction.Sampler, and the context-bound blend and beardifier markers of an unblended chunk without structures. */
final class Constant extends Sampler
{
    public function __construct(public readonly float $constant)
    {
    }

    public function volume(SamplerContext $context, Volume $volume): array
    {
        return array_fill(0, $volume->size, $this->constant);
    }

    public function value(SamplerContext $context, int $x, int $y, int $z): float
    {
        return $this->constant;
    }
}
