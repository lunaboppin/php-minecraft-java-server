<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Noise;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

/** synth.NoiseStack: octaves with a coordinate frequency and a float amplitude. */
final class NoiseStack implements Noise
{
    /** @param list<array{0: Noise, 1: float, 2: float}> $layers */
    public function __construct(public readonly array $layers)
    {
    }

    public function get(float $x, float $y, float $z): float
    {
        $value = 0.0;
        foreach ($this->layers as [$noise, $frequency, $amplitude]) {
            $value = F32::r($value + F32::r($amplitude * $noise->get($x * $frequency, $y * $frequency, $z * $frequency)));
        }
        return $value;
    }

    public function addToVolume(array &$buffer, Volume $volume, float $xzScale, float $yScale, float $amplitude): void
    {
        foreach ($this->layers as [$noise, $frequency, $layerAmplitude]) {
            $noise->addToVolume($buffer, $volume, $xzScale * $frequency, $yScale * $frequency, F32::r($amplitude * $layerAmplitude));
        }
    }
}
