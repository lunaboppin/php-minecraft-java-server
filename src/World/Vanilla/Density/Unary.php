<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Density;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Sampler;
use PhpMinecraftJavaServer\World\Vanilla\SamplerContext;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

/** UnaryFunction, ClampFunction and the constant-exponent PowFunction samplers. */
final class Unary extends Sampler
{
    public function __construct(
        private readonly string $type,
        private readonly Sampler $input,
        private readonly float $a = 0.0,
        private readonly float $b = 0.0,
    ) {
    }

    public function apply(float $v): float
    {
        switch ($this->type) {
            case 'abs':
                return abs($v);
            case 'square':
                return F32::r($v * $v);
            case 'cube':
                return F32::r(F32::r($v * $v) * $v);
            case 'sqrt':
                return F32::r(sqrt($v));
            case 'half_negative':
                return $v > 0.0 ? $v : F32::r($v * 0.5);
            case 'quarter_negative':
                return $v > 0.0 ? $v : F32::r($v * 0.25);
            case 'reciprocal':
                return F32::r(fdiv(1.0, $v));
            case 'negate':
                return -$v;
            case 'squeeze':
                $c = $v < -1.0 ? -1.0 : min($v, 1.0);
                return F32::r(F32::r($c / 2.0) - F32::r(F32::r(F32::r($c * $c) * $c) / 24.0));
            case 'log':
                return F32::r(log($v));
            case 'sign':
                return $v > 0.0 ? 1.0 : ($v < 0.0 ? -1.0 : $v);
            case 'clamp':
                return $v < $this->a ? $this->a : min($v, $this->b);
            case 'pow':
                return F32::r($v ** $this->a);
        }
        throw new \LogicException('Unknown unary density ' . $this->type);
    }

    public function volume(SamplerContext $context, Volume $volume): array
    {
        return array_map($this->apply(...), $this->input->volume($context, $volume));
    }

    public function value(SamplerContext $context, int $x, int $y, int $z): float
    {
        return $this->apply($this->input->value($context, $x, $y, $z));
    }
}
