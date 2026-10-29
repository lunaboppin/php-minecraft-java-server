<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/**
 * Nether features: huge fungi, netherrack replace blobs, deltas, random neighbour spread
 * (glowstone and weeping vines), single block pillars, projected patchy squares and stepped
 * column clusters (basalt columns).
 */
final class Nether
{
    /** Direction.values(): down, up, north, south, west, east. */
    private const DIRECTIONS = [[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]];

    private const DELTA_CANNOT_REPLACE = ['bedrock', 'nether_bricks', 'nether_brick_fence', 'nether_brick_stairs', 'nether_wart', 'chest', 'spawner'];

    private readonly Blocks $blocks;

    public function __construct(private readonly Decorator $decorator)
    {
        $this->blocks = $decorator->blocks;
    }

    /** Mth.nextInt */
    private static function between(RandomSource $random, int $min, int $max): int
    {
        return $min >= $max ? $min : $random->nextInt($max - $min + 1) + $min;
    }

    /** Membership in a block HolderSet: a block, a #tag or a list of blocks. */
    private function inSet(mixed $set, int $state): bool
    {
        foreach ((array) $set as $block) {
            if ($this->blocks->is($state, $block)) {
                return true;
            }
        }
        return false;
    }

    private function sameBlock(int $state, mixed $json): bool
    {
        return $this->blocks->name($state) === $this->blocks->name($this->blocks->parse($json));
    }

    /**
     * BlockPos.withinBoxByManhattanDistance: rings of growing Manhattan distance, each Z offset
     * followed by its mirror.
     *
     * @return \Generator<array{0: int, 1: int, 2: int}>
     */
    private static function manhattan(int $originX, int $originY, int $originZ, int $reachX, int $reachY, int $reachZ): \Generator
    {
        $maxDepth = $reachX + $reachY + $reachZ;
        for ($depth = 0; $depth <= $maxDepth; ++$depth) {
            $maxX = min($reachX, $depth);
            for ($x = -$maxX; $x <= $maxX; ++$x) {
                $maxY = min($reachY, $depth - abs($x));
                for ($y = -$maxY; $y <= $maxY; ++$y) {
                    $z = $depth - abs($x) - abs($y);
                    if ($z <= $reachZ) {
                        yield [$originX + $x, $originY + $y, $originZ + $z];
                        if ($z !== 0) {
                            yield [$originX + $x, $originY + $y, $originZ - $z];
                        }
                    }
                }
            }
        }
    }

    /** HugeFungusFeature without planting: a stem and a wart or wart-block hat with shroomlights. */
    public function hugeFungus(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        if (!$this->sameBlock($region->get($x, $y - 1, $z), $feature['valid_base_block'])) {
            return false;
        }
        $planted = $feature['planted'] ?? false;
        $height = self::between($random, 4, 13);
        if ($random->nextInt(12) === 0) {
            $height *= 2;
        }
        if (!$planted && $y + $height + 1 >= $region->height) {
            return false;
        }
        $huge = !$planted && $random->nextFloat() < F32::r(0.06);
        $region->set($x, $y, $z, $this->blocks->state('air'));
        $stem = $this->blocks->parse($feature['stem_state']);
        $radius = $huge ? 1 : 0;
        for ($dx = -$radius; $dx <= $radius; ++$dx) {
            for ($dz = -$radius; $dz <= $radius; ++$dz) {
                $corner = $huge && abs($dx) === $radius && abs($dz) === $radius;
                for ($dy = 0; $dy < $height; ++$dy) {
                    if ($this->fungusReplaceable($feature, $region, $x + $dx, $y + $dy, $z + $dz, true)
                        && (!$corner || $random->nextFloat() < F32::r(0.1))) {
                        $region->set($x + $dx, $y + $dy, $z + $dz, $stem);
                    }
                }
            }
        }
        $this->fungusHat($feature, $region, $random, $x, $y, $z, $height, $huge);
        return true;
    }

    private function fungusReplaceable(array $feature, Region $region, int $x, int $y, int $z, bool $plants): bool
    {
        return $this->blocks->replaceable($region->get($x, $y, $z))
            || ($plants && $this->decorator->predicates->test($feature['replaceable_blocks'], $region, $x, $y, $z));
    }

    private function fungusHat(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z, int $height, bool $huge): void
    {
        $hat = $this->blocks->parse($feature['hat_state']);
        $decor = $this->blocks->parse($feature['decor_state']);
        $vines = $this->blocks->name($hat) === 'nether_wart_block';
        $hatHeight = min($random->nextInt(1 + intdiv($height, 3)) + 5, $height);
        $hatStart = $height - $hatHeight;
        for ($dy = $hatStart; $dy <= $height; ++$dy) {
            $radius = $dy < $height - $random->nextInt(3) ? 2 : 1;
            if ($hatHeight > 8 && $dy < $hatStart + 4) {
                $radius = 3;
            }
            if ($huge) {
                ++$radius;
            }
            for ($dx = -$radius; $dx <= $radius; ++$dx) {
                for ($dz = -$radius; $dz <= $radius; ++$dz) {
                    $edgeX = $dx === -$radius || $dx === $radius;
                    $edgeZ = $dz === -$radius || $dz === $radius;
                    $inside = !$edgeX && !$edgeZ && $dy !== $height;
                    $px = $x + $dx;
                    $py = $y + $dy;
                    $pz = $z + $dz;
                    if (!$this->fungusReplaceable($feature, $region, $px, $py, $pz, false)) {
                        continue;
                    }
                    if ($dy < $hatStart + 3) {
                        if (!$inside) {
                            // placeHatDropBlock compares with a double.
                            if ($this->blocks->name($region->get($px, $py - 1, $pz)) === $this->blocks->name($hat)) {
                                $region->set($px, $py, $pz, $hat);
                            } elseif ($random->nextFloat() < 0.15) {
                                $region->set($px, $py, $pz, $hat);
                                if ($vines && $random->nextInt(11) === 0) {
                                    $this->weepingVines($region, $random, $px, $py, $pz);
                                }
                            }
                        }
                    } elseif ($inside) {
                        $this->hatBlock($region, $random, $px, $py, $pz, $hat, $decor, 0.1, 0.2, $vines ? 0.1 : 0.0);
                    } elseif ($edgeX && $edgeZ) {
                        $this->hatBlock($region, $random, $px, $py, $pz, $hat, $decor, 0.01, 0.7, $vines ? 0.083 : 0.0);
                    } else {
                        $this->hatBlock($region, $random, $px, $py, $pz, $hat, $decor, 5.0E-4, 0.98, $vines ? 0.07 : 0.0);
                    }
                }
            }
        }
    }

    private function hatBlock(Region $region, RandomSource $random, int $x, int $y, int $z, int $hat, int $decor, float $decorChance, float $hatChance, float $vineChance): void
    {
        if ($random->nextFloat() < F32::r($decorChance)) {
            $region->set($x, $y, $z, $decor);
        } elseif ($random->nextFloat() < F32::r($hatChance)) {
            $region->set($x, $y, $z, $hat);
            if ($random->nextFloat() < F32::r($vineChance)) {
                $this->weepingVines($region, $random, $x, $y, $z);
            }
        }
    }

    /** HugeFungusFeature.tryPlaceWeepingVines below a hat block. */
    private function weepingVines(Region $region, RandomSource $random, int $x, int $y, int $z): void
    {
        --$y;
        if (!$this->blocks->isAir($region->get($x, $y, $z))) {
            return;
        }
        $goal = self::between($random, 1, 5);
        if ($random->nextInt(7) === 0) {
            $goal *= 2;
        }
        for ($height = 0; $height <= $goal; ++$height) {
            if ($this->blocks->isAir($region->get($x, $y, $z))) {
                if ($height === $goal || !$this->blocks->isAir($region->get($x, $y - 1, $z))) {
                    $region->set($x, $y, $z, $this->blocks->state('weeping_vines', ['age' => (string) self::between($random, 23, 25)]));
                    break;
                }
                $region->set($x, $y, $z, $this->blocks->state('weeping_vines_plant'));
            }
            --$y;
        }
    }

    /** ReplaceBlobsFeature: target blocks within a Manhattan ellipsoid below the origin become the state. */
    public function replaceBlobs(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $target = $this->blocks->name($this->blocks->parse($feature['target']));
        // ReplaceBlobsFeature.findTarget from the clamped origin down.
        $y = min(max($y, $region->minY + 1), $region->maxY());
        while ($y > $region->minY + 1 && $this->blocks->name($region->get($x, $y, $z)) !== $target) {
            --$y;
        }
        if ($y <= $region->minY + 1) {
            return false;
        }
        $radiusX = Providers::int($feature['radius'], $random);
        $radiusY = Providers::int($feature['radius'], $random);
        $radiusZ = Providers::int($feature['radius'], $random);
        $limit = max($radiusX, $radiusY, $radiusZ);
        $state = $this->blocks->parse($feature['state']);
        $replaced = false;
        foreach (self::manhattan($x, $y, $z, $radiusX, $radiusY, $radiusZ) as [$px, $py, $pz]) {
            if (abs($px - $x) + abs($py - $y) + abs($pz - $z) > $limit) {
                break;
            }
            if ($this->blocks->name($region->get($px, $py, $pz)) === $target) {
                $region->set($px, $py, $pz, $state);
                $replaced = true;
            }
        }
        return $replaced;
    }

    /** DeltaFeature: a flat pool of contents with an optional offset rim. */
    public function delta(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $contents = $this->blocks->parse($feature['contents']);
        $rim = $this->blocks->parse($feature['rim']);
        $spawnRim = $random->nextDouble() < 0.9;
        $rimX = $spawnRim ? Providers::int($feature['rim_size'], $random) : 0;
        $rimZ = $spawnRim ? Providers::int($feature['rim_size'], $random) : 0;
        $hasRim = $spawnRim && $rimX !== 0 && $rimZ !== 0;
        $radiusX = Providers::int($feature['size'], $random);
        $radiusZ = Providers::int($feature['size'], $random);
        $limit = max($radiusX, $radiusZ);
        $placed = false;
        foreach (self::manhattan($x, $y, $z, $radiusX, 0, $radiusZ) as [$px, $py, $pz]) {
            if (abs($px - $x) + abs($pz - $z) > $limit) {
                break;
            }
            if ($this->deltaClear($region, $contents, $px, $py, $pz)) {
                if ($hasRim) {
                    $placed = true;
                    $region->set($px, $py, $pz, $rim);
                }
                if ($this->deltaClear($region, $contents, $px + $rimX, $py, $pz + $rimZ)) {
                    $placed = true;
                    $region->set($px + $rimX, $py, $pz + $rimZ, $contents);
                }
            }
        }
        return $placed;
    }

    private function deltaClear(Region $region, int $contents, int $x, int $y, int $z): bool
    {
        $name = $this->blocks->name($region->get($x, $y, $z));
        if ($name === $this->blocks->name($contents) || in_array($name, self::DELTA_CANNOT_REPLACE, true)) {
            return false;
        }
        foreach (self::DIRECTIONS as $direction => [$dx, $dy, $dz]) {
            if ($this->blocks->isAir($region->get($x + $dx, $y + $dy, $z + $dz)) !== ($direction === Blocks::UP)) {
                return false;
            }
        }
        return true;
    }

    /** RandomNeighborSpreadFeature: the block at the origin, then attempts beside exactly one accepted neighbour. */
    public function randomNeighborSpread(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $providers = $this->decorator->providers;
        $region->set($x, $y, $z, $providers->state($feature['block'], $region, $random, $x, $y, $z));
        $attempts = Providers::int($feature['attempts'], $random);
        for ($i = 0; $i < $attempts; ++$i) {
            $px = $x + Providers::int($feature['xz_offset'], $random);
            $py = $y + Providers::int($feature['y_offset'], $random);
            $pz = $z + Providers::int($feature['xz_offset'], $random);
            if (!$this->decorator->predicates->test($feature['can_replace'], $region, $px, $py, $pz)) {
                continue;
            }
            $neighbours = 0;
            foreach (self::DIRECTIONS as [$dx, $dy, $dz]) {
                if ($this->inSet($feature['accepted_neighbors'], $region->get($px + $dx, $py + $dy, $pz + $dz))) {
                    ++$neighbours;
                }
                if ($neighbours > 1) {
                    break;
                }
            }
            if ($neighbours === 1) {
                $region->set($px, $py, $pz, $providers->state($feature['block'], $region, $random, $px, $py, $pz));
            }
        }
        return true;
    }

    /** SingleBlockPillarFeature: a column grown up or down while it may continue, then its cap feature. */
    public function singleBlockPillar(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $step = $feature['direction'] === 'up' ? 1 : -1;
        $canReplace = $feature['can_replace'] ?? ['type' => 'true'];
        $chance = F32::r((float) ($feature['chance_to_continue'] ?? 1.0));
        while ($this->decorator->predicates->test($canReplace, $region, $x, $y, $z) && $random->nextFloat() < $chance && !$region->isOutsideBuildHeight($y)) {
            $region->set($x, $y, $z, $this->decorator->providers->state($feature['block'], $region, $random, $x, $y, $z));
            $y += $step;
        }
        if (isset($feature['cap_feature'])) {
            $this->decorator->placePlaced($feature['cap_feature'], $region, $random, [$x, $y - $step, $z]);
        }
        return true;
    }

    /** ProjectedRandomPatchySquare: a square thinning towards its corners, each block dropped through the projection predicate. */
    public function projectedPatchySquare(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $size = Providers::int($feature['size'], $random);
        $bound = $size * $size + 1;
        for ($dx = -$size; $dx <= $size; ++$dx) {
            for ($dz = -$size; $dz <= $size; ++$dz) {
                if ($random->nextInt($bound) >= $bound - abs($dx) * abs($dz)) {
                    continue;
                }
                $by = $y;
                $drop = $feature['max_projection_height'];
                while ($this->decorator->predicates->test($feature['project_through'], $region, $x + $dx, $by - 1, $z + $dz)) {
                    --$by;
                    if (--$drop <= 0) {
                        break;
                    }
                }
                $state = $this->decorator->providers->optionalState($feature['block'], $region, $random, $x + $dx, $by, $z + $dz);
                if ($state !== null) {
                    $region->set($x + $dx, $by, $z + $dz, $state);
                }
            }
        }
        return true;
    }

    /** SteppedColumnClusterFeature: basalt columns, shorter towards the cluster's edge. */
    public function steppedColumnCluster(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        if (!$this->columnCanPlace($feature, $region, $x, $y, $z)) {
            return false;
        }
        $height = Providers::int($feature['height'], $random);
        $reach = min($height, Providers::int($feature['cluster_reach'], $random));
        $count = Providers::int($feature['column_count'], $random);
        $placed = false;
        // BlockPos.randomBetweenClosed draws each position as it is used.
        for ($i = 0; $i < $count; ++$i) {
            $px = $x - $reach + $random->nextInt(2 * $reach + 1);
            $py = $y + $random->nextInt(1);
            $pz = $z - $reach + $random->nextInt(2 * $reach + 1);
            $blocks = $height - abs($px - $x) - abs($py - $y) - abs($pz - $z);
            if ($blocks >= 0) {
                $placed = $this->column($feature, $region, $random, $px, $py, $pz, $blocks, Providers::int($feature['column_reach'], $random)) || $placed;
            }
        }
        return $placed;
    }

    private function column(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z, int $height, int $reach): bool
    {
        $predicates = $this->decorator->predicates;
        $placed = false;
        for ($dz = -$reach; $dz <= $reach; ++$dz) {
            for ($dx = -$reach; $dx <= $reach; ++$dx) {
                $px = $x + $dx;
                $pz = $z + $dz;
                $limit = abs($dx) + abs($dz);
                $start = $predicates->test($feature['can_replace'], $region, $px, $y, $pz)
                    ? $this->columnSurface($feature, $region, $px, $y, $pz, $limit)
                    : $this->columnAir($feature, $region, $px, $y, $pz, $limit);
                if ($start === null) {
                    continue;
                }
                $cy = $start;
                for ($left = $height - intdiv($limit, 2); $left >= 0; --$left) {
                    if ($predicates->test($feature['can_replace'], $region, $px, $cy, $pz)) {
                        $region->set($px, $cy, $pz, $this->decorator->providers->state($feature['block'], $region, $random, $px, $cy, $pz));
                        $placed = true;
                    } elseif (!$predicates->test($feature['continue_through'], $region, $px, $cy, $pz)) {
                        break;
                    }
                    ++$cy;
                }
            }
        }
        return $placed;
    }

    private function columnSurface(array $feature, Region $region, int $x, int $y, int $z, int $limit): ?int
    {
        while ($y > $region->minY + 1 && $limit > 0) {
            --$limit;
            if ($this->columnCanPlace($feature, $region, $x, $y, $z)) {
                return $y;
            }
            --$y;
        }
        return null;
    }

    private function columnCanPlace(array $feature, Region $region, int $x, int $y, int $z): bool
    {
        if (!$this->decorator->predicates->test($feature['can_replace'], $region, $x, $y, $z)) {
            return false;
        }
        $below = $region->get($x, $y - 1, $z);
        return !$this->blocks->isAir($below) && !$this->inSet($feature['cannot_place_on'], $below);
    }

    private function columnAir(array $feature, Region $region, int $x, int $y, int $z, int $limit): ?int
    {
        while ($y <= $region->maxY() && $limit > 0) {
            --$limit;
            $state = $region->get($x, $y, $z);
            if ($this->inSet($feature['cannot_place_on'], $state)) {
                return null;
            }
            if ($this->blocks->isAir($state)) {
                return $y;
            }
            ++$y;
        }
        return null;
    }
}
