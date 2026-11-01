<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;

/**
 * NaturalSpawner as ServerChunkCache.tickChunks runs it each tick: the mob caps over the chunks
 * near players, then in each chunk a player is within 128 blocks of, for each category with room,
 * a random column position and up to three groups of up to four tries scattered around it, each
 * spawning a type the biome lists there when its placement and spawn rules allow.
 *
 * The spawn rules of the common types are ported; types with rules not ported yet are not spawned
 * (see SPAWN_RULES), nor are structure spawn overrides such as Nether fortresses applied yet. Each
 * tick's work is bounded in time, so not every chunk is tried every tick.
 */
trait NaturalSpawning
{
    /** MobCategory: the most per chunk, whether friendly, and whether its spawns persist. MISC never spawns. */
    private const SPAWN_CATEGORIES = [
        'monster' => [70, false, false], 'creature' => [10, true, true], 'ambient' => [15, true, false], 'axolotls' => [5, true, false],
        'underground_water_creature' => [5, true, false], 'water_creature' => [5, true, false], 'water_ambient' => [20, true, false],
    ];

    /** SpawnPlacements' spawn predicates for the types ported, by the rule they register. */
    private const SPAWN_RULES = [
        'monster' => ['bogged', 'cave_spider', 'creeper', 'enderman', 'giant', 'skeleton', 'spider', 'witch', 'wither_skeleton', 'zombie', 'zombie_villager', 'zombie_horse', 'creaking', 'ravager', 'evoker', 'illusioner', 'vex', 'vindicator', 'warden'],
        'surface_monster' => ['husk', 'parched', 'camel_husk'],
        'any_light_monster' => ['blaze', 'breeze', 'zoglin'],
        'animal' => ['chicken', 'cow', 'donkey', 'happy_ghast', 'horse', 'llama', 'mule', 'pig', 'sheep', 'cat', 'panda', 'trader_llama'],
        'surface_water_animal' => ['cod', 'pufferfish', 'salmon', 'squid', 'dolphin'],
        'bat' => ['bat'],
        'tagged_animal' => ['wolf', 'rabbit', 'goat', 'mooshroom', 'armadillo', 'camel', 'frog', 'fox', 'parrot'],
        'mob' => ['ender_dragon', 'iron_golem', 'snow_golem', 'villager', 'phantom', 'shulker', 'wandering_trader'],
        'drowned' => ['drowned'],
        'guardian' => ['guardian', 'elder_guardian'],
        'glow_squid' => ['glow_squid'],
        'tropical_fish' => ['tropical_fish'],
        'axolotl' => ['axolotl'],
        'nautilus' => ['nautilus'],
        'polar_bear' => ['polar_bear'],
        'ocelot' => ['ocelot'],
        'turtle' => ['turtle'],
        'strider' => ['strider'],
        'ghast' => ['ghast'],
        'not_on_wart' => ['piglin', 'hoglin'],
        'zombified_piglin' => ['zombified_piglin'],
        'stray' => ['stray'],
        'alone_any_light' => ['endermite', 'silverfish'],
        'skeleton_horse' => ['skeleton_horse'],
    ];

    private ?LegacyRandom $spawnRandom = null;

    /** @var array<string, true> this tick's entity-ticking chunks near players, by chunk key */
    private array $spawningChunks = [];

    /** @var array<string, array{int, list<int>}> WORLD_SURFACE heights by chunk, with the tick read */
    private array $surfaceHeights = [];

    /** natural-spawning.json, its bitsets decoded, and each biome's spawn settings as read. */
    private function naturalSpawningReference(): array
    {
        static $reference = null;
        if ($reference === null) {
            $reference = json_decode((string) file_get_contents($this->version->directory . '/natural-spawning.json'), true, 512, JSON_THROW_ON_ERROR);
            $reference['bitsets'] = array_map(static fn (string $bits): string => (string) base64_decode($bits, true), $reference['bitsets']);
            $reference['rules'] = [];
            foreach (self::SPAWN_RULES as $rule => $types) {
                foreach ($types as $type) {
                    $reference['rules'][$type] = $rule;
                }
            }
        }
        return $reference;
    }

    /** A state in one of natural-spawning.json's bitsets, in state ID order. */
    private function stateIn(int $bitset, int $state): bool
    {
        $bits = $this->naturalSpawningReference()['bitsets'][$bitset];
        return isset($bits[$state >> 3]) && ((ord($bits[$state >> 3]) >> ($state & 7)) & 1) === 1;
    }

    private function stateTagged(string $tag, int $state): bool
    {
        $bitset = $this->naturalSpawningReference()['blocks']['tags'][$tag] ?? null;
        return $bitset !== null && $this->stateIn($bitset, $state);
    }

    /** The biome's natural_mob_spawns attribute: spawners by category and spawn costs. */
    private function biomeMobSpawns(string $biome): array
    {
        static $biomes = [];
        if (!isset($biomes[$biome])) {
            $file = $this->version->directory . '/worldgen/biome/' . $biome . '.json';
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
            $biomes[$biome] = $data['attributes']['minecraft:gameplay/natural_mob_spawns']['argument'] ?? ['spawns_by_category' => [], 'spawn_costs' => []];
        }
        return $biomes[$biome];
    }

    /** Heightmap.Types.WORLD_SURFACE: above the highest block that is not air, read once a second per chunk. */
    private function surfaceHeight(int $x, int $z): int
    {
        $key = $this->world->dimension . ':' . ($x >> 4) . ':' . ($z >> 4);
        if (!isset($this->surfaceHeights[$key]) || $this->surfaceHeights[$key][0] < $this->ticks - 20) {
            if (count($this->surfaceHeights) >= 512) {
                $this->surfaceHeights = array_slice($this->surfaceHeights, 256, null, true);
            }
            $sections = $this->world->sections($x >> 4, $z >> 4);
            $flags = $this->version->catalog->flags;
            $heights = array_fill(0, 256, $this->world->minY);
            for ($column = 0; $column < 256; ++$column) {
                for ($y = count($sections) * 16 - 1; $y >= 0; --$y) {
                    $state = $sections[$y >> 4][(($y & 15) << 8) | $column] ?? 0;
                    if ((($flags[$state] ?? 0) & 1) === 0) {
                        $heights[$column] = $this->world->minY + $y + 1;
                        break;
                    }
                }
            }
            $this->surfaceHeights[$key] = [$this->ticks, $heights];
        }
        return $this->surfaceHeights[$key][1][(($z & 15) << 4) | ($x & 15)];
    }

    /** ServerChunkCache.tickChunks: the spawn state, the categories with room, then each spawning chunk. */
    private function tickNaturalSpawning(): void
    {
        if ($this->gameRule('spawn_mobs') !== 'true') {
            return;
        }
        $players = array_values(array_filter($this->localPlayers(), static fn (Client $p): bool => $p->gameMode !== 3));
        if ($players === []) {
            return;
        }
        $this->spawnRandom ??= new LegacyRandom(random_int(PHP_INT_MIN, PHP_INT_MAX));
        $reference = $this->naturalSpawningReference();
        // DistanceManager's natural spawn chunk count: chunks within 8 of a player.
        $counted = [];
        foreach ($players as $player) {
            [$px, $pz] = [(int) floor($player->x) >> 4, (int) floor($player->z) >> 4];
            for ($dx = -8; $dx <= 8; ++$dx) {
                for ($dz = -8; $dz <= 8; ++$dz) {
                    $counted[($px + $dx) . ':' . ($pz + $dz)] = true;
                }
            }
        }
        $chunkCount = count($counted);
        // ChunkMap.playerIsCloseEnoughForSpawning: within 128 blocks of the chunk's centre.
        $close = static function (int $cx, int $cz) use ($players): array {
            return array_values(array_filter($players, static fn (Client $p): bool => ($p->x - ($cx * 16 + 8)) ** 2 + ($p->z - ($cz * 16 + 8)) ** 2 < 128 ** 2));
        };
        // NaturalSpawner.createState: mobs that may despawn, by category, globally and per nearby player.
        $counts = [];
        $local = [];
        foreach ($this->animals as $mob) {
            if (isset($mob['deadAt']) || ($mob['persistent'] ?? false) || isset($mob['vehicle'])) {
                continue;
            }
            $category = $reference['mobs'][$mob['kind']]['category'] ?? $this->version->catalog->mobs['entities'][$mob['kind']]['category'] ?? 'misc';
            if (!isset(self::SPAWN_CATEGORIES[$category])) {
                continue;
            }
            $counts[$category] = ($counts[$category] ?? 0) + 1;
            foreach ($close((int) floor($mob['x']) >> 4, (int) floor($mob['z']) >> 4) as $player) {
                $local[spl_object_id($player)][$category] = ($local[spl_object_id($player)][$category] ?? 0) + 1;
            }
        }
        // getFilteredSpawningCategories: monsters only when they may spawn, persistent ones every 400 ticks.
        $enemies = $this->gameRule('spawn_monsters') === 'true';
        $persistent = $this->worldAge % 400 === 0;
        $categories = [];
        foreach (self::SPAWN_CATEGORIES as $category => [$max, $friendly, $persists]) {
            if (($enemies || $friendly) && ($persistent || !$persists) && ($counts[$category] ?? 0) < intdiv($max * $chunkCount, 289)) {
                $categories[] = $category;
            }
        }
        if ($categories === []) {
            return;
        }
        // ChunkMap.collectSpawningChunks: entity-ticking chunks a player is close enough to.
        $radius = $this->config->viewDistance;
        $chunks = [];
        foreach ($players as $player) {
            [$px, $pz] = [(int) floor($player->x) >> 4, (int) floor($player->z) >> 4];
            foreach ($player->loadedChunks as $key => [$cx, $cz]) {
                if (max(abs($cx - $px), abs($cz - $pz)) <= $radius && $close($cx, $cz) !== []) {
                    $chunks[$key] = [$cx, $cz];
                }
            }
        }
        $this->spawningChunks = array_fill_keys(array_keys($chunks), true);
        $chunks = array_values($chunks);
        // Util.shuffle with the level's random.
        for ($i = count($chunks); $i > 1; --$i) {
            $j = $this->spawnRandom->nextInt($i);
            [$chunks[$i - 1], $chunks[$j]] = [$chunks[$j], $chunks[$i - 1]];
        }
        // Unlike vanilla, which tries every spawning chunk each tick, the work stops after a few
        // milliseconds; the shuffle spreads the tries over the chunks from tick to tick.
        $deadline = hrtime(true) + 4_000_000;
        foreach ($chunks as [$cx, $cz]) {
            if (hrtime(true) > $deadline) {
                break;
            }
            foreach ($categories as $category) {
                // LocalMobCapCalculator.canSpawn: some close player has room in this category.
                $room = false;
                foreach ($close($cx, $cz) as $player) {
                    if (($local[spl_object_id($player)][$category] ?? 0) < self::SPAWN_CATEGORIES[$category][0]) {
                        $room = true;
                        break;
                    }
                }
                if ($room) {
                    $this->spawnCategoryForChunk($category, $cx, $cz, $players, $counts, $local, $close);
                }
            }
        }
    }

    /** NaturalSpawner.spawnCategoryForChunk and spawnCategoryForPosition */
    private function spawnCategoryForChunk(string $category, int $cx, int $cz, array $players, array &$counts, array &$local, \Closure $close): void
    {
        $random = $this->spawnRandom;
        // getRandomPosWithin: a column of the chunk, up to one above its surface.
        $x = $cx * 16 + $random->nextInt(16);
        $z = $cz * 16 + $random->nextInt(16);
        $top = $this->surfaceHeight($x, $z) + 1;
        $minY = $this->world->minY;
        $y = $random->nextInt($top - $minY + 1) + $minY;
        if ($y < $minY + 1) {
            return;
        }
        $reference = $this->naturalSpawningReference();
        if ($this->stateIn($reference['blocks']['redstone_conductor'], $this->world->get($x, $y, $z))) {
            return;
        }
        $clusterSize = 0;
        $startX = $x;
        $startZ = $z;
        for ($group = 0; $group < 3; ++$group) {
            $x = $startX;
            $z = $startZ;
            $spawner = null;
            $max = (int) ceil($random->nextFloat() * 4.0);
            $groupSize = 0;
            for ($try = 0; $try < $max; ++$try) {
                $x += $random->nextInt(6) - $random->nextInt(6);
                $z += $random->nextInt(6) - $random->nextInt(6);
                [$xx, $zz] = [$x + 0.5, $z + 0.5];
                // Level.getNearestPlayer: any player here who is not a spectator.
                $nearest = null;
                foreach ($players as $player) {
                    $distance = ($player->x - $xx) ** 2 + ($player->y - $y) ** 2 + ($player->z - $zz) ** 2;
                    $nearest = $nearest === null ? $distance : min($nearest, $distance);
                }
                if ($nearest === null || !$this->rightDistanceToSpawn($x, $y, $z, $cx, $cz, $nearest)) {
                    continue;
                }
                $biome = $this->world->biomeAt($x, $y, $z);
                if ($spawner === null) {
                    $spawner = $this->randomSpawner($category, $biome);
                    if ($spawner === null) {
                        break;
                    }
                    $max = is_array($spawner['count']) ? $spawner['count']['min_inclusive'] + $random->nextInt($spawner['count']['max_inclusive'] - $spawner['count']['min_inclusive'] + 1) : $spawner['count'];
                }
                $kind = substr($spawner['type'], strlen('minecraft:'));
                if (!$this->validSpawnPositionForType($category, $kind, $biome, $spawner, $x, $y, $z, $nearest)) {
                    continue;
                }
                $yaw = $random->nextFloat() * 360.0;
                if (!$this->validPositionForMob($kind, $xx, $y, $zz, $nearest)) {
                    continue;
                }
                $id = $this->spawnAnimal($kind, $xx, (float) $y, $zz, ['yaw' => $yaw]);
                if ($id === null) {
                    return;
                }
                ++$clusterSize;
                ++$groupSize;
                // SpawnState.afterSpawn: the caps count the new mob at once.
                $counts[$category] = ($counts[$category] ?? 0) + 1;
                foreach ($close($cx, $cz) as $player) {
                    $local[spl_object_id($player)][$category] = ($local[spl_object_id($player)][$category] ?? 0) + 1;
                }
                $info = $reference['mobs'][$kind] ?? [];
                if ($clusterSize >= ($info['cluster'] ?? 4)) {
                    return;
                }
                if (isset($info['max_group']) && $groupSize >= $info['max_group']) {
                    break;
                }
            }
        }
    }

    /** isRightDistanceToPlayerAndSpawnPoint: beyond 24 blocks of players and the world spawn, in a spawning chunk. */
    private function rightDistanceToSpawn(int $x, int $y, int $z, int $cx, int $cz, float $nearest): bool
    {
        if ($nearest <= 576.0) {
            return false;
        }
        if ($this->world->dimension === 'overworld') {
            [$sx, $sy, $sz] = $this->sharedSpawn();
            [$bx, $by, $bz] = [floor($sx) + 0.5, floor($sy), floor($sz) + 0.5];
            if (($bx - ($x + 0.5)) ** 2 + ($by - $y) ** 2 + ($bz - ($z + 0.5)) ** 2 < 576.0) {
                return false;
            }
        }
        // ServerLevel.canSpawnEntitiesInChunk: the same chunk, or another that is ticking entities.
        return ($x >> 4) === $cx && ($z >> 4) === $cz || isset($this->spawningChunks[($x >> 4) . ':' . ($z >> 4)]);
    }

    /** getRandomSpawnMobAt: a weighted pick from the biome's spawners in the category. */
    private function randomSpawner(string $category, string $biome): ?array
    {
        $spawners = $this->biomeMobSpawns($biome)['spawns_by_category'][$category] ?? [];
        // REDUCED_WATER_AMBIENT_SPAWNS: rivers and the like mostly skip fish.
        if ($category === 'water_ambient' && in_array($biome, $this->version->registryTags('minecraft:worldgen/biome')['minecraft:reduced_water_ambient_spawns'] ?? [], true) && $this->spawnRandom->nextFloat() < 0.98) {
            return null;
        }
        $total = array_sum(array_column($spawners, 'weight'));
        if ($total <= 0) {
            return null;
        }
        $pick = $this->spawnRandom->nextInt($total);
        foreach ($spawners as $spawner) {
            $pick -= $spawner['weight'];
            if ($pick < 0) {
                return $spawner;
            }
        }
        return null;
    }

    /** isValidSpawnPostitionForType: category, distance, the biome still listing it, placement, rules and room. */
    private function validSpawnPositionForType(string $category, string $kind, string $biome, array $spawner, int $x, int $y, int $z, float $nearest): bool
    {
        $info = $this->naturalSpawningReference()['mobs'][$kind] ?? null;
        $despawn = $this->mobDespawnReference()[$kind]['despawn_distance'] ?? 128;
        if ($info === null || $info['category'] === 'misc' || (!$info['far'] && $nearest > $despawn ** 2) || !$info['summon']) {
            return false;
        }
        if (!in_array($spawner, $this->biomeMobSpawns($biome)['spawns_by_category'][$category] ?? [], true)) {
            return false;
        }
        if (!$this->spawnPositionOk($kind, $info, $x, $y, $z) || !$this->checkSpawnRules($kind, $info, $x, $y, $z)) {
            return false;
        }
        // Level.noCollision for the type's spawn box.
        $size = $this->version->catalog->mobs['entities'][$kind];
        $half = $size['width'] / 2;
        $box = [$x + 0.5 - $half, (float) $y, $z + 0.5 - $half, $x + 0.5 + $half, $y + $size['height'], $z + 0.5 + $half];
        foreach ((new Collision($this->version->catalog))->boxes($this->world, $box) as $solid) {
            if ($solid[0] < $box[3] && $solid[3] > $box[0] && $solid[1] < $box[4] && $solid[4] > $box[1] && $solid[2] < $box[5] && $solid[5] > $box[2]) {
                return false;
            }
        }
        return true;
    }

    /** SpawnPlacementTypes: standing on a valid block in two empty ones, in water, in lava, or anywhere. */
    private function spawnPositionOk(string $kind, array $info, int $x, int $y, int $z): bool
    {
        $blocks = $this->naturalSpawningReference()['blocks'];
        return match ($info['placement']) {
            'on_ground' => $this->stateIn($info['valid_spawn'], $this->world->get($x, $y - 1, $z))
                && $this->validEmptySpawnBlock($info, $this->world->get($x, $y, $z)) && $this->validEmptySpawnBlock($info, $this->world->get($x, $y + 1, $z)),
            'in_water' => $this->stateIn($blocks['water'], $this->world->get($x, $y, $z)) && !$this->stateIn($blocks['redstone_conductor'], $this->world->get($x, $y + 1, $z)),
            'in_lava' => $this->stateIn($blocks['lava'], $this->world->get($x, $y, $z)),
            default => true,
        };
    }

    /** NaturalSpawner.isValidEmptySpawnBlock */
    private function validEmptySpawnBlock(array $info, int $state): bool
    {
        $blocks = $this->naturalSpawningReference()['blocks'];
        return !$this->stateIn($blocks['full_collision'], $state) && !$this->stateIn($blocks['signal_source'], $state) && !$this->stateIn($blocks['fluid'], $state)
            && !$this->stateIn($blocks['prevent_spawn_inside'], $state) && !$this->stateIn($info['dangerous'], $state);
    }

    /** SpawnPlacements.checkSpawnRules: each type's registered predicate, as far as ported. */
    private function checkSpawnRules(string $kind, array $info, int $x, int $y, int $z): bool
    {
        $random = $this->spawnRandom;
        $below = $this->world->get($x, $y - 1, $z);
        // Mob.checkMobSpawnRules: the block below lets the type spawn on it.
        $mobRule = fn (): bool => $this->stateIn($info['valid_spawn'], $below);
        // Animal.isBrightEnoughToSpawn
        $bright = fn (): bool => $this->world->rawBrightness($x, $y, $z, false, 0) > 8;
        $water = fn (int $state): bool => $this->stateIn($this->naturalSpawningReference()['blocks']['water'], $state);
        $above = $this->world->get($x, $y + 1, $z);
        $biome = fn (string $tag): bool => in_array($this->world->biomeAt($x, $y, $z), $this->version->registryTags('minecraft:worldgen/biome')['minecraft:' . $tag] ?? [], true);
        $sea = $this->seaLevel();
        // WaterAnimal and AgeableWaterCreature's surface rule: near the sea's surface, water below and above.
        $surfaceWater = fn (): bool => $y >= $sea - 13 && $y <= $sea && $water($below) && $this->version->catalog->blockName($above) === 'water';
        return match ($this->naturalSpawningReference()['rules'][$kind] ?? null) {
            'monster' => $this->darkEnoughToSpawn($x, $y, $z) && $mobRule(),
            'surface_monster' => $this->darkEnoughToSpawn($x, $y, $z) && $mobRule() && $this->world->skyLight($x, $y, $z) >= 15,
            'any_light_monster', 'mob' => $mobRule(),
            'animal', 'skeleton_horse' => $this->stateTagged('minecraft:animals_spawnable_on', $below) && $bright(),
            // Wolf, Rabbit, Goat, MushroomCow, Armadillo, Camel, Frog, Fox and Parrot: their own tag of blocks, in the light.
            'tagged_animal' => $this->stateTagged('minecraft:' . ['wolf' => 'wolves', 'rabbit' => 'rabbits', 'goat' => 'goats', 'mooshroom' => 'mooshrooms', 'armadillo' => 'armadillo', 'camel' => 'camels', 'frog' => 'frogs', 'fox' => 'foxes', 'parrot' => 'parrots'][$kind] . '_spawnable_on', $below) && $bright(),
            'surface_water_animal' => $surfaceWater(),
            // Drowned.checkDrownedSpawnRules: in water over water, dark, and rarely; deep down outside its rivers.
            'drowned' => $water($below) && $this->darkEnoughToSpawn($x, $y, $z) && $water($this->world->get($x, $y, $z))
                && ($biome('more_frequent_drowned_spawns') ? $random->nextInt(15) === 0 : $random->nextInt(40) === 0 && $y < $sea - 5),
            // Guardian.checkGuardianSpawnRules: under cover of water, or one time in twenty.
            'guardian' => ($random->nextInt(20) === 0 || !$this->skyFromBelowWater($x, $y, $z)) && $water($this->world->get($x, $y, $z)) && $water($below),
            'glow_squid' => $y <= $sea - 33 && $this->world->rawBrightness($x, $y, $z, false, 0) === 0 && $this->version->catalog->blockName($this->world->get($x, $y, $z)) === 'water',
            'tropical_fish' => $water($below) && $this->version->catalog->blockName($above) === 'water' && ($biome('allows_tropical_fish_spawns_at_any_height') || $surfaceWater()),
            'axolotl' => $this->stateTagged('minecraft:axolotls_spawnable_on', $below),
            'nautilus' => $y >= $sea - 25 && $y <= $sea - 5 && $water($below) && $this->version->catalog->blockName($above) === 'water',
            'polar_bear' => $biome('polar_bears_spawn_on_alternate_blocks')
                ? $bright() && in_array($this->version->catalog->blockName($below), $this->version->registryTags('minecraft:block')['minecraft:polar_bears_spawnable_on_alternate'] ?? [], true)
                : $this->stateTagged('minecraft:animals_spawnable_on', $below) && $bright(),
            'ocelot' => $random->nextInt(3) !== 0,
            // TurtleEggBlock.onSand
            'turtle' => $y < $sea + 4 && in_array($this->version->catalog->blockName($below), $this->version->registryTags('minecraft:block')['minecraft:sand'] ?? [], true) && $bright(),
            'strider' => $this->striderSurfaced($x, $y, $z),
            'ghast' => $random->nextInt(20) === 0 && $mobRule(),
            'not_on_wart' => $this->version->catalog->blockName($below) !== 'nether_wart_block',
            'zombified_piglin' => $this->version->catalog->blockName($below) !== 'nether_wart_block',
            'stray' => $this->darkEnoughToSpawn($x, $y, $z) && $mobRule() && $this->strayUnderSky($x, $y, $z),
            // Endermite and Silverfish: any light, with no player within 5 blocks.
            'alone_any_light' => $mobRule() && !$this->playerWithin($x + 0.5, $y + 0.5, $z + 0.5, 5.0),
            // Bat.checkBatSpawnRules: below the surface, in the dark, on a bat's block.
            'bat' => $y < $this->surfaceHeight($x, $z) && !$random->nextBoolean() && $this->world->rawBrightness($x, $y, $z, false, $this->skyDarken()) <= $random->nextInt(4)
                && $this->stateTagged('minecraft:bats_spawnable_on', $below) && $mobRule(),
            default => false,
        };
    }

    /** Level.getSeaLevel: the noise settings' 63, a flat world's -63, the Nether's 32 and the End's 0. */
    private function seaLevel(): int
    {
        return match ($this->world->dimension) {
            'the_nether' => 32,
            'the_end' => 0,
            default => $this->world->generator === 'flat' ? -63 : 63,
        };
    }

    /** Level.canSeeSkyFromBelowWater: the sky above the water column the position is in. */
    private function skyFromBelowWater(int $x, int $y, int $z): bool
    {
        if ($y >= $this->seaLevel()) {
            return $this->world->skyLight($x, $y, $z) >= 15;
        }
        $top = $y;
        while ($top < $this->seaLevel() && $this->stateIn($this->naturalSpawningReference()['blocks']['water'], $this->world->get($x, $top + 1, $z))) {
            ++$top;
        }
        return $this->world->skyLight($x, $top + 1, $z) >= 15;
    }

    /** Strider.checkStriderSpawnRules: air right above the lava it is in. */
    private function striderSurfaced(int $x, int $y, int $z): bool
    {
        $lava = $this->naturalSpawningReference()['blocks']['lava'];
        do {
            ++$y;
        } while ($this->stateIn($lava, $this->world->get($x, $y, $z)));
        return (($this->version->catalog->flags[$this->world->get($x, $y, $z)] ?? 0) & 1) !== 0;
    }

    /** Stray.checkStraySpawnRules: the sky above any powder snow it stands in. */
    private function strayUnderSky(int $x, int $y, int $z): bool
    {
        $top = $y + 1;
        while ($this->version->catalog->blockName($this->world->get($x, $top, $z)) === 'powder_snow') {
            ++$top;
        }
        return $this->world->skyLight($x, $top - 1, $z) >= 15;
    }

    /** Level.getNearestPlayer within a distance, survival or adventure players only. */
    private function playerWithin(float $x, float $y, float $z, float $distance): bool
    {
        foreach ($this->localPlayers() as $player) {
            if (in_array($player->gameMode, [0, 2], true) && ($player->x - $x) ** 2 + ($player->y - $y) ** 2 + ($player->z - $z) ** 2 < $distance ** 2) {
                return true;
            }
        }
        return false;
    }

    /** Monster.isDarkEnoughToSpawn: little sky light, no block light here, and dark enough by the dimension's test. */
    private function darkEnoughToSpawn(int $x, int $y, int $z): bool
    {
        $random = $this->spawnRandom;
        if ($this->world->skyLight($x, $y, $z) > $random->nextInt(32)) {
            return false;
        }
        $dimension = $this->naturalSpawningReference()['dimensions'][$this->world->dimension];
        if ($dimension['block_light_limit'] < 15 && $this->world->rawBrightness($x, $y, $z, true) > $dimension['block_light_limit']) {
            return false;
        }
        $brightness = $this->world->rawBrightness($x, $y, $z, false, $this->world->dimension === 'overworld' && $this->thunderStrength() > 0.9 ? 10 : $this->skyDarken());
        $test = $dimension['light_test'];
        $limit = is_array($test) ? $test['min_inclusive'] + $random->nextInt($test['max_inclusive'] - $test['min_inclusive'] + 1) : $test;
        return $brightness <= $limit;
    }

    /** isValidPositionForMob: not beyond the despawn distance if it would go, and room clear of other living entities. */
    private function validPositionForMob(string $kind, float $x, int $y, float $z, float $nearest): bool
    {
        $despawn = $this->mobDespawnReference()[$kind] ?? null;
        if ($despawn !== null && $nearest > $despawn['despawn_distance'] ** 2 && $this->removeWhenFarAwayByDefault($despawn['remove_when_far_away'])) {
            return false;
        }
        $size = $this->version->catalog->mobs['entities'][$kind];
        $half = $size['width'] / 2;
        // Mob.checkSpawnObstruction: no liquid in the mob's box, except for those spawning in water
        // or lava, then Level.isUnobstructed against other living entities.
        $placement = $this->naturalSpawningReference()['mobs'][$kind]['placement'] ?? 'on_ground';
        if ($placement !== 'in_water' && $placement !== 'in_lava') {
            $fluid = $this->naturalSpawningReference()['blocks']['fluid'];
            for ($bx = (int) floor($x - $half); $bx <= (int) floor($x + $half); ++$bx) {
                for ($by = $y; $by <= (int) floor($y + $size['height']); ++$by) {
                    for ($bz = (int) floor($z - $half); $bz <= (int) floor($z + $half); ++$bz) {
                        if ($this->stateIn($fluid, $this->world->get($bx, $by, $bz))) {
                            return false;
                        }
                    }
                }
            }
        }
        // Ocelot.checkSpawnObstruction: at or above sea level, on grass or leaves.
        if ($kind === 'ocelot') {
            $below = $this->world->get((int) floor($x), $y - 1, (int) floor($z));
            $name = $this->version->catalog->blockName($below);
            if ($y < $this->seaLevel() || ($name !== 'grass_block' && !in_array($name, $this->version->registryTags('minecraft:block')['minecraft:leaves'] ?? [], true))) {
                return false;
            }
        }
        foreach ([...$this->animals, ...array_map(static fn (Client $p): array => ['x' => $p->x, 'y' => $p->y, 'z' => $p->z, 'kind' => 'player'], $this->localPlayers())] as $other) {
            if (isset($other['deadAt'])) {
                continue;
            }
            $o = $this->version->catalog->mobs['entities'][$other['kind']] ?? ['width' => 0.6, 'height' => 1.8];
            if (abs($other['x'] - $x) < ($o['width'] / 2 + $half) && abs($other['z'] - $z) < ($o['width'] / 2 + $half) && $other['y'] < $y + $size['height'] && $other['y'] + $o['height'] > $y) {
                return false;
            }
        }
        return true;
    }

    /** removeWhenFarAway for a freshly spawned mob of a type, by the class that decides it. */
    private function removeWhenFarAwayByDefault(string $class): bool
    {
        return !in_array($class, ['Animal', 'AbstractGolem', 'Allay', 'Villager', 'WanderingTrader', 'Warden', 'Chicken', 'Cat', 'Ocelot'], true);
    }
}
