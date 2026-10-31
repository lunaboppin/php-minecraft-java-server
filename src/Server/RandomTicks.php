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
                    isset(self::GROWERS[$name]) => $name !== 'mangrove_propagule' || $p['hanging'] === 'false' || (int) $p['age'] < 4,
                    in_array($name, ['grass_block', 'mycelium', 'farmland', 'sugar_cane', 'cactus', 'ice', 'snow', 'melon_stem', 'pumpkin_stem'], true) => true,
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
        } elseif ($name === 'nether_wart') {
            if ((int) $p['age'] < 3 && random_int(0, 9) === 0) {
                $this->setBlock($x, $y, $z, $with(['age' => (int) $p['age'] + 1]));
            }
        } elseif (isset(self::GROWERS[$name])) {
            $this->saplingTick($x, $y, $z, $name, $p);
        } elseif ($name === 'sugar_cane' || $name === 'cactus') {
            $this->growTall($x, $y, $z, $name, $p);
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
        if (random_int(0, (int) (25.0 / $speed)) !== 0) {
            return;
        }
        $age = (int) $p['age'];
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
