<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

/**
 * A chunk between carving and decoration: block state ids in y, z, x order and quart biomes in
 * y, z, x order. Chunks stay at the TERRAIN status while features are placed, so the worldgen
 * heightmaps (WORLD_SURFACE_WG, then OCEAN_FLOOR_WG, 256 columns each, z then x) keep their
 * post-carving values.
 */
final class ProtoChunk
{
    /**
     * @param list<int> $blocks
     * @param list<string> $biomes
     * @param list<int> $worldgenHeights
     */
    public function __construct(public readonly int $x, public readonly int $z, public array $blocks, public readonly array $biomes, public readonly array $worldgenHeights)
    {
    }

    /**
     * The worldgen heightmaps of carved blocks: the lowest Y above every non-air block, then
     * above every motion-blocking block.
     *
     * @param list<int> $states
     * @return list<int>
     */
    public static function worldgenHeights(Blocks $blocks, array $states, int $minY, int $height): array
    {
        $out = array_fill(0, 512, $minY);
        for ($column = 0; $column < 256; ++$column) {
            $surface = false;
            for ($y = $height - 1; $y >= 0; --$y) {
                $state = $states[($y << 8) | $column];
                if (!$surface && !$blocks->isAir($state)) {
                    $out[$column] = $y + $minY + 1;
                    $surface = true;
                }
                if ($blocks->flag($state, Blocks::MOTION_HEIGHTMAP)) {
                    $out[256 + $column] = $y + $minY + 1;
                    break;
                }
            }
        }
        return $out;
    }
}
