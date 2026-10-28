<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Density;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Sampler;
use PhpMinecraftJavaServer\World\Vanilla\SamplerContext;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

/** LerpFunction's samplers: alpha 0 and 1 take an endpoint exactly. */
final class Lerp extends Sampler
{
    public function __construct(private readonly Sampler $alpha, private readonly Sampler $first, private readonly Sampler $second)
    {
    }

    public function volume(SamplerContext $context, Volume $volume): array
    {
        $alpha = $this->alpha->volume($context, $volume);
        $first = $this->first instanceof Constant ? null : $this->first->volume($context, $volume);
        $second = $this->second instanceof Constant ? null : $this->second->volume($context, $volume);
        $out = [];
        foreach ($alpha as $i => $a) {
            $f = $first === null ? $this->first->constant : $first[$i];
            $s = $second === null ? $this->second->constant : $second[$i];
            $out[] = $a == 0.0 ? $f : ($a == 1.0 ? $s : F32::lerp($a, $f, $s));
        }
        return $out;
    }

    public function value(SamplerContext $context, int $x, int $y, int $z): float
    {
        $a = $this->alpha->value($context, $x, $y, $z);
        if ($a == 0.0) {
            return $this->first->value($context, $x, $y, $z);
        }
        if ($a == 1.0) {
            return $this->second->value($context, $x, $y, $z);
        }
        $f = $this->first->value($context, $x, $y, $z);
        return F32::lerp($a, $f, $this->second->value($context, $x, $y, $z));
    }
}
