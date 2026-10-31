<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\World\Vanilla\BiomeClimate;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Decorator;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\ProtoChunk;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\WorldgenRandom;

/**
 * SaplingBlock and TreeGrower: saplings in enough light grow a stage, then into the tree their
 * grower picks, placed by the worldgen tree features on a copy of the 3×3 chunks around them.
 */
trait TreeGrowth
{
    private ?Decorator $treeDecorator = null;

    /**
     * TreeGrower's weighted trees, mega trees (2×2 saplings) and trees grown near flowers.
     *
     * @var array<string, array{0: list<array{0: string, 1: int}>, 1: list<array{0: string, 1: int}>, 2: list<array{0: string, 1: int}>}>
     */
    private const GROWERS = [
        'oak_sapling' => [[['oak', 9], ['fancy_oak', 1]], [], [['oak_bees_005', 9], ['fancy_oak_bees_005', 1]]],
        'spruce_sapling' => [[['spruce', 1]], [['mega_spruce', 1], ['mega_pine', 1]], []],
        'mangrove_propagule' => [[['mangrove', 15], ['tall_mangrove', 85]], [], []],
        'birch_sapling' => [[['birch', 1]], [], [['birch_bees_005', 1]]],
        'jungle_sapling' => [[['jungle_tree_no_vine', 1]], [['mega_jungle_tree', 1]], []],
        'acacia_sapling' => [[['acacia', 1]], [], []],
        'cherry_sapling' => [[['cherry', 1]], [], [['cherry_bees_005', 1]]],
        'dark_oak_sapling' => [[], [['dark_oak', 1]], []],
        'pale_oak_sapling' => [[], [['pale_oak_bonemeal', 1]], []],
        'poplar_sapling' => [[['red_poplar', 1], ['orange_poplar', 1], ['yellow_poplar', 1]], [], []],
    ];

    /**
     * Level.skyDarken: 15 less the dimension's gameplay/sky_light_level, which the overworld's day
     * timeline scales through dusk and dawn and rain and thunder blend towards 4.
     */
    private function skyDarken(): int
    {
        $f = static fn (float $v): float => unpack('g', pack('g', $v))[1];
        if ($this->world->dimension !== 'overworld') {
            return $this->world->dimension === 'the_nether' ? 11 : 0;
        }
        // The day timeline's sky_light_level keyframes, looping every 24000 ticks.
        $frames = [[-1670, 0.26666668], [133, 1.0], [11867, 1.0], [13670, 0.26666668], [22330, 0.26666668], [24133, 1.0]];
        $time = (($this->dayTime % 24000) + 24000) % 24000;
        $factor = 1.0;
        for ($i = 0; $i < count($frames) - 1; ++$i) {
            [[$from, $a], [$to, $b]] = [$frames[$i], $frames[$i + 1]];
            if ($time < $to) {
                $factor = $time <= $from ? $a : $f($a + $f(($time - $from) / ($to - $from)) * ($b - $a));
                break;
            }
        }
        $level = $f(15.0 * $factor);
        if ($this->weather === 'rain') {
            $level = $f($level + 0.3125 * (4.0 - $level));
        } elseif ($this->weather === 'thunder') {
            $level = $f($level + 0.52734375 * (4.0 - $level));
        }
        return (int) (15.0 - $level);
    }

    /** SaplingBlock.randomTick and MangrovePropaguleBlock.randomTick */
    private function saplingTick(int $x, int $y, int $z, string $name, array $p): void
    {
        if ($name === 'mangrove_propagule') {
            if ($p['hanging'] === 'true') {
                if ((int) $p['age'] < 4) {
                    $this->setBlock($x, $y, $z, $this->version->catalog->state($name, array_merge($p, ['age' => (string) ((int) $p['age'] + 1)])));
                }
                return;
            }
            if (random_int(0, 6) !== 0) {
                return;
            }
        } elseif ($this->world->rawBrightness($x, $y + 1, $z, false, $this->skyDarken()) < 9 || random_int(0, 6) !== 0) {
            return;
        }
        if ($p['stage'] === '0') {
            $this->setBlockQuietly($x, $y, $z, $this->version->catalog->state($name, array_merge($p, ['stage' => '1'])));
            return;
        }
        $this->growTree($x, $y, $z, $name);
    }

    /** TreeGrower.growTree */
    private function growTree(int $x, int $y, int $z, string $name): bool
    {
        $catalog = $this->version->catalog;
        [$trees, $megaTrees, $flowerTrees] = self::GROWERS[$name];
        $random = new WorldgenRandom(random_int(PHP_INT_MIN, PHP_INT_MAX));
        $pick = static function (array $list) use ($random): ?string {
            if ($list === []) {
                return null;
            }
            $selection = $random->nextInt(array_sum(array_column($list, 1)));
            foreach ($list as [$feature, $weight]) {
                $selection -= $weight;
                if ($selection < 0) {
                    return $feature;
                }
            }
            return null;
        };
        $mega = $pick($megaTrees);
        if ($mega !== null) {
            for ($dx = 0; $dx >= -1; --$dx) {
                for ($dz = 0; $dz >= -1; --$dz) {
                    $saplings = [];
                    foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$ox, $oz]) {
                        $saplings[] = [$x + $dx + $ox, $y, $z + $dz + $oz, $this->world->get($x + $dx + $ox, $y, $z + $dz + $oz)];
                    }
                    if (array_filter($saplings, fn (array $s): bool => $catalog->blockName($s[3]) !== $name) === []) {
                        return $this->placeTree($mega, $x + $dx, $y, $z + $dz, $saplings, $random);
                    }
                }
            }
        }
        $flowers = false;
        $blocks = $this->treeDecorator()->blocks;
        for ($a = -2; $a <= 2 && !$flowers; ++$a) {
            for ($b = -1; $b <= 1 && !$flowers; ++$b) {
                for ($c = -2; $c <= 2 && !$flowers; ++$c) {
                    $flowers = $blocks->inTag($this->world->get($x + $a, $y + $b, $z + $c), 'flowers');
                }
            }
        }
        $feature = $pick($flowers && $flowerTrees !== [] ? $flowerTrees : $trees);
        return $feature !== null && $this->placeTree($feature, $x, $y, $z, [[$x, $y, $z, $this->world->get($x, $y, $z)]], $random);
    }

    /**
     * TreeGrower.removeSaplings, Feature.place and resetSaplings: the saplings give way to the
     * fluid they held, the tree is placed on a copy of the surrounding chunks, and the blocks it
     * changed are set in the world. A tree that does not fit puts the saplings back.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $saplings
     */
    private function placeTree(string $feature, int $x, int $y, int $z, array $saplings, WorldgenRandom $random): bool
    {
        $catalog = $this->version->catalog;
        foreach ($saplings as [$sx, $sy, $sz, $state]) {
            $this->setBlock($sx, $sy, $sz, $catalog->state(($catalog->properties($state)['waterlogged'] ?? 'false') === 'true' ? 'water' : 'air'));
        }
        $decorator = $this->treeDecorator();
        [$cx, $cz] = [$x >> 4, $z >> 4];
        $height = $this->world->height;
        $chunks = [];
        $before = [];
        for ($dx = -1; $dx <= 1; ++$dx) {
            for ($dz = -1; $dz <= 1; ++$dz) {
                $sections = $this->world->sections($cx + $dx, $cz + $dz);
                $states = [];
                for ($section = 0; $section < intdiv($height, 16); ++$section) {
                    array_push($states, ...($sections[$section] ?? array_fill(0, 4096, 0)));
                }
                $before[($cx + $dx) . ',' . ($cz + $dz)] = $states;
                $chunks[($cx + $dx) . ',' . ($cz + $dz)] = new ProtoChunk($cx + $dx, $cz + $dz, $states, [], []);
            }
        }
        $region = new Region($decorator->blocks, $cx, $cz, $chunks, $this->world->minY, $height, 63, 0, $this->world->seed);
        if (!$decorator->place($feature, $region, $random, $x, $y, $z)) {
            foreach ($saplings as [$sx, $sy, $sz, $state]) {
                $this->setBlock($sx, $sy, $sz, $state);
            }
            return false;
        }
        $changed = [];
        foreach ($chunks as $key => $chunk) {
            foreach (array_diff_assoc($chunk->blocks, $before[$key]) as $index => $state) {
                $changed[] = [$chunk->x * 16 + ($index & 15), $this->world->minY + ($index >> 8), $chunk->z * 16 + (($index >> 4) & 15), $state];
            }
        }
        $this->setTreeBlocks($changed);
        return true;
    }

    /**
     * Set the tree's blocks, then send them as ChunkHolder.broadcastChanges does: one
     * section_blocks_update per section with several changes, block_update for a single one,
     * and the data of block entities among them (bee nests).
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $changes
     */
    private function setTreeBlocks(array $changes): void
    {
        $sections = [];
        foreach ($changes as [$x, $y, $z, $state]) {
            $this->world->set($x, $y, $z, $state);
            $sections[($x >> 4) . ':' . ($y >> 4) . ':' . ($z >> 4)][] = [$x, $y, $z];
        }
        foreach ($changes as [$x, $y, $z]) {
            $this->changedSimulation($x, $y, $z);
            if ($this->world->generator === 'terrain') {
                $this->relightAround($x, $z);
            }
        }
        foreach ($sections as $positions) {
            [$sx, $sy, $sz] = [$positions[0][0] >> 4, $positions[0][1] >> 4, $positions[0][2] >> 4];
            $payload = null;
            if (count($positions) > 1) {
                $payload = Binary::writeLong((($sx & 0x3fffff) << 42) | (($sz & 0x3fffff) << 20) | ($sy & 0xfffff)) . Binary::writeVarInt(count($positions));
                foreach ($positions as [$x, $y, $z]) {
                    $payload .= Binary::writeVarLong(($this->world->get($x, $y, $z) << 12) | (($x & 15) << 8) | (($z & 15) << 4) | ($y & 15));
                }
            }
            foreach ($this->localPlayers() as $player) {
                if (!isset($player->loadedChunks[$sx . ':' . $sz])) {
                    continue;
                }
                if ($payload === null) {
                    $this->blockUpdate($player, ...$positions[0]);
                    continue;
                }
                $player->send($this->version, 'section_blocks_update', $payload);
                foreach ($positions as [$x, $y, $z]) {
                    $type = $this->world->blockEntityType($this->world->get($x, $y, $z));
                    if ($type !== null) {
                        $player->send($this->version, 'block_entity_data', Binary::writePosition($x, $y, $z) . Binary::writeVarInt($type) . $this->world->blockEntityNbt($x, $y, $z));
                    }
                }
            }
        }
    }

    private function treeDecorator(): Decorator
    {
        $directory = $this->version->directory . '/worldgen';
        return $this->treeDecorator ??= new Decorator(new Blocks($this->version, $directory . '/block-behaviour.json'), new BiomeClimate($directory), $directory);
    }
}
