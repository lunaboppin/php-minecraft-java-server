<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\BiomeClimate;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/**
 * ChunkGenerator.applyBiomeDecoration: each step's placed features in the generator's global
 * order, seeded from the chunk's decoration seed, placed through their placement modifiers.
 */
final class Decorator
{
    /** @var list<list<string>> */
    private readonly array $order;
    /** @var array<string, array<string, true>> biome => placed features */
    private array $biomeFeatures = [];
    /** @var array<string, list<list<string>>> */
    private array $biomeSteps = [];
    /** @var array<string, array> */
    private array $json = [];
    public readonly Predicates $predicates;
    public readonly Providers $providers;
    public readonly Survival $survival;
    private ?Trees $trees = null;
    private ?Patches $patches = null;
    /** @var array<string, int> features not implemented yet, by type */
    public array $skipped = [];

    public function __construct(
        public readonly Blocks $blocks,
        private readonly BiomeClimate $climate,
        private readonly string $directory,
    ) {
        $this->order = json_decode((string) file_get_contents($directory . '/feature-order.json'), true, 512, JSON_THROW_ON_ERROR)['steps'];
        $this->predicates = new Predicates($blocks);
        $this->survival = new Survival($blocks, $directory . '/block-behaviour.json');
        $this->predicates->survival = $this->survival;
        $this->providers = new Providers($blocks, $directory, $this->predicates);
    }

    public function load(string $kind, string $name): array
    {
        $name = preg_replace('/^minecraft:/', '', $name);
        return $this->json["$kind/$name"] ??= json_decode((string) file_get_contents($this->directory . "/$kind/$name.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<list<string>> */
    private function steps(string $biome): array
    {
        if (!isset($this->biomeSteps[$biome])) {
            $steps = $this->load('biome', $biome)['features'] ?? [];
            $this->biomeSteps[$biome] = array_map(static fn ($step): array => array_map(static fn (string $f): string => preg_replace('/^minecraft:/', '', $f), (array) $step), $steps);
            $this->biomeFeatures[$biome] = array_fill_keys(array_merge([], ...$this->biomeSteps[$biome]), true);
        }
        return $this->biomeSteps[$biome];
    }

    /** Decorate the region's center chunk. */
    public function decorate(Region $region): void
    {
        $random = new WorldgenRandom();
        $originX = $region->centerX * 16;
        $originZ = $region->centerZ * 16;
        $decorationSeed = $random->setDecorationSeed($region->seed, $originX, $originZ);
        $possible = [];
        for ($dz = -1; $dz <= 1; ++$dz) {
            for ($dx = -1; $dx <= 1; ++$dx) {
                $chunk = $region->chunk($region->centerX + $dx, $region->centerZ + $dz);
                foreach (array_unique($chunk->biomes) as $biome) {
                    $possible[$biome] = true;
                }
            }
        }
        $origin = [$originX, $region->minY, $originZ];
        foreach ($this->order as $step => $features) {
            $indexes = [];
            $index = array_flip($features);
            foreach (array_keys($possible) as $biome) {
                foreach ($this->steps($biome)[$step] ?? [] as $feature) {
                    $indexes[$index[$feature]] = true;
                }
            }
            $indexes = array_keys($indexes);
            sort($indexes);
            foreach ($indexes as $featureIndex) {
                $random->setFeatureSeed($decorationSeed, $featureIndex, $step);
                $this->placePlaced($features[$featureIndex], $region, $random, $origin, $features[$featureIndex]);
            }
        }
    }

    /**
     * FeaturePlacer.place: modifiers expand positions depth first, and each final position
     * places the configured feature.
     */
    public function placePlaced(mixed $placed, Region $region, RandomSource $random, array $origin, ?string $top = null): bool
    {
        if (is_string($placed)) {
            $placed = $this->load('placed_feature', $placed);
        }
        $feature = $placed['feature'];
        $placement = $placed['placement'] ?? [];
        if ($placement === []) {
            return $this->place($feature, $region, $random, ...$origin);
        }
        $placedAny = false;
        $stack = [[$origin, 0]];
        while ($stack !== []) {
            [$position, $modifierIndex] = array_pop($stack);
            $modified = $this->modify($placement[$modifierIndex], $region, $random, $position, $top);
            if ($modifierIndex + 1 < count($placement)) {
                for ($i = count($modified) - 1; $i >= 0; --$i) {
                    $stack[] = [$modified[$i], $modifierIndex + 1];
                }
            } else {
                foreach ($modified as $next) {
                    $placedAny = $this->place($feature, $region, $random, ...$next) || $placedAny;
                }
            }
        }
        return $placedAny;
    }

    /** @return list<array{0: int, 1: int, 2: int}> */
    private function modify(array $modifier, Region $region, RandomSource $random, array $position, ?string $top): array
    {
        [$x, $y, $z] = $position;
        switch (preg_replace('/^minecraft:/', '', $modifier['type'])) {
            case 'count':
                return array_fill(0, max(0, Providers::int($modifier['count'], $random)), $position);
            case 'in_square':
                $nx = $random->nextInt(16) + $x;
                return [[$nx, $y, $random->nextInt(16) + $z]];
            case 'heightmap':
                $height = $region->height($modifier['heightmap'], $x, $z);
                return $height > $region->minY ? [[$x, $height, $z]] : [];
            case 'height_range':
                return [[$x, Providers::height($modifier['height'], $random, $region), $z]];
            case 'biome':
                $biome = $region->biome($x, $y, $z);
                $this->steps($biome);
                return isset($this->biomeFeatures[$biome][$top]) ? [$position] : [];
            case 'block_predicate_filter':
                return $this->predicates->test($modifier['predicate'], $region, $x, $y, $z) ? [$position] : [];
            case 'offset':
                $dx = Providers::int($modifier['x'] ?? 0, $random);
                $dy = Providers::int($modifier['y'] ?? 0, $random);
                return [[$x + $dx, $y + $dy, $z + Providers::int($modifier['z'] ?? 0, $random)]];
            case 'rarity_filter':
                return $random->nextFloat() < F32::r(1.0 / $modifier['chance']) ? [$position] : [];
            case 'random_chance':
                return $random->nextFloat() < F32::r((float) $modifier['chance']) ? [$position] : [];
            case 'surface_water_depth_filter':
                return $region->height(Region::WORLD_SURFACE, $x, $z) - $region->height(Region::OCEAN_FLOOR, $x, $z) <= $modifier['max_water_depth'] ? [$position] : [];
            case 'surface_relative_threshold_filter':
                $surface = $region->height($modifier['heightmap'], $x, $z);
                return $surface + ($modifier['min_inclusive'] ?? PHP_INT_MIN - $surface) <= $y && $y <= $surface + ($modifier['max_inclusive'] ?? PHP_INT_MAX - $surface) ? [$position] : [];
            case 'noise_threshold_count':
                $noise = $this->climate->infoNoise($x / 200.0, $z / 200.0);
                return array_fill(0, $noise < $modifier['noise_level'] ? $modifier['below_noise'] : $modifier['above_noise'], $position);
            case 'noise_based_count':
                $noise = $this->climate->infoNoise($x / $modifier['noise_factor'], $z / $modifier['noise_factor']);
                return array_fill(0, max(0, (int) ceil(($noise + ($modifier['noise_offset'] ?? 0.0)) * $modifier['noise_to_count_ratio'])), $position);
            case 'environment_scan':
                return $this->environmentScan($modifier, $region, $position);
            case 'randomly_selected':
                return $this->modify($modifier['placements'][$random->nextInt(count($modifier['placements']))], $region, $random, $position, $top);
        }
        $this->skipped['placement ' . $modifier['type']] = ($this->skipped['placement ' . $modifier['type']] ?? 0) + 1;
        return [];
    }

    private function environmentScan(array $modifier, Region $region, array $position): array
    {
        [$x, $y, $z] = $position;
        $step = $modifier['direction_of_search'] === 'up' ? 1 : -1;
        $allowed = $modifier['allowed_search_condition'] ?? ['type' => 'true'];
        if (!$this->predicates->test($allowed, $region, $x, $y, $z)) {
            return [];
        }
        for ($i = 0; $i < $modifier['max_steps']; ++$i) {
            if ($this->predicates->test($modifier['target_condition'], $region, $x, $y, $z)) {
                return [[$x, $y, $z]];
            }
            $y += $step;
            if ($region->isOutsideBuildHeight($y)) {
                return [];
            }
            if (!$this->predicates->test($allowed, $region, $x, $y, $z)) {
                break;
            }
        }
        return $this->predicates->test($modifier['target_condition'], $region, $x, $y, $z) ? [[$x, $y, $z]] : [];
    }

    /** Feature.place for a configured feature, by name or inline. */
    public function place(mixed $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        if (is_string($feature)) {
            $feature = $this->load('feature', $feature);
        }
        $type = preg_replace('/^minecraft:/', '', $feature['type']);
        switch ($type) {
            case 'ore':
                return Ores::ore($this, $feature, $region, $random, $x, $y, $z);
            case 'scattered_ore':
                return Ores::scattered($this, $feature, $region, $random, $x, $y, $z);
            case 'simple_block':
                return $this->simpleBlock($feature, $region, $random, $x, $y, $z);
            case 'block_column':
                return $this->blockColumn($feature, $region, $random, $x, $y, $z);
            case 'random_selector':
                foreach ($feature['features'] as $weighted) {
                    if ($random->nextFloat() < F32::r((float) $weighted['chance'])) {
                        return $this->placePlaced($weighted['feature'], $region, $random, [$x, $y, $z]);
                    }
                }
                return $this->placePlaced($feature['default'], $region, $random, [$x, $y, $z]);
            case 'simple_random_selector':
                $features = $feature['features'];
                if (is_string($features)) {
                    $features = [$features];
                }
                return $this->placePlaced($features[$random->nextInt(count($features))], $region, $random, [$x, $y, $z]);
            case 'weighted_random_selector':
                $chosen = Providers::weighted($feature['features'], $random);
                return $chosen !== null && $this->placePlaced($chosen, $region, $random, [$x, $y, $z]);
            case 'random_boolean_selector':
                return $this->placePlaced($random->nextBoolean() ? $feature['feature_true'] : $feature['feature_false'], $region, $random, [$x, $y, $z]);
            case 'sequence':
                foreach ((array) $feature['features'] as $placed) {
                    if (!$this->placePlaced($placed, $region, $random, [$x, $y, $z])) {
                        return false;
                    }
                }
                return true;
            case 'disk':
                return $this->disk($feature, $region, $random, $x, $y, $z);
            case 'spring_feature':
                return $this->spring($feature, $region, $x, $y, $z);
            case 'freeze_top_layer':
                return $this->freeze($region, $x, $z);
            case 'tree':
                if (!class_exists(Trees::class)) {
                    break;
                }
                return ($this->trees ??= new Trees($this))->tree($feature, $region, $random, $x, $y, $z);
            case 'vegetation_patch':
            case 'waterlogged_vegetation_patch':
                return ($this->patches ??= new Patches($this))->vegetationPatch($feature, $region, $random, $x, $y, $z, $type === 'waterlogged_vegetation_patch');
            case 'lake':
                return ($this->patches ??= new Patches($this))->lake($feature, $region, $random, $x, $y, $z);
            case 'vines':
                return ($this->patches ??= new Patches($this))->vines($region, $x, $y, $z);
            case 'block_blob':
                return ($this->patches ??= new Patches($this))->blockBlob($feature, $region, $random, $x, $y, $z);
            case 'underwater_magma':
                return ($this->patches ??= new Patches($this))->underwaterMagma($feature, $region, $random, $x, $y, $z);
            case 'multiface_growth':
                return ($this->patches ??= new Patches($this))->multifaceGrowth($feature, $region, $random, $x, $y, $z);
            case 'huge_red_mushroom':
            case 'huge_brown_mushroom':
                return ($this->patches ??= new Patches($this))->hugeMushroom($feature, $region, $random, $x, $y, $z, $type === 'huge_red_mushroom');
            case 'monster_room':
                return ($this->patches ??= new Patches($this))->monsterRoom($region, $random, $x, $y, $z);
            case 'geode':
                return ($this->patches ??= new Patches($this))->geode($feature, $region, $random, $x, $y, $z);
            case 'fallen_tree':
                return ($this->trees ??= new Trees($this))->fallenTree($feature, $region, $random, $x, $y, $z);
            case 'bamboo':
                return ($this->patches ??= new Patches($this))->bamboo($feature, $region, $random, $x, $y, $z);
            case 'speleothem':
                return ($this->patches ??= new Patches($this))->speleothem($feature, $region, $random, $x, $y, $z);
            case 'speleothem_cluster':
                return ($this->patches ??= new Patches($this))->speleothemCluster($feature, $region, $random, $x, $y, $z);
            case 'no_op':
                return false;
        }
        $this->skipped[$type] = ($this->skipped[$type] ?? 0) + 1;
        return false;
    }

    private function simpleBlock(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $state = $this->providers->optionalState($feature['to_place'], $region, $random, $x, $y, $z);
        if ($state === null || !$this->survival->canSurvive($state, $region, $x, $y, $z)) {
            return false;
        }
        if ($this->survival->isA($state, 'DoublePlantBlock')) {
            $above = $region->get($x, $y + 1, $z);
            if (!$this->blocks->isAir($above)
                && ($this->blocks->hasFluid($state) !== $this->blocks->hasFluid($above) || !$this->blocks->replaceable($above))) {
                return false;
            }
            $this->placeDoublePlant($region, $state, $x, $y, $z);
            return true;
        }
        $region->set($x, $y, $z, $state);
        return true;
    }

    /** DoublePlantBlock.placeAt, copying water into waterloggable halves. */
    public function placeDoublePlant(Region $region, int $state, int $x, int $y, int $z): void
    {
        foreach ([[0, 'lower'], [1, 'upper']] as [$dy, $half]) {
            $half = $this->blocks->with($state, 'half', $half);
            if ($this->blocks->has($half, 'waterlogged')) {
                $here = $region->get($x, $y + $dy, $z);
                $half = $this->blocks->with($half, 'waterlogged', $this->blocks->isWater($here) && $this->blocks->isSource($here) ? 'true' : 'false');
            }
            $region->set($x, $y + $dy, $z, $half);
        }
    }

    private function blockColumn(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $direction = array_search($feature['direction'], ['down', 'up', 'north', 'south', 'west', 'east'], true);
        [$dx, $dy, $dz] = Blocks::OFFSETS[$direction];
        $heights = [];
        $total = 0;
        foreach ($feature['layers'] as $layer) {
            $heights[] = Providers::int($layer['height'], $random);
            $total += end($heights);
        }
        if ($total === 0) {
            return false;
        }
        $nx = $x + $dx;
        $ny = $y + $dy;
        $nz = $z + $dz;
        for ($i = 0; $i < $total; ++$i) {
            if (!$this->predicates->test($feature['allowed_placement'], $region, $nx, $ny, $nz)) {
                // BlockColumnFeature.truncate
                $remove = $total - $i;
                $tip = $feature['prioritize_tip'] ?? false;
                $order = $tip ? array_keys($heights) : array_reverse(array_keys($heights));
                foreach ($order as $layer) {
                    if ($remove <= 0) {
                        break;
                    }
                    $taken = min($heights[$layer], $remove);
                    $remove -= $taken;
                    $heights[$layer] -= $taken;
                }
                break;
            }
            $nx += $dx;
            $ny += $dy;
            $nz += $dz;
        }
        foreach ($feature['layers'] as $index => $layer) {
            for ($i = 0; $i < $heights[$index]; ++$i) {
                $region->set($x, $y, $z, $this->providers->state($layer['provider'] ?? $layer['state'], $region, $random, $x, $y, $z));
                $x += $dx;
                $y += $dy;
                $z += $dz;
            }
        }
        return true;
    }

    private function disk(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $placedAny = false;
        $top = $y + $feature['half_height'];
        $bottom = $y - $feature['half_height'] - 1;
        $r = Providers::int($feature['radius'], $random);
        for ($cz = $z - $r; $cz <= $z + $r; ++$cz) {
            for ($cx = $x - $r; $cx <= $x + $r; ++$cx) {
                $xd = $cx - $x;
                $zd = $cz - $z;
                if ($xd * $xd + $zd * $zd > $r * $r) {
                    continue;
                }
                for ($cy = $top; $cy > $bottom; --$cy) {
                    if ($this->predicates->test($feature['target'], $region, $cx, $cy, $cz)) {
                        $state = $this->providers->optionalState($feature['state_provider'], $region, $random, $cx, $cy, $cz);
                        if ($state !== null) {
                            $region->set($cx, $cy, $cz, $state);
                            $placedAny = true;
                        }
                    }
                }
            }
        }
        return $placedAny;
    }

    private function spring(array $feature, Region $region, int $x, int $y, int $z): bool
    {
        $valid = static fn (int $state, Blocks $blocks): bool => in_array($blocks->name($state), array_map(static fn (string $b): string => preg_replace('/^minecraft:/', '', $b), (array) $feature['valid_blocks']), true);
        $blocks = $this->blocks;
        if (!$valid($region->get($x, $y + 1, $z), $blocks)) {
            return false;
        }
        if (($feature['requires_block_below'] ?? true) && !$valid($region->get($x, $y - 1, $z), $blocks)) {
            return false;
        }
        $current = $region->get($x, $y, $z);
        if (!$blocks->isAir($current) && !$valid($current, $blocks)) {
            return false;
        }
        $rocks = 0;
        $holes = 0;
        foreach ([[-1, 0, 0], [1, 0, 0], [0, 0, -1], [0, 0, 1], [0, -1, 0]] as [$dx, $dy, $dz]) {
            $neighbour = $region->get($x + $dx, $y + $dy, $z + $dz);
            $rocks += $valid($neighbour, $blocks) ? 1 : 0;
            $holes += $blocks->isAir($neighbour) ? 1 : 0;
        }
        if ($rocks !== ($feature['rock_count'] ?? 4) || $holes !== ($feature['hole_count'] ?? 1)) {
            return false;
        }
        $state = $feature['state'];
        $name = preg_replace('/^minecraft:/', '', is_string($state) ? $state : ($state['Name'] ?? $state['id']));
        $region->set($x, $y, $z, $blocks->state($name === 'flowing_water' ? 'water' : ($name === 'flowing_lava' ? 'lava' : $name)));
        return true;
    }

    /** SnowAndFreezeFeature over the chunk at the origin. */
    private function freeze(Region $region, int $x, int $z): bool
    {
        $blocks = $this->blocks;
        $ice = $blocks->state('ice');
        $snow = $blocks->state('snow');
        for ($dx = 0; $dx < 16; ++$dx) {
            for ($dz = 0; $dz < 16; ++$dz) {
                $bx = $x + $dx;
                $bz = $z + $dz;
                $y = $region->height(Region::MOTION_BLOCKING, $bx, $bz);
                $biome = $region->biome($bx, $y, $bz);
                $below = $region->get($bx, $y - 1, $bz);
                $cold = !($this->climate->temperature($biome, $bx, $y - 1, $bz, $region->seaLevel) >= F32::r(0.15));
                if ($cold && !$region->isOutsideBuildHeight($y - 1) && $blocks->name($below) === 'water' && $blocks->isSource($below)) {
                    $region->set($bx, $y - 1, $bz, $ice);
                }
                $precipitation = $this->load('biome', $biome)['has_precipitation'] ?? true;
                if ($precipitation && !($this->climate->temperature($biome, $bx, $y, $bz, $region->seaLevel) >= F32::r(0.15)) && !$region->isOutsideBuildHeight($y)) {
                    $here = $region->get($bx, $y, $bz);
                    if (($blocks->isAir($here) || $blocks->name($here) === 'snow') && $this->survival->canSurvive($snow, $region, $bx, $y, $bz)) {
                        $region->set($bx, $y, $bz, $snow);
                        $below = $region->get($bx, $y - 1, $bz);
                        if ($blocks->has($below, 'snowy')) {
                            $region->set($bx, $y - 1, $bz, $blocks->with($below, 'snowy', 'true'));
                        }
                    }
                }
            }
        }
        return true;
    }
}
