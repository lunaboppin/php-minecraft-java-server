<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

/**
 * ServerLevel.tickChunk: random block ticks in the chunks around players. Each tick, every chunk
 * section holding a randomly ticking block picks random_tick_speed positions with the level's
 * linear congruential position random, and the blocks there grow, spread or decay. A per-chunk
 * index of the randomly ticking blocks lets a section with none be skipped as vanilla skips it.
 */
trait RandomTicks
{
    /** @var array<string, array<int, array<int, true>>> per "cx:cz": section => cell indexes of ticking blocks */
    private array $randomTickIndex = [];

    /** @var array<int, true>|null state ids that tick randomly */
    private ?array $randomTicking = null;

    /** Level.randValue */
    private int $randomTickValue = 0;

    /** @var list<int>|null light dampening by state */
    private ?array $lightDampening = null;

    private const CROPS = ['wheat' => 7, 'carrots' => 7, 'potatoes' => 7, 'beetroots' => 3];

    /** WeatheringCopper.NEXT_BY_BLOCK: the unaffected copper blocks; copper_block's later stages drop "_block". */
    private const COPPER = ['copper_block', 'cut_copper', 'chiseled_copper', 'cut_copper_slab', 'cut_copper_stairs', 'copper_door', 'copper_trapdoor',
        'copper_bars', 'copper_grate', 'copper_bulb', 'copper_lantern', 'copper_chest', 'copper_golem_statue', 'lightning_rod', 'copper_chain'];

    /** GrowingPlantHeadBlock: growth chance a tick, direction (1 up, -1 down) and body block. */
    private const PLANT_HEADS = ['kelp' => [0.14, 1, 'kelp_plant'], 'weeping_vines' => [0.1, -1, 'weeping_vines_plant'],
        'twisting_vines' => [0.1, 1, 'twisting_vines_plant'], 'cave_vines' => [0.1, -1, 'cave_vines_plant']];

    /** BlockBehaviour.isRandomlyTicking for the blocks whose random ticks are implemented. */
    private function randomlyTicking(int $state): bool
    {
        if ($this->randomTicking === null) {
            $this->randomTicking = [];
            $catalog = $this->version->catalog;
            for ($id = 0; $id < $this->version->blocks['state_count']; ++$id) {
                $name = $catalog->blockName($id);
                $p = $catalog->properties($id);
                $ticks = match (true) {
                    str_ends_with($name, '_leaves') => ($p['distance'] ?? '') === '7' && ($p['persistent'] ?? 'true') === 'false',
                    isset(self::CROPS[$name]) => (int) $p['age'] < self::CROPS[$name],
                    $name === 'nether_wart' => (int) $p['age'] < 3,
                    $name === 'torchflower_crop' => true,
                    $name === 'pitcher_crop' => $p['half'] === 'lower' && (int) $p['age'] < 4,
                    isset(self::PLANT_HEADS[$name]) => (int) $p['age'] < 25,
                    ($age = self::copperAge($name)) !== null => $age[1] < 3 && ($name !== 'copper_door' && !str_ends_with($name, '_copper_door') || $p['half'] === 'lower'),
                    $name === 'sweet_berry_bush' => (int) $p['age'] < 3,
                    $name === 'cocoa' => (int) $p['age'] < 2,
                    $name === 'redstone_ore' || $name === 'deepslate_redstone_ore' => $p['lit'] === 'true',
                    $name === 'bamboo' => $p['stage'] === '0',
                    in_array($name, ['budding_amethyst', 'bamboo_sapling', 'brown_mushroom', 'red_mushroom'], true) => true,
                    isset(self::GROWERS[$name]) => $name !== 'mangrove_propagule' || $p['hanging'] === 'false' || (int) $p['age'] < 4,
                    in_array($name, ['grass_block', 'mycelium', 'farmland', 'sugar_cane', 'cactus', 'ice', 'snow', 'melon_stem', 'pumpkin_stem', 'lava'], true) => true,
                    default => false,
                };
                if ($ticks) {
                    $this->randomTicking[$id] = true;
                }
            }
        }
        return isset($this->randomTicking[$state]);
    }

    private function indexRandomTicks(int $cx, int $cz): void
    {
        $this->randomlyTicking(0);
        $index = [];
        foreach ($this->world->sections($cx, $cz) as $section => $states) {
            // Count the section's states first; only the cells of ticking states are looked up.
            foreach (array_keys(array_intersect_key(array_count_values($states), $this->randomTicking)) as $state) {
                foreach (array_keys($states, $state, true) as $cell) {
                    $index[$section][$cell] = true;
                }
            }
        }
        $this->randomTickIndex[$cx . ':' . $cz] = $index;
    }

    /** Keep the index current as a block changes. */
    private function randomTickChanged(int $x, int $y, int $z, int $state): void
    {
        $key = ($x >> 4) . ':' . ($z >> 4);
        if (!isset($this->randomTickIndex[$key])) {
            return;
        }
        $section = ($y - $this->world->minY) >> 4;
        $cell = (($y & 15) << 8) | (($z & 15) << 4) | ($x & 15);
        if ($this->randomlyTicking($state)) {
            $this->randomTickIndex[$key][$section][$cell] = true;
        } else {
            unset($this->randomTickIndex[$key][$section][$cell]);
        }
    }

    private function tickRandomBlocks(): void
    {
        $speed = (int) $this->gameRule('random_tick_speed');
        if ($speed <= 0) {
            return;
        }
        $radius = $this->config->viewDistance;
        $chunks = [];
        foreach ($this->localPlayers() as $player) {
            [$px, $pz] = [(int) floor($player->x / 16), (int) floor($player->z / 16)];
            foreach ($player->loadedChunks as $key => [$cx, $cz]) {
                if (max(abs($cx - $px), abs($cz - $pz)) <= $radius) {
                    $chunks[$key] = [$cx, $cz];
                }
            }
        }
        // Chunks that left every player's range stop ticking and are indexed again when they return.
        $this->randomTickIndex = array_intersect_key($this->randomTickIndex, $chunks);
        // New chunks are indexed a few per tick, so a player arriving does not stall the server.
        $indexed = 0;
        foreach ($chunks as $key => [$cx, $cz]) {
            if (!isset($this->randomTickIndex[$key])) {
                if (++$indexed > 16) {
                    continue;
                }
                $this->indexRandomTicks($cx, $cz);
            }
            $this->tickThunder($cx, $cz);
            $this->tickChunkPrecipitation($cx, $cz, $speed);
            foreach ($this->randomTickIndex[$key] as $section => $cells) {
                if ($cells === []) {
                    continue;
                }
                for ($i = 0; $i < $speed; ++$i) {
                    // Level.getBlockRandomPos
                    $this->randomTickValue = ($this->randomTickValue * 3 + 1013904223) & 0xffffffff;
                    $k = ($this->randomTickValue >= 0x80000000 ? $this->randomTickValue - 0x100000000 : $this->randomTickValue) >> 2;
                    $cell = ((($k >> 16) & 15) << 8) | ((($k >> 8) & 15) << 4) | ($k & 15);
                    if (isset($this->randomTickIndex[$key][$section][$cell])) {
                        $this->randomTick($cx * 16 + ($k & 15), $this->world->minY + $section * 16 + (($k >> 16) & 15), $cz * 16 + (($k >> 8) & 15));
                    }
                }
            }
        }
    }

    private function dampening(int $state): int
    {
        if ($this->lightDampening === null) {
            $behaviour = json_decode((string) file_get_contents($this->version->directory . '/worldgen/block-behaviour.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->lightDampening = array_map(static fn (int $v): int => $v & 15, array_values(unpack('v*', base64_decode($behaviour['spawning']))));
        }
        return $this->lightDampening[$state] ?? 0;
    }

    private function randomTick(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $name = $catalog->blockName($state);
        $p = $catalog->properties($state);
        $with = fn (array $changes): int => $catalog->state($name, array_merge($p, array_map('strval', $changes)));
        if (str_ends_with($name, '_leaves')) {
            // LeavesBlock.randomTick: leaves seven blocks from any log decay.
            if ($p['distance'] === '7' && $p['persistent'] === 'false') {
                $this->dropBlockResources($x, $y, $z, $state);
                $this->setBlock($x, $y, $z, $catalog->state('air'));
            }
        } elseif ($name === 'grass_block' || $name === 'mycelium') {
            $this->spreadSnowyBlock($x, $y, $z, $name);
        } elseif (isset(self::CROPS[$name]) || $name === 'melon_stem' || $name === 'pumpkin_stem') {
            $this->growCrop($x, $y, $z, $name, $p);
        } elseif ($name === 'torchflower_crop') {
            // TorchflowerCropBlock.randomTick: two ticks in three reach CropBlock.randomTick.
            if (random_int(0, 2) !== 0) {
                $this->growCrop($x, $y, $z, $name, $p);
            }
        } elseif ($name === 'pitcher_crop') {
            $this->pitcherTick($x, $y, $z, $p);
        } elseif ($name === 'nether_wart') {
            if ((int) $p['age'] < 3 && random_int(0, 9) === 0) {
                $this->setBlock($x, $y, $z, $with(['age' => (int) $p['age'] + 1]));
            }
        } elseif (($age = self::copperAge($name)) !== null && $age[1] < 3) {
            $this->weatherCopper($x, $y, $z, $state, $age);
        } elseif (isset(self::PLANT_HEADS[$name]) || in_array($name, ['sweet_berry_bush', 'cocoa', 'redstone_ore', 'deepslate_redstone_ore', 'bamboo', 'bamboo_sapling', 'budding_amethyst', 'brown_mushroom', 'red_mushroom'], true)) {
            $this->plantTick($x, $y, $z, $name, $p);
        } elseif (isset(self::GROWERS[$name])) {
            $this->saplingTick($x, $y, $z, $name, $p);
        } elseif ($name === 'sugar_cane' || $name === 'cactus') {
            $this->growTall($x, $y, $z, $name, $p);
        } elseif ($name === 'lava') {
            // LiquidBlock.randomTick passes the tick to the fluid, and ServerLevel.tickChunk then
            // random-ticks the fluid state itself, so lava gets two.
            $this->lavaTick($x, $y, $z);
            $this->lavaTick($x, $y, $z);
        } elseif ($name === 'farmland') {
            $this->farmlandTick($x, $y, $z, $p);
        } elseif ($name === 'ice' || $name === 'snow') {
            // IceBlock and SnowLayerBlock melt in block light above 11.
            if ($this->blockLight($x, $y, $z) > 11 - ($name === 'ice' ? $this->dampening($state) : 0)) {
                if ($name === 'snow') {
                    $this->dropBlockResources($x, $y, $z, $state);
                    $this->setBlock($x, $y, $z, $catalog->state('air'));
                } else {
                    $this->setBlock($x, $y, $z, $this->world->dimension === 'the_nether' ? $catalog->state('air') : $catalog->state('water'));
                }
            }
        }
    }

    /**
     * Random ticks of growing plants, bamboo, budding amethyst, mushrooms and lit redstone ore
     * (GrowingPlantHeadBlock, SweetBerryBushBlock, CocoaBlock, BambooStalkBlock,
     * BambooSaplingBlock, BuddingAmethystBlock, MushroomBlock, RedStoneOreBlock).
     *
     * @param array<string, string> $p
     */
    private function plantTick(int $x, int $y, int $z, string $name, array $p): void
    {
        $catalog = $this->version->catalog;
        $with = fn (array $changes): int => $catalog->state($name, array_merge($p, array_map('strval', $changes)));
        if (isset(self::PLANT_HEADS[$name])) {
            [$chance, $step, $body] = self::PLANT_HEADS[$name];
            if ((int) $p['age'] >= 25 || self::randomUnit() >= $chance) {
                return;
            }
            $target = $this->world->get($x, $y + $step, $z);
            $into = $name === 'kelp' ? $catalog->blockName($target) === 'water' : $catalog->air($target);
            if (!$into || $y + $step < $this->world->minY || $y + $step >= $this->world->minY + $this->world->height) {
                return;
            }
            $grown = ['age' => (int) $p['age'] + 1];
            if ($name === 'cave_vines') {
                $grown['berries'] = self::randomUnit() < 0.11 ? 'true' : 'false';
            }
            $this->setBlock($x, $y + $step, $z, $with($grown));
            // GrowingPlantHeadBlock.updateShape: the old head becomes the plant's body.
            $this->setBlock($x, $y, $z, $catalog->state($body, $name === 'cave_vines' ? ['berries' => $p['berries']] : []));
            return;
        }
        switch ($name) {
            case 'sweet_berry_bush':
                if ((int) $p['age'] < 3 && random_int(0, 4) === 0 && $this->world->rawBrightness($x, $y + 1, $z) >= 9) {
                    $this->setBlock($x, $y, $z, $with(['age' => (int) $p['age'] + 1]));
                }
                return;
            case 'cocoa':
                if (random_int(0, 4) === 0 && (int) $p['age'] < 2) {
                    $this->setBlock($x, $y, $z, $with(['age' => (int) $p['age'] + 1]));
                }
                return;
            case 'redstone_ore':
            case 'deepslate_redstone_ore':
                if ($p['lit'] === 'true') {
                    $this->setBlock($x, $y, $z, $with(['lit' => 'false']));
                }
                return;
            case 'bamboo_sapling':
                if (random_int(0, 2) === 0 && $catalog->air($this->world->get($x, $y + 1, $z)) && $this->world->rawBrightness($x, $y + 1, $z) >= 9) {
                    $this->setBlock($x, $y + 1, $z, $catalog->state('bamboo', ['leaves' => 'small']));
                    // BambooSaplingBlock.updateShape: with bamboo above, the shoot becomes bamboo.
                    $this->setBlock($x, $y, $z, $catalog->state('bamboo'));
                }
                return;
            case 'bamboo':
                if ($p['stage'] !== '0' || random_int(0, 2) !== 0 || !$catalog->air($this->world->get($x, $y + 1, $z)) || $this->world->rawBrightness($x, $y + 1, $z) < 9) {
                    return;
                }
                $height = 0;
                while ($height < 16 && $catalog->blockName($this->world->get($x, $y - $height - 1, $z)) === 'bamboo') {
                    ++$height;
                }
                if (++$height < 16) {
                    $this->growBamboo($x, $y, $z, $p, $height);
                }
                return;
            case 'budding_amethyst':
                $this->growAmethyst($x, $y, $z);
                return;
            case 'brown_mushroom':
            case 'red_mushroom':
                $this->spreadMushroom($x, $y, $z, $name);
                return;
        }
    }

    /** BambooStalkBlock.growBamboo: the new top gets leaves and the stalk's leaves shift down. */
    private function growBamboo(int $x, int $y, int $z, array $p, int $height): void
    {
        $catalog = $this->version->catalog;
        $below = $this->world->get($x, $y - 1, $z);
        $twoBelow = $this->world->get($x, $y - 2, $z);
        $isBamboo = static fn (int $state): bool => $catalog->blockName($state) === 'bamboo';
        $leaves = 'none';
        if ($height >= 1) {
            if (!$isBamboo($below) || $catalog->properties($below)['leaves'] === 'none') {
                $leaves = 'small';
            } else {
                $leaves = 'large';
                if ($isBamboo($twoBelow)) {
                    $this->setBlock($x, $y - 1, $z, $catalog->state('bamboo', array_merge($catalog->properties($below), ['leaves' => 'small'])));
                    $this->setBlock($x, $y - 2, $z, $catalog->state('bamboo', array_merge($catalog->properties($twoBelow), ['leaves' => 'none'])));
                }
            }
        }
        $age = $p['age'] !== '1' && !$isBamboo($twoBelow) ? 0 : 1;
        $stage = ($height < 11 || !(self::randomUnit() < 0.25)) && $height !== 15 ? 0 : 1;
        $this->setBlock($x, $y + 1, $z, $catalog->state('bamboo', ['age' => (string) $age, 'leaves' => $leaves, 'stage' => (string) $stage]));
        // BambooStalkBlock.updateShape: a stalk under older bamboo ages too, down the stalk.
        for ($b = $y; $isBamboo($this->world->get($x, $b, $z)); --$b) {
            $state = $this->world->get($x, $b, $z);
            if ((int) $catalog->properties($this->world->get($x, $b + 1, $z))['age'] <= (int) $catalog->properties($state)['age']) {
                break;
            }
            $this->setBlock($x, $b, $z, $catalog->state('bamboo', array_merge($catalog->properties($state), ['age' => '1'])));
        }
    }

    /** BuddingAmethystBlock.randomTick: a bud on a random face grows a stage. */
    private function growAmethyst(int $x, int $y, int $z): void
    {
        if (random_int(0, 4) !== 0) {
            return;
        }
        $catalog = $this->version->catalog;
        [$face, $dx, $dy, $dz] = [['down', 0, -1, 0], ['up', 0, 1, 0], ['north', 0, 0, -1], ['south', 0, 0, 1], ['west', -1, 0, 0], ['east', 1, 0, 0]][random_int(0, 5)];
        $target = $this->world->get($x + $dx, $y + $dy, $z + $dz);
        $name = $catalog->blockName($target);
        $water = $name === 'water' && $catalog->properties($target)['level'] === '0';
        $stages = ['small_amethyst_bud' => 'medium_amethyst_bud', 'medium_amethyst_bud' => 'large_amethyst_bud', 'large_amethyst_bud' => 'amethyst_cluster'];
        $next = null;
        if ($catalog->air($target) || $water) {
            $next = 'small_amethyst_bud';
        } elseif (isset($stages[$name]) && $catalog->properties($target)['facing'] === $face) {
            $next = $stages[$name];
            $water = ($catalog->properties($target)['waterlogged'] ?? 'false') === 'true';
        }
        if ($next !== null) {
            $this->setBlock($x + $dx, $y + $dy, $z + $dz, $catalog->state($next, ['facing' => $face, 'waterlogged' => $water ? 'true' : 'false']));
        }
    }

    /** MushroomBlock.randomTick: one in 25, a mushroom with few like it near spreads a short walk away. */
    private function spreadMushroom(int $x, int $y, int $z, string $name): void
    {
        if (random_int(0, 24) !== 0) {
            return;
        }
        $catalog = $this->version->catalog;
        $count = 0;
        for ($dx = -4; $dx <= 4; ++$dx) {
            for ($dy = -1; $dy <= 1; ++$dy) {
                for ($dz = -4; $dz <= 4; ++$dz) {
                    if ($catalog->blockName($this->world->get($x + $dx, $y + $dy, $z + $dz)) === $name && ++$count > 4) {
                        return;
                    }
                }
            }
        }
        $survives = function (int $a, int $b, int $c): bool {
            $below = $this->world->get($a, $b - 1, $c);
            if ($this->fireBlocks()->inTag($below, 'overrides_mushroom_light_requirement')) {
                return true;
            }
            return $this->world->rawBrightness($a, $b, $c) < 13 && $this->fireBlocks()->flag($below, \PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks::SOLID_RENDER);
        };
        $step = static fn (array $from): array => [$from[0] + random_int(0, 2) - 1, $from[1] + random_int(0, 1) - random_int(0, 1), $from[2] + random_int(0, 2) - 1];
        $position = [$x, $y, $z];
        $offset = $step($position);
        for ($i = 0; $i < 4; ++$i) {
            if ($catalog->air($this->world->get(...$offset)) && $survives(...$offset)) {
                $position = $offset;
            }
            $offset = $step($position);
        }
        if ($catalog->air($this->world->get(...$offset)) && $survives(...$offset)) {
            $this->setBlock($offset[0], $offset[1], $offset[2], $catalog->state($name));
        }
    }

    /**
     * The unaffected copper block a weathering copper block ages from and its WeatherState
     * (0 unaffected, 1 exposed, 2 weathered, 3 oxidized), or null for anything else (waxed
     * copper included).
     *
     * @return array{0: string, 1: int}|null
     */
    private static function copperAge(string $name): ?array
    {
        foreach (['oxidized_' => 3, 'weathered_' => 2, 'exposed_' => 1, '' => 0] as $prefix => $age) {
            if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                continue;
            }
            $base = substr($name, strlen($prefix));
            if ($age > 0 && $base === 'copper') {
                $base = 'copper_block';
            }
            if (in_array($base, self::COPPER, true)) {
                return [$base, $age];
            }
        }
        return null;
    }

    /**
     * ChangeOverTimeBlock.changeOverTime: about once a day per block it may age a stage, unless
     * younger copper is within 4 blocks; more older neighbours make it likelier.
     *
     * @param array{0: string, 1: int} $age
     */
    private function weatherCopper(int $x, int $y, int $z, int $state, array $age): void
    {
        if (self::randomUnit() >= 0.05688889) {
            return;
        }
        $catalog = $this->version->catalog;
        [$base, $own] = $age;
        $same = 0;
        $older = 0;
        for ($dx = -4; $dx <= 4; ++$dx) {
            for ($dy = -4 + abs($dx); $dy <= 4 - abs($dx); ++$dy) {
                $reach = 4 - abs($dx) - abs($dy);
                for ($dz = -$reach; $dz <= $reach; ++$dz) {
                    if ($dx === 0 && $dy === 0 && $dz === 0) {
                        continue;
                    }
                    $other = self::copperAge($catalog->blockName($this->world->get($x + $dx, $y + $dy, $z + $dz)));
                    if ($other === null) {
                        continue;
                    }
                    if ($other[1] < $own) {
                        return;
                    }
                    $other[1] > $own ? ++$older : ++$same;
                }
            }
        }
        $chance = ($older + 1) / ($older + $same + 1);
        if (self::randomUnit() >= $chance * $chance * ($own === 0 ? 0.75 : 1.0)) {
            return;
        }
        $stem = $base === 'copper_block' ? 'copper' : $base;
        $next = ['exposed_', 'weathered_', 'oxidized_'][$own] . $stem;
        $properties = $catalog->properties($state);
        $this->setBlock($x, $y, $z, $catalog->state($next, $properties));
        // A copper door's upper half follows its lower half.
        if (str_ends_with($base, 'copper_door') && ($properties['half'] ?? '') === 'lower') {
            $upper = $this->world->get($x, $y + 1, $z);
            if (self::copperAge($catalog->blockName($upper)) === $age) {
                $this->setBlock($x, $y + 1, $z, $catalog->state($next, $catalog->properties($upper)));
            }
        }
    }

    private function blockLight(int $x, int $y, int $z): int
    {
        return $this->world->rawBrightness($x, $y, $z, true);
    }

    /** SpreadingSnowyBlock.randomTick */
    private function spreadSnowyBlock(int $x, int $y, int $z, string $name): void
    {
        $catalog = $this->version->catalog;
        $alive = function (int $a, int $b, int $c) use ($catalog): bool {
            $above = $this->world->get($a, $b + 1, $c);
            $aboveName = $catalog->blockName($above);
            if ($aboveName === 'snow' && $catalog->properties($above)['layers'] === '1') {
                return true;
            }
            if (in_array($aboveName, ['water', 'lava'], true) && in_array($catalog->properties($above)['level'], ['0', '8'], true)) {
                return false;
            }
            return $this->dampening($above) < 15;
        };
        if (!$alive($x, $y, $z)) {
            $this->setBlock($x, $y, $z, $catalog->state('dirt'));
            return;
        }
        if ($this->world->rawBrightness($x, $y + 1, $z) < 9) {
            return;
        }
        for ($i = 0; $i < 4; ++$i) {
            [$a, $b, $c] = [$x + random_int(0, 2) - 1, $y + random_int(0, 4) - 3, $z + random_int(0, 2) - 1];
            if ($catalog->blockName($this->world->get($a, $b, $c)) === 'dirt' && $alive($a, $b, $c)
                && !in_array($catalog->blockName($this->world->get($a, $b + 1, $c)), ['water'], true) && ($catalog->properties($this->world->get($a, $b + 1, $c))['waterlogged'] ?? 'false') !== 'true') {
                $snowy = in_array($catalog->blockName($this->world->get($a, $b + 1, $c)), ['snow', 'snow_block', 'powder_snow'], true);
                $this->setBlock($a, $b, $c, $catalog->state($name, ['snowy' => $snowy ? 'true' : 'false']));
            }
        }
    }

    /** CropBlock and StemBlock random ticks, with CropBlock.getGrowthSpeed. */
    private function growCrop(int $x, int $y, int $z, string $name, array $p): void
    {
        $catalog = $this->version->catalog;
        if ($this->world->rawBrightness($x, $y, $z) < 9) {
            return;
        }
        if (random_int(0, (int) (25.0 / $this->cropGrowthSpeed($x, $y, $z, $name))) !== 0) {
            return;
        }
        $age = (int) $p['age'];
        if ($name === 'torchflower_crop') {
            // TorchflowerCropBlock.getStateForAge: at age 2 the crop is a torchflower.
            $this->setBlock($x, $y, $z, $age + 1 >= 2 ? $catalog->state('torchflower') : $catalog->state($name, ['age' => (string) ($age + 1)]));
            return;
        }
        if (isset(self::CROPS[$name]) || $age < 7) {
            if ($age < (self::CROPS[$name] ?? 7)) {
                $this->setBlock($x, $y, $z, $catalog->state($name, ['age' => (string) ($age + 1)]));
            }
            return;
        }
        // A grown stem bears its fruit on a random side over dirt or farmland.
        [$dx, $dz, $facing] = [[0, -1, 'north'], [1, 0, 'east'], [0, 1, 'south'], [-1, 0, 'west']][random_int(0, 3)];
        $below = $catalog->blockName($this->world->get($x + $dx, $y - 1, $z + $dz));
        if ($catalog->air($this->world->get($x + $dx, $y, $z + $dz)) && in_array($below, ['farmland', 'dirt', 'coarse_dirt', 'podzol', 'grass_block', 'mud', 'muddy_mangrove_roots', 'rooted_dirt', 'moss_block', 'pale_moss_block'], true)) {
            $fruit = $name === 'melon_stem' ? 'melon' : 'pumpkin';
            $this->setBlock($x + $dx, $y, $z + $dz, $catalog->state($fruit));
            $this->setBlock($x, $y, $z, $catalog->state('attached_' . $name, ['facing' => $facing]));
        }
    }

    /** CropBlock.getGrowthSpeed: farmland under and around the crop, halved among rows of the same crop. */
    private function cropGrowthSpeed(int $x, int $y, int $z, string $name): float
    {
        $catalog = $this->version->catalog;
        $speed = 1.0;
        for ($dx = -1; $dx <= 1; ++$dx) {
            for ($dz = -1; $dz <= 1; ++$dz) {
                $below = $this->world->get($x + $dx, $y - 1, $z + $dz);
                $blockSpeed = 0.0;
                if ($catalog->blockName($below) === 'farmland') {
                    $blockSpeed = (int) $catalog->properties($below)['moisture'] > 0 ? 3.0 : 1.0;
                }
                $speed += ($dx !== 0 || $dz !== 0) ? $blockSpeed / 4.0 : $blockSpeed;
            }
        }
        $same = fn (int $a, int $c): bool => $catalog->blockName($this->world->get($a, $y, $c)) === $name;
        $horizontal = $same($x - 1, $z) || $same($x + 1, $z);
        $vertical = $same($x, $z - 1) || $same($x, $z + 1);
        if ($horizontal && $vertical || $same($x - 1, $z - 1) || $same($x + 1, $z - 1) || $same($x + 1, $z + 1) || $same($x - 1, $z + 1)) {
            $speed /= 2.0;
        }
        return $speed;
    }

    /** PitcherCropBlock.randomTick: the lower half grows by the crop's speed, without needing light to roll. */
    private function pitcherTick(int $x, int $y, int $z, array $p): void
    {
        if (random_int(0, (int) (25.0 / $this->cropGrowthSpeed($x, $y, $z, 'pitcher_crop'))) === 0) {
            $this->growPitcher($x, $y, $z, $p);
        }
    }

    /** PitcherCropBlock.canGrow: under age 4, light 8, and room above once it is two blocks tall. */
    private function pitcherCanGrow(int $x, int $y, int $z, array $p): bool
    {
        $catalog = $this->version->catalog;
        $above = $this->world->get($x, $y + 1, $z);
        return (int) $p['age'] < 4 && $this->world->rawBrightness($x, $y, $z) >= 8 && $y + 1 < $this->world->minY + $this->world->height
            && ((int) $p['age'] + 1 < 3 || $catalog->air($above) || $catalog->blockName($above) === 'pitcher_crop');
    }

    /** PitcherCropBlock.grow: one age, with an upper half from age 3. */
    private function growPitcher(int $x, int $y, int $z, array $p): void
    {
        if (!$this->pitcherCanGrow($x, $y, $z, $p)) {
            return;
        }
        $catalog = $this->version->catalog;
        $age = (string) ((int) $p['age'] + 1);
        $this->setBlock($x, $y, $z, $catalog->state('pitcher_crop', ['age' => $age, 'half' => 'lower']));
        if ((int) $age >= 3) {
            $this->setBlock($x, $y + 1, $z, $catalog->state('pitcher_crop', ['age' => $age, 'half' => 'upper']));
        }
    }

    /**
     * SugarCaneBlock and CactusBlock random ticks: grow up to three tall, and a cactus at age 8
     * may sprout a flower. Ages change on the server only (block flags 260), so clients keep the
     * age they last saw.
     */
    private function growTall(int $x, int $y, int $z, string $name, array $p): void
    {
        $catalog = $this->version->catalog;
        if (!$catalog->air($this->world->get($x, $y + 1, $z))) {
            return;
        }
        $height = 1;
        $age = (int) $p['age'];
        while ($catalog->blockName($this->world->get($x, $y - $height, $z)) === $name) {
            if (++$height === 3 && $name === 'cactus' && $age === 15) {
                return;
            }
        }
        if ($name === 'sugar_cane') {
            if ($height >= 3) {
                return;
            }
            if ($age === 15) {
                $this->setBlock($x, $y + 1, $z, $catalog->state($name));
                $this->setBlockQuietly($x, $y, $z, $catalog->state($name, ['age' => '0']));
            } else {
                $this->setBlockQuietly($x, $y, $z, $catalog->state($name, ['age' => (string) ($age + 1)]));
            }
            return;
        }
        if ($age === 8 && $this->cactusSurvives($x, $y + 1, $z)) {
            if (self::randomUnit() <= ($height >= 3 ? 0.25 : 0.1)) {
                $this->setBlock($x, $y + 1, $z, $catalog->state('cactus_flower'));
            }
        } elseif ($age === 15 && $height < 3) {
            $this->setBlock($x, $y + 1, $z, $catalog->state($name));
            $this->setBlockQuietly($x, $y, $z, $catalog->state($name, ['age' => '0']));
        }
        if ($age < 15) {
            $this->setBlockQuietly($x, $y, $z, $catalog->state($name, ['age' => (string) ($age + 1)]));
        }
    }

    /** CactusBlock.canSurvive for a cactus placed on one: no solid block or lava beside it, no liquid above. */
    private function cactusSurvives(int $x, int $y, int $z): bool
    {
        $blocks = $this->fireBlocks();
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]) {
            $state = $this->world->get($x + $dx, $y, $z + $dz);
            if ($blocks->isSolid($state) || $blocks->isLava($state)) {
                return false;
            }
        }
        return !$blocks->hasFluid($this->world->get($x, $y + 1, $z));
    }

    /**
     * Level.setBlock without UPDATE_CLIENTS or neighbour updates (flags 260): only the server's
     * block changes, as for growing ages and stages.
     */
    private function setBlockQuietly(int $x, int $y, int $z, int $state): void
    {
        $this->world->set($x, $y, $z, $state);
        $this->randomTickChanged($x, $y, $z, $state);
    }

    /** FarmlandBlock.randomTick: wet near water, drying out and turning back to dirt without it. */
    private function farmlandTick(int $x, int $y, int $z, array $p): void
    {
        $catalog = $this->version->catalog;
        $water = false;
        for ($dx = -4; $dx <= 4 && !$water; ++$dx) {
            for ($dy = 0; $dy <= 1 && !$water; ++$dy) {
                for ($dz = -4; $dz <= 4 && !$water; ++$dz) {
                    $state = $this->world->get($x + $dx, $y + $dy, $z + $dz);
                    $water = ($this->fluids()->fluid($state)[0] ?? null) === 'water';
                }
            }
        }
        $moisture = (int) $p['moisture'];
        if ($water) {
            if ($moisture < 7) {
                $this->setBlock($x, $y, $z, $catalog->state('farmland', ['moisture' => '7']));
            }
        } elseif ($moisture > 0) {
            $this->setBlock($x, $y, $z, $catalog->state('farmland', ['moisture' => (string) ($moisture - 1)]));
        } elseif (!isset(self::CROPS[$catalog->blockName($this->world->get($x, $y + 1, $z))]) && !in_array($catalog->blockName($this->world->get($x, $y + 1, $z)), ['melon_stem', 'pumpkin_stem', 'attached_melon_stem', 'attached_pumpkin_stem', 'torchflower_crop', 'pitcher_crop'], true)) {
            $this->setBlock($x, $y, $z, $catalog->state('dirt'));
        }
    }

    /** LeavesBlock.updateShape: leaves next to a change recompute their distance on the next tick. */
    private function leavesChanged(int $x, int $y, int $z): void
    {
        foreach ([[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]] as [$dx, $dy, $dz]) {
            if (str_ends_with($this->version->catalog->blockName($this->world->get($x + $dx, $y + $dy, $z + $dz)), '_leaves')) {
                $this->scheduleFluid('leaves', $x + $dx, $y + $dy, $z + $dz, 1);
            }
        }
    }

    /** LeavesBlock.tick: the distance to the nearest log, through other leaves, up to 7. */
    private function tickLeaves(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $name = $catalog->blockName($state);
        if (!str_ends_with($name, '_leaves')) {
            return;
        }
        $distance = 7;
        foreach ([[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]] as [$dx, $dy, $dz]) {
            $neighbour = $this->world->get($x + $dx, $y + $dy, $z + $dz);
            $distance = min($distance, $this->leafDistanceAt($neighbour) + 1);
            if ($distance === 1) {
                break;
            }
        }
        $p = $catalog->properties($state);
        if ((int) $p['distance'] !== $distance) {
            $p['distance'] = (string) $distance;
            $this->setBlock($x, $y, $z, $catalog->state($name, $p));
        }
    }

    /** LeavesBlock.getDistanceAt: logs count 0, leaves their distance, anything else 7. */
    private function leafDistanceAt(int $state): int
    {
        static $preventing = null;
        $preventing ??= array_flip($this->version->blockTags()['minecraft:prevents_nearby_leaf_decay'] ?? []);
        $name = $this->version->catalog->blockName($state);
        if (isset($preventing[$name])) {
            return 0;
        }
        return isset($this->version->catalog->properties($state)['distance']) && str_ends_with($name, '_leaves') ? (int) $this->version->catalog->properties($state)['distance'] : 7;
    }
}
