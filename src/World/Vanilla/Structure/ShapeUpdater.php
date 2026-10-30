<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Survival;

/**
 * LevelChunk.postProcessGeneration for the blocks structure pieces mark
 * (StructurePiece.SHAPE_CHECK_BLOCKS), and StructureTemplate.placeInWorld's shape updates:
 * Block.updateFromNeighbourShapes connects fences, walls and iron bars to their neighbours, pairs
 * chests, and torches and ladders without support break.
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
     * Update marked blocks in order, then again while any shape changed (up to three passes), so a
     * fence sees the final shape of stairs updated after it. Vanilla reaches that state when the
     * neighbouring chunk was decorated first.
     *
     * @param list<array{0: int, 1: int, 2: int}> $marks
     * @param callable(int, int, int): int $get
     * @param callable(int, int, int, int): void $set
     */
    public function updateAll(array $marks, callable $get, callable $set): void
    {
        for ($pass = 0; $pass < 3; ++$pass) {
            $changed = false;
            foreach ($marks as [$x, $y, $z]) {
                $updated = $this->update($get($x, $y, $z), $x, $y, $z, $get);
                if ($updated !== null) {
                    $set($x, $y, $z, $updated);
                    $changed = true;
                }
            }
            if (!$changed) {
                return;
            }
        }
    }

    /**
     * The block's shape after its neighbours are known, or null when it is unchanged. Chests pair
     * up or split only when a template places them ($placement), as later updates leave them.
     *
     * @param callable(int, int, int): int $get
     */
    public function update(int $state, int $x, int $y, int $z, callable $get, bool $placement = false): ?int
    {
        $name = $this->blocks->name($state);
        if ($this->survival->isA($state, 'WallBlock')) {
            $updated = $this->wall($state, $x, $y, $z, $get);
            return $updated === $state ? null : $updated;
        }
        if ($placement && $this->survival->isA($state, 'ChestBlock')) {
            $updated = $this->chest($state, $x, $y, $z, $get);
            return $updated === $state ? null : $updated;
        }
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
        if ($this->survival->isA($state, 'StairBlock')) {
            $shape = $this->stairsShape($state, $x, $y, $z, $get);
            $updated = $this->blocks->with($state, 'shape', $shape);
            return $updated === $state ? null : $updated;
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

    private const FACINGS = ['north' => [0, -1], 'east' => [1, 0], 'south' => [0, 1], 'west' => [-1, 0]];

    private const CLOCKWISE = ['north' => 'east', 'east' => 'south', 'south' => 'west', 'west' => 'north'];

    /** WallBlock.connectsTo for each side, then its sides' heights and post from the block above. */
    private function wall(int $state, int $x, int $y, int $z, callable $get): int
    {
        $above = $get($x, $y + 1, $z);
        $sides = [];
        foreach (self::SIDES as $side => [$dx, $dz, $face]) {
            $neighbour = $get($x + $dx, $y, $z + $dz);
            $gate = $this->survival->isA($neighbour, 'FenceGateBlock')
                && in_array($this->blocks->properties($neighbour)['facing'], $dx !== 0 ? ['north', 'south'] : ['east', 'west'], true);
            $connects = $this->blocks->inTag($neighbour, 'walls') || !$this->exception($neighbour) && $this->blocks->sturdy($neighbour, $face)
                || $this->survival->isA($neighbour, 'IronBarsBlock') || $gate;
            $sides[$side] = $connects ? ($this->blocks->coversWall($above, $side) ? 'tall' : 'low') : 'none';
            $state = $this->blocks->with($state, $side, $sides[$side]);
        }
        // WallBlock.shouldRaisePost
        if ($this->survival->isA($above, 'WallBlock') && $this->blocks->properties($above)['up'] === 'true') {
            $post = true;
        } elseif ($sides['north'] === 'none' && $sides['south'] === 'none' && $sides['west'] === 'none' && $sides['east'] === 'none'
            || ($sides['north'] === 'none') !== ($sides['south'] === 'none') || ($sides['west'] === 'none') !== ($sides['east'] === 'none')) {
            $post = true;
        } elseif ($sides['north'] === 'tall' && $sides['south'] === 'tall' || $sides['east'] === 'tall' && $sides['west'] === 'tall') {
            $post = false;
        } else {
            $post = $this->blocks->inTag($above, 'wall_post_override') || $this->blocks->coversWall($above, null);
        }
        return $this->blocks->with($state, 'up', $post ? 'true' : 'false');
    }

    /** ChestBlock.updateShape from each horizontal neighbour, in Block.UPDATE_SHAPE_ORDER. */
    private function chest(int $state, int $x, int $y, int $z, callable $get): int
    {
        $name = $this->blocks->name($state);
        $connected = static fn (array $p): string => $p['type'] === 'left' ? self::CLOCKWISE[$p['facing']] : self::COUNTER_CLOCKWISE[$p['facing']];
        foreach (['west', 'east', 'north', 'south'] as $direction) {
            [$dx, $dz] = self::FACINGS[$direction];
            $neighbour = $get($x + $dx, $y, $z + $dz);
            $properties = $this->blocks->properties($state);
            if ($this->blocks->name($neighbour) === $name) {
                $other = $this->blocks->properties($neighbour);
                if ($properties['type'] === 'single' && $other['type'] !== 'single' && $properties['facing'] === $other['facing'] && $connected($other) === self::OPPOSITE[$direction]) {
                    $state = $this->blocks->with($state, 'type', $other['type'] === 'left' ? 'right' : 'left');
                }
            } elseif ($properties['type'] !== 'single' && $connected($properties) === $direction) {
                $state = $this->blocks->with($state, 'type', 'single');
            }
        }
        return $state;
    }

    private const COUNTER_CLOCKWISE = ['north' => 'west', 'west' => 'south', 'south' => 'east', 'east' => 'north'];

    private const OPPOSITE = ['north' => 'south', 'south' => 'north', 'east' => 'west', 'west' => 'east'];

    /** StairBlock.getStairsShape: corners with the stairs behind or in front of it. */
    private function stairsShape(int $state, int $x, int $y, int $z, callable $get): string
    {
        $properties = $this->blocks->properties($state);
        $facing = $properties['facing'];
        $half = $properties['half'];
        $stairsAt = function (string $direction) use ($get, $x, $y, $z): ?array {
            [$dx, $dz] = self::FACINGS[$direction];
            $neighbour = $get($x + $dx, $y, $z + $dz);
            return $this->survival->isA($neighbour, 'StairBlock') ? $this->blocks->properties($neighbour) : null;
        };
        $axis = static fn (string $direction): string => $direction === 'east' || $direction === 'west' ? 'x' : 'z';
        // StairBlock.canTakeShape
        $canTake = static function (string $direction) use ($stairsAt, $facing, $half): bool {
            $neighbour = $stairsAt($direction);
            return $neighbour === null || $neighbour['facing'] !== $facing || $neighbour['half'] !== $half;
        };
        $behind = $stairsAt($facing);
        if ($behind !== null && $behind['half'] === $half && $axis($behind['facing']) !== $axis($facing) && $canTake(self::OPPOSITE[$behind['facing']])) {
            return $behind['facing'] === self::COUNTER_CLOCKWISE[$facing] ? 'outer_left' : 'outer_right';
        }
        $front = $stairsAt(self::OPPOSITE[$facing]);
        if ($front !== null && $front['half'] === $half && $axis($front['facing']) !== $axis($facing) && $canTake($front['facing'])) {
            return $front['facing'] === self::COUNTER_CLOCKWISE[$facing] ? 'inner_left' : 'inner_right';
        }
        return 'straight';
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
