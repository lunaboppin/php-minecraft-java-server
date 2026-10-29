<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;

/**
 * BlockState.mirror and BlockState.rotate for structure pieces and templates: facings, axes,
 * 16-step rotations and side connections, with StairBlock's and DoorBlock's own mirroring.
 */
final class BlockTransform
{
    public const NONE = 0;

    /** Mirror.LEFT_RIGHT swaps north and south; Mirror.FRONT_BACK swaps east and west. */
    public const LEFT_RIGHT = 1;

    public const FRONT_BACK = 2;

    /** Rotation in clockwise quarter turns: NONE, CLOCKWISE_90, CLOCKWISE_180, COUNTERCLOCKWISE_90. */
    private const HORIZONTAL = ['north', 'east', 'south', 'west'];

    /** @var array<string, int> */
    private array $cache = [];

    public function __construct(private readonly Blocks $blocks)
    {
    }

    /** StructurePiece.placeBlock and StructureTemplate: mirror first, then rotate. */
    public function apply(int $state, int $mirror, int $rotation): int
    {
        if ($mirror === self::NONE && $rotation === 0) {
            return $state;
        }
        return $this->cache["$state,$mirror,$rotation"] ??= $this->rotate($this->mirror($state, $mirror), $rotation);
    }

    /** Mirror.getRotation: a half turn for facings along the mirrored axis. */
    private static function mirrorRotation(int $mirror, string $facing): int
    {
        return match ($mirror) {
            self::LEFT_RIGHT => $facing === 'north' || $facing === 'south' ? 2 : 0,
            self::FRONT_BACK => $facing === 'east' || $facing === 'west' ? 2 : 0,
            default => 0,
        };
    }

    public function mirror(int $state, int $mirror): int
    {
        if ($mirror === self::NONE) {
            return $state;
        }
        $properties = $this->blocks->properties($state);
        $name = $this->blocks->name($state);
        $facing = $properties['facing'] ?? null;
        if (str_ends_with($name, '_stairs')) {
            $alongAxis = $mirror === self::LEFT_RIGHT ? $facing === 'north' || $facing === 'south' : $facing === 'east' || $facing === 'west';
            if (!$alongAxis) {
                return $state;
            }
            $shape = $properties['shape'];
            $swapped = match ($shape) {
                'outer_left' => 'outer_right',
                'outer_right' => 'outer_left',
                // StairBlock.mirror keeps inner shapes for FRONT_BACK.
                'inner_left' => $mirror === self::LEFT_RIGHT ? 'inner_right' : 'inner_left',
                'inner_right' => $mirror === self::LEFT_RIGHT ? 'inner_left' : 'inner_right',
                default => $shape,
            };
            return $this->blocks->with($this->rotate($state, 2), 'shape', $swapped);
        }
        if (isset($properties['hinge']) && $facing !== null) {
            $state = $this->rotate($state, self::mirrorRotation($mirror, $facing));
            return $this->blocks->with($state, 'hinge', $properties['hinge'] === 'left' ? 'right' : 'left');
        }
        if ($facing !== null && in_array($facing, self::HORIZONTAL, true)) {
            return $this->rotate($state, self::mirrorRotation($mirror, $facing));
        }
        if (isset($properties['rotation'])) {
            $value = (int) $properties['rotation'];
            return $this->blocks->with($state, 'rotation', (string) ($mirror === self::LEFT_RIGHT ? (16 - $value) % 16 : (8 - $value + 16) % 16));
        }
        [$a, $b] = $mirror === self::LEFT_RIGHT ? ['north', 'south'] : ['east', 'west'];
        if (isset($properties[$a], $properties[$b])) {
            $state = $this->blocks->with($state, $a, $properties[$b]);
            return $this->blocks->with($state, $b, $properties[$a]);
        }
        return $state;
    }

    public function rotate(int $state, int $rotation): int
    {
        $rotation &= 3;
        if ($rotation === 0) {
            return $state;
        }
        $properties = $this->blocks->properties($state);
        if (isset($properties['facing']) && ($index = array_search($properties['facing'], self::HORIZONTAL, true)) !== false) {
            $state = $this->blocks->with($state, 'facing', self::HORIZONTAL[($index + $rotation) % 4]);
        }
        if (isset($properties['axis']) && $rotation !== 2 && $properties['axis'] !== 'y') {
            $state = $this->blocks->with($state, 'axis', $properties['axis'] === 'x' ? 'z' : 'x');
        }
        if (isset($properties['rotation'])) {
            $state = $this->blocks->with($state, 'rotation', (string) (((int) $properties['rotation'] + $rotation * 4) % 16));
        }
        if (isset($properties['north'], $properties['east'], $properties['south'], $properties['west'])) {
            foreach (self::HORIZONTAL as $index => $side) {
                $state = $this->blocks->with($state, self::HORIZONTAL[($index + $rotation) % 4], $properties[$side]);
            }
        }
        return $state;
    }
}
