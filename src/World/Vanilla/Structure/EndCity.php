<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/**
 * EndCityPieces: houses, towers, fat towers and bridges grown from the start house, each section
 * kept only if it does not collide with pieces outside its parent's section, and sometimes a ship.
 */
final class EndCity extends TemplatePiece
{
    private const TOWER_BRIDGES = [[0, [1, -1, 0]], [1, [6, -1, 1]], [3, [0, -1, 5]], [2, [5, -1, 6]]];

    private const FAT_TOWER_BRIDGES = [[0, [4, -1, 0]], [1, [12, -1, 4]], [3, [0, -1, 8]], [2, [8, -1, 12]]];

    public bool $overwrite = true;

    /** StructurePiece.genDepth, which end cities set to section tags. */
    public int $tag = 0;

    private static bool $shipCreated = false;

    private static Blocks $blocks;

    private static string $directory;

    public static function restoreWith(array $piece): self
    {
        $city = new self($piece['box'], $piece['template'], $piece['position'], $piece['rotation'], [0, 0, 0]);
        [$city->overwrite, $city->tag] = $piece['city'];
        return $city;
    }

    public function type(): string
    {
        return 'end_city';
    }

    public function data(): array
    {
        return parent::data() + ['city' => [$this->overwrite, $this->tag]];
    }

    private static function make(string $name, array $position, int $rotation, bool $overwrite): self
    {
        $template = 'end_city/' . $name;
        $box = Template::load(self::$blocks, self::$directory, $template)->boundingBox($position, BlockTransform::NONE, $rotation, [0, 0, 0]);
        $piece = new self($box, $template, $position, $rotation, [0, 0, 0]);
        $piece->overwrite = $overwrite;
        return $piece;
    }

    /** EndCityPieces.addPiece: placed where the parent's offset meets the child's origin. */
    private static function add(self $parent, array $offset, string $name, int $rotation, bool $overwrite): self
    {
        [$dx, $dy, $dz] = Template::transform($offset[0], $offset[1], $offset[2], BlockTransform::NONE, $parent->rotation, [0, 0, 0]);
        $position = [$parent->position[0] + $dx, $parent->position[1] + $dy, $parent->position[2] + $dz];
        return self::make($name, $position, $rotation, $overwrite);
    }

    /** EndCityPieces.startHouseTower */
    public static function start(Blocks $blocks, string $directory, array $origin, int $rotation, RandomSource $random): array
    {
        self::$blocks = $blocks;
        self::$directory = $directory;
        self::$shipCreated = false;
        $pieces = [];
        $pieces[] = $last = self::make('base_floor', $origin, $rotation, true);
        $pieces[] = $last = self::add($last, [-1, 0, -1], 'second_floor_1', $rotation, false);
        $pieces[] = $last = self::add($last, [-1, 4, -1], 'third_floor_1', $rotation, false);
        $pieces[] = $last = self::add($last, [-1, 8, -1], 'third_roof', $rotation, true);
        self::recursive('tower', 1, $last, null, $pieces, $random);
        return $pieces;
    }

    /** EndCityPieces.recursiveChildren: a section joins only if it collides with nothing outside its parent's section. */
    private static function recursive(string $generator, int $depth, self $parent, ?array $offset, array &$pieces, RandomSource $random): bool
    {
        if ($depth > 8) {
            return false;
        }
        $children = [];
        if (!self::$generator($depth, $parent, $offset, $children, $random)) {
            return false;
        }
        $tag = $random->nextInt();
        foreach ($children as $child) {
            $child->tag = $tag;
            foreach ($pieces as $piece) {
                if (Piece::intersects($piece->box, $child->box)) {
                    if ($piece->tag !== $parent->tag) {
                        return false;
                    }
                    break;
                }
            }
        }
        array_push($pieces, ...$children);
        return true;
    }

    private static function house(int $depth, self $parent, ?array $offset, array &$pieces, RandomSource $random): bool
    {
        if ($depth > 8) {
            return false;
        }
        $rotation = $parent->rotation;
        $pieces[] = $last = self::add($parent, $offset, 'base_floor', $rotation, true);
        $floors = $random->nextInt(3);
        if ($floors === 0) {
            $pieces[] = self::add($last, [-1, 4, -1], 'base_roof', $rotation, true);
        } elseif ($floors === 1) {
            $pieces[] = $last = self::add($last, [-1, 0, -1], 'second_floor_2', $rotation, false);
            $pieces[] = $last = self::add($last, [-1, 8, -1], 'second_roof', $rotation, false);
            self::recursive('tower', $depth + 1, $last, null, $pieces, $random);
        } else {
            $pieces[] = $last = self::add($last, [-1, 0, -1], 'second_floor_2', $rotation, false);
            $pieces[] = $last = self::add($last, [-1, 4, -1], 'third_floor_2', $rotation, false);
            $pieces[] = $last = self::add($last, [-1, 8, -1], 'third_roof', $rotation, true);
            self::recursive('tower', $depth + 1, $last, null, $pieces, $random);
        }
        return true;
    }

    private static function tower(int $depth, self $parent, ?array $offset, array &$pieces, RandomSource $random): bool
    {
        $rotation = $parent->rotation;
        $pieces[] = $last = self::add($parent, [3 + $random->nextInt(2), -3, 3 + $random->nextInt(2)], 'tower_base', $rotation, true);
        $pieces[] = $last = self::add($last, [0, 7, 0], 'tower_piece', $rotation, true);
        $bridge = $random->nextInt(3) === 0 ? $last : null;
        $height = 1 + $random->nextInt(3);
        for ($i = 0; $i < $height; ++$i) {
            $pieces[] = $last = self::add($last, [0, 4, 0], 'tower_piece', $rotation, true);
            if ($i < $height - 1 && $random->nextBoolean()) {
                $bridge = $last;
            }
        }
        if ($bridge !== null) {
            foreach (self::TOWER_BRIDGES as [$turn, $at]) {
                if ($random->nextBoolean()) {
                    $pieces[] = $start = self::add($bridge, $at, 'bridge_end', ($rotation + $turn) % 4, true);
                    self::recursive('bridge', $depth + 1, $start, null, $pieces, $random);
                }
            }
        } elseif ($depth !== 7) {
            return self::recursive('fatTower', $depth + 1, $last, null, $pieces, $random);
        }
        $pieces[] = self::add($last, [-1, 4, -1], 'tower_top', $rotation, true);
        return true;
    }

    private static function bridge(int $depth, self $parent, ?array $offset, array &$pieces, RandomSource $random): bool
    {
        $rotation = $parent->rotation;
        $length = $random->nextInt(4) + 1;
        $pieces[] = $last = self::add($parent, [0, 0, -4], 'bridge_piece', $rotation, true);
        $last->tag = -1;
        $nextY = 0;
        for ($i = 0; $i < $length; ++$i) {
            if ($random->nextBoolean()) {
                $pieces[] = $last = self::add($last, [0, $nextY, -4], 'bridge_piece', $rotation, true);
                $nextY = 0;
            } else {
                $pieces[] = $last = $random->nextBoolean()
                    ? self::add($last, [0, $nextY, -4], 'bridge_steep_stairs', $rotation, true)
                    : self::add($last, [0, $nextY, -8], 'bridge_gentle_stairs', $rotation, true);
                $nextY = 4;
            }
        }
        if (!self::$shipCreated && $random->nextInt(10 - $depth) === 0) {
            $pieces[] = self::add($last, [-8 + $random->nextInt(8), $nextY, -70 + $random->nextInt(10)], 'ship', $rotation, true);
            self::$shipCreated = true;
        } elseif (!self::recursive('house', $depth + 1, $last, [-3, $nextY + 1, -11], $pieces, $random)) {
            return false;
        }
        $pieces[] = $end = self::add($last, [4, $nextY, 0], 'bridge_end', ($rotation + 2) % 4, true);
        $end->tag = -1;
        return true;
    }

    private static function fatTower(int $depth, self $parent, ?array $offset, array &$pieces, RandomSource $random): bool
    {
        $rotation = $parent->rotation;
        $pieces[] = $last = self::add($parent, [-3, 4, -3], 'fat_tower_base', $rotation, true);
        $pieces[] = $last = self::add($last, [0, 4, 0], 'fat_tower_middle', $rotation, true);
        for ($i = 0; $i < 2 && $random->nextInt(3) !== 0; ++$i) {
            $pieces[] = $last = self::add($last, [0, 8, 0], 'fat_tower_middle', $rotation, true);
            foreach (self::FAT_TOWER_BRIDGES as [$turn, $at]) {
                if ($random->nextBoolean()) {
                    $pieces[] = $start = self::add($last, $at, 'bridge_end', ($rotation + $turn) % 4, true);
                    self::recursive('bridge', $depth + 1, $start, null, $pieces, $random);
                }
            }
        }
        $pieces[] = self::add($last, [-2, 8, -2], 'fat_tower_top', $rotation, true);
        return true;
    }

    protected function process(StructureContext $context, array $info, array $original): ?array
    {
        $name = $context->blocks->name($info[3]);
        return $name === 'structure_block' || !$this->overwrite && $name === 'air' ? null : $info;
    }

    protected function handleDataMarker(StructureContext $context, Region $region, RandomSource $random, array $chunkBox, string $marker, int $x, int $y, int $z): void
    {
        // Shulker sentries and the ship's elytra item frame are not spawned yet.
        if (str_starts_with($marker, 'Chest') && Piece::inside($chunkBox, $x, $y - 1, $z) && $context->blocks->name($region->get($x, $y - 1, $z)) === 'chest') {
            $context->blockEntities[$x . ',' . ($y - 1) . ',' . $z] = ['id' => 'chest', 'loot_table' => 'minecraft:chests/end_city_treasure', 'seed' => $random->nextLong()];
        }
    }
}
