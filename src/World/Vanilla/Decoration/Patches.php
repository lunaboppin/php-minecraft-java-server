<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Noise\NoiseStack;
use PhpMinecraftJavaServer\World\Vanilla\Noise\NormalNoise;
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

    private ?NoiseStack $geodeNoise = null;

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
        $water = fn (int $state): bool => $this->blocks->name($state) === 'water';
        [, $floor] = self::columnScan($region, $x, $y, $z, $feature['floor_search_range'], $water, fn (int $state): bool => !$water($state)) ?? [null, null];
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

    /**
     * MonsterRoomFeature: a cobblestone dungeon with a spawner and up to two chests. Chest loot
     * and the spawner's mob are drawn from the random as vanilla does but not stored yet.
     */
    public function monsterRoom(Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $blocks = $this->blocks;
        $replaceable = fn (int $state): bool => !$blocks->inTag($state, 'features_cannot_replace');
        $safeSet = function (int $px, int $py, int $pz, int $state) use ($region, $replaceable): void {
            if ($replaceable($region->get($px, $py, $pz))) {
                $region->set($px, $py, $pz, $state);
            }
        };
        $xr = $random->nextInt(2) + 2;
        $zr = $random->nextInt(2) + 2;
        $minX = -$xr - 1;
        $maxX = $xr + 1;
        $minZ = -$zr - 1;
        $maxZ = $zr + 1;
        $holes = 0;
        for ($dx = $minX; $dx <= $maxX; ++$dx) {
            for ($dy = -1; $dy <= 4; ++$dy) {
                for ($dz = $minZ; $dz <= $maxZ; ++$dz) {
                    $solid = $blocks->isSolid($region->get($x + $dx, $y + $dy, $z + $dz));
                    if (($dy === -1 || $dy === 4) && !$solid) {
                        return false;
                    }
                    if (($dx === $minX || $dx === $maxX || $dz === $minZ || $dz === $maxZ) && $dy === 0
                        && $blocks->isAir($region->get($x + $dx, $y, $z + $dz)) && $blocks->isAir($region->get($x + $dx, $y + 1, $z + $dz))) {
                        ++$holes;
                    }
                }
            }
        }
        if ($holes < 1 || $holes > 5) {
            return false;
        }
        $air = $blocks->state('cave_air');
        for ($dx = $minX; $dx <= $maxX; ++$dx) {
            for ($dy = 3; $dy >= -1; --$dy) {
                for ($dz = $minZ; $dz <= $maxZ; ++$dz) {
                    $px = $x + $dx;
                    $py = $y + $dy;
                    $pz = $z + $dz;
                    $wall = $region->get($px, $py, $pz);
                    if ($dx === $minX || $dy === -1 || $dz === $minZ || $dx === $maxX || $dy === 4 || $dz === $maxZ) {
                        if ($py >= $region->minY && !$blocks->isSolid($region->get($px, $py - 1, $pz))) {
                            $region->set($px, $py, $pz, $air);
                        } elseif ($blocks->isSolid($wall) && $blocks->name($wall) !== 'chest') {
                            $safeSet($px, $py, $pz, $blocks->state($dy === -1 && $random->nextInt(4) !== 0 ? 'mossy_cobblestone' : 'cobblestone'));
                        }
                    } elseif (!in_array($blocks->name($wall), ['chest', 'spawner'], true)) {
                        $safeSet($px, $py, $pz, $air);
                    }
                }
            }
        }
        for ($cc = 0; $cc < 2; ++$cc) {
            for ($i = 0; $i < 3; ++$i) {
                $cx = $x + $random->nextInt($xr * 2 + 1) - $xr;
                $cz = $z + $random->nextInt($zr * 2 + 1) - $zr;
                if (!$blocks->isAir($region->get($cx, $y, $cz))) {
                    continue;
                }
                $walls = 0;
                foreach (self::HORIZONTAL as [$dx, $dz]) {
                    $walls += $blocks->isSolid($region->get($cx + $dx, $y, $cz + $dz)) ? 1 : 0;
                }
                if ($walls === 1) {
                    $safeSet($cx, $y, $cz, $this->reorientChest($region, $cx, $y, $cz));
                    // RandomizableContainer.setBlockEntityLootTable draws the loot seed.
                    $random->nextLong();
                    break;
                }
            }
        }
        $safeSet($x, $y, $z, $blocks->state('spawner'));
        $random->nextInt(4);
        return true;
    }

    private const HORIZONTAL = [[0, -1], [1, 0], [0, 1], [-1, 0]];
    private const FACINGS = ['north', 'east', 'south', 'west'];

    /** StructurePiece.reorient for a chest against its wall. */
    private function reorientChest(Region $region, int $x, int $y, int $z): int
    {
        $blocks = $this->blocks;
        $chest = $blocks->state('chest');
        $solidRender = fn (int $state): bool => $blocks->flag($state, 1 << 20) && $blocks->flag($state, Blocks::FULL_CUBE);
        $direction = null;
        foreach (self::HORIZONTAL as $i => [$dx, $dz]) {
            $state = $region->get($x + $dx, $y, $z + $dz);
            if ($blocks->name($state) === 'chest') {
                return $chest;
            }
            if ($solidRender($state)) {
                if ($direction !== null) {
                    $direction = null;
                    break;
                }
                $direction = $i;
            }
        }
        if ($direction !== null) {
            return $blocks->with($chest, 'facing', self::FACINGS[($direction + 2) % 4]);
        }
        $facing = array_search($blocks->properties($chest)['facing'], self::FACINGS, true);
        $solidAt = fn (int $d): bool => $solidRender($region->get($x + self::HORIZONTAL[$d][0], $y, $z + self::HORIZONTAL[$d][1]));
        if ($solidAt($facing)) {
            $facing = ($facing + 2) % 4;
        }
        if ($solidAt($facing)) {
            $facing = ($facing + 1) % 4;
        }
        if ($solidAt($facing)) {
            $facing = ($facing + 2) % 4;
        }
        return $blocks->with($chest, 'facing', self::FACINGS[$facing]);
    }

    /** GeodeFeature: layered shells around random points, cracked open, with crystals inside. */
    public function geode(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $blocks = $this->blocks;
        $settings = $feature['blocks'];
        $layers = ($feature['layers'] ?? []) + ['filling' => 1.7, 'inner_layer' => 2.2, 'middle_layer' => 3.2, 'outer_layer' => 4.2];
        $crack = ($feature['crack'] ?? []) + ['generate_crack_chance' => 1.0, 'base_crack_size' => 2.0, 'crack_point_offset' => 2];
        $outerWall = $feature['outer_wall_distance'] ?? ['type' => 'uniform', 'min_inclusive' => 4, 'max_inclusive' => 5];
        $points = Providers::int($feature['distribution_points'] ?? ['type' => 'uniform', 'min_inclusive' => 3, 'max_inclusive' => 4], $random);
        // NormalNoise.createParity(-4, 1.0), seeded from the world seed.
        $this->geodeNoise ??= NormalNoise::create(new LegacyRandom($region->seed), ['base_amplitude' => 0.955388882960065, 'base_octave' => -4, 'octave_count' => 1]);
        $adjust = $points / ($outerWall['max_inclusive'] ?? 5);
        $innerAir = 1.0 / sqrt($layers['filling']);
        $innermost = 1.0 / sqrt($layers['inner_layer'] + $adjust);
        $innerCrust = 1.0 / sqrt($layers['middle_layer'] + $adjust);
        $outerCrust = 1.0 / sqrt($layers['outer_layer'] + $adjust);
        $crackSize = 1.0 / sqrt($crack['base_crack_size'] + $random->nextDouble() / 2.0 + ($points > 3 ? $adjust : 0.0));
        $cracked = $random->nextFloat() < F32::r((float) $crack['generate_crack_chance']);
        $invalid = 0;
        $pointList = [];
        for ($i = 0; $i < $points; ++$i) {
            $px = $x + Providers::int($outerWall, $random);
            $py = $y + Providers::int($outerWall, $random);
            $pz = $z + Providers::int($outerWall, $random);
            $state = $region->get($px, $py, $pz);
            if (($blocks->isAir($state) || $blocks->is($state, $settings['invalid_blocks'])) && ++$invalid > ($feature['invalid_blocks_threshold'] ?? 0)) {
                return false;
            }
            $pointList[] = [$px, $py, $pz, Providers::int($feature['point_offset'] ?? ['type' => 'uniform', 'min_inclusive' => 1, 'max_inclusive' => 2], $random)];
        }
        $crackPoints = [];
        if ($cracked) {
            $index = $random->nextInt(4);
            $offset = $points * 2 + 1;
            [$cx, $cz] = [[$offset, 0], [0, $offset], [$offset, $offset], [0, 0]][$index];
            foreach ([7, 5, 1] as $dy) {
                $crackPoints[] = [$x + $cx, $y + $dy, $z + $cz];
            }
        }
        $cannot = $settings['cannot_replace'];
        $canReplace = fn (int $state): bool => !$blocks->is($state, $cannot);
        $set = function (int $px, int $py, int $pz, int $state) use ($region, $canReplace): void {
            if ($canReplace($region->get($px, $py, $pz))) {
                $region->set($px, $py, $pz, $state);
            }
        };
        $providers = $this->decorator->providers;
        $multiplier = $feature['noise_multiplier'] ?? 0.05;
        $alternateChance = F32::r((float) ($feature['use_alternate_layer0_chance'] ?? 0.0));
        $potentialChance = F32::r((float) ($feature['use_potential_placements_chance'] ?? 0.35));
        $requireAlternate = $feature['placements_require_layer0_alternate'] ?? true;
        $min = $feature['min_gen_offset'] ?? -16;
        $max = $feature['max_gen_offset'] ?? 16;
        $crystals = [];
        for ($pz = $z + $min; $pz <= $z + $max; ++$pz) {
            for ($py = $y + $min; $py <= $y + $max; ++$py) {
                for ($px = $x + $min; $px <= $x + $max; ++$px) {
                    $noise = $this->geodeNoise->get($px, $py, $pz) * $multiplier;
                    $shell = 0.0;
                    foreach ($pointList as [$qx, $qy, $qz, $qo]) {
                        $shell += 1.0 / sqrt(($px - $qx) ** 2 + ($py - $qy) ** 2 + ($pz - $qz) ** 2 + $qo) + $noise;
                    }
                    if ($shell < $outerCrust) {
                        continue;
                    }
                    if ($shell >= $innerAir) {
                        $set($px, $py, $pz, $providers->state($settings['filling_provider'], $region, $random, $px, $py, $pz));
                        continue;
                    }
                    $crackSum = 0.0;
                    foreach ($crackPoints as [$qx, $qy, $qz]) {
                        $crackSum += 1.0 / sqrt(($px - $qx) ** 2 + ($py - $qy) ** 2 + ($pz - $qz) ** 2 + $crack['crack_point_offset']) + $noise;
                    }
                    if ($cracked && $crackSum >= $crackSize) {
                        $set($px, $py, $pz, $blocks->state('air'));
                    } elseif ($shell >= $innermost) {
                        $alternate = $random->nextFloat() < $alternateChance;
                        $set($px, $py, $pz, $providers->state($alternate ? $settings['alternate_inner_layer_provider'] : $settings['inner_layer_provider'], $region, $random, $px, $py, $pz));
                        if ((!$requireAlternate || $alternate) && $random->nextFloat() < $potentialChance) {
                            $crystals[] = [$px, $py, $pz];
                        }
                    } elseif ($shell >= $innerCrust) {
                        $set($px, $py, $pz, $providers->state($settings['middle_layer_provider'], $region, $random, $px, $py, $pz));
                    } else {
                        $set($px, $py, $pz, $providers->state($settings['outer_layer_provider'], $region, $random, $px, $py, $pz));
                    }
                }
            }
        }
        $placements = $settings['inner_placements'];
        foreach ($crystals as [$px, $py, $pz]) {
            $state = $blocks->parse($placements[$random->nextInt(count($placements))]);
            foreach (self::DIRECTIONS as $direction => $face) {
                $state = $blocks->with($state, 'facing', $face);
                [$dx, $dy, $dz] = Blocks::OFFSETS[$direction];
                $place = $region->get($px + $dx, $py + $dy, $pz + $dz);
                if ($blocks->has($state, 'waterlogged')) {
                    $state = $blocks->with($state, 'waterlogged', $blocks->isSource($place) ? 'true' : 'false');
                }
                if ($blocks->isAir($place) || ($blocks->name($place) === 'water' && $blocks->isSource($place))) {
                    $set($px + $dx, $py + $dy, $pz + $dz, $state);
                    break;
                }
            }
        }
        return true;
    }

    /** BambooFeature, with a podzol disc under some stalks. */
    public function bamboo(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $blocks = $this->blocks;
        if (!$blocks->isAir($region->get($x, $y, $z))) {
            return false;
        }
        $trunk = $blocks->state('bamboo', ['age' => '1', 'leaves' => 'none', 'stage' => '0']);
        if ($this->decorator->survival->canSurvive($trunk, $region, $x, $y, $z)) {
            $height = $random->nextInt(12) + 5;
            if ($random->nextFloat() < F32::r((float) ($feature['probability'] ?? 0.0))) {
                $r = $random->nextInt(4) + 1;
                for ($xx = $x - $r; $xx <= $x + $r; ++$xx) {
                    for ($zz = $z - $r; $zz <= $z + $r; ++$zz) {
                        if (($xx - $x) ** 2 + ($zz - $z) ** 2 <= $r * $r) {
                            $py = $region->height(Region::WORLD_SURFACE, $xx, $zz) - 1;
                            if ($blocks->inTag($region->get($xx, $py, $zz), 'beneath_bamboo_podzol_replaceable')) {
                                $region->set($xx, $py, $zz, $blocks->state('podzol'));
                            }
                        }
                    }
                }
            }
            $py = $y;
            for ($i = 0; $i < $height && $blocks->isAir($region->get($x, $py, $z)); ++$i) {
                $region->set($x, $py, $z, $trunk);
                ++$py;
            }
            if ($py - $y >= 3) {
                $region->set($x, $py, $z, $blocks->state('bamboo', ['age' => '1', 'leaves' => 'large', 'stage' => '1']));
                $region->set($x, $py - 1, $z, $blocks->state('bamboo', ['age' => '1', 'leaves' => 'large', 'stage' => '0']));
                $region->set($x, $py - 2, $z, $blocks->state('bamboo', ['age' => '1', 'leaves' => 'small', 'stage' => '0']));
            }
        }
        return true;
    }

    /**
     * Column.scan: from a position inside the column, the nearest valid edge above and below
     * within the search range, or null when the position is not inside.
     *
     * @return array{0: ?int, 1: ?int}|null ceiling and floor Y
     */
    private static function columnScan(Region $region, int $x, int $y, int $z, int $range, \Closure $inside, \Closure $edge): ?array
    {
        if (!$inside($region->get($x, $y, $z))) {
            return null;
        }
        $out = [];
        foreach ([1, -1] as $step) {
            $py = $y;
            for ($i = 1; $i < $range && $inside($region->get($x, $py, $z)); ++$i) {
                $py += $step;
            }
            $out[] = $edge($region->get($x, $py, $z)) ? $py : null;
        }
        return $out;
    }

    private function emptyOrWater(int $state): bool
    {
        return $this->blocks->isAir($state) || $this->blocks->name($state) === 'water';
    }

    private function speleothemBase(array $feature, int $state): bool
    {
        return $this->blocks->is($state, $feature['base_block']) || $this->blocks->is($state, $feature['replaceable_blocks']);
    }

    private function placeBaseBlock(array $feature, Region $region, int $x, int $y, int $z): bool
    {
        if (!$this->blocks->is($region->get($x, $y, $z), $feature['replaceable_blocks'])) {
            return false;
        }
        $region->set($x, $y, $z, $this->blocks->parse($feature['base_block']));
        return true;
    }

    /** SpeleothemUtils.growSpeleothem */
    private function growSpeleothem(array $feature, Region $region, int $x, int $y, int $z, int $direction, int $height, bool $merged): void
    {
        if (!$this->speleothemBase($feature, $region->get($x, $y - $direction, $z))) {
            return;
        }
        $pointed = $this->blocks->parse($feature['pointed_block']);
        $tip = $direction > 0 ? 'up' : 'down';
        $thicknesses = [];
        if ($height >= 3) {
            $thicknesses[] = 'base';
            for ($i = 0; $i < $height - 3; ++$i) {
                $thicknesses[] = 'middle';
            }
        }
        if ($height >= 2) {
            $thicknesses[] = 'frustum';
        }
        if ($height >= 1) {
            $thicknesses[] = $merged ? 'tip_merge' : 'tip';
        }
        foreach ($thicknesses as $thickness) {
            $state = $this->blocks->with($this->blocks->with($pointed, 'tip_direction', $tip), 'thickness', $thickness);
            $here = $region->get($x, $y, $z);
            $state = $this->blocks->with($state, 'waterlogged', $this->blocks->isWater($here) ? 'true' : 'false');
            $region->set($x, $y, $z, $state);
            $y += $direction;
        }
    }

    /** SpeleothemFeature: one pointed block or two on a patch of base blocks. */
    public function speleothem(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $above = $this->speleothemBase($feature, $region->get($x, $y + 1, $z));
        $below = $this->speleothemBase($feature, $region->get($x, $y - 1, $z));
        if ($above && $below) {
            $direction = $random->nextBoolean() ? -1 : 1;
        } elseif ($above) {
            $direction = -1;
        } elseif ($below) {
            $direction = 1;
        } else {
            return false;
        }
        $rootY = $y - $direction;
        $this->placeBaseBlock($feature, $region, $x, $rootY, $z);
        foreach (self::HORIZONTAL as [$dx, $dz]) {
            if ($random->nextFloat() > F32::r((float) ($feature['chance_of_directional_spread'] ?? 0.7))) {
                continue;
            }
            [$x1, $y1, $z1] = [$x + $dx, $rootY, $z + $dz];
            $this->placeBaseBlock($feature, $region, $x1, $y1, $z1);
            if ($random->nextFloat() > F32::r((float) ($feature['chance_of_spread_radius2'] ?? 0.5))) {
                continue;
            }
            [$ox, $oy, $oz] = Blocks::OFFSETS[$random->nextInt(6)];
            [$x2, $y2, $z2] = [$x1 + $ox, $y1 + $oy, $z1 + $oz];
            $this->placeBaseBlock($feature, $region, $x2, $y2, $z2);
            if ($random->nextFloat() > F32::r((float) ($feature['chance_of_spread_radius3'] ?? 0.5))) {
                continue;
            }
            [$ox, $oy, $oz] = Blocks::OFFSETS[$random->nextInt(6)];
            $this->placeBaseBlock($feature, $region, $x2 + $ox, $y2 + $oy, $z2 + $oz);
        }
        $height = $random->nextFloat() < F32::r((float) ($feature['chance_of_taller_generation'] ?? 0.2)) && $this->emptyOrWater($region->get($x, $y + $direction, $z)) ? 2 : 1;
        $this->growSpeleothem($feature, $region, $x, $y, $z, $direction, $height, false);
        return true;
    }

    /** SpeleothemClusterFeature: stalactites and stalagmites across a cave, with pools. */
    public function speleothemCluster(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        if (!$this->emptyOrWater($region->get($x, $y, $z))) {
            return false;
        }
        $height = Providers::int($feature['height'], $random);
        $wetness = Providers::float($feature['wetness'], $random);
        $density = Providers::float($feature['density'], $random);
        $xRadius = Providers::int($feature['radius'], $random);
        $zRadius = Providers::int($feature['radius'], $random);
        for ($dx = -$xRadius; $dx <= $xRadius; ++$dx) {
            for ($dz = -$zRadius; $dz <= $zRadius; ++$dz) {
                $fromEdge = min($xRadius - abs($dx), $zRadius - abs($dz));
                // Mth.clampedMap over floats.
                $t = F32::r($fromEdge / (float) $feature['max_distance_from_edge_affecting_chance_of_speleothem']);
                $atMax = F32::r((float) $feature['chance_of_speleothem_at_max_distance_from_center']);
                $chance = $t < 0.0 ? $atMax : ($t > 1.0 ? 1.0 : F32::r($atMax + F32::r($t * F32::r(1.0 - $atMax))));
                $this->speleothemColumn($feature, $region, $random, $x + $dx, $y, $z + $dz, $dx, $dz, $wetness, $chance, $height, $density);
            }
        }
        return true;
    }

    private function speleothemColumn(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z, int $dx, int $dz, float $wetness, float $chance, int $clusterHeight, float $density): void
    {
        $scan = self::columnScan($region, $x, $y, $z, $feature['floor_to_ceiling_search_range'], $this->emptyOrWater(...), fn (int $s): bool => !$this->emptyOrWater($s));
        if ($scan === null) {
            return;
        }
        [$ceiling, $floor] = $scan;
        if ($ceiling === null && $floor === null) {
            return;
        }
        $isLava = fn (int $py): bool => $this->blocks->name($region->get($x, $py, $z)) === 'lava';
        if ($random->nextFloat() < $wetness && $floor !== null && $this->canPlacePool($feature, $region, $x, $floor, $z)) {
            $region->set($x, $floor, $z, $this->blocks->state('water'));
            --$floor;
        }
        $stalactite = 0;
        if ($ceiling !== null && $random->nextDouble() < $chance && !$isLava($ceiling)) {
            $this->replaceWithBase($feature, $region, $x, $ceiling, $z, Providers::int($feature['speleothem_block_layer_thickness'], $random), 1);
            $max = $floor !== null ? min($clusterHeight, $ceiling - $floor) : $clusterHeight;
            $stalactite = $this->speleothemHeight($feature, $random, $dx, $dz, $density, $max);
        }
        $stalagmite = 0;
        if ($floor !== null && $random->nextDouble() < $chance && !$isLava($floor)) {
            $this->replaceWithBase($feature, $region, $x, $floor, $z, Providers::int($feature['speleothem_block_layer_thickness'], $random), -1);
            $diff = $feature['max_stalagmite_stalactite_height_diff'];
            $stalagmite = $ceiling !== null
                ? max(0, $stalactite + $random->nextInt(2 * $diff + 1) - $diff)
                : $this->speleothemHeight($feature, $random, $dx, $dz, $density, $clusterHeight);
        }
        if ($ceiling !== null && $floor !== null && $ceiling - $stalactite <= $floor + $stalagmite) {
            $lowest = max($ceiling - $stalactite, $floor + 1);
            $highest = min($floor + $stalagmite, $ceiling - 1);
            $bottom = $random->nextInt($highest + 1 - $lowest + 1) + $lowest;
            $stalactite = $ceiling - $bottom;
            $stalagmite = $bottom - 1 - $floor;
        }
        $merge = $random->nextBoolean() && $stalactite > 0 && $stalagmite > 0 && $ceiling !== null && $floor !== null
            && $stalactite + $stalagmite === $ceiling - $floor - 1;
        if ($ceiling !== null) {
            $this->growSpeleothem($feature, $region, $x, $ceiling - 1, $z, -1, $stalactite, $merge);
        }
        if ($floor !== null) {
            $this->growSpeleothem($feature, $region, $x, $floor + 1, $z, 1, $stalagmite, $merge);
        }
    }

    private function speleothemHeight(array $feature, RandomSource $random, int $dx, int $dz, float $density, int $max): int
    {
        if ($random->nextFloat() > $density) {
            return 0;
        }
        $distance = abs($dx) + abs($dz);
        // Mth.clampedMap(distance, 0, maxDistance, max / 2, 0), as a float mean.
        $t = $distance / (float) $feature['max_distance_from_center_affecting_height_bias'];
        $mean = F32::r($t < 0.0 ? $max / 2.0 : ($t > 1.0 ? 0.0 : $max / 2.0 + $t * (0.0 - $max / 2.0)));
        $value = F32::r($mean + F32::r(F32::r($random->nextGaussian()) * F32::r((float) $feature['height_deviation'])));
        return (int) ($value < 0.0 ? 0.0 : min($value, (float) $max));
    }

    private function canPlacePool(array $feature, Region $region, int $x, int $y, int $z): bool
    {
        $state = $region->get($x, $y, $z);
        if ($this->blocks->name($state) === 'water' || $this->blocks->is($state, $feature['base_block']) || $this->blocks->is($state, $feature['pointed_block'])) {
            return false;
        }
        if ($this->blocks->isWater($region->get($x, $y + 1, $z))) {
            return false;
        }
        $adjacent = fn (int $s): bool => $this->blocks->inTag($s, 'base_stone_overworld') || $this->blocks->isWater($s);
        foreach (self::HORIZONTAL as [$dx, $dz]) {
            if (!$adjacent($region->get($x + $dx, $y, $z + $dz))) {
                return false;
            }
        }
        return $adjacent($region->get($x, $y - 1, $z));
    }

    private function replaceWithBase(array $feature, Region $region, int $x, int $y, int $z, int $count, int $step): void
    {
        for ($i = 0; $i < $count; ++$i) {
            if (!$this->placeBaseBlock($feature, $region, $x, $y, $z)) {
                return;
            }
            $y += $step;
        }
    }

    private function emptyWaterOrLava(int $state): bool
    {
        return $this->emptyOrWater($state) || $this->blocks->name($state) === 'lava';
    }

    /** LargeDripstoneFeature: a pair of large stalactite and stalagmite cones across a cave. */
    public function largeDripstone(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        if (!$this->emptyOrWater($region->get($x, $y, $z))) {
            return false;
        }
        $base = $feature['base_block'] ?? 'dripstone_block';
        $feature['base_block'] = $base;
        [$ceiling, $floor] = self::columnScan(
            $region,
            $x,
            $y,
            $z,
            $feature['floor_to_ceiling_search_range'] ?? 30,
            $this->emptyOrWater(...),
            fn (int $s): bool => $this->speleothemBase($feature, $s) || $this->blocks->name($s) === 'lava',
        ) ?? [null, null];
        if ($ceiling === null || $floor === null || $ceiling - $floor - 1 < 4) {
            return false;
        }
        [$minRadius, $maxRadius] = Providers::intBounds($feature['column_radius']);
        $byHeight = (int) F32::r(($ceiling - $floor - 1) * F32::r((float) $feature['max_column_radius_to_cave_height_ratio']));
        $radius = $random->nextInt(min(max($byHeight, $minRadius), $maxRadius) - $minRadius + 1) + $minRadius;
        $stalactite = ['y' => $ceiling - 1, 'up' => false, 'radius' => $radius, 'blunt' => Providers::float($feature['stalactite_bluntness'], $random), 'scale' => Providers::float($feature['height_scale'], $random)];
        $stalagmite = ['y' => $floor + 1, 'up' => true, 'radius' => $radius, 'blunt' => Providers::float($feature['stalagmite_bluntness'], $random), 'scale' => Providers::float($feature['height_scale'], $random)];
        $minWindRadius = $feature['min_radius_for_wind'];
        $minWindBlunt = F32::r((float) $feature['min_bluntness_for_wind']);
        $wind = null;
        if ($stalactite['radius'] >= $minWindRadius && $stalactite['blunt'] >= $minWindBlunt && $stalagmite['radius'] >= $minWindRadius && $stalagmite['blunt'] >= $minWindBlunt) {
            $speed = Providers::float($feature['wind_speed'], $random);
            $direction = F32::r($random->nextFloat() * F32::r(M_PI));
            $wind = [$y, Trees::mthCos($direction) * $speed, Trees::mthSin($direction) * $speed, 16 - $radius];
        }
        $offset = static function (int $px, int $py, int $pz) use ($wind): array {
            if ($wind === null) {
                return [$px, $pz];
            }
            $dy = $wind[0] - $py;
            $limit = $wind[3];
            return [
                $px + max(-$limit, min($limit, (int) floor(F32::r($wind[1]) * $dy))),
                $pz + max(-$limit, min($limit, (int) floor(F32::r($wind[2]) * $dy))),
            ];
        };
        $embedded = [];
        foreach ([&$stalactite, &$stalagmite] as &$cone) {
            $embedded[] = $this->embedDripstone($region, $x, $z, $cone, $offset);
        }
        unset($cone);
        if ($embedded[0]) {
            $this->placeDripstone($region, $random, $x, $z, $stalactite, $offset);
        }
        if ($embedded[1]) {
            $this->placeDripstone($region, $random, $x, $z, $stalagmite, $offset);
        }
        return true;
    }

    /** SpeleothemUtils.getSpeleothemHeight */
    private static function coneHeight(float $distance, array $cone): int
    {
        $distance = max($distance, $cone['blunt']);
        $r = $distance / $cone['radius'] * 0.384;
        $height = max($cone['scale'] * (0.75 * $r ** 1.3333333333333333 - $r ** 0.6666666666666666 - 0.3333333333333333 * log($r)), 0.0);
        return (int) ($height / 0.384 * $cone['radius']);
    }

    /** moveBackUntilBaseIsInsideStoneAndShrinkRadiusIfNecessary */
    private function embedDripstone(Region $region, int $x, int $z, array &$cone, \Closure $offset): bool
    {
        while ($cone['radius'] > 1) {
            $py = $cone['y'];
            $tries = min(10, self::coneHeight(0.0, $cone));
            for ($i = 0; $i < $tries; ++$i) {
                if ($this->blocks->name($region->get($x, $py, $z)) === 'lava') {
                    return false;
                }
                [$cx, $cz] = $offset($x, $py, $z);
                if ($this->circleInStone($region, $cx, $py, $cz, $cone['radius'])) {
                    $cone['y'] = $py;
                    return true;
                }
                $py += $cone['up'] ? -1 : 1;
            }
            $cone['radius'] = intdiv($cone['radius'], 2);
        }
        return false;
    }

    private function circleInStone(Region $region, int $x, int $y, int $z, int $radius): bool
    {
        if ($this->emptyWaterOrLava($region->get($x, $y, $z))) {
            return false;
        }
        $increment = F32::r(6.0 / $radius);
        $limit = F32::r(M_PI * 2);
        for ($angle = 0.0; $angle < $limit; $angle = F32::r($angle + $increment)) {
            $dx = (int) F32::r(Trees::mthCos($angle) * $radius);
            $dz = (int) F32::r(Trees::mthSin($angle) * $radius);
            if ($this->emptyWaterOrLava($region->get($x + $dx, $y, $z + $dz))) {
                return false;
            }
        }
        return true;
    }

    private function placeDripstone(Region $region, RandomSource $random, int $x, int $z, array $cone, \Closure $offset): void
    {
        $dripstone = $this->blocks->state('dripstone_block');
        $radius = $cone['radius'];
        for ($dx = -$radius; $dx <= $radius; ++$dx) {
            for ($dz = -$radius; $dz <= $radius; ++$dz) {
                $distance = F32::r(sqrt($dx * $dx + $dz * $dz));
                if ($distance > $radius) {
                    continue;
                }
                $height = self::coneHeight($distance, $cone);
                if ($height <= 0) {
                    continue;
                }
                if ($random->nextFloat() < 0.2) {
                    $height = (int) F32::r($height * F32::r($random->nextFloat() * F32::r(0.2) + F32::r(0.8)));
                }
                [$px, $py, $pz] = [$x + $dx, $cone['y'], $z + $dz];
                $out = false;
                $maxY = $cone['up'] ? $region->height(Region::WORLD_SURFACE_WG, $px, $pz) : PHP_INT_MAX;
                for ($i = 0; $i < $height && $py < $maxY; ++$i) {
                    [$wx, $wz] = $offset($px, $py, $pz);
                    $state = $region->get($wx, $py, $wz);
                    if ($this->emptyWaterOrLava($state)) {
                        $out = true;
                        $region->set($wx, $py, $wz, $dripstone);
                    } elseif ($out && $this->blocks->inTag($state, 'base_stone_overworld')) {
                        break;
                    }
                    $py += $cone['up'] ? 1 : -1;
                }
            }
        }
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
