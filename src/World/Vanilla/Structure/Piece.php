<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Patches;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/**
 * StructurePiece: a bounding box with an orientation, whose local coordinates map to the world
 * by StructurePiece.getWorldX/Y/Z and whose blocks are mirrored and rotated to match.
 */
abstract class Piece
{
    /** Direction.Plane.HORIZONTAL order, which getRandomHorizontalDirection draws from. */
    public const HORIZONTAL = ['north', 'east', 'south', 'west'];

    /** StructurePiece.SHAPE_CHECK_BLOCKS: their shapes are updated once the chunk is complete. */
    private const SHAPE_CHECK = ['nether_brick_fence', 'torch', 'wall_torch', 'oak_fence', 'spruce_fence', 'dark_oak_fence', 'pale_oak_fence', 'acacia_fence', 'birch_fence', 'jungle_fence', 'ladder', 'iron_bars'];

    private int $mirror = BlockTransform::NONE;

    private int $rotation = 0;

    /**
     * @param array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int} $box min x, y, z and max x, y, z
     */
    public function __construct(public array $box, public readonly ?string $orientation, public readonly int $genDepth = 0)
    {
        // StructurePiece.setOrientation
        [$this->mirror, $this->rotation] = match ($orientation) {
            'south' => [BlockTransform::LEFT_RIGHT, 0],
            'west' => [BlockTransform::LEFT_RIGHT, 1],
            'east' => [BlockTransform::NONE, 1],
            default => [BlockTransform::NONE, 0],
        };
    }

    abstract public function type(): string;

    /** Saved state beyond the box and orientation, as StructurePiece.addAdditionalSaveData. */
    public function data(): array
    {
        return [];
    }

    abstract public function postProcess(StructureContext $context, Region $region, RandomSource $random, array $chunkBox): void;

    /** Structure.afterPlace, after the start's pieces are placed in a chunk. */
    public function afterPlace(StructureContext $context, Region $region, array $chunkBox): void
    {
    }

    /** A piece from its saved form. */
    public static function restore(array $piece): self
    {
        return match ($piece['type']) {
            'swamp_hut' => new SwampHut($piece['box'], $piece['orientation'], $piece['width'], $piece['height'], $piece['depth'], $piece['hpos']),
            'desert_pyramid' => DesertPyramid::restoreWith($piece),
            'jungle_temple' => JungleTemple::restoreWith($piece),
            'igloo' => Igloo::restoreWith($piece),
            'shipwreck' => Shipwreck::restoreWith($piece),
            'buried_treasure' => BuriedTreasure::restoreWith($piece),
            'jigsaw' => JigsawPiece::restoreWith($piece),
            'mineshaft_room' => MineshaftRoom::restoreWith($piece),
            'mineshaft_corridor' => MineshaftCorridor::restoreWith($piece),
            'mineshaft_crossing' => MineshaftCrossing::restoreWith($piece),
            'mineshaft_stairs' => MineshaftStairs::restoreWith($piece),
            'stronghold' => StrongholdPiece::restoreWith($piece),
            'ocean_ruin' => OceanRuin::restoreWith($piece),
            'ruined_portal' => RuinedPortal::restoreWith($piece),
            'nether_fortress' => NetherFortressPiece::restoreWith($piece),
            'end_city' => EndCity::restoreWith($piece),
            'ocean_monument' => OceanMonument::restoreWith($piece),
        };
    }

    /** StructurePiece.makeBoundingBox */
    public static function makeBox(int $x, int $y, int $z, string $direction, int $width, int $height, int $depth): array
    {
        return $direction === 'north' || $direction === 'south'
            ? [$x, $y, $z, $x + $width - 1, $y + $height - 1, $z + $depth - 1]
            : [$x, $y, $z, $x + $depth - 1, $y + $height - 1, $z + $width - 1];
    }

    public static function intersects(array $a, array $b): bool
    {
        return $a[3] >= $b[0] && $a[0] <= $b[3] && $a[5] >= $b[2] && $a[2] <= $b[5] && $a[4] >= $b[1] && $a[1] <= $b[4];
    }

    public static function inside(array $box, int $x, int $y, int $z): bool
    {
        return $x >= $box[0] && $x <= $box[3] && $z >= $box[2] && $z <= $box[5] && $y >= $box[1] && $y <= $box[4];
    }

    protected function worldX(int $x, int $z): int
    {
        return match ($this->orientation) {
            'north', 'south' => $this->box[0] + $x,
            'west' => $this->box[3] - $z,
            'east' => $this->box[0] + $z,
            default => $x,
        };
    }

    protected function worldY(int $y): int
    {
        return $this->orientation === null ? $y : $y + $this->box[1];
    }

    protected function worldZ(int $x, int $z): int
    {
        return match ($this->orientation) {
            'north' => $this->box[5] - $z,
            'south' => $this->box[2] + $z,
            'west', 'east' => $this->box[2] + $x,
            default => $z,
        };
    }

    protected function canBeReplaced(StructureContext $context, Region $region, int $x, int $y, int $z, array $chunkBox): bool
    {
        return true;
    }

    protected function placeBlock(StructureContext $context, Region $region, int $state, int $x, int $y, int $z, array $chunkBox): void
    {
        [$wx, $wy, $wz] = [$this->worldX($x, $z), $this->worldY($y), $this->worldZ($x, $z)];
        if (!self::inside($chunkBox, $wx, $wy, $wz) || !$this->canBeReplaced($context, $region, $x, $y, $z, $chunkBox)) {
            return;
        }
        $state = $context->transform->apply($state, $this->mirror, $this->rotation);
        $region->set($wx, $wy, $wz, $state);
        if (in_array($context->blocks->name($state), self::SHAPE_CHECK, true)) {
            $context->markForPostProcessing($wx, $wy, $wz);
        }
    }

    /** @return array{0: int, 1: int, 2: int} */
    protected function worldPos(int $x, int $y, int $z): array
    {
        return [$this->worldX($x, $z), $this->worldY($y), $this->worldZ($x, $z)];
    }

    /** StructurePiece.createChest: a chest facing away from its walls, with a loot table and seed. */
    protected function createChest(StructureContext $context, Region $region, array $chunkBox, RandomSource $random, int $x, int $y, int $z, string $lootTable, ?int $state = null): bool
    {
        [$wx, $wy, $wz] = $this->worldPos($x, $y, $z);
        if (!self::inside($chunkBox, $wx, $wy, $wz) || $context->blocks->name($region->get($wx, $wy, $wz)) === 'chest') {
            return false;
        }
        $state ??= Patches::reorient($context->blocks, $region, $wx, $wy, $wz, $context->state('chest'));
        $region->set($wx, $wy, $wz, $state);
        $context->blockEntities["$wx,$wy,$wz"] = ['id' => 'chest', 'loot_table' => 'minecraft:chests/' . $lootTable, 'seed' => $random->nextLong()];
        return true;
    }

    protected function getBlock(Region $region, int $x, int $y, int $z, array $chunkBox): int
    {
        [$wx, $wy, $wz] = [$this->worldX($x, $z), $this->worldY($y), $this->worldZ($x, $z)];
        return self::inside($chunkBox, $wx, $wy, $wz) ? $region->get($wx, $wy, $wz) : 0;
    }

    /** StructurePiece.generateBox with an edge and a fill block. */
    protected function generateBox(StructureContext $context, Region $region, array $chunkBox, int $x0, int $y0, int $z0, int $x1, int $y1, int $z1, int $edge, int $fill, bool $skipAir): void
    {
        for ($y = $y0; $y <= $y1; ++$y) {
            for ($x = $x0; $x <= $x1; ++$x) {
                for ($z = $z0; $z <= $z1; ++$z) {
                    if ($skipAir && $context->blocks->isAir($this->getBlock($region, $x, $y, $z, $chunkBox))) {
                        continue;
                    }
                    $inside = $y !== $y0 && $y !== $y1 && $x !== $x0 && $x !== $x1 && $z !== $z0 && $z !== $z1;
                    $this->placeBlock($context, $region, $inside ? $fill : $edge, $x, $y, $z, $chunkBox);
                }
            }
        }
    }

    /**
     * StructurePiece.generateBox with a BlockSelector, which picks each block from the random
     * source and whether it is on the box's edge.
     *
     * @param callable(RandomSource, bool): int $selector
     */
    protected function generateSelectedBox(StructureContext $context, Region $region, array $chunkBox, int $x0, int $y0, int $z0, int $x1, int $y1, int $z1, bool $skipAir, RandomSource $random, callable $selector): void
    {
        for ($y = $y0; $y <= $y1; ++$y) {
            for ($x = $x0; $x <= $x1; ++$x) {
                for ($z = $z0; $z <= $z1; ++$z) {
                    if ($skipAir && $context->blocks->isAir($this->getBlock($region, $x, $y, $z, $chunkBox))) {
                        continue;
                    }
                    $edge = $y === $y0 || $y === $y1 || $x === $x0 || $x === $x1 || $z === $z0 || $z === $z1;
                    $this->placeBlock($context, $region, $selector($random, $edge), $x, $y, $z, $chunkBox);
                }
            }
        }
    }

    /** StructurePiece.generateAirBox */
    protected function generateAirBox(StructureContext $context, Region $region, array $chunkBox, int $x0, int $y0, int $z0, int $x1, int $y1, int $z1): void
    {
        $air = $context->state('air');
        for ($y = $y0; $y <= $y1; ++$y) {
            for ($x = $x0; $x <= $x1; ++$x) {
                for ($z = $z0; $z <= $z1; ++$z) {
                    $this->placeBlock($context, $region, $air, $x, $y, $z, $chunkBox);
                }
            }
        }
    }

    /** StructurePiece.createDispenser: a dispenser with a loot table and seed. */
    protected function createDispenser(StructureContext $context, Region $region, array $chunkBox, RandomSource $random, int $x, int $y, int $z, string $facing, string $lootTable): bool
    {
        [$wx, $wy, $wz] = $this->worldPos($x, $y, $z);
        if (!self::inside($chunkBox, $wx, $wy, $wz) || $context->blocks->name($region->get($wx, $wy, $wz)) === 'dispenser') {
            return false;
        }
        $this->placeBlock($context, $region, $context->state('dispenser', ['facing' => $facing]), $x, $y, $z, $chunkBox);
        $context->blockEntities["$wx,$wy,$wz"] = ['id' => 'dispenser', 'loot_table' => 'minecraft:dispensers/' . $lootTable, 'seed' => $random->nextLong()];
        return true;
    }

    /** StructurePiece.isReplaceableByStructures */
    protected static function replaceableByStructures(StructureContext $context, int $state): bool
    {
        // BlockState.liquid: water and lava blocks, not waterlogged ones.
        return $context->blocks->isAir($state) || in_array($context->blocks->name($state), ['water', 'lava', 'glow_lichen', 'seagrass', 'tall_seagrass'], true);
    }

    /** StructurePiece.fillColumnDown */
    protected function fillColumnDown(StructureContext $context, Region $region, int $state, int $x, int $startY, int $z, array $chunkBox): void
    {
        [$wx, $wy, $wz] = [$this->worldX($x, $z), $this->worldY($startY), $this->worldZ($x, $z)];
        if (!self::inside($chunkBox, $wx, $wy, $wz)) {
            return;
        }
        while (self::replaceableByStructures($context, $region->get($wx, $wy, $wz)) && $wy > $region->minY + 1) {
            $region->set($wx, $wy, $wz, $state);
            --$wy;
        }
    }
}
