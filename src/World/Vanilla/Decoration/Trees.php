<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/**
 * feature.TreeFeature with its trunk and foliage placers, feature sizes and decorators.
 * Decorators walk the logs and leaves in Java HashSet order sorted by Y, which decides their
 * random draws, so that order is reproduced.
 */
final class Trees
{
    private const HORIZONTAL = [[0, -1], [1, 0], [0, 1], [-1, 0]];

    private readonly Blocks $blocks;
    private readonly Providers $providers;
    /** @var list<float> */
    private static ?array $sine = null;

    // Per-tree state.
    private array $feature = [];
    /** @var array<string, array{0: int, 1: int, 2: int}> */
    private array $trunks = [];
    /** @var array<string, array{0: int, 1: int, 2: int}> */
    private array $foliage = [];
    /** @var array<string, array{0: int, 1: int, 2: int}> */
    private array $roots = [];
    /** @var array<string, array{0: int, 1: int, 2: int}> */
    private array $decorations = [];
    private Region $region;
    private RandomSource $random;

    public function __construct(private readonly Decorator $decorator)
    {
        $this->blocks = $decorator->blocks;
        $this->providers = $decorator->providers;
    }

    private static function mthSin(float $value): float
    {
        self::$sine ??= array_map(static fn (int $i): float => F32::r(sin($i / 10430.378350470453)), range(0, 65535));
        return self::$sine[((int) ($value * 10430.378350470453)) & 65535];
    }

    private static function mthCos(float $value): float
    {
        self::$sine ??= array_map(static fn (int $i): float => F32::r(sin($i / 10430.378350470453)), range(0, 65535));
        return self::$sine[((int) ($value * 10430.378350470453 + 16384.0)) & 65535];
    }

    /** Java HashSet<BlockPos> iteration order: buckets of the final table, insertion order inside each. */
    public static function hashOrder(array $positions): array
    {
        $count = count($positions);
        $capacity = 16;
        while ($count > $capacity * 0.75) {
            $capacity *= 2;
        }
        $keyed = [];
        $i = 0;
        foreach ($positions as $position) {
            [$x, $y, $z] = $position;
            $h = (($y + $z * 31) * 31 + $x) & 0xffffffff;
            $h = ($h ^ ($h >> 16)) & ($capacity - 1);
            $keyed[] = [$h, $i++, $position];
        }
        usort($keyed, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        return array_column($keyed, 2);
    }

    /** The decorator context's list: HashSet order, stably sorted by Y. */
    private static function sortedList(array $set): array
    {
        $list = self::hashOrder(array_values($set));
        $indexed = [];
        foreach ($list as $i => $position) {
            $indexed[] = [$position[1], $i, $position];
        }
        usort($indexed, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        return array_column($indexed, 2);
    }

    private function get(int $x, int $y, int $z): int
    {
        return $this->region->get($x, $y, $z);
    }

    private function validTreePos(int $x, int $y, int $z): bool
    {
        $state = $this->get($x, $y, $z);
        return $this->blocks->isAir($state) || $this->blocks->inTag($state, 'replaceable_by_trees');
    }

    private function isFree(int $x, int $y, int $z): bool
    {
        return $this->validTreePos($x, $y, $z) || $this->blocks->inTag($this->get($x, $y, $z), 'logs');
    }

    private function setTrunk(int $x, int $y, int $z, int $state): void
    {
        $this->trunks["$x,$y,$z"] = [$x, $y, $z];
        $this->region->set($x, $y, $z, $state);
    }

    private function setFoliage(int $x, int $y, int $z, int $state): void
    {
        $this->foliage["$x,$y,$z"] = [$x, $y, $z];
        $this->region->set($x, $y, $z, $state);
    }

    private function setDecoration(int $x, int $y, int $z, int $state): void
    {
        $this->decorations["$x,$y,$z"] = [$x, $y, $z];
        $this->region->set($x, $y, $z, $state);
    }

    private function placeBelowTrunk(int $x, int $y, int $z): void
    {
        $state = $this->providers->optionalState($this->feature['below_trunk_provider'], $this->region, $this->random, $x, $y, $z);
        if ($state !== null) {
            $this->setTrunk($x, $y, $z, $state);
        }
    }

    private function placeLog(int $x, int $y, int $z, ?string $axis = null): bool
    {
        if (!$this->validTreePos($x, $y, $z)) {
            return false;
        }
        $state = $this->providers->state($this->feature['trunk_provider'], $this->region, $this->random, $x, $y, $z);
        if ($axis !== null) {
            $state = $this->blocks->with($state, 'axis', $axis);
        }
        $this->setTrunk($x, $y, $z, $state);
        return true;
    }

    private function placeLogIfFree(int $x, int $y, int $z): void
    {
        if ($this->isFree($x, $y, $z)) {
            $this->placeLog($x, $y, $z);
        }
    }

    private function sizeAt(int $treeHeight, int $yo): int
    {
        $size = $this->feature['minimum_size'];
        $type = preg_replace('/^minecraft:/', '', $size['type']);
        if ($type === 'three_layers_feature_size') {
            if ($yo < ($size['limit'] ?? 1)) {
                return $size['lower_size'] ?? 0;
            }
            return $yo >= $treeHeight - ($size['upper_limit'] ?? 1) ? ($size['upper_size'] ?? 1) : ($size['middle_size'] ?? 1);
        }
        return $yo < ($size['limit'] ?? 1) ? ($size['lower_size'] ?? 0) : ($size['upper_size'] ?? 1);
    }

    public function tree(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        if (isset($feature['root_placer'])) {
            $this->decorator->skipped['tree root placer'] = ($this->decorator->skipped['tree root placer'] ?? 0) + 1;
            return false;
        }
        $this->feature = $feature;
        $this->region = $region;
        $this->random = $random;
        $this->trunks = $this->foliage = $this->roots = $this->decorations = [];
        $trunk = $feature['trunk_placer'];
        $foliage = $feature['foliage_placer'];
        $treeHeight = $this->treeHeight($trunk);
        $foliageHeight = $this->foliageHeight($foliage, $treeHeight);
        $trunkHeight = $treeHeight - $foliageHeight;
        $leafRadius = $this->foliageRadius($foliage, $trunkHeight);
        if ($y < $region->minY + 1 || $y + $treeHeight + 1 > $region->maxY() + 1) {
            return false;
        }
        $clipped = $this->maxFreeTreeHeight($treeHeight, $x, $y, $z);
        $minClipped = $feature['minimum_size']['min_clipped_height'] ?? null;
        if ($clipped < $treeHeight && ($minClipped === null || $clipped < $minClipped)) {
            return false;
        }
        $attachments = $this->placeTrunk($trunk, $clipped, $x, $y, $z);
        foreach ($attachments as $attachment) {
            $this->createFoliage($foliage, $clipped, $attachment, $foliageHeight, $leafRadius);
        }
        if ($this->trunks === [] && $this->foliage === []) {
            return false;
        }
        $this->decorate();
        $this->updateLeaves();
        return true;
    }

    private function treeHeight(array $trunk): int
    {
        return $trunk['base_height'] + $this->random->nextInt($trunk['height_rand_a'] + 1) + $this->random->nextInt($trunk['height_rand_b'] + 1);
    }

    private function maxFreeTreeHeight(int $maxHeight, int $x, int $y, int $z): int
    {
        $ignoreVines = $this->feature['ignore_vines'] ?? false;
        for ($dy = 0; $dy <= $maxHeight + 1; ++$dy) {
            $r = $this->sizeAt($maxHeight, $dy);
            for ($dx = -$r; $dx <= $r; ++$dx) {
                for ($dz = -$r; $dz <= $r; ++$dz) {
                    if (!$this->trunkIsFree($x + $dx, $y + $dy, $z + $dz) || (!$ignoreVines && $this->blocks->name($this->get($x + $dx, $y + $dy, $z + $dz)) === 'vine')) {
                        return $dy - 2;
                    }
                }
            }
        }
        return $maxHeight;
    }

    private function trunkIsFree(int $x, int $y, int $z): bool
    {
        $type = preg_replace('/^minecraft:/', '', $this->feature['trunk_placer']['type']);
        if ($type === 'upwards_branching_trunk_placer') {
            $state = $this->get($x, $y, $z);
            $through = $this->feature['trunk_placer']['can_grow_through'];
            return $this->isFree($x, $y, $z) || (str_starts_with($through, '#') ? $this->blocks->inTag($state, preg_replace('/^#?(minecraft:)?/', '', $through)) : $this->blocks->is($state, $through));
        }
        return $this->isFree($x, $y, $z);
    }

    /** @return list<array{0: array{0: int, 1: int, 2: int}, 1: int, 2: bool, 3: int}> pos, radius offset, double trunk, height offset */
    private function placeTrunk(array $trunk, int $height, int $x, int $y, int $z): array
    {
        $random = $this->random;
        switch (preg_replace('/^minecraft:/', '', $trunk['type'])) {
            case 'straight_trunk_placer':
                $this->placeBelowTrunk($x, $y - 1, $z);
                for ($i = 0; $i < $height; ++$i) {
                    $this->placeLog($x, $y + $i, $z);
                }
                return [[[$x, $y + $height, $z], 0, false, 0]];
            case 'forking_trunk_placer':
                $this->placeBelowTrunk($x, $y - 1, $z);
                $attachments = [];
                $lean = self::HORIZONTAL[$random->nextInt(4)];
                $leanHeight = $height - $random->nextInt(4) - 1;
                $leanSteps = 3 - $random->nextInt(3);
                $tx = $x;
                $tz = $z;
                $top = null;
                for ($yo = 0; $yo < $height; ++$yo) {
                    if ($yo >= $leanHeight && $leanSteps > 0) {
                        $tx += $lean[0];
                        $tz += $lean[1];
                        --$leanSteps;
                    }
                    if ($this->placeLog($tx, $y + $yo, $tz)) {
                        $top = $y + $yo + 1;
                    }
                }
                if ($top !== null) {
                    $attachments[] = [[$tx, $top, $tz], 1, false, 0];
                }
                $tx = $x;
                $tz = $z;
                $branch = self::HORIZONTAL[$random->nextInt(4)];
                if ($branch !== $lean) {
                    $branchPos = $leanHeight - $random->nextInt(2) - 1;
                    $branchSteps = 1 + $random->nextInt(3);
                    $top = null;
                    for ($yo = $branchPos; $yo < $height && $branchSteps > 0; --$branchSteps) {
                        if ($yo >= 1) {
                            $tx += $branch[0];
                            $tz += $branch[1];
                            if ($this->placeLog($tx, $y + $yo, $tz)) {
                                $top = $y + $yo + 1;
                            }
                        }
                        ++$yo;
                    }
                    if ($top !== null) {
                        $attachments[] = [[$tx, $top, $tz], 0, false, 0];
                    }
                }
                return $attachments;
            case 'giant_trunk_placer':
                return $this->giantTrunk($height, $x, $y, $z);
            case 'mega_jungle_trunk_placer':
                $attachments = $this->giantTrunk($height, $x, $y, $z);
                for ($branchHeight = $height - 2 - $random->nextInt(4); $branchHeight > intdiv($height, 2); $branchHeight -= 2 + $random->nextInt(4)) {
                    $angle = F32::r($random->nextFloat() * 6.2831854820251465);
                    $bx = 0;
                    $bz = 0;
                    for ($b = 0; $b < 5; ++$b) {
                        $bx = (int) F32::r(1.5 + F32::r(self::mthCos($angle) * $b));
                        $bz = (int) F32::r(1.5 + F32::r(self::mthSin($angle) * $b));
                        $this->placeLog($x + $bx, $y + $branchHeight - 3 + intdiv($b, 2), $z + $bz);
                    }
                    $attachments[] = [[$x + $bx, $y + $branchHeight, $z + $bz], -2, false, 0];
                }
                return $attachments;
            case 'dark_oak_trunk_placer':
                foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$dx, $dz]) {
                    $this->placeBelowTrunk($x + $dx, $y - 1, $z + $dz);
                }
                $lean = self::HORIZONTAL[$random->nextInt(4)];
                $leanHeight = $height - $random->nextInt(4);
                $leanSteps = 2 - $random->nextInt(3);
                $tx = $x;
                $tz = $z;
                $ey = $y + $height - 1;
                for ($dy = 0; $dy < $height; ++$dy) {
                    if ($dy >= $leanHeight && $leanSteps > 0) {
                        $tx += $lean[0];
                        $tz += $lean[1];
                        --$leanSteps;
                    }
                    $state = $this->get($tx, $y + $dy, $tz);
                    if ($this->blocks->isAir($state) || $this->blocks->inTag($state, 'leaves')) {
                        $this->placeLog($tx, $y + $dy, $tz);
                        $this->placeLog($tx + 1, $y + $dy, $tz);
                        $this->placeLog($tx, $y + $dy, $tz + 1);
                        $this->placeLog($tx + 1, $y + $dy, $tz + 1);
                    }
                }
                $attachments = [[[$tx, $ey, $tz], 0, true, 0]];
                for ($ox = -1; $ox <= 2; ++$ox) {
                    for ($oz = -1; $oz <= 2; ++$oz) {
                        if (($ox < 0 || $ox > 1 || $oz < 0 || $oz > 1) && $random->nextInt(3) <= 0) {
                            $length = $random->nextInt(3) + 2;
                            for ($branchY = 0; $branchY < $length; ++$branchY) {
                                $this->placeLog($x + $ox, $ey - $branchY - 1, $z + $oz);
                            }
                            $attachments[] = [[$x + $ox, $ey, $z + $oz], 0, false, 0];
                        }
                    }
                }
                return $attachments;
            case 'fancy_trunk_placer':
                return $this->fancyTrunk($height, $x, $y, $z);
            case 'bending_trunk_placer':
                $direction = self::HORIZONTAL[$random->nextInt(4)];
                $logHeight = $height - 1;
                $this->placeBelowTrunk($x, $y - 1, $z);
                $points = [];
                [$px, $py, $pz] = [$x, $y, $z];
                for ($i = 0; $i <= $logHeight; ++$i) {
                    if ($i + 1 >= $logHeight + $random->nextInt(2)) {
                        $px += $direction[0];
                        $pz += $direction[1];
                    }
                    if ($this->validTreePos($px, $py, $pz)) {
                        $this->placeLog($px, $py, $pz);
                    }
                    if ($i >= ($trunk['min_height_for_leaves'] ?? 1)) {
                        $points[] = [[$px, $py, $pz], 0, false, 0];
                    }
                    ++$py;
                }
                $length = Providers::int($trunk['bend_length'], $random);
                for ($i = 0; $i <= $length; ++$i) {
                    if ($this->validTreePos($px, $py, $pz)) {
                        $this->placeLog($px, $py, $pz);
                    }
                    $points[] = [[$px, $py, $pz], 0, false, 0];
                    $px += $direction[0];
                    $pz += $direction[1];
                }
                return $points;
        }
        $this->decorator->skipped['trunk ' . $trunk['type']] = ($this->decorator->skipped['trunk ' . $trunk['type']] ?? 0) + 1;
        return [];
    }

    private function giantTrunk(int $height, int $x, int $y, int $z): array
    {
        foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$dx, $dz]) {
            $this->placeBelowTrunk($x + $dx, $y - 1, $z + $dz);
        }
        for ($hh = 0; $hh < $height; ++$hh) {
            $this->placeLogIfFree($x, $y + $hh, $z);
            if ($hh < $height - 1) {
                $this->placeLogIfFree($x + 1, $y + $hh, $z);
                $this->placeLogIfFree($x + 1, $y + $hh, $z + 1);
                $this->placeLogIfFree($x, $y + $hh, $z + 1);
            }
        }
        return [[[$x, $y + $height, $z], 0, true, 0]];
    }

    private function fancyTrunk(int $treeHeight, int $x, int $y, int $z): array
    {
        $random = $this->random;
        $height = $treeHeight + 2;
        $trunkHeight = (int) floor($height * 0.618);
        $this->placeBelowTrunk($x, $y - 1, $z);
        $clustersPerY = min(1, (int) floor(1.382 + (1.0 * $height / 13.0) ** 2.0));
        $trunkTop = $y + $trunkHeight;
        $relativeY = $height - 5;
        $coords = [[[$x, $y + $relativeY, $z], $trunkTop]];
        for (; $relativeY >= 0; --$relativeY) {
            $shape = self::treeShape($height, $relativeY);
            if ($shape < 0.0) {
                continue;
            }
            for ($i = 0; $i < $clustersPerY; ++$i) {
                $radius = 1.0 * $shape * ($random->nextFloat() + 0.328);
                $angle = F32::r($random->nextFloat() * 2.0) * M_PI;
                $cx = $x + (int) floor($radius * sin($angle) + 0.5);
                $cz = $z + (int) floor($radius * cos($angle) + 0.5);
                $start = [$cx, $y + $relativeY - 1, $cz];
                if ($this->makeLimb($start, [$cx, $start[1] + 5, $cz], false)) {
                    $dx = $x - $cx;
                    $dz = $z - $cz;
                    $branchHeight = $start[1] - sqrt($dx * $dx + $dz * $dz) * 0.381;
                    $branchTop = $branchHeight > $trunkTop ? $trunkTop : (int) $branchHeight;
                    if ($this->makeLimb([$x, $branchTop, $z], $start, false)) {
                        $coords[] = [$start, $branchTop];
                    }
                }
            }
        }
        $this->makeLimb([$x, $y, $z], [$x, $y + $trunkHeight, $z], true);
        foreach ($coords as [$end, $base]) {
            if ([$x, $base, $z] !== $end && $base - $y >= $height * 0.2) {
                $this->makeLimb([$x, $base, $z], $end, true);
            }
        }
        $attachments = [];
        foreach ($coords as [$end, $base]) {
            if ($base - $y >= $height * 0.2) {
                $attachments[] = [$end, 0, false, 0];
            }
        }
        return $attachments;
    }

    private static function treeShape(int $height, int $y): float
    {
        if ($y < F32::r($height * 0.3)) {
            return -1.0;
        }
        $radius = F32::r($height / 2.0);
        $adjacent = F32::r($radius - $y);
        $distance = F32::r(sqrt(F32::r(F32::r($radius * $radius) - F32::r($adjacent * $adjacent))));
        if ($adjacent == 0.0) {
            $distance = $radius;
        } elseif (abs($adjacent) >= $radius) {
            return 0.0;
        }
        return F32::r($distance * 0.5);
    }

    private function makeLimb(array $start, array $end, bool $place): bool
    {
        if (!$place && $start === $end) {
            return true;
        }
        $delta = [$end[0] - $start[0], $end[1] - $start[1], $end[2] - $start[2]];
        $steps = max(abs($delta[0]), abs($delta[1]), abs($delta[2]));
        $dx = F32::r($delta[0] / $steps);
        $dy = F32::r($delta[1] / $steps);
        $dz = F32::r($delta[2] / $steps);
        for ($i = 0; $i <= $steps; ++$i) {
            $px = $start[0] + (int) floor(F32::r(0.5 + F32::r($i * $dx)));
            $py = $start[1] + (int) floor(F32::r(0.5 + F32::r($i * $dy)));
            $pz = $start[2] + (int) floor(F32::r(0.5 + F32::r($i * $dz)));
            if ($place) {
                $xd = abs($px - $start[0]);
                $zd = abs($pz - $start[2]);
                $max = max($xd, $zd);
                $this->placeLog($px, $py, $pz, $max > 0 ? ($xd === $max ? 'x' : 'z') : 'y');
            } elseif (!$this->isFree($px, $py, $pz)) {
                return false;
            }
        }
        return true;
    }

    private function foliageHeight(array $foliage, int $treeHeight): int
    {
        return match (preg_replace('/^minecraft:/', '', $foliage['type'])) {
            'spruce_foliage_placer' => max(4, $treeHeight - Providers::int($foliage['trunk_height'], $this->random)),
            'pine_foliage_placer' => Providers::int($foliage['height'], $this->random),
            'mega_pine_foliage_placer' => Providers::int($foliage['crown_height'], $this->random),
            'random_spread_foliage_placer' => Providers::int($foliage['foliage_height'], $this->random),
            'acacia_foliage_placer' => 0,
            'dark_oak_foliage_placer' => 4,
            default => $foliage['height'] ?? 0,
        };
    }

    private function foliageRadius(array $foliage, int $trunkHeight): int
    {
        $radius = Providers::int($foliage['radius'], $this->random);
        if (preg_replace('/^minecraft:/', '', $foliage['type']) === 'pine_foliage_placer') {
            $radius += $this->random->nextInt(max($trunkHeight + 1, 1));
        }
        return $radius;
    }

    private function createFoliage(array $foliage, int $treeHeight, array $attachment, int $foliageHeight, int $leafRadius): void
    {
        $random = $this->random;
        $offset = Providers::int($foliage['offset'], $random);
        [$pos, $radiusOffset, $doubleTrunk, $heightOffset] = $attachment;
        $type = preg_replace('/^minecraft:/', '', $foliage['type']);
        $withOffset = $foliageHeight + $heightOffset;
        switch ($type) {
            case 'blob_foliage_placer':
            case 'bush_foliage_placer':
                for ($yo = $offset; $yo >= $offset - $withOffset; --$yo) {
                    $radius = $type === 'bush_foliage_placer'
                        ? $leafRadius + $radiusOffset - 1 - $yo
                        : max($leafRadius + $radiusOffset - 1 - intdiv($yo, 2), 0);
                    $this->leavesRow($type, $pos, $radius, $yo, $doubleTrunk);
                }
                return;
            case 'fancy_foliage_placer':
                for ($yo = $offset; $yo >= $offset - $foliageHeight; --$yo) {
                    $this->leavesRow($type, $pos, $leafRadius + ($yo !== $offset && $yo !== $offset - $foliageHeight ? 1 : 0), $yo, $doubleTrunk);
                }
                return;
            case 'spruce_foliage_placer':
                $radius = $random->nextInt(2);
                $max = 1;
                $min = 0;
                for ($yo = $offset; $yo >= -$withOffset; --$yo) {
                    $this->leavesRow($type, $pos, $radius, $yo, $doubleTrunk);
                    if ($radius >= $max) {
                        $radius = $min;
                        $min = 1;
                        $max = min($max + 1, $leafRadius + $radiusOffset);
                    } else {
                        ++$radius;
                    }
                }
                return;
            case 'pine_foliage_placer':
                $radius = 0;
                for ($yo = $offset; $yo >= $offset - $withOffset; --$yo) {
                    $this->leavesRow($type, $pos, $radius, $yo, $doubleTrunk);
                    if ($radius >= 1 && $yo === $offset - $withOffset + 1) {
                        --$radius;
                    } elseif ($radius < $leafRadius + $radiusOffset) {
                        ++$radius;
                    }
                }
                return;
            case 'mega_pine_foliage_placer':
                $previous = 0;
                for ($yy = $pos[1] - $withOffset + $offset; $yy <= $pos[1] + $offset; ++$yy) {
                    $yo = $pos[1] - $yy;
                    $smooth = $leafRadius + $radiusOffset + (int) floor(F32::r(F32::r($yo / $withOffset) * 3.5));
                    $jagged = $yo > 0 && $smooth === $previous && ($yy & 1) === 0 ? $smooth + 1 : $smooth;
                    $this->leavesRow($type, [$pos[0], $yy, $pos[2]], $jagged, 0, $doubleTrunk);
                    $previous = $smooth;
                }
                return;
            case 'acacia_foliage_placer':
                $base = [$pos[0], $pos[1] + $offset, $pos[2]];
                $this->leavesRow($type, $base, $leafRadius + $radiusOffset, -1 - $withOffset, $doubleTrunk);
                $this->leavesRow($type, $base, $leafRadius - 1, -$withOffset, $doubleTrunk);
                $this->leavesRow($type, $base, $leafRadius + $radiusOffset - 1, 0, $doubleTrunk);
                return;
            case 'jungle_foliage_placer':
                $leafHeight = ($doubleTrunk ? $foliageHeight : 1 + $random->nextInt(2)) + $heightOffset;
                for ($yo = $offset; $yo >= $offset - $leafHeight; --$yo) {
                    $this->leavesRow($type, $pos, $leafRadius + $radiusOffset + 1 - $yo, $yo, $doubleTrunk);
                }
                return;
            case 'dark_oak_foliage_placer':
                $base = [$pos[0], $pos[1] + $offset, $pos[2]];
                if ($doubleTrunk) {
                    $this->leavesRow($type, $base, $leafRadius + 2, -1, true);
                    $this->leavesRow($type, $base, $leafRadius + 3, 0, true);
                    $this->leavesRow($type, $base, $leafRadius + 2, 1, true);
                    if ($random->nextBoolean()) {
                        $this->leavesRow($type, $base, $leafRadius, 2, true);
                    }
                } else {
                    $this->leavesRow($type, $base, $leafRadius + 2, -1, false);
                    $this->leavesRow($type, $base, $leafRadius + 1, 0, false);
                }
                return;
            case 'random_spread_foliage_placer':
                for ($i = 0; $i < $foliage['leaf_placement_attempts']; ++$i) {
                    $dx = $random->nextInt($leafRadius) - $random->nextInt($leafRadius);
                    $dy = $random->nextInt($foliageHeight) - $random->nextInt($foliageHeight);
                    $dz = $random->nextInt($leafRadius) - $random->nextInt($leafRadius);
                    $this->tryPlaceLeaf($pos[0] + $dx, $pos[1] + $dy, $pos[2] + $dz);
                }
                return;
        }
        $this->decorator->skipped['foliage ' . $type] = ($this->decorator->skipped['foliage ' . $type] ?? 0) + 1;
    }

    private function skipLocation(string $type, int $dx, int $y, int $dz, int $radius, bool $doubleTrunk): bool
    {
        $random = $this->random;
        return match ($type) {
            'blob_foliage_placer' => $dx === $radius && $dz === $radius && ($random->nextInt(2) === 0 || $y === 0),
            'bush_foliage_placer' => $dx === $radius && $dz === $radius && $random->nextInt(2) === 0,
            'fancy_foliage_placer' => F32::r(F32::r(($dx + 0.5) * ($dx + 0.5)) + F32::r(($dz + 0.5) * ($dz + 0.5))) > $radius * $radius,
            'spruce_foliage_placer', 'pine_foliage_placer' => $dx === $radius && $dz === $radius && $radius > 0,
            'mega_pine_foliage_placer', 'jungle_foliage_placer' => $dx + $dz >= 7 ? true : $dx * $dx + $dz * $dz > $radius * $radius,
            'acacia_foliage_placer' => $y === 0 ? ($dx > 1 || $dz > 1) && $dx !== 0 && $dz !== 0 : $dx === $radius && $dz === $radius && $radius > 0,
            'dark_oak_foliage_placer' => $y === -1 && !$doubleTrunk ? $dx === $radius && $dz === $radius : ($y === 1 ? $dx + $dz > $radius * 2 - 2 : false),
            default => false,
        };
    }

    private function skipSigned(string $type, int $dx, int $y, int $dz, int $radius, bool $doubleTrunk): bool
    {
        if ($type === 'dark_oak_foliage_placer' && $y === 0 && $doubleTrunk
            && ($dx === -$radius || $dx >= $radius) && ($dz === -$radius || $dz >= $radius)) {
            return true;
        }
        $minX = $doubleTrunk ? min(abs($dx), abs($dx - 1)) : abs($dx);
        $minZ = $doubleTrunk ? min(abs($dz), abs($dz - 1)) : abs($dz);
        return $this->skipLocation($type, $minX, $y, $minZ, $radius, $doubleTrunk);
    }

    private function leavesRow(string $type, array $origin, int $radius, int $y, bool $doubleTrunk): void
    {
        $offset = $doubleTrunk ? 1 : 0;
        for ($dx = -$radius; $dx <= $radius + $offset; ++$dx) {
            for ($dz = -$radius; $dz <= $radius + $offset; ++$dz) {
                if (!$this->skipSigned($type, $dx, $y, $dz, $radius, $doubleTrunk)) {
                    $this->tryPlaceLeaf($origin[0] + $dx, $origin[1] + $y, $origin[2] + $dz);
                }
            }
        }
    }

    private function tryPlaceLeaf(int $x, int $y, int $z): bool
    {
        $existing = $this->get($x, $y, $z);
        if (($this->blocks->properties($existing)['persistent'] ?? 'false') === 'true' || !$this->validTreePos($x, $y, $z)) {
            return false;
        }
        $state = $this->providers->state($this->feature['foliage_provider'], $this->region, $this->random, $x, $y, $z);
        if ($this->blocks->has($state, 'waterlogged')) {
            $state = $this->blocks->with($state, 'waterlogged', $this->blocks->isWater($existing) && $this->blocks->isSource($existing) ? 'true' : 'false');
        }
        $this->setFoliage($x, $y, $z, $state);
        return true;
    }

    private function decorate(): void
    {
        $decorators = $this->feature['decorators'] ?? [];
        if ($decorators === []) {
            return;
        }
        $logs = self::sortedList($this->trunks);
        $leaves = self::sortedList($this->foliage);
        $roots = self::sortedList($this->roots);
        $random = $this->random;
        foreach ($decorators as $decorator) {
            $type = preg_replace('/^minecraft:/', '', $decorator['type']);
            switch ($type) {
                case 'beehive':
                    $this->beehive($decorator, $logs, $leaves);
                    break;
                case 'leave_vine':
                    $probability = F32::r((float) $decorator['probability']);
                    foreach ($leaves as [$x, $y, $z]) {
                        foreach ([[-1, 0, 'east'], [1, 0, 'west'], [0, -1, 'south'], [0, 1, 'north']] as [$dx, $dz, $face]) {
                            if ($random->nextFloat() < $probability && $this->blocks->isAir($this->get($x + $dx, $y, $z + $dz))) {
                                $vine = $this->blocks->with($this->blocks->state('vine'), $face, 'true');
                                $this->setDecoration($x + $dx, $y, $z + $dz, $vine);
                                for ($below = $y - 1, $max = 4; $this->blocks->isAir($this->get($x + $dx, $below, $z + $dz)) && $max > 0; --$max, --$below) {
                                    $this->setDecoration($x + $dx, $below, $z + $dz, $vine);
                                }
                            }
                        }
                    }
                    break;
                case 'trunk_vine':
                    foreach ($logs as [$x, $y, $z]) {
                        foreach ([[-1, 0, 'east'], [1, 0, 'west'], [0, -1, 'south'], [0, 1, 'north']] as [$dx, $dz, $face]) {
                            if ($random->nextInt(3) > 0 && $this->blocks->isAir($this->get($x + $dx, $y, $z + $dz))) {
                                $this->setDecoration($x + $dx, $y, $z + $dz, $this->blocks->with($this->blocks->state('vine'), $face, 'true'));
                            }
                        }
                    }
                    break;
                case 'cocoa':
                    if (!($random->nextFloat() >= F32::r((float) $decorator['probability'])) && $logs !== []) {
                        $treeY = $logs[0][1];
                        foreach ($logs as [$x, $y, $z]) {
                            if ($y - $treeY > 2) {
                                continue;
                            }
                            foreach ([[0, -1, 'north'], [1, 0, 'east'], [0, 1, 'south'], [-1, 0, 'west']] as [$sx, $sz, $facing]) {
                                if ($random->nextFloat() <= 0.25) {
                                    if ($this->blocks->isAir($this->get($x - $sx, $y, $z - $sz))) {
                                        $cocoa = $this->blocks->with($this->blocks->state('cocoa'), 'age', (string) $random->nextInt(3));
                                        $this->setDecoration($x - $sx, $y, $z - $sz, $this->blocks->with($cocoa, 'facing', $facing));
                                    }
                                }
                            }
                        }
                    }
                    break;
                case 'alter_ground':
                    $positions = $this->lowest($logs, $roots);
                    if ($positions === []) {
                        break;
                    }
                    $minY = $positions[0][1];
                    foreach ($positions as [$x, $y, $z]) {
                        if ($y !== $minY) {
                            continue;
                        }
                        foreach ([[-1, -1], [2, -1], [-1, 2], [2, 2]] as [$dx, $dz]) {
                            $this->alterCircle($decorator, $x + $dx, $y, $z + $dz);
                        }
                        for ($i = 0; $i < 5; ++$i) {
                            $placement = $random->nextInt(64);
                            $xx = $placement % 8;
                            $zz = intdiv($placement, 8);
                            if ($xx === 0 || $xx === 7 || $zz === 0 || $zz === 7) {
                                $this->alterCircle($decorator, $x - 3 + $xx, $y, $z - 3 + $zz);
                            }
                        }
                    }
                    break;
                case 'place_on_ground':
                    $this->placeOnGround($decorator, $logs, $roots);
                    break;
                case 'attached_to_logs':
                    foreach ($this->shuffled($logs) as [$x, $y, $z]) {
                        $directions = $decorator['directions'];
                        $direction = array_search($directions[$random->nextInt(count($directions))], ['down', 'up', 'north', 'south', 'west', 'east'], true);
                        [$dx, $dy, $dz] = Blocks::OFFSETS[$direction];
                        if ($random->nextFloat() <= F32::r((float) $decorator['probability']) && $this->blocks->isAir($this->get($x + $dx, $y + $dy, $z + $dz))) {
                            $this->setDecoration($x + $dx, $y + $dy, $z + $dz, $this->providers->state($decorator['block_provider'], $this->region, $random, $x + $dx, $y + $dy, $z + $dz));
                        }
                    }
                    break;
                default:
                    $this->decorator->skipped['tree decorator ' . $type] = ($this->decorator->skipped['tree decorator ' . $type] ?? 0) + 1;
            }
        }
    }

    /** Util.shuffledCopy */
    private function shuffled(array $list): array
    {
        for ($i = count($list); $i > 1; --$i) {
            $j = $this->random->nextInt($i);
            [$list[$i - 1], $list[$j]] = [$list[$j], $list[$i - 1]];
        }
        return $list;
    }

    private function lowest(array $logs, array $roots): array
    {
        if ($roots === []) {
            return $logs;
        }
        if ($logs !== [] && $roots[0][1] === $logs[0][1]) {
            return array_merge($logs, $roots);
        }
        return $roots;
    }

    private function alterCircle(array $decorator, int $x, int $y, int $z): void
    {
        for ($xx = -2; $xx <= 2; ++$xx) {
            for ($zz = -2; $zz <= 2; ++$zz) {
                if (abs($xx) === 2 && abs($zz) === 2) {
                    continue;
                }
                for ($dy = 2; $dy >= -3; --$dy) {
                    $state = $this->providers->optionalState($decorator['provider'], $this->region, $this->random, $x + $xx, $y + $dy, $z + $zz);
                    if ($state !== null) {
                        $this->setDecoration($x + $xx, $y + $dy, $z + $zz, $state);
                        break;
                    }
                    if (!$this->blocks->isAir($this->get($x + $xx, $y + $dy, $z + $zz)) && $dy < 0) {
                        break;
                    }
                }
            }
        }
    }

    private function placeOnGround(array $decorator, array $logs, array $roots): void
    {
        $positions = $this->lowest($logs, $roots);
        if ($positions === []) {
            return;
        }
        [$ox, $minY, $oz] = $positions[0];
        $minX = $maxX = $ox;
        $minZ = $maxZ = $oz;
        foreach ($positions as [$x, $y, $z]) {
            if ($y === $minY) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minZ = min($minZ, $z);
                $maxZ = max($maxZ, $z);
            }
        }
        $radius = $decorator['radius'] ?? 2;
        $height = $decorator['height'] ?? 1;
        $random = $this->random;
        for ($i = 0; $i < ($decorator['tries'] ?? 128); ++$i) {
            $x = $random->nextInt($maxX + $radius - ($minX - $radius) + 1) + $minX - $radius;
            $y = $random->nextInt($minY + $height - ($minY - $height) + 1) + $minY - $height;
            $z = $random->nextInt($maxZ + $radius - ($minZ - $radius) + 1) + $minZ - $radius;
            $above = $this->get($x, $y + 1, $z);
            $here = $this->get($x, $y, $z);
            if (($this->blocks->isAir($above) || $this->blocks->name($above) === 'vine')
                && $this->blocks->flag($here, 1 << 20) && $this->blocks->flag($here, Blocks::FULL_CUBE)
                && $this->region->height(Region::MOTION_BLOCKING_NO_LEAVES, $x, $z) <= $y + 1) {
                $this->setDecoration($x, $y + 1, $z, $this->providers->state($decorator['block_state_provider'], $this->region, $random, $x, $y + 1, $z));
            }
        }
    }

    private function beehive(array $decorator, array $logs, array $leaves): void
    {
        if ($logs === []) {
            return;
        }
        $random = $this->random;
        if ($random->nextFloat() >= F32::r((float) $decorator['probability'])) {
            return;
        }
        $hiveY = $leaves !== []
            ? max($leaves[0][1] - 1, $logs[0][1] + 1)
            : min($logs[0][1] + 1 + $random->nextInt(3), $logs[count($logs) - 1][1]);
        $placements = [];
        foreach ($logs as [$x, $y, $z]) {
            if ($y === $hiveY) {
                // SPAWN_DIRECTIONS: the horizontal directions except north, in east, south, west order.
                foreach ([[1, 0], [0, 1], [-1, 0]] as [$dx, $dz]) {
                    $placements[] = [$x + $dx, $y, $z + $dz];
                }
            }
        }
        if ($placements === []) {
            return;
        }
        $placements = $this->shuffled($placements);
        foreach ($placements as [$x, $y, $z]) {
            if ($this->blocks->isAir($this->get($x, $y, $z)) && $this->blocks->isAir($this->get($x, $y, $z + 1))) {
                $this->setDecoration($x, $y, $z, $this->blocks->with($this->blocks->state('bee_nest'), 'facing', 'south'));
                // The nest's bees draw from the random as vanilla's block entity does.
                $bees = 2 + $random->nextInt(2);
                for ($i = 0; $i < $bees; ++$i) {
                    $random->nextInt(599);
                }
                return;
            }
        }
    }

    /** TreeFeature.updateLeaves: leaf distances from the logs within the tree's bounds. */
    private function updateLeaves(): void
    {
        $all = array_merge($this->roots, $this->trunks, $this->foliage, $this->decorations);
        $minX = $minY = $minZ = PHP_INT_MAX;
        $maxX = $maxY = $maxZ = PHP_INT_MIN;
        foreach ($all as [$x, $y, $z]) {
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $minZ = min($minZ, $z);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
            $maxZ = max($maxZ, $z);
        }
        $inside = static fn (int $x, int $y, int $z): bool => $x >= $minX && $x <= $maxX && $y >= $minY && $y <= $maxY && $z >= $minZ && $z <= $maxZ;
        $filled = [];
        foreach (array_merge($this->decorations, $this->roots) as $key => [$x, $y, $z]) {
            $filled[$key] = true;
        }
        $buckets = array_fill(0, 7, []);
        $buckets[0] = $this->trunks;
        $smallest = 0;
        while (true) {
            while ($smallest >= 7 || $buckets[$smallest] !== []) {
                if ($smallest >= 7) {
                    return;
                }
                $key = array_key_first($buckets[$smallest]);
                [$x, $y, $z] = $buckets[$smallest][$key];
                unset($buckets[$smallest][$key]);
                if (!$inside($x, $y, $z)) {
                    continue;
                }
                if ($smallest !== 0) {
                    $this->region->set($x, $y, $z, $this->blocks->with($this->get($x, $y, $z), 'distance', (string) $smallest));
                }
                $filled[$key] = true;
                foreach (Blocks::OFFSETS as [$dx, $dy, $dz]) {
                    $nx = $x + $dx;
                    $ny = $y + $dy;
                    $nz = $z + $dz;
                    $neighbourKey = "$nx,$ny,$nz";
                    if (!$inside($nx, $ny, $nz) || isset($filled[$neighbourKey])) {
                        continue;
                    }
                    $state = $this->get($nx, $ny, $nz);
                    $distance = $this->blocks->inTag($state, 'prevents_nearby_leaf_decay') ? 0 : (int) ($this->blocks->properties($state)['distance'] ?? -1);
                    if ($distance < 0) {
                        continue;
                    }
                    $new = min($distance, $smallest + 1);
                    if ($new < 7) {
                        $buckets[$new][$neighbourKey] = [$nx, $ny, $nz];
                        $smallest = min($smallest, $new);
                    }
                }
            }
            ++$smallest;
        }
    }
}
