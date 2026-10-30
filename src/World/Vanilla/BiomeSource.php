<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

/**
 * MultiNoiseBiomeSource with Climate.RTree: the nearest biome parameter point to the sampled
 * climate. As in vanilla, the previous result seeds each search, which decides exact ties.
 */
final class BiomeSource
{
    /** @var array{0: list<array{0: int, 1: int}>, 1: ?list<array>, 2: ?string} the root node: bounds, children, and a leaf's biome */
    private array $root;

    private ?array $lastResult = null;

    /** @var array<string, list<string>> */
    private array $chunks = [];

    /** @var list<string> */
    private readonly array $possible;

    /** TheEndBiomeSource replaces the climate search with island height thresholds. */
    private readonly bool $end;

    public function __construct(private readonly OverworldSettings $settings, string $preset = 'overworld')
    {
        $this->end = $preset === 'end';
        if ($this->end) {
            $this->possible = ['the_end', 'end_highlands', 'end_midlands', 'small_end_islands', 'end_barrens'];
            return;
        }
        $rows = json_decode((string) file_get_contents($settings->directory . '/biome-parameters.json'), true, 512, JSON_THROW_ON_ERROR)[$preset];
        $leaves = [];
        foreach ($rows as $row) {
            $bounds = [];
            for ($i = 0; $i < 6; ++$i) {
                $bounds[] = [$row[1 + 2 * $i], $row[2 + 2 * $i]];
            }
            $bounds[] = [$row[13], $row[13]];
            $leaves[] = [$bounds, null, $row[0]];
        }
        $this->root = self::build($leaves);
        $this->possible = array_values(array_unique(array_column($rows, 0)));
    }

    /**
     * BiomeSource.possibleBiomes
     *
     * @return list<string>
     */
    public function possible(): array
    {
        return $this->possible;
    }

    private static function center(array $parameter): int
    {
        return intdiv($parameter[0] + $parameter[1], 2);
    }

    private static function build(array $children): array
    {
        if (count($children) === 1) {
            return $children[0];
        }
        if (count($children) <= 19) {
            usort($children, static function (array $a, array $b): int {
                $magnitude = static function (array $node): int {
                    $total = 0;
                    foreach ($node[0] as $parameter) {
                        $total += abs(self::center($parameter));
                    }
                    return $total;
                };
                return $magnitude($a) <=> $magnitude($b);
            });
            return self::subTree($children);
        }
        $minCost = PHP_INT_MAX;
        $minDimension = -1;
        $minBuckets = null;
        for ($d = 0; $d < 7; ++$d) {
            self::sort($children, $d, false);
            $buckets = self::bucketize($children);
            $cost = 0;
            foreach ($buckets as $bucket) {
                foreach ($bucket[0] as $parameter) {
                    $cost += abs($parameter[1] - $parameter[0]);
                }
            }
            if ($minCost > $cost) {
                $minCost = $cost;
                $minDimension = $d;
                $minBuckets = $buckets;
            }
        }
        self::sort($minBuckets, $minDimension, true);
        return self::subTree(array_map(static fn (array $bucket): array => self::build($bucket[1]), $minBuckets));
    }

    private static function sort(array &$nodes, int $dimension, bool $absolute): void
    {
        usort($nodes, static function (array $a, array $b) use ($dimension, $absolute): int {
            for ($i = 0; $i < 7; ++$i) {
                $d = ($dimension + $i) % 7;
                $ca = self::center($a[0][$d]);
                $cb = self::center($b[0][$d]);
                if ($absolute) {
                    $ca = abs($ca);
                    $cb = abs($cb);
                }
                if ($ca !== $cb) {
                    return $ca <=> $cb;
                }
            }
            return 0;
        });
    }

    private static function bucketize(array $nodes): array
    {
        $expected = (int) (19 ** floor(log(count($nodes) - 0.01) / log(19)));
        $buckets = [];
        $children = [];
        foreach ($nodes as $node) {
            $children[] = $node;
            if (count($children) >= $expected) {
                $buckets[] = self::subTree($children);
                $children = [];
            }
        }
        if ($children !== []) {
            $buckets[] = self::subTree($children);
        }
        return $buckets;
    }

    private static function subTree(array $children): array
    {
        $bounds = $children[0][0];
        foreach ($children as $child) {
            foreach ($child[0] as $d => $parameter) {
                $bounds[$d] = [min($bounds[$d][0], $parameter[0]), max($bounds[$d][1], $parameter[1])];
            }
        }
        return [$bounds, $children, null];
    }

    private static function distance(array $node, array $target): int
    {
        $distance = 0;
        foreach ($node[0] as $i => [$min, $max]) {
            $above = $target[$i] - $max;
            $below = $min - $target[$i];
            $d = $above > 0 ? $above : max($below, 0);
            $distance += $d * $d;
        }
        return $distance;
    }

    private static function search(array $node, array $target, ?array $candidate): array
    {
        if ($node[1] === null) {
            return $node;
        }
        $minDistance = $candidate === null ? PHP_INT_MAX : self::distance($candidate, $target);
        $closest = $candidate;
        foreach ($node[1] as $child) {
            $childDistance = self::distance($child, $target);
            if ($minDistance > $childDistance) {
                $leaf = self::search($child, $target, $closest);
                $leafDistance = $child[1] === null ? $childDistance : self::distance($leaf, $target);
                if ($minDistance > $leafDistance) {
                    $minDistance = $leafDistance;
                    $closest = $leaf;
                }
            }
        }
        return $closest;
    }

    /** @param list<float> $climate temperature, humidity, continentalness, erosion, depth and weirdness */
    public function find(array $climate): string
    {
        $target = [];
        foreach ($climate as $value) {
            // Climate.quantizeCoord: (long) (value * 10000.0F)
            $target[] = (int) F32::r($value * 10000.0);
        }
        $target[] = 0;
        $leaf = self::search($this->root, $target, $this->lastResult);
        $this->lastResult = $leaf;
        return $leaf[2];
    }

    /** A chunk's stored noise biome at a quart; Y is clamped to the chunk's height, as ChunkAccess.getNoiseBiome does. */
    public function quart(int $quartX, int $quartY, int $quartZ): string
    {
        $chunkX = $quartX >> 2;
        $chunkZ = $quartZ >> 2;
        $biomes = $this->chunks["$chunkX,$chunkZ"] ??= $this->chunk($chunkX, $chunkZ);
        if (count($this->chunks) > 256) {
            array_shift($this->chunks);
        }
        $minQuartY = $this->settings->minY >> 2;
        $y = min(max($quartY - $minQuartY, 0), intdiv($this->settings->height, 4) - 1);
        return $biomes[($y << 4) | (($quartZ & 3) << 2) | ($quartX & 3)];
    }

    /**
     * BiomeSource.findClosestBiome3d: sample columns spiralling out from the origin
     * (BlockPos.spiralAround east then south), each at heights stepping out from the origin's
     * (Mth.outFromOrigin), for the first allowed biome.
     *
     * @param list<string> $allowed biome names without "minecraft:"
     * @return array{0: int, 1: int, 2: int, 3: string}|null
     */
    public function findClosest3d(array $allowed, int $x, int $y, int $z, int $radius, int $horizontal, int $vertical, int $minY, int $maxY): ?array
    {
        $candidates = array_intersect_key(array_fill_keys($allowed, true), array_fill_keys($this->possible, true));
        if ($candidates === []) {
            return null;
        }
        // Mth.outFromOrigin(y, minY + 1, maxY + 1, vertical)
        $lower = $minY + 1;
        $upper = $maxY + 1;
        $origin = min(max($y, $lower), $upper);
        $heights = [];
        for ($cursor = $origin; ; ) {
            $heights[] = $cursor;
            $wasNegative = $cursor <= $origin;
            $current = abs($origin - $cursor);
            $next = $origin + $current + $vertical;
            if (!$wasNegative || $origin + $current + $vertical > $upper) {
                $attempt = $origin - $current - ($wasNegative ? $vertical : 0);
                if ($attempt >= $lower) {
                    $next = $attempt;
                }
            }
            $cursor = $next;
            $distance = abs($origin - $cursor);
            if (!($origin - $distance >= $lower || $origin + $distance <= $upper)) {
                break;
            }
        }
        // BlockPos.spiralAround(ZERO, radius / horizontal, EAST, SOUTH)
        $directions = [[1, 0], [0, 1], [-1, 0], [0, -1]];
        $legs = 4 * intdiv($radius, $horizontal);
        [$cx, $cz] = [0, 1];
        $leg = -1;
        $legSize = 0;
        $legIndex = 0;
        while (true) {
            [$dx, $dz] = $directions[($leg + 4) % 4];
            $cx += $dx;
            $cz += $dz;
            if ($legIndex >= $legSize) {
                if ($leg >= $legs) {
                    return null;
                }
                ++$leg;
                $legIndex = 0;
                $legSize = intdiv($leg, 2) + 1;
            }
            ++$legIndex;
            $blockX = $x + $cx * $horizontal;
            $blockZ = $z + $cz * $horizontal;
            foreach ($heights as $blockY) {
                $biome = $this->uncached($blockX >> 2, $blockY >> 2, $blockZ >> 2);
                if (isset($candidates[$biome])) {
                    return [$blockX, $blockY, $blockZ, $biome];
                }
            }
        }
    }

    /** BiomeSource.createUncachedResolver: climate sampled point by point without caches. */
    public function uncached(int $quartX, int $quartY, int $quartZ): string
    {
        if ($this->end) {
            return $this->endBiome($quartX, $quartY, $quartZ);
        }
        $router = $this->settings->json['noise_router'];
        $context = new SamplerContext(false);
        $climate = [];
        foreach (['temperature', 'vegetation', 'continents', 'erosion', 'depth', 'ridges'] as $name) {
            $climate[] = $this->settings->state->sampler($router[$name])->value($context, $quartX << 2, $quartY << 2, $quartZ << 2);
        }
        return $this->find($climate);
    }

    /** TheEndBiomeSource.getNoiseBiome: the main island within 64 chunks, then by the island height at the chunk's centre. */
    private function endBiome(int $quartX, int $quartY, int $quartZ): string
    {
        $chunkX = $quartX >> 2;
        $chunkZ = $quartZ >> 2;
        if ($chunkX * $chunkX + $chunkZ * $chunkZ <= 4096) {
            return 'the_end';
        }
        $height = $this->settings->state->sampler($this->settings->json['noise_router']['erosion'])
            ->value(new SamplerContext(false), ($chunkX * 2 + 1) * 8, $quartY << 2, ($chunkZ * 2 + 1) * 8);
        return match (true) {
            $height > 0.25 => 'end_highlands',
            $height >= -0.0625 => 'end_midlands',
            $height < -0.21875 => 'small_end_islands',
            default => 'end_barrens',
        };
    }

    /** @return list<string> the distinct biomes of a chunk */
    public function palette(int $chunkX, int $chunkZ): array
    {
        $this->quart($chunkX << 2, $this->settings->minY >> 2, $chunkZ << 2);
        return array_values(array_unique($this->chunks["$chunkX,$chunkZ"]));
    }

    /**
     * ChunkGenerator.doCreateBiomes: quart biomes of one chunk from climate volumes, in x, z, y
     * order from the chunk's lowest quart.
     *
     * @return list<string> indexed by (quartY << 4) | (quartZ << 2) | quartX
     */
    public function chunk(int $chunkX, int $chunkZ): array
    {
        if ($this->end) {
            $biomes = [];
            for ($y = 0, $layers = intdiv($this->settings->height, 4); $y < $layers; ++$y) {
                for ($z = 0; $z < 4; ++$z) {
                    for ($x = 0; $x < 4; ++$x) {
                        $biomes[($y << 4) | ($z << 2) | $x] = $this->endBiome(($chunkX << 2) + $x, ($this->settings->minY >> 2) + $y, ($chunkZ << 2) + $z);
                    }
                }
            }
            return $biomes;
        }
        $router = $this->settings->json['noise_router'];
        $state = $this->settings->state;
        $context = new SamplerContext();
        $volume = new Volume(4, intdiv($this->settings->height, 4), 4, $chunkX * 16, $this->settings->minY, $chunkZ * 16, 4, 4, 4);
        $climate = [];
        foreach (['temperature', 'vegetation', 'continents', 'erosion', 'depth', 'ridges'] as $name) {
            $climate[] = $state->sampler($router[$name])->volume($context, $volume);
        }
        // ChunkAccess.fillBiomesFromNoise: sections from the bottom, each in x, y, z order. The
        // order matters because the previous result decides exact ties.
        $biomes = [];
        for ($section = 0; $section < intdiv($volume->sizeY, 4); ++$section) {
            for ($x = 0; $x < 4; ++$x) {
                for ($dy = 0; $dy < 4; ++$dy) {
                    $y = $section * 4 + $dy;
                    for ($z = 0; $z < 4; ++$z) {
                        $index = $volume->index($x, $y, $z);
                        $biomes[($y << 4) | ($z << 2) | $x] = $this->find(array_map(static fn (array $buffer): float => $buffer[$index], $climate));
                    }
                }
            }
        }
        ksort($biomes);
        return $biomes;
    }
}
