<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/** OreFeature and ScatteredOreFeature. */
final class Ores
{
    private const PI = 3.1415927410125732;

    /** @var list<float>|null */
    private static ?array $sine = null;

    private static function mthSin(float $value): float
    {
        self::$sine ??= array_map(static fn (int $i): float => F32::r(sin($i / 10430.378350470453)), range(0, 65535));
        return self::$sine[((int) ($value * 10430.378350470453)) & 65535];
    }

    private static function canPlace(Decorator $decorator, array $target, int $state, Region $region, RandomSource $random, float $discard, int $x, int $y, int $z): bool
    {
        if (!$decorator->predicates->rule($target['target'], $state, $y, $random)) {
            return false;
        }
        // shouldSkipAirCheck
        if ($discard <= 0.0 || ($discard < 1.0 && $random->nextFloat() >= $discard)) {
            return true;
        }
        foreach (Blocks::OFFSETS as [$dx, $dy, $dz]) {
            if ($decorator->blocks->isAir($region->get($x + $dx, $y + $dy, $z + $dz))) {
                return false;
            }
        }
        return true;
    }

    public static function ore(Decorator $decorator, array $feature, Region $region, RandomSource $random, int $ox, int $oy, int $oz): bool
    {
        $size = $feature['size'];
        $dir = F32::r($random->nextFloat() * self::PI);
        $spread = F32::r($size / 8.0);
        $maxRadius = (int) ceil(F32::r(F32::r(F32::r(F32::r($size / 16.0) * 2.0) + 1.0) / 2.0));
        $x0 = $ox + sin($dir) * $spread;
        $x1 = $ox - sin($dir) * $spread;
        $z0 = $oz + cos($dir) * $spread;
        $z1 = $oz - cos($dir) * $spread;
        $y0 = (float) ($oy + $random->nextInt(3) - 2);
        $y1 = (float) ($oy + $random->nextInt(3) - 2);
        $ceilSpread = (int) ceil($spread);
        $xStart = $ox - $ceilSpread - $maxRadius;
        $yStart = $oy - 2 - $maxRadius;
        $zStart = $oz - $ceilSpread - $maxRadius;
        $sizeXZ = 2 * ($ceilSpread + $maxRadius);
        $sizeY = 2 * (2 + $maxRadius);
        for ($px = $xStart; $px <= $xStart + $sizeXZ; ++$px) {
            for ($pz = $zStart; $pz <= $zStart + $sizeXZ; ++$pz) {
                if ($yStart <= $region->height(Region::OCEAN_FLOOR_WG, $px, $pz)) {
                    return self::doPlace($decorator, $feature, $region, $random, $x0, $x1, $z0, $z1, $y0, $y1, $xStart, $yStart, $zStart, $sizeXZ, $sizeY);
                }
            }
        }
        return false;
    }

    private static function doPlace(Decorator $decorator, array $feature, Region $region, RandomSource $random, float $x0, float $x1, float $z0, float $z1, float $y0, float $y1, int $xStart, int $yStart, int $zStart, int $sizeXZ, int $sizeY): bool
    {
        $size = $feature['size'];
        $discard = F32::r((float) ($feature['discard_chance_on_air_exposure'] ?? 0.0));
        $data = [];
        for ($i = 0; $i < $size; ++$i) {
            $step = F32::r($i / $size);
            $xx = $x0 + $step * ($x1 - $x0);
            $yy = $y0 + $step * ($y1 - $y0);
            $zz = $z0 + $step * ($z1 - $z0);
            $ss = $random->nextDouble() * $size / 16.0;
            $r = (F32::r(self::mthSin(F32::r(self::PI * $step)) + 1.0) * $ss + 1.0) / 2.0;
            $data[] = [$xx, $yy, $zz, $r];
        }
        for ($i1 = 0; $i1 < $size - 1; ++$i1) {
            if ($data[$i1][3] <= 0.0) {
                continue;
            }
            for ($i2 = $i1 + 1; $i2 < $size; ++$i2) {
                if ($data[$i2][3] <= 0.0) {
                    continue;
                }
                $dx = $data[$i1][0] - $data[$i2][0];
                $dy = $data[$i1][1] - $data[$i2][1];
                $dz = $data[$i1][2] - $data[$i2][2];
                $dr = $data[$i1][3] - $data[$i2][3];
                if ($dr * $dr > $dx * $dx + $dy * $dy + $dz * $dz) {
                    if ($dr > 0.0) {
                        $data[$i2][3] = -1.0;
                    } else {
                        $data[$i1][3] = -1.0;
                    }
                }
            }
        }
        $targets = array_map(fn (array $t): array => $t + ['state_id' => $decorator->blocks->parse($t['state'])], $feature['targets']);
        $tested = [];
        $placed = 0;
        foreach ($data as [$xx, $yy, $zz, $r]) {
            if ($r < 0.0) {
                continue;
            }
            $xMin = max((int) floor($xx - $r), $xStart);
            $yMin = max((int) floor($yy - $r), $yStart);
            $zMin = max((int) floor($zz - $r), $zStart);
            $xMax = max((int) floor($xx + $r), $xMin);
            $yMax = max((int) floor($yy + $r), $yMin);
            $zMax = max((int) floor($zz + $r), $zMin);
            for ($x = $xMin; $x <= $xMax; ++$x) {
                $xd = ($x + 0.5 - $xx) / $r;
                if (!($xd * $xd < 1.0)) {
                    continue;
                }
                for ($y = $yMin; $y <= $yMax; ++$y) {
                    $yd = ($y + 0.5 - $yy) / $r;
                    if (!($xd * $xd + $yd * $yd < 1.0)) {
                        continue;
                    }
                    for ($z = $zMin; $z <= $zMax; ++$z) {
                        $zd = ($z + 0.5 - $zz) / $r;
                        if (!($xd * $xd + $yd * $yd + $zd * $zd < 1.0) || $region->isOutsideBuildHeight($y)) {
                            continue;
                        }
                        $bit = $x - $xStart + ($y - $yStart) * $sizeXZ + ($z - $zStart) * $sizeXZ * $sizeY;
                        if (isset($tested[$bit])) {
                            continue;
                        }
                        $tested[$bit] = true;
                        if (!$region->canWrite($x, $z) || $region->chunk($x >> 4, $z >> 4) === null) {
                            continue;
                        }
                        $state = $region->get($x, $y, $z);
                        foreach ($targets as $target) {
                            if (self::canPlace($decorator, $target, $state, $region, $random, $discard, $x, $y, $z)) {
                                $region->set($x, $y, $z, $target['state_id']);
                                ++$placed;
                                break;
                            }
                        }
                    }
                }
            }
        }
        return $placed > 0;
    }

    public static function scattered(Decorator $decorator, array $feature, Region $region, RandomSource $random, int $ox, int $oy, int $oz): bool
    {
        $discard = F32::r((float) ($feature['discard_chance_on_air_exposure'] ?? 0.0));
        $targets = array_map(fn (array $t): array => $t + ['state_id' => $decorator->blocks->parse($t['state'])], $feature['targets']);
        $tries = $random->nextInt($feature['size'] + 1);
        for ($i = 0; $i < $tries; ++$i) {
            $max = min($i, 7);
            $offset = [];
            for ($axis = 0; $axis < 3; ++$axis) {
                $offset[] = (int) floor(F32::r(F32::r($random->nextFloat() - $random->nextFloat()) * $max) + 0.5);
            }
            [$x, $y, $z] = [$ox + $offset[0], $oy + $offset[1], $oz + $offset[2]];
            $state = $region->get($x, $y, $z);
            foreach ($targets as $target) {
                if (self::canPlace($decorator, $target, $state, $region, $random, $discard, $x, $y, $z)) {
                    $region->set($x, $y, $z, $target['state_id']);
                    break;
                }
            }
        }
        return true;
    }
}
