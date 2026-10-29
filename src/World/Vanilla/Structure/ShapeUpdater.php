<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Survival;

/**
 * LevelChunk.postProcessGeneration for the blocks structure pieces mark
 * (StructurePiece.SHAPE_CHECK_BLOCKS): Block.updateFromNeighbourShapes connects fences and iron
 * bars to their neighbours, and torches and ladders without support break.
 */
final class ShapeUpdater
{
    /** Direction indexes of Blocks::sturdy: down, up, north, south, west, east. */
    private const SIDES = ['north' => [0, -1, 3], 'south' => [0, 1, 2], 'west' => [-1, 0, 5], 'east' => [1, 0, 4]];

    public function __construct(private readonly Blocks $blocks, private readonly Survival $survival)
    {
    }

    /** FenceBlock.isExceptionForConnection */
    private function exception(int $state): bool
    {
        return $this->survival->isA($state, 'LeavesBlock') || $this->blocks->inTag($state, 'shulker_boxes')
            || in_array($this->blocks->name($state), ['barrier', 'carved_pumpkin', 'jack_o_lantern', 'melon', 'pumpkin'], true);
    }

    /**
     * The block's shape after its neighbours are known, or null when it is unchanged.
     *
     * @param callable(int, int, int): int $get
     */
    public function update(int $state, int $x, int $y, int $z, callable $get): ?int
    {
        $name = $this->blocks->name($state);
        $air = $this->blocks->state('air');
        if ($name === 'torch') {
            return $this->blocks->sturdyCenter($get($x, $y - 1, $z), 1) ? null : $air;
        }
        if ($name === 'wall_torch' || $name === 'ladder') {
            $facing = $this->blocks->properties($state)['facing'];
            [$dx, $dz, $face] = self::SIDES[$facing];
            // The block behind, on the side opposite the facing, must have a sturdy face towards it.
            return $this->blocks->sturdy($get($x - $dx, $y, $z - $dz), self::opposite($face)) ? null : $air;
        }
        $fence = $this->survival->isA($state, 'FenceBlock');
        $bars = $this->survival->isA($state, 'IronBarsBlock');
        if (!$fence && !$bars) {
            return null;
        }
        $wooden = $this->blocks->inTag($state, 'wooden_fences');
        $updated = $state;
        foreach (self::SIDES as $side => [$dx, $dz, $face]) {
            $neighbour = $get($x + $dx, $y, $z + $dz);
            $solid = !$this->exception($neighbour) && $this->blocks->sturdy($neighbour, $face);
            if ($fence) {
                // FenceBlock.connectsTo: the same kind of fence, a gate across the side, or a sturdy face.
                $sameFence = $this->blocks->inTag($neighbour, 'fences') && $this->blocks->inTag($neighbour, 'wooden_fences') === $wooden;
                $gate = $this->survival->isA($neighbour, 'FenceGateBlock')
                    && in_array($this->blocks->properties($neighbour)['facing'], $dx !== 0 ? ['north', 'south'] : ['east', 'west'], true);
                $connects = $solid || $sameFence || $gate;
            } else {
                // IronBarsBlock.attachsTo
                $connects = $solid || $this->survival->isA($neighbour, 'IronBarsBlock') || $this->blocks->inTag($neighbour, 'walls');
            }
            $updated = $this->blocks->with($updated, $side, $connects ? 'true' : 'false');
        }
        return $updated === $state ? null : $updated;
    }

    /** The Blocks::sturdy index of the opposite horizontal direction. */
    private static function opposite(int $face): int
    {
        return match ($face) {
            2 => 3,
            3 => 2,
            4 => 5,
            default => 4,
        };
    }
}
