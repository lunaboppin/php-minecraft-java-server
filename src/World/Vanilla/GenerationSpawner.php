<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Generation\Int64;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/**
 * NaturalSpawner.spawnMobsForChunkGeneration: the creatures a chunk starts with, picked from its
 * biome's creature spawns with a decoration-seeded random once the chunk and its neighbours are
 * decorated and lit. Each one is an entity record like a structure's: its saved tags and that
 * Mob.finalizeSpawn runs when it is added.
 */
final class GenerationSpawner
{
    private const ON_GROUND = 'on_ground';
    private const NO_RESTRICTIONS = 'no_restrictions';
    private const IN_LAVA = 'in_lava';

    /**
     * SpawnPlacements of the creatures biomes spawn at generation: placement type, heightmap and
     * the block tag their spawn rule wants below them (Animal.checkAnimalSpawnRules unless named).
     */
    private const PLACEMENTS = [
        'armadillo' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, 'armadillo_spawnable_on'],
        'camel' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, 'camels_spawnable_on'],
        'chicken' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, 'animals_spawnable_on'],
        'cow' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, 'animals_spawnable_on'],
        'donkey' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, 'animals_spawnable_on'],
        'fox' => [self::NO_RESTRICTIONS, Region::MOTION_BLOCKING_NO_LEAVES, 'foxes_spawnable_on'],
        'frog' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, 'frogs_spawnable_on'],
        'goat' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, 'goats_spawnable_on'],
        'horse' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, 'animals_spawnable_on'],
        'llama' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, 'animals_spawnable_on'],
        'mooshroom' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, 'mooshrooms_spawnable_on'],
        'panda' => [self::NO_RESTRICTIONS, Region::MOTION_BLOCKING_NO_LEAVES, 'animals_spawnable_on'],
        'parrot' => [self::ON_GROUND, Region::MOTION_BLOCKING, 'parrots_spawnable_on'],
        'pig' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, 'animals_spawnable_on'],
        'polar_bear' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, 'animals_spawnable_on'],
        'rabbit' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, 'rabbits_spawnable_on'],
        'sheep' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, 'animals_spawnable_on'],
        'strider' => [self::IN_LAVA, Region::MOTION_BLOCKING_NO_LEAVES, null],
        'turtle' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, null],
        'wolf' => [self::ON_GROUND, Region::MOTION_BLOCKING_NO_LEAVES, 'wolves_spawnable_on'],
    ];

    /** EntityType.immuneTo: blocks these creatures do not count as dangerous. */
    private const IMMUNE_TO = ['fox' => 'fox_immune_to', 'polar_bear' => 'polar_bear_immune_to'];

    /** @var array<string, array{list<array{type: string, weight: int, min: int, max: int}>, float}> */
    private array $biomes = [];

    /** @var list<int> two bytes per state: see block-behaviour.json "spawning_bits" */
    private readonly array $spawning;

    private readonly Collision $collision;

    /** @var array<string, true> */
    private array $alternatePolarBearBiomes = [];

    /** @var array<string, int> the lowest Y of each column that sky light reaches undimmed */
    private array $skyFloor = [];

    public function __construct(private readonly Blocks $blocks, private readonly string $directory)
    {
        $behaviour = json_decode((string) file_get_contents($directory . '/block-behaviour.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->spawning = array_values(unpack('v*', base64_decode($behaviour['spawning'])));
        $this->collision = new Collision($blocks->version->catalog);
        foreach (json_decode((string) file_get_contents($directory . '/tags/biome/polar_bears_spawn_on_alternate_blocks.json'), true, 512, JSON_THROW_ON_ERROR)['values'] as $biome) {
            $this->alternatePolarBearBiomes[preg_replace('/^minecraft:/', '', $biome)] = true;
        }
    }

    /**
     * The creatures one chunk starts with, in spawn order.
     *
     * @return list<array{nbt: array, finalize: bool}>
     */
    public function spawn(Region $region, bool $hasCeiling): array
    {
        $this->skyFloor = [];
        $minX = $region->centerX << 4;
        $minZ = $region->centerZ << 4;
        $sourceY = $region->maxY();
        // EnvironmentAttributes.NATURAL_MOB_SPAWNS reads full-resolution biomes, the creature
        // probability the noise biome.
        [$mobs, $total] = $this->creatures($region->biome($minX, $sourceY, $minZ));
        if ($mobs === []) {
            return [];
        }
        $probability = $this->probability($this->noiseBiome($region, $minX, $sourceY, $minZ));
        $random = self::decorationRandom($region->seed, $minX, $minZ);
        $out = [];
        while ($random->nextFloat() < $probability) {
            // WeightedList.getRandom
            $pick = $random->nextInt($total);
            foreach ($mobs as $data) {
                $pick -= $data['weight'];
                if ($pick < 0) {
                    break;
                }
            }
            $type = $data['type'];
            $count = $data['min'] === $data['max'] ? $data['min'] : $random->nextInt($data['max'] - $data['min'] + 1) + $data['min'];
            $x = $minX + $random->nextInt(16);
            $z = $minZ + $random->nextInt(16);
            [$startX, $startZ] = [$x, $z];
            $info = $this->blocks->version->catalog->mobs['entities'][$type];
            $width = (float) $info['width'];
            for ($i = 0; $i < $count; ++$i) {
                $success = false;
                for ($attempts = 0; !$success && $attempts < 4; ++$attempts) {
                    $y = $this->topNonCollidingY($region, $type, $x, $z, $hasCeiling);
                    if ($this->positionOk($region, $type, $x, $y, $z)) {
                        $fx = min(max((float) $x, $minX + $width), $minX + 16.0 - $width);
                        $fz = min(max((float) $z, $minZ + $width), $minZ + 16.0 - $width);
                        $block = [(int) floor($fx), $y, (int) floor($fz)];
                        // A blocked box or a failed spawn rule retries the same column: vanilla
                        // continues the attempt loop before moving on.
                        if (!$this->noCollision($region, $fx, (float) $y, $fz, $width, (float) $info['height']) || !$this->spawnRules($region, $type, ...$block)) {
                            continue;
                        }
                        $yaw = Float32::round($random->nextFloat() * 360.0);
                        if ($this->walkTargetOk($region, $type, ...$block) && $this->unobstructed($region, $type, $fx, (float) $y, $fz, $width, (float) $info['height'])) {
                            $out[] = ['nbt' => ['id' => 'minecraft:' . $type, 'Pos' => [$fx, (float) $y, $fz], 'Rotation' => [$yaw, 0.0]], 'finalize' => true];
                            $success = true;
                        }
                    }
                    $x += $random->nextInt(5) - $random->nextInt(5);
                    for ($z += $random->nextInt(5) - $random->nextInt(5); $x < $minX || $x >= $minX + 16 || $z < $minZ || $z >= $minZ + 16; $z = $startZ + $random->nextInt(5) - $random->nextInt(5)) {
                        $x = $startX + $random->nextInt(5) - $random->nextInt(5);
                    }
                }
            }
        }
        return $out;
    }

    /** NoiseBasedChunkGenerator.spawnOriginalMobs: a legacy WorldgenRandom with the chunk's decoration seed. */
    private static function decorationRandom(int $seed, int $x, int $z): RandomSource
    {
        $random = new LegacyRandom($seed);
        $xScale = $random->nextLong() | 1;
        $zScale = $random->nextLong() | 1;
        return new LegacyRandom(Int64::add(Int64::multiply($x, $xScale), Int64::multiply($z, $zScale)) ^ $seed);
    }

    /** @return array{list<array{type: string, weight: int, min: int, max: int}>, int} */
    private function creatures(string $biome): array
    {
        $this->load($biome);
        $mobs = $this->biomes[$biome][0];
        return [$mobs, array_sum(array_column($mobs, 'weight'))];
    }

    private function probability(string $biome): float
    {
        $this->load($biome);
        return $this->biomes[$biome][1];
    }

    private function load(string $biome): void
    {
        if (isset($this->biomes[$biome])) {
            return;
        }
        $attributes = json_decode((string) file_get_contents($this->directory . "/biome/$biome.json"), true, 512, JSON_THROW_ON_ERROR)['attributes'] ?? [];
        $spawns = $attributes['minecraft:gameplay/natural_mob_spawns'] ?? [];
        $mobs = [];
        foreach (($spawns['argument'] ?? $spawns)['spawns_by_category']['creature'] ?? [] as $entry) {
            $count = $entry['count'];
            [$min, $max] = is_array($count) ? [$count['min_inclusive'], $count['max_inclusive']] : [$count, $count];
            $mobs[] = ['type' => preg_replace('/^minecraft:/', '', $entry['type']), 'weight' => $entry['weight'], 'min' => $min, 'max' => $max];
        }
        $probability = $attributes['minecraft:gameplay/creature_world_gen_spawn_probability'] ?? 0.1;
        $this->biomes[$biome] = [$mobs, Float32::round((float) $probability)];
    }

    /** BiomeManager.getNoiseBiomeAtPosition */
    private function noiseBiome(Region $region, int $x, int $y, int $z): string
    {
        $chunk = $region->chunk($x >> 4, $z >> 4);
        $quartY = min(max(($y >> 2) - ($region->minY >> 2), 0), intdiv($region->genHeight, 4) - 1);
        return $chunk->biomes[($quartY << 4) | ((($z >> 2) & 3) << 2) | (($x >> 2) & 3)];
    }

    /** NaturalSpawner.getTopNonCollidingPos and SpawnPlacementType.adjustSpawnPosition */
    private function topNonCollidingY(Region $region, string $type, int $x, int $z, bool $hasCeiling): int
    {
        $y = $region->height(self::PLACEMENTS[$type][1], $x, $z);
        if ($hasCeiling) {
            do {
                --$y;
            } while (!$this->blocks->isAir($region->get($x, $y, $z)));
            do {
                --$y;
            } while ($this->blocks->isAir($region->get($x, $y, $z)) && $y > $region->minY);
        }
        if (self::PLACEMENTS[$type][0] === self::ON_GROUND && ($this->spawning[$region->get($x, $y - 1, $z)] & 32) !== 0) {
            return $y - 1;
        }
        return $y;
    }

    /** SpawnPlacements.isSpawnPositionOk (canSummon holds for every generation creature) */
    private function positionOk(Region $region, string $type, int $x, int $y, int $z): bool
    {
        return match (self::PLACEMENTS[$type][0]) {
            self::NO_RESTRICTIONS => true,
            self::IN_LAVA => $this->blocks->isLava($region->get($x, $y, $z)),
            default => $this->validSpawn($type, $region->get($x, $y - 1, $z)) && $this->emptySpawnBlock($type, $region->get($x, $y, $z)) && $this->emptySpawnBlock($type, $region->get($x, $y + 1, $z)),
        };
    }

    /** BlockState.isValidSpawn */
    private function validSpawn(string $type, int $state): bool
    {
        $bit = match ($type) {
            'parrot' => 0x2000,
            'polar_bear' => 0x4000,
            default => 0x1000,
        };
        return ($this->spawning[$state] & $bit) !== 0;
    }

    /** NaturalSpawner.isValidEmptySpawnBlock, with EntityType.immuneTo */
    private function emptySpawnBlock(string $type, int $state): bool
    {
        if (($this->spawning[$state] & 0x8000) !== 0) {
            return true;
        }
        // The exported bit judges dangerous blocks for a cow; an immune creature only needs the rest.
        return isset(self::IMMUNE_TO[$type]) && $this->blocks->inTag($state, self::IMMUNE_TO[$type])
            && !$this->blocks->flag($state, Blocks::FULL_CUBE) && !$this->blocks->hasFluid($state) && !$this->blocks->inTag($state, 'prevent_mob_spawning_inside');
    }

    /** CollisionGetter.noCollision(EntityType.getSpawnAABB): no block shape overlaps the box. */
    private function noCollision(Region $region, float $x, float $y, float $z, float $width, float $height): bool
    {
        $half = Float32::round($width / 2.0);
        $box = [$x - $half, $y, $z - $half, $x + $half, $y + $height, $z + $half];
        for ($bx = (int) floor($box[0] - 1.0E-7) - 1; $bx <= (int) floor($box[3] + 1.0E-7) + 1; ++$bx) {
            for ($bz = (int) floor($box[2] - 1.0E-7) - 1; $bz <= (int) floor($box[5] + 1.0E-7) + 1; ++$bz) {
                for ($by = (int) floor($box[1] - 1.0E-7) - 1; $by <= (int) floor($box[4] + 1.0E-7) + 1; ++$by) {
                    foreach ($this->collision->shape($region->get($bx, $by, $bz)) as [$x0, $y0, $z0, $x1, $y1, $z1]) {
                        if ($bx + $x0 < $box[3] && $bx + $x1 > $box[0] && $by + $y0 < $box[4] && $by + $y1 > $box[1] && $bz + $z0 < $box[5] && $bz + $z1 > $box[2]) {
                            return false;
                        }
                    }
                }
            }
        }
        return true;
    }

    /** SpawnPlacements.checkSpawnRules for a chunk-generation spawn */
    private function spawnRules(Region $region, string $type, int $x, int $y, int $z): bool
    {
        $below = $region->get($x, $y - 1, $z);
        return match ($type) {
            // Strider.checkStriderSpawnRules: air above the lava column.
            'strider' => $this->airAboveLava($region, $x, $y, $z),
            // Turtle.checkTurtleSpawnRules: on sand near sea level.
            'turtle' => $y < $region->seaLevel + 4 && $this->blocks->inTag($below, 'sand') && $this->brightness($region, $x, $y, $z) > 8,
            'polar_bear' => $this->blocks->inTag($below, isset($this->alternatePolarBearBiomes[$region->biome($x, $y, $z)]) ? 'polar_bears_spawnable_on_alternate' : 'animals_spawnable_on') && $this->brightness($region, $x, $y, $z) > 8,
            default => $this->blocks->inTag($below, self::PLACEMENTS[$type][2]) && $this->brightness($region, $x, $y, $z) > 8,
        };
    }

    private function airAboveLava(Region $region, int $x, int $y, int $z): bool
    {
        do {
            ++$y;
        } while ($this->blocks->isLava($region->get($x, $y, $z)));
        return $this->blocks->isAir($region->get($x, $y, $z));
    }

    /**
     * PathfinderMob.checkSpawnRules: Animal.getWalkTargetValue is 10 on its favourite block and
     * otherwise LevelReader.getPathfindingCostFromLightLevels, which is not negative from light 12.
     */
    private function walkTargetOk(Region $region, string $type, int $x, int $y, int $z): bool
    {
        $below = $this->blocks->name($region->get($x, $y - 1, $z));
        $favourite = match ($type) {
            'strider' => true,
            'mooshroom' => $below === 'mycelium',
            'turtle' => $this->blocks->isWater($region->get($x, $y, $z)) || $this->blocks->inTag($region->get($x, $y - 1, $z), 'sand'),
            default => $below === 'grass_block',
        };
        return $favourite || $this->brightness($region, $x, $y, $z) >= 12;
    }

    /** Mob.checkSpawnObstruction: no liquid in the box (a strider only needs no blocking entity). */
    private function unobstructed(Region $region, string $type, float $x, float $y, float $z, float $width, float $height): bool
    {
        if ($type === 'strider') {
            return true;
        }
        $half = Float32::round($width / 2.0);
        for ($bx = (int) floor($x - $half); $bx < (int) ceil($x + $half); ++$bx) {
            for ($by = (int) floor($y); $by < (int) ceil($y + $height); ++$by) {
                for ($bz = (int) floor($z - $half); $bz < (int) ceil($z + $half); ++$bz) {
                    if ($this->blocks->hasFluid($region->get($bx, $by, $bz))) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /**
     * BlockAndLightGetter.getRawBrightness with no darkening: the larger of sky and block light.
     * Light reaches a block over the cheapest path from a cell open to the sky (15) or a light
     * source (its emission), losing at least 1 per step and the dampening of each block it
     * enters; a search outward from the block finds it.
     */
    private function brightness(Region $region, int $x, int $y, int $z): int
    {
        if ($this->openToSky($region, $x, $y, $z)) {
            return 15;
        }
        $best = $this->spawning[$region->get($x, $y, $z)] >> 8 & 15;
        $start = $this->spawning[$region->get($x, $y, $z)] & 15;
        // cost of the light's path into the start block, by the cell it comes from
        $queue = new \SplPriorityQueue();
        $seen = ["$x,$y,$z" => 0];
        foreach (Blocks::OFFSETS as [$dx, $dy, $dz]) {
            $queue->insert([$x + $dx, $y + $dy, $z + $dz, max(1, $start)], -max(1, $start));
        }
        while (!$queue->isEmpty()) {
            [$cx, $cy, $cz, $cost] = $queue->extract();
            if ($cost >= 15 - $best) {
                break;
            }
            $key = "$cx,$cy,$cz";
            if (isset($seen[$key]) || $cy < $region->minY || $cy >= $region->maxY()) {
                continue;
            }
            $seen[$key] = $cost;
            $state = $region->get($cx, $cy, $cz);
            if ($this->openToSky($region, $cx, $cy, $cz)) {
                return 15 - $cost;
            }
            $best = max($best, ($this->spawning[$state] >> 8 & 15) - $cost);
            $step = max(1, $this->spawning[$state] & 15);
            if ($step >= 15) {
                continue;
            }
            foreach (Blocks::OFFSETS as [$dx, $dy, $dz]) {
                $queue->insert([$cx + $dx, $cy + $dy, $cz + $dz, $cost + $step], -($cost + $step));
            }
        }
        return $best;
    }

    /** A block with only light-transparent blocks above it, so sky light reaches it undimmed. */
    private function openToSky(Region $region, int $x, int $y, int $z): bool
    {
        $key = "$x,$z";
        if (!isset($this->skyFloor[$key])) {
            $floor = $region->maxY() + 1;
            while ($floor > $region->minY && ($this->spawning[$region->get($x, $floor - 1, $z)] & 15) === 0) {
                --$floor;
            }
            $this->skyFloor[$key] = $floor;
        }
        return $y >= $this->skyFloor[$key];
    }
}
