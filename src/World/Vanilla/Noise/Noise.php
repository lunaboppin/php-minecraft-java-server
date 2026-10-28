<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Noise;

use PhpMinecraftJavaServer\World\Vanilla\Volume;

/** synth.Noise: single samples and the volume path, which adds amplitude-scaled samples to a buffer. */
interface Noise
{
    public function get(float $x, float $y, float $z): float;

    /**
     * @param list<float> $buffer
     */
    public function addToVolume(array &$buffer, Volume $volume, float $xzScale, float $yScale, float $amplitude): void;
}
