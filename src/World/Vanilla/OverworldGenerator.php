<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

/**
 * The official Overworld chunk pipeline up to carvers: biomes, noise fill with aquifers,
 * material (surface) rules and carvers. Structures are not placed yet, so terrain adaptation
 * around them is missing.
 */
final class OverworldGenerator
{
    public readonly OverworldSettings $settings;

    public readonly BiomeSource $biomes;

    private readonly MaterialSystem $materials;

    private readonly Carvers $carvers;

    private readonly int $zoomSeed;

    /** @var array<string, string> carver source chunk biomes, which neighbouring chunks share */
    private array $sourceBiomes = [];

    /** @param string $settings noise settings: overworld or nether */
    public function __construct(int $seed, string $referenceDirectory, string $settings = 'overworld')
    {
        $this->settings = new OverworldSettings($seed, $referenceDirectory . '/worldgen', $settings);
        $this->biomes = new BiomeSource($this->settings, $settings);
        $this->materials = new MaterialSystem($this->settings);
        $this->carvers = new Carvers($this->settings, (string) file_get_contents($referenceDirectory . '/entity-sine.bin'));
        $this->zoomSeed = BiomeClimate::zoomSeed($seed);
    }

    /** BiomeManager.getBiome over the chunks' stored noise biomes. */
    public function biomeAt(int $x, int $y, int $z): string
    {
        [$qx, $qy, $qz] = BiomeClimate::zoom($this->zoomSeed, $x, $y, $z);
        return $this->biomes->quart($qx, $qy, $qz);
    }

    private function uncachedBiomeAt(int $x, int $y, int $z): string
    {
        [$qx, $qy, $qz] = BiomeClimate::zoom($this->zoomSeed, $x, $y, $z);
        return $this->biomes->uncached($qx, $qy, $qz);
    }

    /** The biome that picks a carver source chunk's carvers, kept for the chunks around it. */
    private function sourceBiome(int $sourceX, int $sourceZ): string
    {
        $key = "$sourceX,$sourceZ";
        if (!isset($this->sourceBiomes[$key])) {
            if (count($this->sourceBiomes) >= 4096) {
                $this->sourceBiomes = array_slice($this->sourceBiomes, 2048, null, true);
            }
            $this->sourceBiomes[$key] = $this->biomes->uncached($sourceX << 2, 0, $sourceZ << 2);
        }
        return $this->sourceBiomes[$key];
    }

    /**
     * @return array{blocks: list<string>, biomes: list<string>, stages: array<string, list<string>>} block
     *     names in y, z, x order and quart biomes in y, z, x order; stages holds copies after
     *     the noise and surface stages when requested
     */
    public function chunk(int $chunkX, int $chunkZ, bool $keepStages = false, ?Structure\Beardifier $beardifier = null): array
    {
        $possible = [];
        for ($dz = -1; $dz <= 1; ++$dz) {
            for ($dx = -1; $dx <= 1; ++$dx) {
                array_push($possible, ...$this->biomes->palette($chunkX + $dx, $chunkZ + $dz));
            }
        }
        $noise = new NoiseChunk($this->settings, $chunkX, $chunkZ);
        $noise->beardifier = $beardifier;
        $blocks = $noise->fill();
        $stages = $keepStages ? ['noise' => $blocks] : [];
        $this->materials->buildSurface($blocks, $noise, $this->biomeAt(...), array_values(array_unique($possible)));
        if ($keepStages) {
            $stages['surface'] = $blocks;
        }
        $this->carvers->carve(
            $blocks,
            $noise,
            $chunkX,
            $chunkZ,
            $this->sourceBiome(...),
            fn (array $current, int $x, int $y, int $z, bool $underFluid): ?string => $this->materials->topMaterial($current, $noise, $this->uncachedBiomeAt(...), $x, $y, $z, $underFluid),
        );
        $biomes = [];
        $minQuartY = $this->settings->minY >> 2;
        for ($i = 0; $i < intdiv($this->settings->height, 4) * 16; ++$i) {
            $biomes[] = $this->biomes->quart(($chunkX << 2) | ($i & 3), $minQuartY + ($i >> 4), ($chunkZ << 2) | (($i >> 2) & 3));
        }
        return ['blocks' => $blocks, 'biomes' => $biomes, 'stages' => $stages];
    }
}
