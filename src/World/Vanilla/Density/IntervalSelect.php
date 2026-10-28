<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Density;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Sampler;
use PhpMinecraftJavaServer\World\Vanilla\SamplerContext;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

/** IntervalSelectFunction: every branch is sampled for a volume, in order. */
final class IntervalSelect extends Sampler
{
    /**
     * @param list<float> $thresholds
     * @param list<Sampler> $samplers
     */
    public function __construct(private readonly Sampler $input, private readonly array $thresholds, private readonly array $samplers)
    {
    }

    private function select(float $v): int
    {
        foreach ($this->thresholds as $i => $threshold) {
            if ($v < $threshold) {
                return $i;
            }
        }
        return count($this->samplers) - 1;
    }

    public function volume(SamplerContext $context, Volume $volume): array
    {
        $input = $this->input->volume($context, $volume);
        $buffers = array_map(static fn (Sampler $s): array => $s->volume($context, $volume), $this->samplers);
        $out = [];
        foreach ($input as $i => $v) {
            $out[] = $buffers[$this->select($v)][$i];
        }
        return $out;
    }

    public function value(SamplerContext $context, int $x, int $y, int $z): float
    {
        return $this->samplers[$this->select($this->input->value($context, $x, $y, $z))]->value($context, $x, $y, $z);
    }
}
