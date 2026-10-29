<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

use PhpMinecraftJavaServer\World\Generation\Int64;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;

/**
 * NoiseBasedChunkGenerator.generateCarvers with the cave and canyon carvers: tunnels started
 * in chunks up to eight away mark a carving mask, which is then filled through the aquifer.
 */
final class Carvers
{
    private const PI = 3.1415927410125732;
    private const TWO_PI = 6.2831854820251465;
    private const HALF_PI = 1.5707963705062866;

    /** @var list<float> */
    private readonly array $sine;

    /** @var array<string, array> */
    private array $carvers = [];

    /** @var array<string, list<string>> */
    private array $biomeCarvers = [];

    /** @var array<int, true> */
    private array $mask = [];

    private int $minMaskY;

    private int $maskHeight;

    public function __construct(private readonly OverworldSettings $settings, string $sineTable)
    {
        $this->sine = array_values(unpack('G*', $sineTable));
        $this->minMaskY = $settings->minY + 1;
        // Seven blocks are protected at the top of a newly generated chunk.
        $this->maskHeight = $settings->minY + $settings->height - 1 - 7 - $this->minMaskY + 1;
    }

    private function sin(float $value): float
    {
        return $this->sine[((int) ($value * 10430.378350470453)) & 65535];
    }

    private function cos(float $value): float
    {
        return $this->sine[((int) ($value * 10430.378350470453 + 16384.0)) & 65535];
    }

    private function carver(string $name): array
    {
        $name = preg_replace('/^minecraft:/', '', $name);
        return $this->carvers[$name] ??= json_decode((string) file_get_contents($this->settings->directory . '/carver/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> a biome's carvers */
    private function carversOf(string $biome): array
    {
        if (!isset($this->biomeCarvers[$biome])) {
            $json = json_decode((string) file_get_contents($this->settings->directory . '/biome/' . $biome . '.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->biomeCarvers[$biome] = (array) ($json['carvers'] ?? []);
        }
        return $this->biomeCarvers[$biome];
    }

    private function anchor(array $anchor): int
    {
        $minY = $this->settings->minY;
        return match (true) {
            isset($anchor['absolute']) => $anchor['absolute'],
            isset($anchor['above_bottom']) => $minY + $anchor['above_bottom'],
            isset($anchor['below_top']) => $minY + $this->settings->height - 1 - $anchor['below_top'],
        };
    }

    private function floatProvider(mixed $json, RandomSource $random): float
    {
        if (is_int($json) || is_float($json)) {
            return F32::r((float) $json);
        }
        switch (preg_replace('/^minecraft:/', '', $json['type'])) {
            case 'constant':
                return F32::r((float) $json['value']);
            case 'uniform':
                $min = F32::r((float) $json['min_inclusive']);
                $max = F32::r((float) $json['max_exclusive']);
                return F32::r(F32::r($random->nextFloat() * F32::r($max - $min)) + $min);
            case 'trapezoid':
                $min = F32::r((float) $json['min']);
                $range = F32::r(F32::r((float) $json['max']) - $min);
                $plateauStart = F32::r(F32::r($range - F32::r((float) $json['plateau'])) / 2.0);
                $plateauEnd = F32::r($range - $plateauStart);
                $a = F32::r($min + F32::r($random->nextFloat() * $plateauEnd));
                return F32::r($a + F32::r($random->nextFloat() * $plateauStart));
        }
        throw new \LogicException('Unsupported float provider ' . $json['type']);
    }

    private function intProvider(mixed $json, RandomSource $random): int
    {
        if (is_int($json)) {
            return $json;
        }
        $min = $json['min_inclusive'] ?? 0;
        $max = $json['max_inclusive'] ?? 0;
        return match (preg_replace('/^minecraft:/', '', $json['type'])) {
            'constant' => $json['value'],
            'uniform' => $random->nextInt($max - $min + 1) + $min,
            'biased_to_bottom' => $min + $random->nextInt($random->nextInt($max - $min + 1) + 1),
            'very_biased_to_bottom' => $min + $random->nextInt($random->nextInt($random->nextInt($max - $min + 1) + 1) + 1),
        };
    }

    private function height(array $json, RandomSource $random): int
    {
        if (preg_replace('/^minecraft:/', '', $json['type']) !== 'uniform') {
            throw new \LogicException('Unsupported height provider ' . $json['type']);
        }
        $min = $this->anchor($json['min_inclusive']);
        $max = $this->anchor($json['max_inclusive']);
        return $min > $max ? $min : $random->nextInt($max - $min + 1) + $min;
    }

    /** WorldgenRandom.setLargeFeatureSeed */
    private static function largeFeatureSeed(LegacyRandom $random, int $seed, int $chunkX, int $chunkZ): void
    {
        $random->setSeed($seed);
        $xScale = $random->nextLong();
        $zScale = $random->nextLong();
        $random->setSeed(Int64::multiply($chunkX, $xScale) ^ Int64::multiply($chunkZ, $zScale) ^ $seed);
    }

    /**
     * Carve one chunk's blocks in y, z, x order.
     *
     * @param \Closure(int, int): string $carverBiome the biome deciding a source chunk's carvers
     * @param \Closure(array &$blocks, int, int, int, bool): ?string $topMaterial the surface rule for exposed dirt
     */
    public function carve(array &$blocks, NoiseChunk $noiseChunk, int $chunkX, int $chunkZ, \Closure $carverBiome, \Closure $topMaterial): void
    {
        $this->mask = [];
        $random = new LegacyRandom(0);
        for ($dx = -8; $dx <= 8; ++$dx) {
            for ($dz = -8; $dz <= 8; ++$dz) {
                $sourceX = $chunkX + $dx;
                $sourceZ = $chunkZ + $dz;
                foreach ($this->carversOf($carverBiome($sourceX, $sourceZ)) as $index => $name) {
                    self::largeFeatureSeed($random, Int64::add($this->settings->state->seed, $index), $sourceX, $sourceZ);
                    $carver = $this->carver($name);
                    if (!($random->nextFloat() <= F32::r((float) $carver['probability']))) {
                        continue;
                    }
                    if (preg_replace('/^minecraft:/', '', $carver['type']) === 'canyon') {
                        $this->canyon($carver, $random, $chunkX, $chunkZ, $sourceX, $sourceZ);
                    } else {
                        $this->cave($carver, $random, $chunkX, $chunkZ, $sourceX, $sourceZ);
                    }
                }
            }
        }
        if ($this->mask !== []) {
            $this->applyMask($blocks, $noiseChunk, $chunkX, $chunkZ, $topMaterial);
        }
    }

    private function cave(array $carver, RandomSource $random, int $chunkX, int $chunkZ, int $sourceX, int $sourceZ): void
    {
        $maxDistance = (4 * 2 - 1) * 16;
        $count = $this->intProvider($carver['count'], $random);
        for ($cave = 0; $cave < $count; ++$cave) {
            $x = (float) ($sourceX * 16 + $random->nextInt(16));
            $y = (float) $this->height($carver['y'], $random);
            $z = (float) ($sourceZ * 16 + $random->nextInt(16));
            $horizontalMultiplier = $this->floatProvider($carver['horizontal_radius_multiplier'], $random);
            $verticalMultiplier = $this->floatProvider($carver['vertical_radius_multiplier'], $random);
            $startVertical = $this->floatProvider($carver['start_vertical_radius_multiplier'] ?? 1.0, $random);
            $floorLevel = $this->floatProvider($carver['floor_level'], $random);
            $skip = static fn (float $xd, float $yd, float $zd, int $worldY): bool => $yd <= $floorLevel ? true : $xd * $xd + $yd * $yd + $zd * $zd >= 1.0;
            $tunnels = 1;
            if ($random->nextInt(4) === 0) {
                $yScale = $this->floatProvider($carver['room_vertical_radius_multiplier'], $random);
                $thickness = F32::r(1.0 + F32::r($random->nextFloat() * 6.0));
                $horizontalRadius = 1.5 + F32::r($this->sin(self::HALF_PI) * $thickness);
                $this->ellipsoid($chunkX, $chunkZ, $x + 1.0, $y, $z, $horizontalRadius, $horizontalRadius * $yScale, $skip);
                $tunnels += $random->nextInt(4);
            }
            for ($i = 0; $i < $tunnels; ++$i) {
                $horizontalRotation = F32::r($random->nextFloat() * self::TWO_PI);
                $verticalRotation = F32::r(F32::r($random->nextFloat() - 0.5) / 4.0);
                $thickness = $this->floatProvider($carver['thickness'], $random);
                if (($carver['weird_thickness_bias'] ?? false) && $random->nextInt(10) === 0) {
                    $a = F32::r($random->nextFloat() * $random->nextFloat());
                    $thickness = F32::r($thickness * F32::r(F32::r($a * 3.0) + 1.0));
                }
                $distance = $maxDistance - $random->nextInt(intdiv($maxDistance, 4));
                $this->tunnel($chunkX, $chunkZ, $random->nextLong(), $x, $y, $z, $horizontalMultiplier, $verticalMultiplier, $thickness, $horizontalRotation, $verticalRotation, 0, $distance, $startVertical, $skip);
            }
        }
    }

    private function tunnel(int $chunkX, int $chunkZ, int $seed, float $x, float $y, float $z, float $horizontalMultiplier, float $verticalMultiplier, float $thickness, float $horizontalRotation, float $verticalRotation, int $step, int $distance, float $yScale, \Closure $skip): void
    {
        $random = new LegacyRandom($seed);
        $splitPoint = $random->nextInt(intdiv($distance, 2)) + intdiv($distance, 4);
        $steep = $random->nextInt(6) === 0;
        $yRota = 0.0;
        $xRota = 0.0;
        for ($current = $step; $current < $distance; ++$current) {
            $horizontalRadius = 1.5 + F32::r($this->sin(F32::r(F32::r(self::PI * $current) / $distance)) * $thickness);
            $verticalRadius = $horizontalRadius * $yScale;
            $cosX = $this->cos($verticalRotation);
            $x += F32::r($this->cos($horizontalRotation) * $cosX);
            $y += $this->sin($verticalRotation);
            $z += F32::r($this->sin($horizontalRotation) * $cosX);
            $verticalRotation = F32::r($verticalRotation * ($steep ? F32::r(0.92) : F32::r(0.7)));
            $verticalRotation = F32::r($verticalRotation + F32::r($xRota * F32::r(0.1)));
            $horizontalRotation = F32::r($horizontalRotation + F32::r($yRota * F32::r(0.1)));
            $xRota = F32::r($xRota * F32::r(0.9));
            $yRota = F32::r($yRota * 0.75);
            $xRota = F32::r($xRota + F32::r(F32::r(F32::r($random->nextFloat() - $random->nextFloat()) * $random->nextFloat()) * 2.0));
            $yRota = F32::r($yRota + F32::r(F32::r(F32::r($random->nextFloat() - $random->nextFloat()) * $random->nextFloat()) * 4.0));
            if ($current === $splitPoint && $thickness > 1.0) {
                foreach ([-1.0, 1.0] as $side) {
                    $branchSeed = $random->nextLong();
                    $branchThickness = F32::r(F32::r($random->nextFloat() * 0.5) + 0.5);
                    $this->tunnel($chunkX, $chunkZ, $branchSeed, $x, $y, $z, $horizontalMultiplier, $verticalMultiplier, $branchThickness, F32::r($horizontalRotation + $side * self::HALF_PI), F32::r($verticalRotation / 3.0), $current, $distance, 1.0, $skip);
                }
                return;
            }
            if ($random->nextInt(4) !== 0) {
                if (!self::canReach($chunkX, $chunkZ, $x, $z, $current, $distance, $thickness)) {
                    return;
                }
                $this->ellipsoid($chunkX, $chunkZ, $x, $y, $z, $horizontalRadius * $horizontalMultiplier, $verticalRadius * $verticalMultiplier, $skip);
            }
        }
    }

    private function canyon(array $carver, RandomSource $random, int $chunkX, int $chunkZ, int $sourceX, int $sourceZ): void
    {
        $maxDistance = (4 * 2 - 1) * 16;
        $shape = $carver['shape'];
        $x = (float) ($sourceX * 16 + $random->nextInt(16));
        $y = (float) $this->height($carver['y'], $random);
        $z = (float) ($sourceZ * 16 + $random->nextInt(16));
        $horizontalRotation = F32::r($random->nextFloat() * self::TWO_PI);
        $verticalRotation = $this->floatProvider($carver['vertical_rotation'], $random);
        $yScale = $this->floatProvider($shape['y_scale'], $random);
        $thickness = $this->floatProvider($shape['thickness'], $random);
        $distance = (int) F32::r($maxDistance * $this->floatProvider($shape['distance_factor'], $random));
        $tunnel = new LegacyRandom($random->nextLong());
        $depth = $this->settings->height;
        $widths = [];
        $width = 1.0;
        for ($i = 0; $i < $depth; ++$i) {
            if ($i === 0 || $tunnel->nextInt($shape['width_smoothness']) === 0) {
                $width = F32::r(1.0 + F32::r($tunnel->nextFloat() * $tunnel->nextFloat()));
            }
            $widths[] = F32::r($width * $width);
        }
        $minY = $this->settings->minY;
        $skip = static fn (float $xd, float $yd, float $zd, int $worldY): bool => ($xd * $xd + $zd * $zd) * $widths[$worldY - $minY - 1] + $yd * $yd / 6.0 >= 1.0;
        $yRota = 0.0;
        $xRota = 0.0;
        $defaultFactor = F32::r((float) $shape['vertical_radius_default_factor']);
        $centerFactor = F32::r((float) $shape['vertical_radius_center_factor']);
        for ($current = 0; $current < $distance; ++$current) {
            $horizontalRadius = 1.5 + F32::r($this->sin(F32::r(F32::r($current * self::PI) / $distance)) * $thickness);
            $verticalRadius = $horizontalRadius * $yScale;
            $horizontalRadius *= $this->floatProvider($shape['horizontal_radius_factor'], $tunnel);
            // updateVerticalRadius
            $multiplier = F32::r(1.0 - F32::r(abs(F32::r(0.5 - F32::r($current / $distance))) * 2.0));
            $factor = F32::r($defaultFactor + F32::r($centerFactor * $multiplier));
            $between = F32::r(F32::r($tunnel->nextFloat() * F32::r(1.0 - 0.75)) + 0.75);
            $verticalRadius = $factor * $verticalRadius * $between;
            $xc = $this->cos($verticalRotation);
            $xs = $this->sin($verticalRotation);
            $x += F32::r($this->cos($horizontalRotation) * $xc);
            $y += $xs;
            $z += F32::r($this->sin($horizontalRotation) * $xc);
            $verticalRotation = F32::r($verticalRotation * F32::r(0.7));
            $verticalRotation = F32::r($verticalRotation + F32::r($xRota * F32::r(0.05)));
            $horizontalRotation = F32::r($horizontalRotation + F32::r($yRota * F32::r(0.05)));
            $xRota = F32::r($xRota * F32::r(0.8));
            $yRota = F32::r($yRota * 0.5);
            $xRota = F32::r($xRota + F32::r(F32::r(F32::r($tunnel->nextFloat() - $tunnel->nextFloat()) * $tunnel->nextFloat()) * 2.0));
            $yRota = F32::r($yRota + F32::r(F32::r(F32::r($tunnel->nextFloat() - $tunnel->nextFloat()) * $tunnel->nextFloat()) * 4.0));
            if ($tunnel->nextInt(4) !== 0) {
                if (!self::canReach($chunkX, $chunkZ, $x, $z, $current, $distance, $thickness)) {
                    return;
                }
                $this->ellipsoid($chunkX, $chunkZ, $x, $y, $z, $horizontalRadius, $verticalRadius, $skip);
            }
        }
    }

    private static function canReach(int $chunkX, int $chunkZ, float $x, float $z, int $current, int $total, float $thickness): bool
    {
        $xd = $x - ($chunkX * 16 + 8);
        $zd = $z - ($chunkZ * 16 + 8);
        $remaining = (float) ($total - $current);
        $rr = F32::r(F32::r($thickness + 2.0) + 16.0);
        return $xd * $xd + $zd * $zd - $remaining * $remaining <= $rr * $rr;
    }

    private function ellipsoid(int $chunkX, int $chunkZ, float $x, float $y, float $z, float $horizontalRadius, float $verticalRadius, \Closure $skip): void
    {
        $centerX = (float) ($chunkX * 16 + 8);
        $centerZ = (float) ($chunkZ * 16 + 8);
        $maxDelta = 16.0 + $horizontalRadius * 2.0;
        if (abs($x - $centerX) > $maxDelta || abs($z - $centerZ) > $maxDelta) {
            return;
        }
        $minChunkX = $chunkX * 16;
        $minChunkZ = $chunkZ * 16;
        $minX = max((int) floor($x - $horizontalRadius) - $minChunkX - 1, 0);
        $maxX = min((int) floor($x + $horizontalRadius) - $minChunkX, 15);
        $minY = max((int) floor($y - $verticalRadius) - 1, $this->minMaskY);
        $maxY = min((int) floor($y + $verticalRadius) + 1, $this->minMaskY + $this->maskHeight - 1);
        $minZ = max((int) floor($z - $horizontalRadius) - $minChunkZ - 1, 0);
        $maxZ = min((int) floor($z + $horizontalRadius) - $minChunkZ, 15);
        for ($ix = $minX; $ix <= $maxX; ++$ix) {
            $xd = ($minChunkX + $ix + 0.5 - $x) / $horizontalRadius;
            for ($iz = $minZ; $iz <= $maxZ; ++$iz) {
                $zd = ($minChunkZ + $iz + 0.5 - $z) / $horizontalRadius;
                if ($xd * $xd + $zd * $zd >= 1.0) {
                    continue;
                }
                for ($worldY = $maxY; $worldY > $minY; --$worldY) {
                    $yd = ($worldY - 0.5 - $y) / $verticalRadius;
                    if (!$skip($xd, $yd, $zd, $worldY)) {
                        $this->mask[$worldY - $this->minMaskY + ($iz + ($ix << 4)) * $this->maskHeight] = true;
                    }
                }
            }
        }
    }

    /** applyCarvingMask: each masked run from the top down, through the aquifer. */
    private function applyMask(array &$blocks, NoiseChunk $noiseChunk, int $chunkX, int $chunkZ, \Closure $topMaterial): void
    {
        ksort($this->mask);
        $minY = $this->settings->minY;
        $runs = [];
        $start = null;
        $previous = null;
        foreach ($this->mask as $index => $_) {
            if ($start === null) {
                $start = $index;
            } elseif ($index !== $previous + 1) {
                $runs[] = [$start, $previous];
                $start = $index;
            }
            $previous = $index;
        }
        $runs[] = [$start, $previous];
        $height = $this->maskHeight;
        foreach ($runs as [$first, $last]) {
            for ($column = intdiv($first, $height); $column <= intdiv($last, $height); ++$column) {
                $x = ($column >> 4) & 15;
                $z = $column & 15;
                $base = $column * $height;
                $bottom = max($first - $base, 0) + $this->minMaskY;
                $top = min($last - $base, $height - 1) + $this->minMaskY;
                $hasGrass = false;
                for ($y = $top; $y >= $bottom; --$y) {
                    $index = (($y - $minY) << 8) | ($z << 4) | $x;
                    $old = $blocks[$index];
                    if ($old === 'bedrock') {
                        continue;
                    }
                    if ($old === 'grass_block' || $old === 'mycelium') {
                        $hasGrass = true;
                    }
                    $state = $noiseChunk->substance($chunkX * 16 + $x, $y, $chunkZ * 16 + $z, 0.0);
                    if ($state === null) {
                        continue;
                    }
                    $blocks[$index] = $state;
                    if ($hasGrass && $y - 1 >= $minY && $blocks[$index - 256] === 'dirt') {
                        $material = $topMaterial($blocks, $chunkX * 16 + $x, $y - 1, $chunkZ * 16 + $z, $state === 'water' || $state === 'lava');
                        if ($material !== null) {
                            $blocks[$index - 256] = $material;
                        }
                    }
                }
            }
        }
    }
}
