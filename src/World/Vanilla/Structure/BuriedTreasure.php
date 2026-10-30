<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Patches;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/** BuriedTreasurePieces.BuriedTreasurePiece: a chest buried in sand on the first stone below the ocean floor. */
final class BuriedTreasure extends Piece
{
    /** Direction.values(): down, up, north, south, west, east. */
    private const DIRECTIONS = [[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]];

    public function type(): string
    {
        return 'buried_treasure';
    }

    public static function restoreWith(array $piece): self
    {
        return new self($piece['box'], null);
    }

    public function postProcess(StructureContext $context, Region $region, RandomSource $random, array $chunkBox): void
    {
        $blocks = $context->blocks;
        $liquid = static fn (int $state): bool => in_array($blocks->name($state), ['water', 'lava'], true);
        [$x, , $z] = $this->box;
        for ($y = $region->height(Region::OCEAN_FLOOR_WG, $x, $z); $y > $region->minY; --$y) {
            $current = $region->get($x, $y, $z);
            $below = $region->get($x, $y - 1, $z);
            if (!in_array($blocks->name($below), ['sandstone', 'stone', 'andesite', 'granite', 'diorite'], true)) {
                continue;
            }
            $soft = !$blocks->isAir($current) && !$liquid($current) ? $current : $context->state('sand');
            foreach (self::DIRECTIONS as $index => [$dx, $dy, $dz]) {
                $relative = $region->get($x + $dx, $y + $dy, $z + $dz);
                if ($blocks->isAir($relative) || $liquid($relative)) {
                    $belowRelative = $region->get($x + $dx, $y + $dy - 1, $z + $dz);
                    $region->set($x + $dx, $y + $dy, $z + $dz, ($blocks->isAir($belowRelative) || $liquid($belowRelative)) && $index !== 1 ? $below : $soft);
                }
            }
            $this->box = [$x, $y, $z, $x, $y, $z];
            if (self::inside($chunkBox, $x, $y, $z) && $blocks->name($region->get($x, $y, $z)) !== 'chest') {
                $region->set($x, $y, $z, Patches::reorient($blocks, $region, $x, $y, $z, $context->state('chest')));
                $context->blockEntities["$x,$y,$z"] = ['id' => 'chest', 'loot_table' => 'minecraft:chests/buried_treasure', 'seed' => $random->nextLong()];
            }
            return;
        }
    }
}
