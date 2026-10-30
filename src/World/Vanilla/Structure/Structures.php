<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\Int64;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Vanilla\BiomeSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Providers;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;
use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\NoiseChunk;
use PhpMinecraftJavaServer\World\Vanilla\OverworldSettings;

/**
 * Structure starts (ChunkGenerator.createStructures) and the starts each chunk places
 * (applyBiomeDecoration). Starts are kept with their pieces' state, which changes as the chunks
 * they overlap are decorated, in the world database when there is one.
 */
final class Structures
{
    /** GenerationStep.Decoration */
    public const STEPS = ['raw_generation', 'lakes', 'local_modifications', 'underground_structures', 'surface_structures', 'strongholds', 'underground_ores', 'underground_decoration', 'fluid_springs', 'vegetal_decoration', 'top_layer_modification'];

    /** StructureManager reference range: starts within 8 chunks can reach a chunk. */
    private const RANGE = 8;

    /** @var array<int, list<string>> every structure of each step, in registry order */
    public readonly array $byStep;

    /** @var list<array{0: string, 1: array}> structure sets that can occur in this dimension, in registry order */
    private array $sets = [];

    /** @var array<string, array> */
    private array $structures = [];

    /** @var array<string, array<string, true>> */
    private array $tags = [];

    /** @var array<string, list<array>> starts by origin chunk when there is no database */
    private array $memory = [];

    /** @var array<string, int> heights by "x,z,type" */
    private array $heights = [];

    private readonly Blocks $blocks;

    private ?Jigsaw $jigsaw = null;

    public function __construct(private readonly OverworldSettings $settings, private readonly BiomeSource $biomes, private readonly string $directory, private readonly ?\PDO $db = null, ?Blocks $blocks = null)
    {
        $this->blocks = $blocks ?? new Blocks(new \PhpMinecraftJavaServer\Protocol\Version(), $directory . '/block-behaviour.json');
        $order = json_decode((string) file_get_contents($directory . '/structure-order.json'), true, 512, JSON_THROW_ON_ERROR);
        $byStep = [];
        foreach ($order['structures'] as [$name, $step]) {
            $byStep[array_search($step, self::STEPS, true)][] = $name;
        }
        $this->byStep = $byStep;
        $possible = array_fill_keys($biomes->possible(), true);
        foreach ($order['structure_sets'] as $name) {
            $set = $this->load('structure_set', $name);
            foreach ($set['structures'] as $entry) {
                if (array_intersect_key($this->biomeSet($this->structure($entry['structure'])['biomes']), $possible) !== []) {
                    $this->sets[] = [$name, $set];
                    break;
                }
            }
        }
        $db?->exec('CREATE TABLE IF NOT EXISTS vanilla_structure_starts(cx INTEGER NOT NULL,cz INTEGER NOT NULL,starts TEXT NOT NULL,PRIMARY KEY(cx,cz))');
    }

    private function load(string $kind, string $name): array
    {
        return json_decode((string) file_get_contents($this->directory . "/$kind/" . preg_replace('/^minecraft:/', '', $name) . '.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function structure(string $name): array
    {
        $name = preg_replace('/^minecraft:/', '', $name);
        return $this->structures[$name] ??= $this->load('structure', $name);
    }

    /** @return array<string, true> a biome HolderSet: a #tag, a biome or a list */
    private function biomeSet(mixed $set): array
    {
        if (is_string($set) && str_starts_with($set, '#')) {
            return $this->tag(substr($set, 1));
        }
        $out = [];
        foreach ((array) $set as $biome) {
            $out[preg_replace('/^minecraft:/', '', $biome)] = true;
        }
        return $out;
    }

    /** @return array<string, true> */
    private function tag(string $name): array
    {
        $name = preg_replace('/^minecraft:/', '', $name);
        if (isset($this->tags[$name])) {
            return $this->tags[$name];
        }
        $this->tags[$name] = [];
        $out = [];
        foreach (json_decode((string) file_get_contents($this->directory . "/tags/biome/$name.json"), true, 512, JSON_THROW_ON_ERROR)['values'] as $value) {
            $id = is_array($value) ? $value['id'] : $value;
            $out += str_starts_with($id, '#') ? $this->tag(substr($id, 1)) : [preg_replace('/^minecraft:/', '', $id) => true];
        }
        return $this->tags[$name] = $out;
    }

    /** WorldgenRandom.setLargeFeatureSeed */
    public static function largeFeatureRandom(int $seed, int $chunkX, int $chunkZ): LegacyRandom
    {
        $random = new LegacyRandom($seed);
        $xScale = $random->nextLong();
        $zScale = $random->nextLong();
        $random->setSeed(Int64::multiply($chunkX, $xScale) ^ Int64::multiply($chunkZ, $zScale) ^ $seed);
        return $random;
    }

    /** WorldgenRandom.setLargeFeatureWithSalt */
    private static function saltedRandom(int $seed, int $x, int $z, int $salt): LegacyRandom
    {
        return new LegacyRandom(Int64::add(Int64::add(Int64::add(Int64::multiply($x, 341873128712), Int64::multiply($z, 132897987541)), $seed), $salt));
    }

    /** AbstractSpreadingStructurePlacement.isStructureChunk without exclusion zones. */
    private function isStructureChunk(array $placement, int $cx, int $cz): bool
    {
        $seed = $this->settings->state->seed;
        switch (preg_replace('/^minecraft:/', '', $placement['type'])) {
            case 'random_spread':
                $spacing = $placement['spacing'];
                $gridX = intdiv($cx - ($cx < 0 ? $spacing - 1 : 0), $spacing);
                $gridZ = intdiv($cz - ($cz < 0 ? $spacing - 1 : 0), $spacing);
                $random = self::saltedRandom($seed, $gridX, $gridZ, $placement['salt']);
                $limit = $spacing - $placement['separation'];
                $triangular = ($placement['spread_type'] ?? 'linear') === 'triangular';
                $spreadX = $triangular ? intdiv($random->nextInt($limit) + $random->nextInt($limit), 2) : $random->nextInt($limit);
                $spreadZ = $triangular ? intdiv($random->nextInt($limit) + $random->nextInt($limit), 2) : $random->nextInt($limit);
                if ($gridX * $spacing + $spreadX !== $cx || $gridZ * $spacing + $spreadZ !== $cz) {
                    return false;
                }
                break;
            default:
                // Concentric rings (strongholds) are not placed yet.
                return false;
        }
        $frequency = F32::r((float) ($placement['frequency'] ?? 1.0));
        if ($frequency >= 1.0) {
            return true;
        }
        $salt = $placement['salt'];
        switch ($placement['frequency_reduction_method'] ?? 'default') {
            case 'legacy_type_1':
                $random = new LegacyRandom(($cx >> 4) ^ (($cz >> 4) << 4) ^ $seed);
                $random->nextInt();
                return $random->nextInt((int) (1.0 / $frequency)) === 0;
            case 'legacy_type_2':
                return self::saltedRandom($seed, $cx, $cz, 10387320)->nextFloat() < $frequency;
            case 'legacy_type_3':
                return self::largeFeatureRandom($seed, $cx, $cz)->nextDouble() < $frequency;
            default:
                return self::saltedRandom($seed, $salt, $cx, $cz)->nextFloat() < $frequency;
        }
    }

    /** ChunkGenerator.getFirstOccupiedHeight from the noise alone. */
    public function firstOccupiedHeight(int $x, int $z, bool $oceanFloor = false): int
    {
        if (count($this->heights) > 4096) {
            $this->heights = [];
        }
        return ($this->heights["$x,$z," . (int) $oceanFloor] ??= NoiseChunk::baseHeight($this->settings, $x, $z, $oceanFloor)) - 1;
    }

    private function validBiome(array $structure, int $x, int $y, int $z): bool
    {
        return isset($this->biomeSet($structure['biomes'])[$this->biomes->uncached($x >> 2, $y >> 2, $z >> 2)]);
    }

    /** Structure.getCornerHeights at an area's corners (one past its size, as vanilla samples them). */
    private function cornerHeights(int $minX, int $minZ, int $sizeX, int $sizeZ): array
    {
        return [
            $this->firstOccupiedHeight($minX, $minZ),
            $this->firstOccupiedHeight($minX, $minZ + $sizeZ),
            $this->firstOccupiedHeight($minX + $sizeX, $minZ),
            $this->firstOccupiedHeight($minX + $sizeX, $minZ + $sizeZ),
        ];
    }

    /** Structure.getLowestY */
    private function lowestY(int $minX, int $minZ, int $sizeX, int $sizeZ): int
    {
        return min($this->cornerHeights($minX, $minZ, $sizeX, $sizeZ));
    }

    /** Structure.getMeanFirstOccupiedHeight */
    private function meanHeight(int $minX, int $minZ, int $sizeX, int $sizeZ): int
    {
        return intdiv(array_sum($this->cornerHeights($minX, $minZ, $sizeX, $sizeZ)), 4);
    }

    /**
     * SinglePieceStructure.findGenerationPoint: every corner of the piece's area is at or above
     * sea level (Structure.getLowestY), and the chunk centre's surface biome is valid.
     */
    private function singlePiece(array $structure, int $cx, int $cz, int $width, int $depth): bool
    {
        $minX = $cx * 16;
        $minZ = $cz * 16;
        if ($this->lowestY($minX, $minZ, $width, $depth) < $this->settings->seaLevel) {
            return false;
        }
        $x = $minX + 8;
        $z = $minZ + 8;
        return $this->validBiome($structure, $x, $this->firstOccupiedHeight($x, $z), $z);
    }

    /**
     * Structure.generate for the structure types that are ported: the start's pieces, an empty
     * list when the structure cannot start here, or null for types not ported yet.
     *
     * @return list<Piece>|null
     */
    private function generate(array $structure, int $cx, int $cz): ?array
    {
        $random = self::largeFeatureRandom($this->settings->state->seed, $cx, $cz);
        switch (preg_replace('/^minecraft:/', '', $structure['type'])) {
            case 'swamp_hut':
                $x = $cx * 16 + 8;
                $z = $cz * 16 + 8;
                if (!$this->validBiome($structure, $x, $this->firstOccupiedHeight($x, $z), $z)) {
                    return [];
                }
                return [SwampHut::create($random, $cx * 16, $cz * 16)];
            case 'jungle_temple':
                return $this->singlePiece($structure, $cx, $cz, 12, 15) ? [JungleTemple::create($random, $cx * 16, $cz * 16)] : [];
            case 'igloo':
                $x = $cx * 16 + 8;
                $z = $cz * 16 + 8;
                if (!$this->validBiome($structure, $x, $this->firstOccupiedHeight($x, $z), $z)) {
                    return [];
                }
                // Rotation.getRandom
                $rotation = $random->nextInt(4);
                return Igloo::pieces($this->blocks, $this->directory, [$cx * 16, 90, $cz * 16], $rotation, $random);
            case 'shipwreck':
                $beached = (bool) ($structure['is_beached'] ?? false);
                $x = $cx * 16 + 8;
                $z = $cz * 16 + 8;
                if (!$this->validBiome($structure, $x, $this->firstOccupiedHeight($x, $z, !$beached), $z)) {
                    return [];
                }
                $rotation = $random->nextInt(4);
                $piece = Shipwreck::create($this->blocks, $this->directory, $cx, $cz, $rotation, $random, $beached);
                if ($piece->tooBig($this->blocks, $this->directory)) {
                    [$minX, , $minZ, $maxX, , $maxZ] = $piece->box;
                    $piece->adjustHeight($beached
                        ? $piece->beachedHeight($this->blocks, $this->directory, $this->lowestY($minX, $minZ, $maxX - $minX + 1, $maxZ - $minZ + 1), $random)
                        : $this->meanHeight($minX, $minZ, $maxX - $minX + 1, $maxZ - $minZ + 1));
                }
                return [$piece];
            case 'buried_treasure':
                $x = $cx * 16 + 8;
                $z = $cz * 16 + 8;
                if (!$this->validBiome($structure, $x, $this->firstOccupiedHeight($x, $z, true), $z)) {
                    return [];
                }
                return [new BuriedTreasure([$cx * 16 + 9, 90, $cz * 16 + 9, $cx * 16 + 9, 90, $cz * 16 + 9], null)];
            case 'jigsaw':
                // JigsawStructure.findGenerationPoint
                $context = new Region($this->blocks, $cx, $cz, [], $this->settings->minY, $this->settings->height, $this->settings->seaLevel, 0, $this->settings->state->seed);
                $height = Providers::height($structure['start_height'], $random, $context);
                $this->jigsaw ??= new Jigsaw($this->blocks, $this->directory, fn (int $x, int $z): int => $this->firstOccupiedHeight($x, $z) + 1);
                $result = $this->jigsaw->generate($structure, $random, [$cx * 16, $height, $cz * 16], $this->settings->minY, $this->settings->minY + $this->settings->height - 1, fn (int $x, int $y, int $z): bool => $this->validBiome($structure, $x, $y, $z), $this->settings->state->seed);
                return $result === null ? [] : $result[0];
            case 'desert_pyramid':
                return $this->singlePiece($structure, $cx, $cz, 21, 21) ? [DesertPyramid::create($random, $cx * 16, $cz * 16)] : [];
        }
        return null;
    }

    /**
     * ChunkGenerator.createStructures: the structures starting in a chunk.
     *
     * @return list<array{structure: string, pieces: list<Piece>}>
     */
    public function create(int $cx, int $cz): array
    {
        $starts = [];
        foreach ($this->sets as [, $set]) {
            if (!$this->isStructureChunk($set['placement'], $cx, $cz)) {
                continue;
            }
            $options = $set['structures'];
            $random = count($options) > 1 ? self::largeFeatureRandom($this->settings->state->seed, $cx, $cz) : null;
            $total = array_sum(array_column($options, 'weight'));
            while ($options !== []) {
                $index = 0;
                if ($random !== null) {
                    $choice = $random->nextInt($total);
                    foreach ($options as $option) {
                        $choice -= $option['weight'];
                        if ($choice < 0) {
                            break;
                        }
                        ++$index;
                    }
                }
                $name = preg_replace('/^minecraft:/', '', $options[$index]['structure']);
                $pieces = $this->generate($this->structure($name), $cx, $cz);
                if ($pieces === null) {
                    // Not ported: count it as generated so the set picks nothing else.
                    break;
                }
                if ($pieces !== []) {
                    $starts[] = ['structure' => $name, 'pieces' => $pieces];
                    break;
                }
                $total -= $options[$index]['weight'];
                array_splice($options, $index, 1);
            }
        }
        return $starts;
    }

    private static function encode(array $starts): string
    {
        return json_encode(array_map(static fn (array $start): array => [
            'structure' => $start['structure'],
            'pieces' => array_map(static fn (Piece $piece): array => ['type' => $piece->type(), 'box' => $piece->box, 'orientation' => $piece->orientation] + $piece->data(), $start['pieces']),
        ], $starts), JSON_THROW_ON_ERROR);
    }

    /** @return list<array{structure: string, pieces: list<Piece>}> */
    private static function decode(string $json): array
    {
        return array_map(static fn (array $start): array => [
            'structure' => $start['structure'],
            'pieces' => array_map(Piece::restore(...), $start['pieces']),
        ], json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * The starts whose pieces reach a chunk, by origin chunk "x,z": all of that chunk's starts,
     * which are saved together, and the indexes of those that reach.
     *
     * @return array<string, array{starts: list<array{structure: string, pieces: list<Piece>}>, reach: list<int>}>
     */
    public function reaching(int $cx, int $cz, bool $all = false): array
    {
        $byOrigin = [];
        if ($this->db !== null) {
            $q = $this->db->prepare('SELECT cx,cz,starts FROM vanilla_structure_starts WHERE cx BETWEEN ? AND ? AND cz BETWEEN ? AND ?');
            $q->execute([$cx - self::RANGE, $cx + self::RANGE, $cz - self::RANGE, $cz + self::RANGE]);
            foreach ($q->fetchAll(\PDO::FETCH_NUM) as [$x, $z, $json]) {
                $byOrigin["$x,$z"] = self::decode($json);
            }
        }
        $insert = $this->db?->prepare('INSERT OR IGNORE INTO vanilla_structure_starts(cx,cz,starts) VALUES(?,?,?)');
        $column = [$cx * 16, PHP_INT_MIN, $cz * 16, $cx * 16 + 15, PHP_INT_MAX, $cz * 16 + 15];
        $out = [];
        for ($dz = -self::RANGE; $dz <= self::RANGE; ++$dz) {
            for ($dx = -self::RANGE; $dx <= self::RANGE; ++$dx) {
                $key = ($cx + $dx) . ',' . ($cz + $dz);
                if (!isset($byOrigin[$key])) {
                    if ($insert !== null) {
                        $byOrigin[$key] = $this->create($cx + $dx, $cz + $dz);
                        $insert->execute([$cx + $dx, $cz + $dz, self::encode($byOrigin[$key])]);
                    } else {
                        $byOrigin[$key] = $this->memory[$key] ??= $this->create($cx + $dx, $cz + $dz);
                    }
                }
                $reach = [];
                foreach ($byOrigin[$key] as $index => $start) {
                    foreach ($start['pieces'] as $piece) {
                        if (Piece::intersects($piece->box, $column)) {
                            $reach[] = $index;
                            break;
                        }
                    }
                }
                if ($reach !== [] || $all && $byOrigin[$key] !== []) {
                    $out[$key] = ['starts' => $byOrigin[$key], 'reach' => $reach];
                }
            }
        }
        return $out;
    }

    /**
     * Beardifier.forStructuresInChunk: the starts that reference the chunk (their overall box
     * reaches it) and adapt terrain, with their pieces within 12 blocks and junctions in range.
     */
    public function beardifier(int $cx, int $cz): ?Beardifier
    {
        $minX = $cx * 16;
        $minZ = $cz * 16;
        $near = [$minX - 12, PHP_INT_MIN, $minZ - 12, $minX + 15 + 12, PHP_INT_MAX, $minZ + 15 + 12];
        $column = [$minX, PHP_INT_MIN, $minZ, $minX + 15, PHP_INT_MAX, $minZ + 15];
        $rigids = [];
        $junctions = [];
        $affected = null;
        $include = static function (?array $box, array $add): array {
            return $box === null ? $add : [min($box[0], $add[0]), min($box[1], $add[1]), min($box[2], $add[2]), max($box[3], $add[3]), max($box[4], $add[4]), max($box[5], $add[5])];
        };
        foreach ($this->startsNear($cx, $cz) as $start) {
            $adjustment = $this->structure($start['structure'])['terrain_adaptation'] ?? 'none';
            if ($adjustment === 'none' || $start['pieces'] === []) {
                continue;
            }
            $union = null;
            foreach ($start['pieces'] as $piece) {
                $union = $include($union, $piece->box);
            }
            if (!Piece::intersects($union, $column)) {
                continue;
            }
            foreach ($start['pieces'] as $piece) {
                if (!Piece::intersects($piece->box, $near)) {
                    continue;
                }
                if ($piece instanceof JigsawPiece) {
                    if (($piece->element['projection'] ?? 'rigid') === 'rigid') {
                        $rigids[] = [$piece->box, $adjustment, $piece->groundLevelDelta];
                        $affected = $include($affected, $piece->box);
                    }
                    foreach ($piece->junctions as [$jx, $jy, $jz]) {
                        if ($jx > $minX - 12 && $jz > $minZ - 12 && $jx < $minX + 15 + 12 && $jz < $minZ + 15 + 12) {
                            $junctions[] = [$jx, $jy, $jz];
                            $affected = $include($affected, [$jx, $jy, $jz, $jx, $jy, $jz]);
                        }
                    }
                } else {
                    $rigids[] = [$piece->box, $adjustment, 0];
                    $affected = $include($affected, $piece->box);
                }
            }
        }
        if ($affected === null) {
            return null;
        }
        return new Beardifier($rigids, $junctions, [$affected[0] - 24, $affected[1] - 24, $affected[2] - 24, $affected[3] + 24, $affected[4] + 24, $affected[5] + 24]);
    }

    /**
     * Every start within reference range of a chunk.
     *
     * @return list<array{structure: string, pieces: list<Piece>}>
     */
    private function startsNear(int $cx, int $cz): array
    {
        $out = [];
        foreach ($this->reaching($cx, $cz, true) as $entry) {
            array_push($out, ...$entry['starts']);
        }
        return $out;
    }

    /** WorldGenRegion's random: the worldgen_region_random factory at the decorated chunk's origin. */
    public function regionRandom(int $cx, int $cz): \PhpMinecraftJavaServer\World\Generation\RandomSource
    {
        return $this->settings->state->factory('minecraft:worldgen_region_random')->at($cx * 16, 0, $cz * 16);
    }

    /** Keep an origin chunk's starts after a chunk placed some of their pieces. */
    public function save(string $origin, array $starts): void
    {
        if ($this->db === null) {
            $this->memory[$origin] = $starts;
            return;
        }
        [$x, $z] = array_map('intval', explode(',', $origin));
        $this->db->prepare('UPDATE vanilla_structure_starts SET starts=? WHERE cx=? AND cz=?')->execute([self::encode($starts), $x, $z]);
    }
}
