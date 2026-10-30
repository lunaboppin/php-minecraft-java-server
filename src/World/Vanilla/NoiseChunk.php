<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

/**
 * NoiseChunk and NoiseBasedChunkGenerator.doFill for one chunk: the final density volume and
 * the aquifer, sharing one caching sampler context with the later stages. Noise settings
 * without aquifers (the Nether) use Aquifer.createDisabled.
 */
final class NoiseChunk
{
    public readonly SamplerContext $context;

    public readonly Volume $volume;

    public readonly ?Aquifer $aquifer;

    public ?Structure\Beardifier $beardifier = null;

    public function __construct(public readonly OverworldSettings $settings, int $chunkX, int $chunkZ, ?Volume $volume = null)
    {
        $this->context = new SamplerContext();
        $this->volume = $volume ?? new Volume(16, $settings->height, 16, $chunkX * 16, $settings->minY, $chunkZ * 16);
        $aquifers = $settings->json['aquifers'] ?? null;
        $state = $settings->state;
        $this->aquifer = $aquifers === null ? null : new Aquifer(
            $this->context,
            $state->sampler($aquifers['barrier']),
            $state->sampler($aquifers['fluid_level_floodedness']),
            $state->sampler($aquifers['fluid_level_spread']),
            $state->sampler($aquifers['lava']),
            $state->sampler($aquifers['exclusion']),
            $state->sampler($aquifers['surface_level']),
            $state->factory('minecraft:aquifer'),
            $this->volume,
            $settings->seaLevel,
        );
    }

    /** Aquifer.computeSubstance: null keeps the default block. */
    public function substance(int $x, int $y, int $z, float $density): ?string
    {
        if ($this->aquifer !== null) {
            return $this->aquifer->computeSubstance($x, $y, $z, $density);
        }
        if ($density > 0.0) {
            return null;
        }
        // NoiseBasedChunkGenerator.createFluidPicker
        $seaLevel = $this->settings->seaLevel;
        return $y < min(-54, $seaLevel) ? ($y < -54 ? 'lava' : 'air') : ($y < $seaLevel ? $this->settings->defaultFluid : 'air');
    }

    /**
     * NoiseBasedChunkGenerator.iterateNoiseColumn: the highest non-air block of one column
     * before surface rules and carvers, and whether it is a fluid.
     *
     * @return array{0: int, 1: bool}
     */
    public static function column(OverworldSettings $settings, int $x, int $z): array
    {
        $chunk = new self($settings, $x >> 4, $z >> 4, new Volume(1, $settings->height, 1, $x, $settings->minY, $z));
        $density = $settings->state->sampler($settings->json['noise_router']['final_density'])->volume($chunk->context, $chunk->volume);
        for ($y = $settings->height - 1; $y >= 0; --$y) {
            $state = $chunk->substance($x, $settings->minY + $y, $z, $density[$y]) ?? $settings->defaultBlock;
            if ($state !== 'air') {
                return [$settings->minY + $y, $state !== $settings->defaultBlock];
            }
        }
        return [$settings->minY, false];
    }

    /**
     * NoiseBasedChunkGenerator.getBaseHeight: one above the highest block of the column, before
     * surface rules and carvers, that is not air (WORLD_SURFACE_WG) or that blocks motion
     * (OCEAN_FLOOR_WG, which skips fluids).
     */
    public static function baseHeight(OverworldSettings $settings, int $x, int $z, bool $oceanFloor): int
    {
        $chunk = new self($settings, $x >> 4, $z >> 4, new Volume(1, $settings->height, 1, $x, $settings->minY, $z));
        $density = $settings->state->sampler($settings->json['noise_router']['final_density'])->volume($chunk->context, $chunk->volume);
        for ($y = $settings->height - 1; $y >= 0; --$y) {
            $state = $chunk->substance($x, $settings->minY + $y, $z, $density[$y]) ?? $settings->defaultBlock;
            if ($state !== 'air' && (!$oceanFloor || $state === $settings->defaultBlock)) {
                return $settings->minY + $y + 1;
            }
        }
        return $settings->minY;
    }

    /**
     * NoiseBasedChunkGenerator.getBaseColumn: the noise column's block names from the bottom.
     *
     * @return list<string>
     */
    public static function baseColumn(OverworldSettings $settings, int $x, int $z): array
    {
        $chunk = new self($settings, $x >> 4, $z >> 4, new Volume(1, $settings->height, 1, $x, $settings->minY, $z));
        $density = $settings->state->sampler($settings->json['noise_router']['final_density'])->volume($chunk->context, $chunk->volume);
        $column = [];
        for ($y = 0; $y < $settings->height; ++$y) {
            $column[] = $chunk->substance($x, $settings->minY + $y, $z, $density[$y]) ?? $settings->defaultBlock;
        }
        return $column;
    }

    /**
     * Block names in y, z, x order from the bottom of the chunk.
     *
     * @return list<string>
     */
    public function fill(): array
    {
        $density = $this->settings->state->sampler($this->settings->json['noise_router']['final_density'])->volume($this->context, $this->volume);
        $volume = $this->volume;
        // The final density's outermost term is the beardifier, which structures supply per chunk.
        if ($this->beardifier !== null && $this->beardifier->affects($volume->minX, $volume->minY, $volume->minZ, $volume->minX + 15, $volume->minY + $volume->sizeY - 1, $volume->minZ + 15)) {
            for ($z = 0; $z < 16; ++$z) {
                for ($x = 0; $x < 16; ++$x) {
                    for ($y = 0; $y < $volume->sizeY; ++$y) {
                        [$bx, $by, $bz] = [$volume->minX + $x, $volume->minY + $y, $volume->minZ + $z];
                        if ($this->beardifier->inside($bx, $by, $bz)) {
                            $index = $volume->index($x, $y, $z);
                            $density[$index] = F32::r($density[$index] + $this->beardifier->sample($bx, $by, $bz));
                        }
                    }
                }
            }
        }
        $blocks = array_fill(0, $volume->size, 'air');
        for ($z = 0; $z < 16; ++$z) {
            $bz = $volume->minZ + $z;
            for ($x = 0; $x < 16; ++$x) {
                $bx = $volume->minX + $x;
                for ($y = $volume->sizeY - 1; $y >= 0; --$y) {
                    $state = $this->substance($bx, $volume->minY + $y, $bz, $density[$volume->index($x, $y, $z)]);
                    $blocks[($y << 8) | ($z << 4) | $x] = $state ?? $this->settings->defaultBlock;
                }
            }
        }
        return $blocks;
    }
}
