<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

use PhpMinecraftJavaServer\World\Generation\PositionalRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;

/**
 * material.MaterialSystem and MaterialRuleContext: the Overworld material rules (surface
 * blocks, bedrock, deepslate, ore veins), applied column by column from the top down.
 */
final class MaterialSystem
{
    private const TERRACOTTA_BANDS = 192;

    /** @var list<string> */
    private readonly array $clayBands;

    private readonly PositionalRandom $noiseRandom;

    private readonly BiomeClimate $climate;

    // MaterialRuleContext state, reset for each chunk.
    private int $lastUpdateXZ = 0;

    private int $lastUpdateY = 0;

    private int $blockX = 0;

    private int $blockY = 0;

    private int $blockZ = 0;

    private int $gradientX = 0;

    private int $gradientZ = 0;

    private int $surfaceDepth = 0;

    private int $waterHeight = PHP_INT_MIN;

    private int $stoneBelow = 0;

    private int $stoneAbove = 0;

    private ?string $biome = null;

    private int $secondaryUpdate = -1;

    private float $secondary = 0.0;

    private int $minSurfaceUpdate = -1;

    private int $minSurface = 0;

    private ?array $preliminarySurface = null;

    private ?Volume $preliminaryVolume = null;

    private ?Volume $expectedVolume = null;

    private ?NoiseChunk $noiseChunk = null;

    private ?\Closure $biomeAt = null;

    private ?array $possibleBiomes = null;

    /** @var array<string, array> */
    private array $ruleJson = [];

    /** @var array<string, array> */
    private array $conditionJson = [];

    public function __construct(private readonly OverworldSettings $settings)
    {
        $state = $settings->state;
        $this->noiseRandom = $state->random;
        $this->climate = new BiomeClimate($settings->directory);
        $this->clayBands = self::bands($state->random->fromHashOf('minecraft:clay_bands'));
    }

    public function climate(): BiomeClimate
    {
        return $this->climate;
    }

    private static function bands(RandomSource $random): array
    {
        $bands = array_fill(0, self::TERRACOTTA_BANDS, 'terracotta');
        for ($i = 0; $i < self::TERRACOTTA_BANDS; ++$i) {
            $i += $random->nextInt(5) + 1;
            if ($i < self::TERRACOTTA_BANDS) {
                $bands[$i] = 'orange_terracotta';
            }
        }
        foreach ([[1, 'yellow_terracotta'], [2, 'brown_terracotta'], [1, 'red_terracotta']] as [$width, $state]) {
            $count = $random->nextInt(15 - 6 + 1) + 6;
            for ($i = 0; $i < $count; ++$i) {
                $bandWidth = $width + $random->nextInt(3);
                $start = $random->nextInt(self::TERRACOTTA_BANDS);
                for ($p = 0; $start + $p < self::TERRACOTTA_BANDS && $p < $bandWidth; ++$p) {
                    $bands[$start + $p] = $state;
                }
            }
        }
        $white = $random->nextInt(15 - 9 + 1) + 9;
        for ($i = 0, $start = 0; $i < $white && $start < self::TERRACOTTA_BANDS; $start += $random->nextInt(16) + 4) {
            $bands[$start] = 'white_terracotta';
            if ($start - 1 > 0 && $random->nextBoolean()) {
                $bands[$start - 1] = 'light_gray_terracotta';
            }
            if ($start + 1 < self::TERRACOTTA_BANDS && $random->nextBoolean()) {
                $bands[$start + 1] = 'light_gray_terracotta';
            }
            ++$i;
        }
        return $bands;
    }

    private function band(int $x, int $y, int $z): string
    {
        $offset = (int) floor(F32::r($this->settings->state->noise('clay_bands_offset')->get($x, 0.0, $z) * 4.0) + 0.5);
        return $this->clayBands[($y + $offset + self::TERRACOTTA_BANDS) % self::TERRACOTTA_BANDS];
    }

    private function surfaceDepthAt(int $x, int $z): int
    {
        $noise = $this->settings->state->noise('surface')->get($x, 0.0, $z);
        return (int) ($noise * 2.75 + 3.0 + $this->noiseRandom->at($x, 0, $z)->nextDouble() * 0.25);
    }

    private static function isAir(string $state): bool
    {
        return $state === 'air' || $state === 'cave_air' || $state === 'void_air';
    }

    private static function isFluid(string $state): bool
    {
        return $state === 'water' || $state === 'lava';
    }

    /**
     * MaterialSystem.buildSurface on blocks in y, z, x order.
     *
     * @param list<string> $blocks
     * @param \Closure(int, int, int): string $biomeAt BiomeManager.getBiome
     * @param list<string> $possibleBiomes biomes of this and the eight neighbouring chunks
     */
    public function buildSurface(array &$blocks, NoiseChunk $noiseChunk, \Closure $biomeAt, array $possibleBiomes): void
    {
        $minY = $this->settings->minY;
        $volume = $noiseChunk->volume;
        $heights = self::heights($blocks, $minY);
        $highestSection = -1;
        foreach (array_chunk($blocks, 4096) as $index => $section) {
            foreach ($section as $state) {
                if (!self::isAir($state)) {
                    $highestSection = $index;
                    break;
                }
            }
        }
        $maxBlockY = ($highestSection + ($minY >> 4)) * 16 + 15;
        $this->noiseChunk = $noiseChunk;
        $this->biomeAt = $biomeAt;
        $this->possibleBiomes = array_flip($possibleBiomes);
        $this->expectedVolume = new Volume($volume->sizeX, max($maxBlockY - $volume->minY + 1, 1), $volume->sizeZ, $volume->minX, $volume->minY, $volume->minZ);
        $this->preliminaryVolume = new Volume($volume->sizeX, 1, $volume->sizeZ, $volume->minX, 0, $volume->minZ);
        $this->preliminarySurface = null;
        $this->lastUpdateXZ = PHP_INT_MIN + 1;
        $this->lastUpdateY = PHP_INT_MIN + 1;
        $this->secondaryUpdate = $this->lastUpdateXZ - 1;
        $this->minSurfaceUpdate = $this->lastUpdateXZ - 1;
        $rule = $this->compileRule($this->settings->json['material_rule']);
        $maxY = $minY + $this->settings->height - 1;
        for ($x = 0; $x < 16; ++$x) {
            for ($z = 0; $z < 16; ++$z) {
                $bx = $volume->minX + $x;
                $bz = $volume->minZ + $z;
                $start = $heights[($z << 4) | $x] + 1;
                $surfaceBiome = $biomeAt($bx, $start, $bz);
                if ($surfaceBiome === 'eroded_badlands') {
                    $this->erodedBadlands($blocks, $x, $z, $bx, $bz, $start);
                    $heights[($z << 4) | $x] = self::columnHeight($blocks, ($z << 4) | $x, $minY);
                }
                $height = $heights[($z << 4) | $x] + 1;
                $gradientX = $heights[($z << 4) | min($x + 1, 15)] - $heights[($z << 4) | max($x - 1, 0)];
                $gradientZ = $heights[(min($z + 1, 15) << 4) | $x] - $heights[(max($z - 1, 0) << 4) | $x];
                $this->updateXZ($bx, $bz, $gradientX, $gradientZ);
                $stoneAbove = 0;
                $waterHeight = PHP_INT_MIN;
                $nextCeiling = PHP_INT_MAX;
                for ($y = $height; $y >= $minY; --$y) {
                    $old = $y > $maxY ? 'air' : $blocks[(($y - $minY) << 8) | ($z << 4) | $x];
                    if (self::isAir($old)) {
                        $stoneAbove = 0;
                        $waterHeight = PHP_INT_MIN;
                    } elseif (self::isFluid($old)) {
                        if ($waterHeight === PHP_INT_MIN) {
                            $waterHeight = $y + 1;
                        }
                    } else {
                        if ($nextCeiling >= $y) {
                            $nextCeiling = -32512;
                            for ($look = $y - 1; $look >= $minY - 1; --$look) {
                                $next = $look < $minY ? 'void_air' : $blocks[(($look - $minY) << 8) | ($z << 4) | $x];
                                if (self::isAir($next) || self::isFluid($next)) {
                                    $nextCeiling = $look + 1;
                                    break;
                                }
                            }
                        }
                        ++$stoneAbove;
                        $this->updateY($stoneAbove, $y - $nextCeiling + 1, $waterHeight, $y);
                        $state = $rule($bx, $y, $bz);
                        if ($state !== null) {
                            $blocks[(($y - $minY) << 8) | ($z << 4) | $x] = $state;
                        }
                    }
                }
                if ($surfaceBiome === 'frozen_ocean' || $surfaceBiome === 'deep_frozen_ocean') {
                    $this->frozenOcean($blocks, $this->minSurfaceLevel(), $surfaceBiome, $x, $z, $bx, $bz, $start);
                }
                // ProtoChunk.setBlockState keeps WORLD_SURFACE_WG current for later columns' gradients.
                $heights[($z << 4) | $x] = self::columnHeight($blocks, ($z << 4) | $x, $minY);
            }
        }
    }

    /**
     * MaterialSystem.topMaterial: the surface rule for one block exposed by a carver, in a
     * fresh rule context without biome pruning.
     */
    public function topMaterial(array $blocks, NoiseChunk $noiseChunk, \Closure $biomeAt, int $x, int $y, int $z, bool $underFluid): ?string
    {
        $minY = $this->settings->minY;
        $this->noiseChunk = $noiseChunk;
        $this->biomeAt = $biomeAt;
        $this->possibleBiomes = null;
        $this->expectedVolume = new Volume(1, 1, 1, $x, $y, $z);
        $this->preliminaryVolume = new Volume(1, 1, 1, $x, 0, $z);
        $this->preliminarySurface = null;
        $this->lastUpdateXZ = PHP_INT_MIN + 1;
        $this->lastUpdateY = PHP_INT_MIN + 1;
        $this->secondaryUpdate = $this->lastUpdateXZ - 1;
        $this->minSurfaceUpdate = $this->lastUpdateXZ - 1;
        $rule = $this->compileRule($this->settings->json['material_rule']);
        $lx = $x & 15;
        $lz = $z & 15;
        $height = static fn (int $cx, int $cz): int => self::columnHeight($blocks, ($cz << 4) | $cx, $minY);
        $gradientX = $height(min($lx + 1, 15), $lz) - $height(max($lx - 1, 0), $lz);
        $gradientZ = $height($lx, min($lz + 1, 15)) - $height($lx, max($lz - 1, 0));
        $this->updateXZ($x, $z, $gradientX, $gradientZ);
        $this->updateY(1, 1, $underFluid ? $y + 1 : PHP_INT_MIN, $y);
        return $rule($x, $y, $z);
    }

    /**
     * WORLD_SURFACE_WG heights: the lowest Y above every non-air block, minus one; minY - 1
     * when a column is empty.
     *
     * @return list<int> indexed by (z << 4) | x
     */
    private static function heights(array $blocks, int $minY): array
    {
        $heights = [];
        for ($column = 0; $column < 256; ++$column) {
            $heights[] = self::columnHeight($blocks, $column, $minY);
        }
        return $heights;
    }

    private static function columnHeight(array $blocks, int $column, int $minY): int
    {
        for ($y = intdiv(count($blocks), 256) - 1; $y >= 0; --$y) {
            if (!self::isAir($blocks[($y << 8) | $column])) {
                return $y + $minY;
            }
        }
        return $minY - 1;
    }

    private function updateXZ(int $x, int $z, int $gradientX, int $gradientZ): void
    {
        ++$this->lastUpdateXZ;
        ++$this->lastUpdateY;
        $this->blockX = $x;
        $this->blockZ = $z;
        $this->gradientX = $gradientX;
        $this->gradientZ = $gradientZ;
        $this->surfaceDepth = $this->surfaceDepthAt($x, $z);
    }

    private function updateY(int $stoneAbove, int $stoneBelow, int $waterHeight, int $y): void
    {
        ++$this->lastUpdateY;
        $this->biome = null;
        $this->blockY = $y;
        $this->waterHeight = $waterHeight;
        $this->stoneBelow = $stoneBelow;
        $this->stoneAbove = $stoneAbove;
    }

    private function biome(): string
    {
        return $this->biome ??= ($this->biomeAt)($this->blockX, $this->blockY, $this->blockZ);
    }

    private function secondary(): float
    {
        if ($this->secondaryUpdate !== $this->lastUpdateXZ) {
            $this->secondaryUpdate = $this->lastUpdateXZ;
            $this->secondary = $this->settings->state->noise('surface_secondary')->get($this->blockX, 0.0, $this->blockZ);
        }
        return $this->secondary;
    }

    public function minSurfaceLevel(): int
    {
        if ($this->minSurfaceUpdate !== $this->lastUpdateXZ) {
            $this->minSurfaceUpdate = $this->lastUpdateXZ;
            $sampler = $this->settings->state->sampler($this->settings->json['noise_router']['chunk_surface_level']);
            $index = $this->preliminaryVolume->indexOfBlock($this->blockX, 0, $this->blockZ);
            if ($index !== -1) {
                $this->preliminarySurface ??= $sampler->volume($this->noiseChunk->context, $this->preliminaryVolume);
                $level = $this->preliminarySurface[$index];
            } else {
                $level = $sampler->value($this->noiseChunk->context, $this->blockX, 0, $this->blockZ);
            }
            $this->minSurface = (int) floor($level) + $this->surfaceDepth - 8;
        }
        return $this->minSurface;
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

    private function json(string $kind, string $name): array
    {
        $name = preg_replace('/^minecraft:/', '', $name);
        $cache = $kind === 'material_rule' ? 'ruleJson' : 'conditionJson';
        return $this->{$cache}[$name] ??= json_decode((string) file_get_contents($this->settings->directory . '/' . $kind . '/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function state(string $name): string
    {
        return preg_replace('/^minecraft:/', '', $name);
    }

    /** MaterialRule.compile: a closure returning a block name, or null to try the next rule. */
    private function compileRule(mixed $json): \Closure
    {
        if (is_string($json)) {
            return $this->compileRule($this->json('material_rule', $json));
        }
        switch (preg_replace('/^minecraft:/', '', $json['type'])) {
            case 'block':
                $state = self::state($json['result_state']);
                return static fn (int $x, int $y, int $z): string => $state;
            case 'bandlands':
                return $this->band(...);
            case 'sequence':
                if (count($json['sequence']) === 1) {
                    return $this->compileRule($json['sequence'][0]);
                }
                $rules = array_map($this->compileRule(...), $json['sequence']);
                return static function (int $x, int $y, int $z) use ($rules): ?string {
                    foreach ($rules as $rule) {
                        $state = $rule($x, $y, $z);
                        if ($state !== null) {
                            return $state;
                        }
                    }
                    return null;
                };
            case 'condition':
                $condition = $this->compileCondition($json['if_true']);
                $then = $this->compileRule($json['then_run']);
                return static fn (int $x, int $y, int $z): ?string => $condition() ? $then($x, $y, $z) : null;
            case 'ore_vein':
                return $this->oreVein($json);
        }
        throw new \LogicException('Unknown material rule ' . $json['type']);
    }

    private function densityGetter(mixed $function, bool $prefill): \Closure
    {
        $sampler = $this->settings->state->sampler($function);
        $context = $this->noiseChunk->context;
        if (!$prefill) {
            return fn (): float => $sampler->value($context, $this->blockX, $this->blockY, $this->blockZ);
        }
        $volume = $this->expectedVolume;
        $buffer = $sampler->volume($context, $volume);
        return function () use ($sampler, $context, $volume, $buffer): float {
            $index = $volume->indexOfBlock($this->blockX, $this->blockY, $this->blockZ);
            return $index === -1 ? $sampler->value($context, $this->blockX, $this->blockY, $this->blockZ) : $buffer[$index];
        };
    }

    private function oreVein(array $json): \Closure
    {
        $density = $this->densityGetter($json['density'], true);
        $richness = $this->densityGetter($json['richness'], true);
        $gap = $this->densityGetter($json['filler_gap'], false);
        $random = $this->settings->state->factory('minecraft:ore');
        $ore = self::state($json['ore_block']);
        $raw = self::state($json['raw_ore_block']);
        $filler = self::state($json['filler_block']);
        $rawChance = F32::r((float) $json['raw_ore_chance']);
        return static function (int $x, int $y, int $z) use ($density, $richness, $gap, $random, $ore, $raw, $filler, $rawChance): ?string {
            $d = $density();
            if ($d <= 0.0) {
                return null;
            }
            $r = $random->at($x, $y, $z);
            if ($r->nextFloat() > $d) {
                return null;
            }
            if ($r->nextFloat() < $richness() && $gap() < 0.0) {
                return $r->nextFloat() < $rawChance ? $raw : $ore;
            }
            return $filler;
        };
    }

    /** A condition re-evaluated once per column (XZ) or per block (Y). */
    private function lazy(bool $perBlock, \Closure $compute): \Closure
    {
        $last = ($perBlock ? $this->lastUpdateY : $this->lastUpdateXZ) - 1;
        $result = null;
        return function () use ($perBlock, $compute, &$last, &$result): bool {
            $update = $perBlock ? $this->lastUpdateY : $this->lastUpdateXZ;
            if ($update !== $last) {
                $last = $update;
                $result = $compute();
            }
            return $result;
        };
    }

    private function compileCondition(mixed $json): \Closure
    {
        if (is_string($json)) {
            return $this->compileCondition($this->json('material_condition', $json));
        }
        switch (preg_replace('/^minecraft:/', '', $json['type'])) {
            case 'not':
                $target = $this->compileCondition($json['invert']);
                return static fn (): bool => !$target();
            case 'above_preliminary_surface':
                return fn (): bool => $this->blockY >= $this->minSurfaceLevel();
            case 'biome':
                $biomes = array_flip(array_map(self::state(...), (array) $json['biome_is']));
                if ($this->possibleBiomes !== null && array_diff_key($biomes, $this->possibleBiomes) === $biomes) {
                    return static fn (): bool => false;
                }
                if ($this->possibleBiomes !== null && array_diff_key($this->possibleBiomes, $biomes) === []) {
                    return static fn (): bool => true;
                }
                return $this->lazy(true, fn (): bool => isset($biomes[$this->biome()]));
            case 'noise_threshold':
                $noise = $this->settings->state->noise($json['noise']);
                $min = (float) $json['min_threshold'];
                $max = (float) ($json['max_threshold'] ?? PHP_FLOAT_MAX);
                $is3d = (bool) ($json['is_3d'] ?? false);
                $last = null;
                $value = 0.0;
                return function () use ($noise, $min, $max, $is3d, &$last, &$value): bool {
                    $update = $is3d ? 'y' . $this->lastUpdateY : 'xz' . $this->lastUpdateXZ;
                    if ($update !== $last) {
                        $last = $update;
                        $value = $noise->get($this->blockX, $is3d ? $this->blockY : 0.0, $this->blockZ);
                    }
                    return $value >= $min && $value <= $max;
                };
            case 'hole':
                return $this->lazy(false, fn (): bool => $this->surfaceDepth <= 0);
            case 'steep':
                return $this->lazy(false, fn (): bool => $this->gradientX <= -4 || $this->gradientZ >= 4);
            case 'stone_depth':
                $ceiling = $json['surface_type'] === 'ceiling';
                $offset = $json['offset'];
                $addSurface = $json['add_surface_depth'];
                $range = $json['secondary_depth_range'];
                return $this->lazy(true, function () use ($ceiling, $offset, $addSurface, $range): bool {
                    $depth = $ceiling ? $this->stoneBelow : $this->stoneAbove;
                    $surface = $addSurface ? $this->surfaceDepth : 0;
                    // Mth.map(secondary, -1, 1, 0, range)
                    $secondary = $range === 0 ? 0 : (int) (0.0 + ($this->secondary() - -1.0) / (1.0 - -1.0) * ($range - 0.0));
                    return $depth <= 1 + $offset + $surface + $secondary;
                });
            case 'temperature':
                return fn (): bool => $this->climate->coldEnoughToSnow($this->biome(), $this->blockX, $this->blockY, $this->blockZ, $this->settings->seaLevel);
            case 'vertical_gradient':
                $trueBelow = $this->anchor($json['true_at_and_below']);
                $falseAbove = $this->anchor($json['false_at_and_above']);
                $random = $this->settings->state->factory(str_contains($json['random_name'], ':') ? $json['random_name'] : 'minecraft:' . $json['random_name']);
                return $this->lazy(true, function () use ($trueBelow, $falseAbove, $random): bool {
                    $y = $this->blockY;
                    if ($y <= $trueBelow) {
                        return true;
                    }
                    if ($y >= $falseAbove) {
                        return false;
                    }
                    $probability = 1.0 + ($y - $trueBelow) / ($falseAbove - $trueBelow) * (0.0 - 1.0);
                    return $random->at($this->blockX, $y, $this->blockZ)->nextFloat() < $probability;
                });
            case 'water':
                $offset = $json['offset'];
                $multiplier = $json['surface_depth_multiplier'];
                $addStone = $json['add_stone_depth'];
                return $this->lazy(true, fn (): bool => $this->waterHeight === PHP_INT_MIN
                    || $this->blockY + ($addStone ? $this->stoneAbove : 0) >= $this->waterHeight + $offset + $this->surfaceDepth * $multiplier);
            case 'y_above':
                $anchor = $this->anchor($json['anchor']);
                $multiplier = $json['surface_depth_multiplier'];
                $addStone = $json['add_stone_depth'];
                return $this->lazy(true, fn (): bool => $this->blockY + ($addStone ? $this->stoneAbove : 0) >= $anchor + $this->surfaceDepth * $multiplier);
        }
        throw new \LogicException('Unknown material condition ' . $json['type']);
    }

    private function erodedBadlands(array &$blocks, int $x, int $z, int $bx, int $bz, int $height): void
    {
        $state = $this->settings->state;
        $minY = $this->settings->minY;
        $buffer = min(abs($state->noise('badlands_surface')->get($bx, 0.0, $bz) * 8.25), F32::r($state->noise('badlands_pillar')->get($bx * 0.2, 0.0, $bz * 0.2) * 15.0));
        if ($buffer <= 0.0) {
            return;
        }
        $floor = abs($state->noise('badlands_pillar_roof')->get($bx * 0.75, 0.0, $bz * 0.75) * 1.5);
        $top = 64.0 + min($buffer * $buffer * 2.5, ceil($floor * 50.0) + 24.0);
        $startY = (int) floor($top);
        if ($height > $startY) {
            return;
        }
        for ($y = $startY; $y >= $minY; --$y) {
            $old = $this->blockAt($blocks, $x, $y, $z);
            if ($old === 'stone') {
                break;
            }
            if ($old === 'water') {
                return;
            }
        }
        for ($y = $startY; $y >= $minY && self::isAir($this->blockAt($blocks, $x, $y, $z)); --$y) {
            $this->setBlock($blocks, $x, $y, $z, 'stone');
        }
    }

    private function frozenOcean(array &$blocks, int $minSurface, string $biome, int $x, int $z, int $bx, int $bz, int $height): void
    {
        $state = $this->settings->state;
        $seaLevel = $this->settings->seaLevel;
        $iceberg = min(abs($state->noise('iceberg_surface')->get($bx, 0.0, $bz) * 8.25), F32::r($state->noise('iceberg_pillar')->get($bx * 1.28, 0.0, $bz * 1.28) * 15.0));
        if ($iceberg <= 1.8) {
            return;
        }
        $roof = abs($state->noise('iceberg_pillar_roof')->get($bx * 1.17, 0.0, $bz * 1.17) * 1.5);
        $top = min($iceberg * $iceberg * 1.2, ceil($roof * 40.0) + 14.0);
        if ($this->climate->temperature($biome, $bx, $seaLevel, $bz, $seaLevel) > F32::r(0.1)) {
            $top -= 2.0;
        }
        if ($top <= 2.0) {
            return;
        }
        $bottom = $seaLevel - $top - 7.0;
        $top += $seaLevel;
        $random = $this->noiseRandom->at($bx, 0, $bz);
        $maxSnowDepth = 2 + $random->nextInt(4);
        $minSnowHeight = $seaLevel + 18 + $random->nextInt(10);
        $snowDepth = 0;
        for ($y = max($height, (int) $top + 1); $y >= $minSurface; --$y) {
            $old = $this->blockAt($blocks, $x, $y, $z);
            if ((self::isAir($old) && $y < (int) $top && $random->nextDouble() > 0.01)
                || ($old === 'water' && $y > (int) $bottom && $y < $seaLevel && $random->nextDouble() > 0.15)) {
                if ($snowDepth <= $maxSnowDepth && $y > $minSnowHeight) {
                    $this->setBlock($blocks, $x, $y, $z, 'snow_block');
                    ++$snowDepth;
                } else {
                    $this->setBlock($blocks, $x, $y, $z, 'packed_ice');
                }
            }
        }
    }

    private function blockAt(array $blocks, int $x, int $y, int $z): string
    {
        $minY = $this->settings->minY;
        if ($y < $minY || $y >= $minY + $this->settings->height) {
            return 'void_air';
        }
        return $blocks[(($y - $minY) << 8) | ($z << 4) | $x];
    }

    private function setBlock(array &$blocks, int $x, int $y, int $z, string $state): void
    {
        $minY = $this->settings->minY;
        if ($y >= $minY && $y < $minY + $this->settings->height) {
            $blocks[(($y - $minY) << 8) | ($z << 4) | $x] = $state;
        }
    }
}
