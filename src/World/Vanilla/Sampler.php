<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

/** DensitySampler: Java float results, for a whole volume or a single block. */
abstract class Sampler
{
    /** @return list<float> */
    abstract public function volume(SamplerContext $context, Volume $volume): array;

    abstract public function value(SamplerContext $context, int $x, int $y, int $z): float;

    /** DensitySampler.sampleVolumeNaive */
    protected function naiveVolume(SamplerContext $context, Volume $volume): array
    {
        $out = [];
        for ($z = 0; $z < $volume->sizeZ; ++$z) {
            $bz = $volume->blockZ($z);
            for ($x = 0; $x < $volume->sizeX; ++$x) {
                $bx = $volume->blockX($x);
                for ($y = 0; $y < $volume->sizeY; ++$y) {
                    $out[] = $this->value($context, $bx, $volume->blockY($y), $bz);
                }
            }
        }
        return $out;
    }
}
