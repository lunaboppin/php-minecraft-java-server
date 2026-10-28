<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/**
 * Ground and fluid features: vegetation patches (lush cave moss and clay pools), lakes,
 * vines, block blobs, underwater magma and multiface growth. Multiface growth places its
 * first face but does not spread further yet.
 */
final class Patches
{
    private const DIRECTIONS = ['down', 'up', 'north', 'south', 'west', 'east'];

    private readonly Blocks $blocks;

    public function __construct(private readonly Decorator $decorator)
    {
        $this->blocks = $decorator->blocks;
    }

    private function matches(mixed $blocks, int $state): bool
    {
        foreach ((array) $blocks as $block) {
            if ($this->blocks->is($state, $block)) {
                return true;
            }
        }
        return false;
    }

    public function vegetationPatch(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z, bool $waterlogged): bool
    {
        $xRadius = Providers::int($feature['xz_radius'], $random) + 1;
        $zRadius = Providers::int($feature['xz_radius'], $random) + 1;
        $ceiling = ($feature['surface'] ?? 'floor') === 'ceiling';
        $inward = $ceiling ? 1 : -1;
        $edgeChance = F32::r((float) ($feature['extra_edge_column_chance'] ?? 0.0));
        $bottomChance = F32::r((float) ($feature['extra_bottom_block_chance'] ?? 0.0));
        $range = $feature['vertical_range'];
        $surface = [];
        for ($dx = -$xRadius; $dx <= $xRadius; ++$dx) {
            $xEdge = $dx === -$xRadius || $dx === $xRadius;
            for ($dz = -$zRadius; $dz <= $zRadius; ++$dz) {
                $zEdge = $dz === -$zRadius || $dz === $zRadius;
                $corner = $xEdge && $zEdge;
                $edge = ($xEdge || $zEdge) && !$corner;
                if ($corner || ($edge && ($edgeChance == 0.0 || $random->nextFloat() > $edgeChance))) {
                    continue;
                }
                $px = $x + $dx;
                $py = $y;
                $pz = $z + $dz;
                for ($i = 0; $this->blocks->isAir($region->get($px, $py, $pz)) && $i < $range; ++$i) {
                    $py += $inward;
                }
                for ($i = 0; !$this->blocks->isAir($region->get($px, $py, $pz)) && $i < $range; ++$i) {
                    $py -= $inward;
                }
                $by = $py + $inward;
                $below = $region->get($px, $by, $pz);
                if ($this->blocks->isAir($region->get($px, $py, $pz)) && $this->blocks->sturdy($below, $ceiling ? Blocks::DOWN : Blocks::UP)) {
                    $depth = Providers::int($feature['depth'], $random) + ($bottomChance > 0.0 && $random->nextFloat() < $bottomChance ? 1 : 0);
                    if ($this->placeGround($feature, $region, $random, $px, $by, $pz, $depth, $inward)) {
                        $surface[] = [$px, $by, $pz];
                    }
                }
            }
        }
        if ($waterlogged) {
            $water = [];
            foreach ($surface as [$sx, $sy, $sz]) {
                $exposed = false;
                foreach ([[0, 0, -1, Blocks::SOUTH], [1, 0, 0, Blocks::WEST], [0, 0, 1, Blocks::NORTH], [-1, 0, 0, Blocks::EAST], [0, -1, 0, Blocks::UP]] as [$ox, $oy, $oz, $face]) {
                    if (!$this->blocks->sturdy($region->get($sx + $ox, $sy + $oy, $sz + $oz), $face)) {
                        $exposed = true;
                        break;
                    }
                }
                if (!$exposed) {
                    $water[] = [$sx, $sy, $sz];
                }
            }
            $water = Trees::hashOrder(array_values(array_unique($water, SORT_REGULAR)));
            foreach ($water as [$sx, $sy, $sz]) {
                $region->set($sx, $sy, $sz, $this->blocks->state('water'));
            }
            $surface = $water;
        } else {
            $surface = Trees::hashOrder(array_values(array_unique($surface, SORT_REGULAR)));
        }
        $chance = F32::r((float) ($feature['vegetation_chance'] ?? 0.0));
        foreach ($surface as [$sx, $sy, $sz]) {
            if ($chance > 0.0 && $random->nextFloat() < $chance) {
                if ($waterlogged) {
                    if ($this->decorator->placePlaced($feature['vegetation_feature'], $region, $random, [$sx, $sy, $sz])) {
                        $placed = $region->get($sx, $sy + 1, $sz);
                        if (($this->blocks->properties($placed)['waterlogged'] ?? 'true') === 'false') {
                            $region->set($sx, $sy + 1, $sz, $this->blocks->with($placed, 'waterlogged', 'true'));
                        }
                    }
                } else {
                    $this->decorator->placePlaced($feature['vegetation_feature'], $region, $random, [$sx, $sy - $inward, $sz]);
                }
            }
        }
        return $surface !== [];
    }

    private function placeGround(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z, int $depth, int $inward): bool
    {
        for ($i = 0; $i < $depth; ++$i) {
            $state = $this->decorator->providers->state($feature['ground_state'], $region, $random, $x, $y, $z);
            $below = $region->get($x, $y, $z);
            if ($this->blocks->name($state) === $this->blocks->name($below)) {
                continue;
            }
            if (!$this->matches($feature['replaceable'], $below)) {
                return $i !== 0;
            }
            $region->set($x, $y, $z, $state);
            $y += $inward;
        }
        return true;
    }

    public function lake(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        if ($y <= $region->minY + 4) {
            return false;
        }
        $x -= 8;
        $y -= 4;
        $z -= 8;
        $grid = [];
        $spots = $random->nextInt(4) + 4;
        for ($i = 0; $i < $spots; ++$i) {
            $xr = $random->nextDouble() * 6.0 + 3.0;
            $yr = $random->nextDouble() * 4.0 + 2.0;
            $zr = $random->nextDouble() * 6.0 + 3.0;
            $xp = $random->nextDouble() * (16.0 - $xr - 2.0) + 1.0 + $xr / 2.0;
            $yp = $random->nextDouble() * (8.0 - $yr - 4.0) + 2.0 + $yr / 2.0;
            $zp = $random->nextDouble() * (16.0 - $zr - 2.0) + 1.0 + $zr / 2.0;
            for ($xx = 1; $xx < 15; ++$xx) {
                for ($zz = 1; $zz < 15; ++$zz) {
                    for ($yy = 1; $yy < 7; ++$yy) {
                        $xd = ($xx - $xp) / ($xr / 2.0);
                        $yd = ($yy - $yp) / ($yr / 2.0);
                        $zd = ($zz - $zp) / ($zr / 2.0);
                        if ($xd * $xd + $yd * $yd + $zd * $zd < 1.0) {
                            $grid[($xx * 16 + $zz) * 8 + $yy] = true;
                        }
                    }
                }
            }
        }
        $edge = static function (int $xx, int $zz, int $yy) use (&$grid): bool {
            return !isset($grid[($xx * 16 + $zz) * 8 + $yy])
                && (($xx < 15 && isset($grid[(($xx + 1) * 16 + $zz) * 8 + $yy])) || ($xx > 0 && isset($grid[(($xx - 1) * 16 + $zz) * 8 + $yy]))
                    || ($zz < 15 && isset($grid[($xx * 16 + $zz + 1) * 8 + $yy])) || ($zz > 0 && isset($grid[($xx * 16 + $zz - 1) * 8 + $yy]))
                    || ($yy < 7 && isset($grid[($xx * 16 + $zz) * 8 + $yy + 1])) || ($yy > 0 && isset($grid[($xx * 16 + $zz) * 8 + $yy - 1])));
        };
        $providers = $this->decorator->providers;
        $predicates = $this->decorator->predicates;
        $fluid = $providers->state($feature['fluid'], $region, $random, $x, $y, $z);
        for ($xx = 0; $xx < 16; ++$xx) {
            for ($zz = 0; $zz < 16; ++$zz) {
                for ($yy = 0; $yy < 8; ++$yy) {
                    if (!$edge($xx, $zz, $yy)) {
                        continue;
                    }
                    $state = $region->get($x + $xx, $y + $yy, $z + $zz);
                    if ($yy >= 4 && in_array($this->blocks->name($state), ['water', 'lava'], true)) {
                        return false;
                    }
                    if ($yy < 4 && !$this->blocks->isSolid($state) && $state !== $fluid) {
                        return false;
                    }
                    if (!$predicates->test($feature['can_place_feature'], $region, $x + $xx, $y + $yy, $z + $zz)) {
                        return false;
                    }
                }
            }
        }
        $air = $this->blocks->state('cave_air');
        for ($xx = 0; $xx < 16; ++$xx) {
            for ($zz = 0; $zz < 16; ++$zz) {
                for ($yy = 0; $yy < 8; ++$yy) {
                    if (isset($grid[($xx * 16 + $zz) * 8 + $yy]) && $predicates->test($feature['can_replace_with_air_or_fluid'], $region, $x + $xx, $y + $yy, $z + $zz)) {
                        $region->set($x + $xx, $y + $yy, $z + $zz, $yy >= 4 ? $air : $fluid);
                    }
                }
            }
        }
        $barrier = $providers->state($feature['barrier'], $region, $random, $x, $y, $z);
        if (!$this->blocks->isAir($barrier)) {
            for ($xx = 0; $xx < 16; ++$xx) {
                for ($zz = 0; $zz < 16; ++$zz) {
                    for ($yy = 0; $yy < 8; ++$yy) {
                        if ($edge($xx, $zz, $yy) && ($yy < 4 || $random->nextInt(2) !== 0)) {
                            $state = $region->get($x + $xx, $y + $yy, $z + $zz);
                            if ($this->blocks->isSolid($state) && $predicates->test($feature['can_replace_with_barrier'], $region, $x + $xx, $y + $yy, $z + $zz)) {
                                $region->set($x + $xx, $y + $yy, $z + $zz, $barrier);
                            }
                        }
                    }
                }
            }
        }
        return true;
    }

    public function vines(Region $region, int $x, int $y, int $z): bool
    {
        if (!$this->blocks->isAir($region->get($x, $y, $z))) {
            return false;
        }
        foreach (self::DIRECTIONS as $direction => $face) {
            if ($direction === Blocks::DOWN) {
                continue;
            }
            [$dx, $dy, $dz] = Blocks::OFFSETS[$direction];
            if ($this->blocks->sturdy($region->get($x + $dx, $y + $dy, $z + $dz), $direction ^ 1)) {
                $region->set($x, $y, $z, $this->blocks->with($this->blocks->state('vine'), $face, 'true'));
                return true;
            }
        }
        return false;
    }

    public function blockBlob(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $predicates = $this->decorator->predicates;
        while ($y > $region->minY + 3 && !$predicates->test($feature['can_place_on'], $region, $x, $y - 1, $z)) {
            --$y;
        }
        if ($y <= $region->minY + 3) {
            return false;
        }
        $state = $this->blocks->parse($feature['state']);
        for ($c = 0; $c < 3; ++$c) {
            $xr = $random->nextInt(2);
            $yr = $random->nextInt(2);
            $zr = $random->nextInt(2);
            $tr = F32::r(F32::r(($xr + $yr + $zr) * F32::r(0.333)) + 0.5);
            for ($bz = $z - $zr; $bz <= $z + $zr; ++$bz) {
                for ($by = $y - $yr; $by <= $y + $yr; ++$by) {
                    for ($bx = $x - $xr; $bx <= $x + $xr; ++$bx) {
                        if (($bx - $x) ** 2 + ($by - $y) ** 2 + ($bz - $z) ** 2 <= F32::r($tr * $tr)) {
                            $region->set($bx, $by, $bz, $state);
                        }
                    }
                }
            }
            $x += -1 + $random->nextInt(2);
            $y += -$random->nextInt(2);
            $z += -1 + $random->nextInt(2);
        }
        return true;
    }

    public function underwaterMagma(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        // Column.scan: water up to the search range, ending on a non-water floor.
        $floor = null;
        $range = $feature['floor_search_range'];
        if ($this->blocks->name($region->get($x, $y, $z)) === 'water') {
            for ($i = 1; $i <= $range; ++$i) {
                $state = $region->get($x, $y - $i, $z);
                if ($this->blocks->name($state) !== 'water') {
                    $floor = $y - $i;
                    break;
                }
            }
        }
        if ($floor === null) {
            return false;
        }
        $r = $feature['placement_radius_around_floor'];
        $probability = F32::r((float) $feature['placement_probability_per_valid_position']);
        $magma = $this->blocks->state('magma_block');
        $placed = 0;
        for ($bz = $z - $r; $bz <= $z + $r; ++$bz) {
            for ($by = $floor - $r; $by <= $floor + $r; ++$by) {
                for ($bx = $x - $r; $bx <= $x + $r; ++$bx) {
                    if (!($random->nextFloat() < $probability) || !$this->magmaPlacement($region, $bx, $by, $bz)) {
                        continue;
                    }
                    $region->set($bx, $by, $bz, $magma);
                    ++$placed;
                }
            }
        }
        return $placed > 0;
    }

    private function magmaPlacement(Region $region, int $x, int $y, int $z): bool
    {
        $state = $region->get($x, $y, $z);
        if ($this->blocks->isAir($state) || $this->blocks->name($state) === 'water' || !$this->blocks->sturdy($region->get($x, $y - 1, $z), Blocks::UP)) {
            return false;
        }
        foreach ([[0, -1, Blocks::SOUTH], [1, 0, Blocks::WEST], [0, 1, Blocks::NORTH], [-1, 0, Blocks::EAST]] as [$dx, $dz, $face]) {
            if (!$this->blocks->sturdy($region->get($x + $dx, $y, $z + $dz), $face)) {
                return false;
            }
        }
        return true;
    }

    public function multifaceGrowth(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $airOrWater = fn (int $state): bool => $this->blocks->isAir($state) || $this->blocks->name($state) === 'water';
        if (!$airOrWater($region->get($x, $y, $z))) {
            return false;
        }
        $block = preg_replace('/^minecraft:/', '', $feature['block']);
        // MultifaceGrowthConfiguration: ceiling, floor, then the horizontal directions.
        $placeable = [];
        if ($feature['can_place_on_ceiling'] ?? false) {
            $placeable[] = Blocks::UP;
        }
        if ($feature['can_place_on_floor'] ?? false) {
            $placeable[] = Blocks::DOWN;
        }
        if ($feature['can_place_on_wall'] ?? false) {
            array_push($placeable, Blocks::NORTH, Blocks::EAST, Blocks::SOUTH, Blocks::WEST);
        }
        $shuffled = $this->shuffle($placeable, $random);
        if ($this->growth($feature, $region, $block, $x, $y, $z, $shuffled, $random)) {
            return true;
        }
        foreach ($shuffled as $search) {
            $except = $this->shuffle(array_values(array_filter($placeable, static fn (int $d): bool => $d !== ($search ^ 1))), $random);
            [$dx, $dy, $dz] = Blocks::OFFSETS[$search];
            for ($i = 0; $i < ($feature['search_range'] ?? 10); ++$i) {
                $px = $x + $dx;
                $py = $y + $dy;
                $pz = $z + $dz;
                $state = $region->get($px, $py, $pz);
                if (!$airOrWater($state) && $this->blocks->name($state) !== $block) {
                    break;
                }
                if ($this->growth($feature, $region, $block, $px, $py, $pz, $except, $random)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function growth(array $feature, Region $region, string $block, int $x, int $y, int $z, array $directions, RandomSource $random): bool
    {
        foreach ($directions as $direction) {
            [$dx, $dy, $dz] = Blocks::OFFSETS[$direction];
            $neighbour = $region->get($x + $dx, $y + $dy, $z + $dz);
            if (!$this->matches($feature['can_be_placed_on'], $neighbour)) {
                continue;
            }
            if (!$this->blocks->sturdy($neighbour, $direction ^ 1)) {
                return false;
            }
            $old = $region->get($x, $y, $z);
            $state = $this->blocks->name($old) === $block ? $old : $this->blocks->state($block);
            $state = $this->blocks->with($state, self::DIRECTIONS[$direction], 'true');
            if ($this->blocks->has($state, 'waterlogged')) {
                $state = $this->blocks->with($state, 'waterlogged', $this->blocks->name($old) === 'water' ? 'true' : 'false');
            }
            $region->set($x, $y, $z, $state);
            // MultifaceSpreader is not ported; its chance still draws from the random.
            $random->nextFloat();
            return true;
        }
        return false;
    }

    /** HugeRedMushroomFeature and HugeBrownMushroomFeature. */
    public function hugeMushroom(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z, bool $red): bool
    {
        $height = $random->nextInt(3) + 4;
        if ($random->nextInt(12) === 0) {
            $height *= 2;
        }
        $radius = $feature['foliage_radius'] ?? 2;
        if ($y < $region->minY + 1 || $y + $height + 1 > $region->maxY()) {
            return false;
        }
        if (!$this->decorator->predicates->test($feature['can_place_on'], $region, $x, $y - 1, $z)) {
            return false;
        }
        for ($dy = 0; $dy <= $height; ++$dy) {
            // The red mushroom checks with trunk and tree heights of -1, so only its column is tested.
            $r = $red ? 0 : ($dy <= 3 ? 0 : $radius);
            for ($dx = -$r; $dx <= $r; ++$dx) {
                for ($dz = -$r; $dz <= $r; ++$dz) {
                    $state = $region->get($x + $dx, $y + $dy, $z + $dz);
                    if (!$this->blocks->isAir($state) && !$this->blocks->inTag($state, 'leaves')) {
                        return false;
                    }
                }
            }
        }
        $providers = $this->decorator->providers;
        $place = function (int $px, int $py, int $pz, int $state) use ($region): void {
            $current = $region->get($px, $py, $pz);
            if ($this->blocks->isAir($current) || $this->blocks->inTag($current, 'replaceable_by_mushrooms')) {
                $region->set($px, $py, $pz, $state);
            }
        };
        $faces = function (int $state, array $values): int {
            foreach ($values as $face => $value) {
                $state = $this->blocks->with($state, $face, $value ? 'true' : 'false');
            }
            return $state;
        };
        if ($red) {
            for ($dy = $height - 3; $dy <= $height; ++$dy) {
                $r = $dy < $height ? $radius : $radius - 1;
                $center = $radius - 2;
                for ($dx = -$r; $dx <= $r; ++$dx) {
                    for ($dz = -$r; $dz <= $r; ++$dz) {
                        $xEdge = $dx === -$r || $dx === $r;
                        $zEdge = $dz === -$r || $dz === $r;
                        if ($dy >= $height || $xEdge !== $zEdge) {
                            $state = $providers->state($feature['cap_provider'], $region, $random, $x, $y, $z);
                            if ($this->blocks->has($state, 'up') && $this->blocks->has($state, 'west')) {
                                $state = $faces($state, ['up' => $dy >= $height - 1, 'west' => $dx < -$center, 'east' => $dx > $center, 'north' => $dz < -$center, 'south' => $dz > $center]);
                            }
                            $place($x + $dx, $y + $dy, $z + $dz, $state);
                        }
                    }
                }
            }
        } else {
            for ($dx = -$radius; $dx <= $radius; ++$dx) {
                for ($dz = -$radius; $dz <= $radius; ++$dz) {
                    $minX = $dx === -$radius;
                    $maxX = $dx === $radius;
                    $minZ = $dz === -$radius;
                    $maxZ = $dz === $radius;
                    $xEdge = $minX || $maxX;
                    $zEdge = $minZ || $maxZ;
                    if ($xEdge && $zEdge) {
                        continue;
                    }
                    $state = $providers->state($feature['cap_provider'], $region, $random, $x, $y, $z);
                    if ($this->blocks->has($state, 'west')) {
                        $state = $faces($state, [
                            'west' => $minX || ($zEdge && $dx === 1 - $radius),
                            'east' => $maxX || ($zEdge && $dx === $radius - 1),
                            'north' => $minZ || ($xEdge && $dz === 1 - $radius),
                            'south' => $maxZ || ($xEdge && $dz === $radius - 1),
                        ]);
                    }
                    $place($x + $dx, $y + $height, $z + $dz, $state);
                }
            }
        }
        for ($dy = 0; $dy < $height; ++$dy) {
            $place($x, $y + $dy, $z, $providers->state($feature['stem_provider'], $region, $random, $x, $y, $z));
        }
        return true;
    }

    /** Util.toShuffledList */
    private function shuffle(array $list, RandomSource $random): array
    {
        for ($i = count($list); $i > 1; --$i) {
            $j = $random->nextInt($i);
            [$list[$i - 1], $list[$j]] = [$list[$j], $list[$i - 1]];
        }
        return $list;
    }
}
