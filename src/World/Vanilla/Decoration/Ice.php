<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/** Ice features: icebergs, the blue ice inside them, and ice spikes. */
final class Ice
{
    private readonly Blocks $blocks;

    private readonly Predicates $predicates;

    public function __construct(Decorator $decorator)
    {
        $this->blocks = $decorator->blocks;
        $this->predicates = $decorator->predicates;
    }

    private function named(int $state, string ...$names): bool
    {
        return in_array($this->blocks->name($state), $names, true);
    }

    private function icebergState(int $state): bool
    {
        return $this->named($state, 'packed_ice', 'snow_block', 'blue_ice');
    }

    /** Mth.ceil over a float. */
    private static function ceil(float $value): int
    {
        return (int) ceil($value);
    }

    /** IcebergFeature */
    public function iceberg(array $feature, Region $region, RandomSource $random, int $x, int $z): bool
    {
        $y = $region->seaLevel;
        $snowOnTop = $random->nextDouble() > 0.7;
        $main = $this->blocks->parse($feature['state']);
        $angle = $random->nextDouble() * 2.0 * M_PI;
        $ellipseA = 11 - $random->nextInt(5);
        $ellipseC = 3 + $random->nextInt(3);
        $isEllipse = $random->nextDouble() > 0.7;
        $overWater = $isEllipse ? $random->nextInt(6) + 6 : $random->nextInt(15) + 3;
        if (!$isEllipse && $random->nextDouble() > 0.9) {
            $overWater += $random->nextInt(19) + 7;
        }
        $underWater = min($overWater + $random->nextInt(11), 18);
        $width = min($overWater + $random->nextInt(7) - $random->nextInt(5), 11);
        $a = $isEllipse ? $ellipseA : 11;
        $shape = [$region, $random, $x, $y, $z, $isEllipse, $ellipseC, $angle, $snowOnTop, $main];
        for ($xo = -$a; $xo < $a; ++$xo) {
            for ($zo = -$a; $zo < $a; ++$zo) {
                for ($yOff = 0; $yOff < $overWater; ++$yOff) {
                    $radius = $isEllipse ? $this->radiusEllipse($yOff, $overWater, $width) : $this->radiusRound($random, $yOff, $overWater, $width);
                    // Vanilla compares only the x offset with the radius here.
                    if ($isEllipse || $xo < $radius) {
                        $this->icebergBlock($shape, $overWater, $xo, $yOff, $zo, $radius, $a);
                    }
                }
            }
        }
        $this->smooth($region, $x, $y, $z, $width, $overWater, $isEllipse, $ellipseA);
        for ($xo = -$a; $xo < $a; ++$xo) {
            for ($zo = -$a; $zo < $a; ++$zo) {
                for ($yOff = -1; $yOff > -$underWater; --$yOff) {
                    $newA = $isEllipse ? self::ceil(F32::r($a * F32::r(1.0 - F32::r(F32::r((float) ($yOff * $yOff)) / F32::r($underWater * 8.0))))) : $a;
                    $radius = $this->radiusSteep($random, -$yOff, $underWater, $width);
                    if ($xo < $radius) {
                        $this->icebergBlock($shape, $underWater, $xo, $yOff, $zo, $radius, $newA);
                    }
                }
            }
        }
        if ($isEllipse ? $random->nextDouble() > 0.1 : $random->nextDouble() > 0.7) {
            $this->cutOut($region, $random, $width, $overWater, $x, $y, $z, $isEllipse, $ellipseA, $angle, $ellipseC);
        }
        return true;
    }

    private function cutOut(Region $region, RandomSource $random, int $width, int $height, int $x, int $y, int $z, bool $isEllipse, int $ellipseA, float $shapeAngle, int $ellipseC): void
    {
        $signX = $random->nextBoolean() ? -1 : 1;
        $signZ = $random->nextBoolean() ? -1 : 1;
        $half = intdiv($width, 2);
        $xOff = $random->nextInt(max($half - 2, 1));
        if ($random->nextBoolean()) {
            $xOff = $half + 1 - $random->nextInt(max($width - $half - 1, 1));
        }
        $zOff = $random->nextInt(max($half - 2, 1));
        if ($random->nextBoolean()) {
            $zOff = $half + 1 - $random->nextInt(max($width - $half - 1, 1));
        }
        if ($isEllipse) {
            $xOff = $zOff = $random->nextInt(max($ellipseA - 5, 1));
        }
        $localX = $signX * $xOff;
        $localZ = $signZ * $zOff;
        $angle = $isEllipse ? $shapeAngle + M_PI / 2 : $random->nextDouble() * 2.0 * M_PI;
        for ($yOff = 0; $yOff < $height - 3; ++$yOff) {
            $radius = $this->radiusRound($random, $yOff, $height, $width);
            $this->carve($radius, $yOff, $x, $y, $z, $region, false, $angle, $localX, $localZ, $ellipseA, $ellipseC);
        }
        for ($yOff = -1; $yOff > -$height + $random->nextInt(5); --$yOff) {
            $radius = $this->radiusSteep($random, -$yOff, $height, $width);
            $this->carve($radius, $yOff, $x, $y, $z, $region, true, $angle, $localX, $localZ, $ellipseA, $ellipseC);
        }
    }

    private function carve(int $radius, int $yOff, int $x, int $y, int $z, Region $region, bool $underWater, float $angle, int $localX, int $localZ, int $ellipseA, int $ellipseC): void
    {
        $a = $radius + 1 + intdiv($ellipseA, 3);
        $c = min($radius - 3, 3) + intdiv($ellipseC, 2) - 1;
        for ($xo = -$a; $xo < $a; ++$xo) {
            for ($zo = -$a; $zo < $a; ++$zo) {
                if (!(self::ellipse($xo - $localX, $zo - $localZ, $a, $c, $angle) < 0.0)) {
                    continue;
                }
                [$px, $py, $pz] = [$x + $xo, $y + $yOff, $z + $zo];
                if (!$this->icebergState($region->get($px, $py, $pz))) {
                    continue;
                }
                if ($underWater) {
                    $region->set($px, $py, $pz, $this->blocks->state('water'));
                } else {
                    $region->set($px, $py, $pz, $this->blocks->state('air'));
                    if ($this->named($region->get($px, $py + 1, $pz), 'snow')) {
                        $region->set($px, $py + 1, $pz, $this->blocks->state('air'));
                    }
                }
            }
        }
    }

    private function icebergBlock(array $shape, int $height, int $xo, int $yOff, int $zo, int $radius, int $a): void
    {
        [$region, $random, $x, $y, $z, $isEllipse, $ellipseC, $angle, $snowOnTop, $main] = $shape;
        if ($isEllipse) {
            $c = $ellipseC;
            if ($yOff > 0 && $height - $yOff <= 3) {
                $c = $ellipseC - (4 - ($height - $yOff));
            }
            $distance = self::ellipse($xo, $zo, $a, $c, $angle);
        } else {
            $clamped = max(F32::r(0.2), min(F32::r(0.8), $random->nextFloat()));
            $distance = F32::r(fdiv(F32::r(10.0 * $clamped), $radius)) + (float) ($xo * $xo) + (float) ($zo * $zo) - (float) ($radius * $radius);
        }
        if (!($distance < 0.0)) {
            return;
        }
        $compare = $isEllipse ? -0.5 : (float) (-6 - $random->nextInt(3));
        if ($distance > $compare && $random->nextDouble() > 0.9) {
            return;
        }
        [$px, $py, $pz] = [$x + $xo, $y + $yOff, $z + $zo];
        $state = $region->get($px, $py, $pz);
        if (!$this->blocks->isAir($state) && !$this->named($state, 'snow_block', 'ice', 'water')) {
            return;
        }
        $randomness = !$isEllipse || $random->nextDouble() > 0.05;
        $divisor = $isEllipse ? 3 : 2;
        if ($snowOnTop && !$this->named($state, 'water') && $height - $yOff <= $random->nextInt(max(1, intdiv($height, $divisor))) + $height * 0.6 && $randomness) {
            $region->set($px, $py, $pz, $this->blocks->state('snow_block'));
        } else {
            $region->set($px, $py, $pz, $main);
        }
    }

    private static function ellipse(int $dx, int $dz, int $a, int $c, float $angle): float
    {
        $cos = cos($angle);
        $sin = sin($angle);
        return fdiv($dx * $cos - $dz * $sin, $a) ** 2 + fdiv($dx * $sin + $dz * $cos, $c) ** 2 - 1.0;
    }

    private function radiusRound(RandomSource $random, int $yOff, int $height, int $width): int
    {
        $k = F32::r(3.5 - $random->nextFloat());
        $scale = F32::r(F32::r(1.0 - F32::r(F32::r((float) ($yOff * $yOff)) / F32::r($height * $k))) * $width);
        if ($height > 15 + $random->nextInt(5)) {
            $tempYOff = $yOff < 3 + $random->nextInt(6) ? intdiv($yOff, 2) : $yOff;
            $scale = F32::r(F32::r(1.0 - F32::r(fdiv($tempYOff, F32::r(F32::r($height * $k) * F32::r(0.4))))) * $width);
        }
        return self::ceil(F32::r($scale / 2.0));
    }

    private function radiusEllipse(int $yOff, int $height, int $width): int
    {
        $scale = F32::r(F32::r(1.0 - F32::r(F32::r((float) ($yOff * $yOff)) / (float) $height)) * $width);
        return self::ceil(F32::r($scale / 2.0));
    }

    private function radiusSteep(RandomSource $random, int $yOff, int $height, int $width): int
    {
        $k = F32::r(1.0 + F32::r($random->nextFloat() / 2.0));
        $scale = F32::r(F32::r(1.0 - F32::r($yOff / F32::r($height * $k))) * $width);
        return self::ceil(F32::r($scale / 2.0));
    }

    private function smooth(Region $region, int $x, int $y, int $z, int $width, int $height, bool $isEllipse, int $ellipseA): void
    {
        $a = $isEllipse ? $ellipseA : intdiv($width, 2);
        $air = $this->blocks->state('air');
        for ($dx = -$a; $dx <= $a; ++$dx) {
            for ($dz = -$a; $dz <= $a; ++$dz) {
                for ($yOff = 0; $yOff <= $height; ++$yOff) {
                    [$px, $py, $pz] = [$x + $dx, $y + $yOff, $z + $dz];
                    $state = $region->get($px, $py, $pz);
                    $iceberg = $this->icebergState($state);
                    if (!$iceberg && !$this->named($state, 'snow')) {
                        continue;
                    }
                    if ($this->blocks->isAir($region->get($px, $py - 1, $pz))) {
                        $region->set($px, $py, $pz, $air);
                        $region->set($px, $py + 1, $pz, $air);
                    } elseif ($iceberg) {
                        $open = 0;
                        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$sx, $sz]) {
                            if (!$this->icebergState($region->get($px + $sx, $py, $pz + $sz))) {
                                ++$open;
                            }
                        }
                        if ($open >= 3) {
                            $region->set($px, $py, $pz, $air);
                        }
                    }
                }
            }
        }
    }

    /** BlueIceFeature */
    public function blueIce(Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        if ($y > $region->seaLevel - 1) {
            return false;
        }
        if (!$this->named($region->get($x, $y, $z), 'water') && !$this->named($region->get($x, $y - 1, $z), 'water')) {
            return false;
        }
        $found = false;
        foreach (Blocks::OFFSETS as $direction => [$dx, $dy, $dz]) {
            if ($direction !== Blocks::DOWN && $this->named($region->get($x + $dx, $y + $dy, $z + $dz), 'packed_ice')) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return false;
        }
        $blue = $this->blocks->state('blue_ice');
        $region->set($x, $y, $z, $blue);
        for ($i = 0; $i < 200; ++$i) {
            $yOff = $random->nextInt(5) - $random->nextInt(6);
            $spread = 3;
            if ($yOff < 2) {
                $spread += intdiv($yOff, 2);
            }
            if ($spread < 1) {
                continue;
            }
            $px = $x + $random->nextInt($spread) - $random->nextInt($spread);
            $py = $y + $yOff;
            $pz = $z + $random->nextInt($spread) - $random->nextInt($spread);
            $state = $region->get($px, $py, $pz);
            if (!$this->blocks->isAir($state) && !$this->named($state, 'water', 'packed_ice', 'ice')) {
                continue;
            }
            foreach (Blocks::OFFSETS as [$dx, $dy, $dz]) {
                if ($this->named($region->get($px + $dx, $py + $dy, $pz + $dz), 'blue_ice')) {
                    $region->set($px, $py, $pz, $blue);
                    break;
                }
            }
        }
        return true;
    }

    /** SpikeFeature: an ice spike with a pillar down to Y 50. */
    public function spike(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        while ($this->blocks->isAir($region->get($x, $y, $z)) && $y > $region->minY + 2) {
            --$y;
        }
        if (!$this->predicates->test($feature['can_place_on'], $region, $x, $y, $z)) {
            return false;
        }
        $state = $this->blocks->parse($feature['state']);
        $replace = fn (int $px, int $py, int $pz): bool => $this->predicates->test($feature['can_replace'], $region, $px, $py, $pz);
        $y += $random->nextInt(4);
        $height = $random->nextInt(4) + 7;
        $width = intdiv($height, 4) + $random->nextInt(2);
        if ($width > 1 && $random->nextInt(60) === 0) {
            $y += 10 + $random->nextInt(30);
        }
        for ($yOff = 0; $yOff < $height; ++$yOff) {
            $scale = F32::r(F32::r(1.0 - F32::r($yOff / (float) $height)) * $width);
            $newWidth = self::ceil($scale);
            for ($xo = -$newWidth; $xo <= $newWidth; ++$xo) {
                $dx = F32::r(abs($xo) - 0.25);
                for ($zo = -$newWidth; $zo <= $newWidth; ++$zo) {
                    $dz = F32::r(abs($zo) - 0.25);
                    $inside = ($xo === 0 && $zo === 0) || !(F32::r(F32::r($dx * $dx) + F32::r($dz * $dz)) > F32::r($scale * $scale));
                    if (!$inside) {
                        continue;
                    }
                    $edge = $xo === -$newWidth || $xo === $newWidth || $zo === -$newWidth || $zo === $newWidth;
                    if ($edge && $random->nextFloat() > 0.75) {
                        continue;
                    }
                    foreach ($yOff !== 0 && $newWidth > 1 ? [$yOff, -$yOff] : [$yOff] as $dy) {
                        [$px, $py, $pz] = [$x + $xo, $y + $dy, $z + $zo];
                        if ($this->blocks->isAir($region->get($px, $py, $pz)) || $replace($px, $py, $pz)) {
                            $region->set($px, $py, $pz, $state);
                        }
                    }
                }
            }
        }
        $pillar = max(0, min(1, $width - 1));
        for ($xo = -$pillar; $xo <= $pillar; ++$xo) {
            for ($zo = -$pillar; $zo <= $pillar; ++$zo) {
                [$px, $py, $pz] = [$x + $xo, $y - 1, $z + $zo];
                $run = 50;
                if (abs($xo) === 1 && abs($zo) === 1) {
                    $run = $random->nextInt(5);
                }
                while ($py > 50) {
                    $here = $region->get($px, $py, $pz);
                    if (!$this->blocks->isAir($here) && !$replace($px, $py, $pz) && $here !== $state) {
                        break;
                    }
                    $region->set($px, $py, $pz, $state);
                    --$py;
                    if (--$run <= 0) {
                        $py -= $random->nextInt(5) + 1;
                        $run = $random->nextInt(5);
                    }
                }
            }
        }
        return true;
    }
}
