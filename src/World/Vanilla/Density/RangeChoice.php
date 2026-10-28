<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Density;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Sampler;
use PhpMinecraftJavaServer\World\Vanilla\SamplerContext;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

/** RangeChoiceFunction: the in-range branch is sampled first, then the input, then the other branch. */
final class RangeChoice extends Sampler
{
    public function __construct(
        private readonly Sampler $input,
        private readonly float $min,
        private readonly float $max,
        private readonly Sampler $inRange,
        private readonly Sampler $outOfRange,
    ) {
    }

    public function volume(SamplerContext $context, Volume $volume): array
    {
        if ($this->inRange instanceof Constant && $this->outOfRange instanceof Constant) {
            return array_map(fn (float $v): float => $v >= $this->min && $v < $this->max ? $this->inRange->constant : $this->outOfRange->constant, $this->input->volume($context, $volume));
        }
        $out = $this->inRange->volume($context, $volume);
        $input = $this->input->volume($context, $volume);
        $other = $this->outOfRange->volume($context, $volume);
        foreach ($input as $i => $v) {
            if (!($v >= $this->min) || !($v < $this->max)) {
                $out[$i] = $other[$i];
            }
        }
        return $out;
    }

    public function value(SamplerContext $context, int $x, int $y, int $z): float
    {
        $v = $this->input->value($context, $x, $y, $z);
        return ($v >= $this->min && $v < $this->max ? $this->inRange : $this->outOfRange)->value($context, $x, $y, $z);
    }
}
