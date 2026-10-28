<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

/**
 * NoiseChunk and NoiseBasedChunkGenerator.doFill for one chunk: the final density volume and
 * the aquifer, sharing one caching sampler context with the later stages.
 */
final class NoiseChunk
{
    public readonly SamplerContext $context;

    public readonly Volume $volume;

    public readonly Aquifer $aquifer;

    public function __construct(public readonly OverworldSettings $settings, int $chunkX, int $chunkZ, ?Volume $volume = null)
    {
        $this->context = new SamplerContext();
        $this->volume = $volume ?? new Volume(16, $settings->height, 16, $chunkX * 16, $settings->minY, $chunkZ * 16);
        $aquifers = $settings->json['aquifers'];
        $state = $settings->state;
        $this->aquifer = new Aquifer(
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
            $state = $chunk->aquifer->computeSubstance($x, $settings->minY + $y, $z, $density[$y]) ?? 'stone';
            if ($state !== 'air') {
                return [$settings->minY + $y, $state !== 'stone'];
            }
        }
        return [$settings->minY, false];
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
        $blocks = array_fill(0, $volume->size, 'air');
        for ($z = 0; $z < 16; ++$z) {
            $bz = $volume->minZ + $z;
            for ($x = 0; $x < 16; ++$x) {
                $bx = $volume->minX + $x;
                for ($y = $volume->sizeY - 1; $y >= 0; --$y) {
                    $state = $this->aquifer->computeSubstance($bx, $volume->minY + $y, $bz, $density[$volume->index($x, $y, $z)]);
                    $blocks[($y << 8) | ($z << 4) | $x] = $state ?? 'stone';
                }
            }
        }
        return $blocks;
    }
}
