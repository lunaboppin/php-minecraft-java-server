<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Density;

use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Sampler;
use PhpMinecraftJavaServer\World\Vanilla\SamplerContext;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

use PhpMinecraftJavaServer\World\Vanilla\Noise\Noise;

/** NoiseFunction.Sampler, and the shifted samplers whose shifts are added to the scaled coordinates. */
final class NoiseSampler extends Sampler
{
    public function __construct(
        private readonly Noise $noise,
        private readonly float $xzScale,
        private readonly float $yScale,
        private readonly ?Sampler $shiftX = null,
        private readonly ?Sampler $shiftY = null,
        private readonly ?Sampler $shiftZ = null,
    ) {
    }

    public function volume(SamplerContext $context, Volume $volume): array
    {
        if ($this->shiftX === null) {
            $buffer = array_fill(0, $volume->size, 0.0);
            $this->noise->addToVolume($buffer, $volume, $this->xzScale, $this->yScale, 1.0);
            return $buffer;
        }
        $shiftX = $this->shiftX->volume($context, $volume);
        $shiftY = $this->shiftY?->volume($context, $volume);
        $shiftZ = $this->shiftZ->volume($context, $volume);
        $out = [];
        $index = 0;
        for ($z = 0; $z < $volume->sizeZ; ++$z) {
            $baseZ = $volume->blockZ($z) * $this->xzScale;
            for ($x = 0; $x < $volume->sizeX; ++$x) {
                $baseX = $volume->blockX($x) * $this->xzScale;
                for ($y = 0; $y < $volume->sizeY; ++$y) {
                    $noiseY = $volume->blockY($y) * $this->yScale + ($shiftY === null ? 0.0 : $shiftY[$index]);
                    $out[] = $this->noise->get($baseX + $shiftX[$index], $noiseY, $baseZ + $shiftZ[$index]);
                    ++$index;
                }
            }
        }
        return $out;
    }

    public function value(SamplerContext $context, int $x, int $y, int $z): float
    {
        if ($this->shiftX === null) {
            return $this->noise->get($x * $this->xzScale, $y * $this->yScale, $z * $this->xzScale);
        }
        $noiseX = $x * $this->xzScale + $this->shiftX->value($context, $x, $y, $z);
        $noiseY = $y * $this->yScale + ($this->shiftY === null ? 0.0 : $this->shiftY->value($context, $x, $y, $z));
        $noiseZ = $z * $this->xzScale + $this->shiftZ->value($context, $x, $y, $z);
        return $this->noise->get($noiseX, $noiseY, $noiseZ);
    }
}
