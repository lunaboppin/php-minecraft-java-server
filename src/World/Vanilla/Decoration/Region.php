<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

use PhpMinecraftJavaServer\World\Vanilla\BiomeClimate;

/**
 * server.level.WorldGenRegion for decoration: the decorating chunk and its eight neighbours.
 * Writes outside the 3×3 area are refused, as vanilla's write radius does. The worldgen
 * heightmaps are the chunks' post-carving ones; the others are found by scanning, which
 * matches vanilla's incrementally maintained ones.
 */
final class Region
{
    public const WORLD_SURFACE_WG = 'WORLD_SURFACE_WG';
    public const WORLD_SURFACE = 'WORLD_SURFACE';
    public const OCEAN_FLOOR_WG = 'OCEAN_FLOOR_WG';
    public const OCEAN_FLOOR = 'OCEAN_FLOOR';
    public const MOTION_BLOCKING = 'MOTION_BLOCKING';
    public const MOTION_BLOCKING_NO_LEAVES = 'MOTION_BLOCKING_NO_LEAVES';

    private readonly int $voidAir;

    private readonly int $air;

    public readonly int $genHeight;

    /** @var array<string, int> heightmap cache: "type,x,z" => first free Y */
    private array $heights = [];

    /** @param array<string, ProtoChunk> $chunks keyed "x,z" */
    public function __construct(
        public readonly Blocks $blocks,
        public readonly int $centerX,
        public readonly int $centerZ,
        private array $chunks,
        public readonly int $minY,
        public readonly int $height,
        public readonly int $seaLevel,
        private readonly int $zoomSeed,
        public readonly int $seed,
        ?int $genHeight = null,
    ) {
        // The generator's height (ChunkGenerator.getGenDepth), below a taller world's build height.
        $this->genHeight = $genHeight ?? $height;
        $this->voidAir = $blocks->state('void_air');
        $this->air = $blocks->state('air');
    }

    public function maxY(): int
    {
        return $this->minY + $this->height - 1;
    }

    public function isOutsideBuildHeight(int $y): bool
    {
        return $y < $this->minY || $y > $this->maxY();
    }

    public function chunk(int $chunkX, int $chunkZ): ?ProtoChunk
    {
        return $this->chunks["$chunkX,$chunkZ"] ?? null;
    }

    public function get(int $x, int $y, int $z): int
    {
        if ($y < $this->minY || $y >= $this->minY + $this->height) {
            return $this->voidAir;
        }
        $chunk = $this->chunks[($x >> 4) . ',' . ($z >> 4)] ?? null;
        if ($chunk === null) {
            return $this->air;
        }
        return $chunk->blocks[(($y - $this->minY) << 8) | (($z & 15) << 4) | ($x & 15)];
    }

    /** WorldGenRegion.ensureCanWrite */
    public function canWrite(int $x, int $z): bool
    {
        return abs(($x >> 4) - $this->centerX) <= 1 && abs(($z >> 4) - $this->centerZ) <= 1;
    }

    /** WorldGenRegion.setBlock; neighbours are not updated during generation. */
    public function set(int $x, int $y, int $z, int $state): bool
    {
        if (!$this->canWrite($x, $z)) {
            return false;
        }
        if ($y < $this->minY || $y >= $this->minY + $this->height) {
            return true;
        }
        $chunk = $this->chunks[($x >> 4) . ',' . ($z >> 4)];
        $chunk->blocks[(($y - $this->minY) << 8) | (($z & 15) << 4) | ($x & 15)] = $state;
        foreach ([self::WORLD_SURFACE, self::OCEAN_FLOOR, self::MOTION_BLOCKING, self::MOTION_BLOCKING_NO_LEAVES] as $type) {
            unset($this->heights["$type,$x,$z"]);
        }
        return true;
    }

    private function matches(string $type, int $state): bool
    {
        $blocks = $this->blocks;
        return match ($type) {
            self::WORLD_SURFACE => !$blocks->isAir($state),
            self::OCEAN_FLOOR => $blocks->flag($state, Blocks::MOTION_HEIGHTMAP),
            self::MOTION_BLOCKING => $blocks->flag($state, Blocks::MOTION_HEIGHTMAP) || $blocks->hasFluid($state),
            self::MOTION_BLOCKING_NO_LEAVES => $blocks->flag($state, Blocks::MOTION_NO_LEAVES) || $blocks->hasFluid($state),
        };
    }

    /** WorldGenRegion.getHeight: the lowest Y above every block the heightmap counts. */
    public function height(string $type, int $x, int $z): int
    {
        if ($type === self::WORLD_SURFACE_WG || $type === self::OCEAN_FLOOR_WG) {
            $chunk = $this->chunks[($x >> 4) . ',' . ($z >> 4)] ?? null;
            return $chunk === null ? $this->minY : $chunk->worldgenHeights[($type === self::OCEAN_FLOOR_WG ? 256 : 0) | (($z & 15) << 4) | ($x & 15)];
        }
        $canonical = $type;
        $key = "$canonical,$x,$z";
        if (isset($this->heights[$key])) {
            return $this->heights[$key];
        }
        $chunk = $this->chunks[($x >> 4) . ',' . ($z >> 4)] ?? null;
        $height = $this->minY;
        if ($chunk !== null) {
            $column = (($z & 15) << 4) | ($x & 15);
            for ($y = $this->height - 1; $y >= 0; --$y) {
                if ($this->matches($canonical, $chunk->blocks[($y << 8) | $column])) {
                    $height = $y + $this->minY + 1;
                    break;
                }
            }
        }
        return $this->heights[$key] = $height;
    }

    /** LevelReader.getBiome: BiomeManager over the chunks' stored biomes. */
    public function biome(int $x, int $y, int $z): string
    {
        [$qx, $qy, $qz] = BiomeClimate::zoom($this->zoomSeed, $x, $y, $z);
        $chunk = $this->chunks[($qx >> 2) . ',' . ($qz >> 2)] ?? null;
        if ($chunk === null) {
            return 'plains';
        }
        $quartY = min(max($qy - ($this->minY >> 2), 0), intdiv($this->genHeight, 4) - 1);
        return $chunk->biomes[($quartY << 4) | (($qz & 3) << 2) | ($qx & 3)];
    }
}
