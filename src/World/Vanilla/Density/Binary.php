<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Density;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Sampler;
use PhpMinecraftJavaServer\World\Vanilla\SamplerContext;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

/**
 * BinaryFunction's samplers. A constant operand selects vanilla's constant sampler: a
 * constant divisor becomes multiplication by its float reciprocal, and subtracting a constant
 * adds its negation.
 */
final class Binary extends Sampler
{
    public function __construct(
        private readonly string $type,
        private readonly ?Sampler $left,
        private readonly ?Sampler $right,
        private readonly float $constant = 0.0,
    ) {
    }

    public static function compile(string $type, Sampler $left, Sampler $right): self
    {
        $l = $left instanceof Constant ? $left->constant : null;
        $r = $right instanceof Constant ? $right->constant : null;
        return match ($type) {
            'add' => $l !== null ? new self('const_add', $right, null, $l) : ($r !== null ? new self('const_add', $left, null, $r) : new self('add', $left, $right)),
            'sub' => $l !== null ? new self('const_sub', null, $right, $l) : ($r !== null ? new self('const_add', $left, null, -$r) : new self('sub', $left, $right)),
            'mul' => $l !== null ? new self('const_mul', $right, null, $l) : ($r !== null ? new self('const_mul', $left, null, $r) : new self('mul', $left, $right)),
            'div' => $l !== null ? new self('const_div', null, $right, $l) : ($r !== null ? new self('const_mul', $left, null, F32::r(fdiv(1.0, $r))) : new self('div', $left, $right)),
            'min' => $l !== null ? new self('const_min', $right, null, $l) : ($r !== null ? new self('const_min', $left, null, $r) : new self('min', $left, $right)),
            'max' => $l !== null ? new self('const_max', $right, null, $l) : ($r !== null ? new self('const_max', $left, null, $r) : new self('max', $left, $right)),
        };
    }

    public function volume(SamplerContext $context, Volume $volume): array
    {
        $c = $this->constant;
        switch ($this->type) {
            case 'const_add':
                return F32::all(array_map(static fn (float $v): float => $v + $c, $this->left->volume($context, $volume)));
            case 'const_mul':
                return F32::all(array_map(static fn (float $v): float => $v * $c, $this->left->volume($context, $volume)));
            case 'const_sub':
                return F32::all(array_map(static fn (float $v): float => $c - $v, $this->right->volume($context, $volume)));
            case 'const_div':
                return F32::all(array_map(static fn (float $v): float => fdiv($c, $v), $this->right->volume($context, $volume)));
            case 'const_min':
                return array_map(static fn (float $v): float => $c < $v ? $c : $v, $this->left->volume($context, $volume));
            case 'const_max':
                return array_map(static fn (float $v): float => $c > $v ? $c : $v, $this->left->volume($context, $volume));
        }
        $left = $this->left->volume($context, $volume);
        $right = $this->right->volume($context, $volume);
        return match ($this->type) {
            'add' => F32::all(array_map(static fn (float $a, float $b): float => $a + $b, $left, $right)),
            'sub' => F32::all(array_map(static fn (float $a, float $b): float => $a - $b, $left, $right)),
            'mul' => F32::all(array_map(static fn (float $a, float $b): float => $a * $b, $left, $right)),
            'div' => F32::all(array_map(static fn (float $a, float $b): float => fdiv($a, $b), $left, $right)),
            'min' => array_map(static fn (float $a, float $b): float => $b < $a ? $b : $a, $left, $right),
            'max' => array_map(static fn (float $a, float $b): float => $b > $a ? $b : $a, $left, $right),
        };
    }

    public function value(SamplerContext $context, int $x, int $y, int $z): float
    {
        $c = $this->constant;
        switch ($this->type) {
            case 'const_add':
                return F32::r($this->left->value($context, $x, $y, $z) + $c);
            case 'const_mul':
                return F32::r($this->left->value($context, $x, $y, $z) * $c);
            case 'const_sub':
                return F32::r($c - $this->right->value($context, $x, $y, $z));
            case 'const_div':
                return F32::r(fdiv($c, $this->right->value($context, $x, $y, $z)));
            case 'const_min':
                return min($this->left->value($context, $x, $y, $z), $c);
            case 'const_max':
                return max($this->left->value($context, $x, $y, $z), $c);
        }
        $left = $this->left->value($context, $x, $y, $z);
        // Vanilla skips the right operand of a zero product or quotient.
        if (($this->type === 'mul' || $this->type === 'div') && $left == 0.0) {
            return 0.0;
        }
        $right = $this->right->value($context, $x, $y, $z);
        return match ($this->type) {
            'add' => F32::r($left + $right),
            'sub' => F32::r($left - $right),
            'mul' => F32::r($left * $right),
            'div' => F32::r(fdiv($left, $right)),
            'min' => min($left, $right),
            'max' => max($left, $right),
        };
    }
}
