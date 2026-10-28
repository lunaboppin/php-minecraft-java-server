<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Density;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Sampler;
use PhpMinecraftJavaServer\World\Vanilla\SamplerContext;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

/**
 * SplineFunction: nested cubic splines whose coordinates are deduplicated and sampled lazily,
 * as whole volumes the first time any point needs them.
 */
final class Spline extends Sampler
{
    /**
     * @param array $spline a constant float, or array{coordinate: int, points: list<array{0: float, 1: float, 2: mixed}>}
     * @param list<Sampler> $coordinates
     */
    public function __construct(private readonly mixed $spline, private readonly array $coordinates)
    {
    }

    public function volume(SamplerContext $context, Volume $volume): array
    {
        $buffers = [];
        $out = [];
        for ($i = 0; $i < $volume->size; ++$i) {
            $out[] = $this->sample($this->spline, function (int $coordinate) use (&$buffers, $context, $volume, $i): float {
                $buffers[$coordinate] ??= $this->coordinates[$coordinate]->volume($context, $volume);
                return $buffers[$coordinate][$i];
            });
        }
        return $out;
    }

    public function value(SamplerContext $context, int $x, int $y, int $z): float
    {
        $values = [];
        return $this->sample($this->spline, function (int $coordinate) use (&$values, $context, $x, $y, $z): float {
            return $values[$coordinate] ??= $this->coordinates[$coordinate]->value($context, $x, $y, $z);
        });
    }

    /** CubicSpline.Multipoint.sample */
    private function sample(mixed $spline, \Closure $coordinate): float
    {
        if (!is_array($spline)) {
            return $spline;
        }
        $v = $coordinate($spline['coordinate']);
        $points = $spline['points'];
        $i = -1;
        foreach ($points as $index => $point) {
            if ($v < $point[0]) {
                break;
            }
            $i = $index;
        }
        $last = count($points) - 1;
        if ($i < 0 || $i === $last) {
            [$location, $derivative, $value] = $points[$i < 0 ? 0 : $last];
            $a = $this->sample($value, $coordinate);
            return $derivative == 0.0 ? $a : F32::r($a + F32::r($derivative * F32::r($v - $location)));
        }
        [$l0, $d0, $f0] = $points[$i];
        [$l1, $d1, $f1] = $points[$i + 1];
        $span = F32::r($l1 - $l0);
        $t = F32::r(F32::r($v - $l0) / $span);
        $a = $this->sample($f0, $coordinate);
        $b = $this->sample($f1, $coordinate);
        $difference = F32::r($b - $a);
        $p = F32::r(F32::r($d0 * $span) - $difference);
        $q = F32::r(-F32::r($d1 * $span) + $difference);
        return F32::r(F32::lerp($t, $a, $b) + F32::r(F32::r($t * F32::r(1.0 - $t)) * F32::lerp($t, $p, $q)));
    }
}
