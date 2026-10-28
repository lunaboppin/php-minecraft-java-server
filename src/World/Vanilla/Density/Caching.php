<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Density;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Sampler;
use PhpMinecraftJavaServer\World\Vanilla\SamplerContext;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

/** CachingDensitySampler: a deduplicated cache, stored in the sampling context. */
final class Caching extends Sampler
{
    public function __construct(private readonly int $id, private readonly Sampler $input)
    {
    }

    public function volume(SamplerContext $context, Volume $volume): array
    {
        return $context->volumeCached($this->id, $this->input, $volume);
    }

    public function value(SamplerContext $context, int $x, int $y, int $z): float
    {
        return $context->valueCached($this->id, $this->input, $x, $y, $z);
    }
}
