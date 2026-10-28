<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Density;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Sampler;
use PhpMinecraftJavaServer\World\Vanilla\SamplerContext;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

/** A single-point function sampled naively over volumes (distance to a point, End outer islands). */
final class PointSampler extends Sampler
{
    public function __construct(private readonly \Closure $function)
    {
    }

    public function volume(SamplerContext $context, Volume $volume): array
    {
        return $this->naiveVolume($context, $volume);
    }

    public function value(SamplerContext $context, int $x, int $y, int $z): float
    {
        return ($this->function)($x, $y, $z);
    }
}
