<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Noise;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/** synth.SmearedPerlinNoise: legacy terrain noise whose gradient Y is lowered to a grid. */
final class SmearedPerlin extends Perlin
{
    public function __construct(RandomSource $random, private readonly float $fudgeYScale)
    {
        parent::__construct($random);
    }

    public function get(float $x, float $y, float $z): float
    {
        $originalY = $y;
        $x = self::wrap($x) + $this->offsetX;
        $y = self::wrap($y) + $this->offsetY;
        $z = self::wrap($z) + $this->offsetZ;
        $fx = (int) floor($x);
        $fy = (int) floor($y);
        $fz = (int) floor($z);
        $relativeY = $y - $fy;
        $fudged = F32::r($relativeY - $this->fudgeY($originalY, $relativeY));
        return $this->sampleAndLerp($fx, $fy, $fz, F32::r($x - $fx), $fudged, F32::r($z - $fz), F32::r($relativeY));
    }

    private function fudgeY(float $originalY, float $relativeY): float
    {
        $limit = $originalY >= 0.0 && $originalY < $relativeY ? $originalY : $relativeY;
        // The 1.0E-7F epsilon is a float widened to double.
        return floor($limit / $this->fudgeYScale + 1.0000000116860974E-7) * $this->fudgeYScale;
    }

    protected function relativeY(float $originalY, float $y, int $floorY): float
    {
        $relativeY = $y - $floorY;
        return F32::r($relativeY - $this->fudgeY($originalY, $relativeY));
    }

    protected function alphaY(float $y, int $floorY, float $relativeY): float
    {
        return F32::r($y - $floorY);
    }
}
