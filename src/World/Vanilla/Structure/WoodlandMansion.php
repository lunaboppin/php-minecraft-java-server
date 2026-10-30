<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/**
 * WoodlandMansionPieces.MansionPiecePlacer and WoodlandMansionPiece: the mansion's outer walls,
 * roofs, corridors and rooms of each floor placed from its grid, and
 * WoodlandMansionStructure.afterPlace's cobblestone foundation. Evokers, vindicators and allays
 * are not spawned yet.
 */
final class WoodlandMansion extends TemplatePiece
{
    /** Rotation: NONE, CLOCKWISE_90, CLOCKWISE_180, COUNTERCLOCKWISE_90. */
    private const CW90 = 1;
    private const CW180 = 2;
    private const CCW90 = 3;

    /** The floors' room collections: 1x1, 1x1 secret, side and front entrance 1x2, 1x2 secret, 2x2. */
    private const ROOMS = [
        ['1x1_a', 5, '1x2_a', 9, '1x2_b', 5, '1x2_s', 2, '2x2_a', 4],
        ['1x1_b', 5, '1x2_c', 4, '1x2_d', 5, '1x2_se', 1, '2x2_b', 5],
    ];

    private static Blocks $blocks;

    private static string $directory;

    private static RandomSource $random;

    /** @var list<self> */
    private static array $pieces;

    private static int $startX;

    private static int $startY;

    public static function restoreWith(array $piece): self
    {
        return new self($piece['box'], $piece['template'], $piece['position'], $piece['rotation'], [0, 0, 0], $piece['mirror']);
    }

    public function type(): string
    {
        return 'woodland_mansion';
    }

    /** Rotation.rotate for a horizontal direction; up stays up. */
    private static function rotate(int $rotation, string $direction): string
    {
        return match ($rotation & 3) {
            self::CW90 => MansionGrid::CLOCKWISE[$direction] ?? $direction,
            self::CW180 => MansionGrid::OPPOSITE[$direction] ?? $direction,
            self::CCW90 => MansionGrid::COUNTER_CLOCKWISE[$direction] ?? $direction,
            default => $direction,
        };
    }

    /** BlockPos.relative along a direction rotated by the mansion's rotation. */
    private static function relative(array $position, int $rotation, string $direction, int $distance): array
    {
        [$sx, $sz] = MansionGrid::STEPS[self::rotate($rotation, $direction)];
        return [$position[0] + $sx * $distance, $position[1], $position[2] + $sz * $distance];
    }

    private static function add(string $name, array $position, int $rotation, int $mirror = BlockTransform::NONE): void
    {
        $template = 'woodland_mansion/' . $name;
        $box = Template::load(self::$blocks, self::$directory, $template)->boundingBox($position, $mirror, $rotation & 3, [0, 0, 0]);
        self::$pieces[] = new self($box, $template, $position, $rotation & 3, [0, 0, 0], $mirror);
    }

    /** The position of a grid cell on a floor. */
    private static function cell(array $origin, int $rotation, int $x, int $y, int $east = 0): array
    {
        return self::relative(self::relative($origin, $rotation, 'south', 8 + ($y - self::$startY) * 8), $rotation, 'east', $east + ($x - self::$startX) * 8);
    }

    /**
     * WoodlandMansionPieces.generateMansion
     *
     * @param array{0: int, 1: int, 2: int} $origin
     * @return list<self>
     */
    public static function generate(Blocks $blocks, string $directory, array $origin, int $rotation, RandomSource $random): array
    {
        self::$blocks = $blocks;
        self::$directory = $directory;
        self::$random = $random;
        self::$pieces = [];
        $mansion = new MansionGrid($random);
        // MansionPiecePlacer.createMansion
        $data = ['position' => $origin, 'rotation' => $rotation, 'wall' => 'wall_flat'];
        self::add('entrance', self::relative($data['position'], $rotation, 'west', 9), $rotation);
        $data['position'] = self::relative($data['position'], $rotation, 'south', 16);
        $second = ['position' => [$data['position'][0], $data['position'][1] + 8, $data['position'][2]], 'rotation' => $rotation, 'wall' => 'wall_window'];
        $base = $mansion->baseGrid;
        $third = $mansion->thirdFloorGrid;
        self::$startX = $mansion->entranceX + 1;
        self::$startY = $mansion->entranceY + 1;
        $endX = $mansion->entranceX + 1;
        $endY = $mansion->entranceY;
        self::traverseOuterWalls($data, $base, 'south', self::$startX, self::$startY, $endX, $endY);
        self::traverseOuterWalls($second, $base, 'south', self::$startX, self::$startY, $endX, $endY);
        $thirdData = ['position' => [$data['position'][0], $data['position'][1] + 19, $data['position'][2]], 'rotation' => $rotation, 'wall' => 'wall_window'];
        for ($y = 0; $y < $third->height; ++$y) {
            for ($x = $third->width - 1; $x >= 0; --$x) {
                if (MansionGrid::isHouse($third, $x, $y)) {
                    $thirdData['position'] = self::relative($thirdData['position'], $rotation, 'south', 8 + ($y - self::$startY) * 8);
                    $thirdData['position'] = self::relative($thirdData['position'], $rotation, 'east', ($x - self::$startX) * 8);
                    self::traverseWallPiece($thirdData);
                    self::traverseOuterWalls($thirdData, $third, 'south', $x, $y, $x, $y);
                    break 2;
                }
            }
        }
        self::createRoof([$origin[0], $origin[1] + 16, $origin[2]], $rotation, $base, $third);
        self::createRoof([$origin[0], $origin[1] + 27, $origin[2]], $rotation, $third, null);
        for ($floor = 0; $floor < 3; ++$floor) {
            $floorOrigin = [$origin[0], $origin[1] + 8 * $floor + ($floor === 2 ? 3 : 0), $origin[2]];
            $rooms = $mansion->floorRooms[$floor];
            $grid = $floor === 2 ? $third : $base;
            $southPiece = $floor === 0 ? 'carpet_south_1' : 'carpet_south_2';
            $westPiece = $floor === 0 ? 'carpet_west_1' : 'carpet_west_2';
            $joins = static fn (int $x, int $y): bool => $grid->get($x, $y) === MansionGrid::CORRIDOR || ($rooms->get($x, $y) & MansionGrid::ROOM_CORRIDOR_FLAG) === MansionGrid::ROOM_CORRIDOR_FLAG;
            for ($y = 0; $y < $grid->height; ++$y) {
                for ($x = 0; $x < $grid->width; ++$x) {
                    if ($grid->get($x, $y) !== MansionGrid::CORRIDOR) {
                        continue;
                    }
                    $position = self::cell($floorOrigin, $rotation, $x, $y);
                    self::add('corridor_floor', $position, $rotation);
                    if ($joins($x, $y - 1)) {
                        $p = self::relative($position, $rotation, 'east', 1);
                        self::add('carpet_north', [$p[0], $p[1] + 1, $p[2]], $rotation);
                    }
                    if ($joins($x + 1, $y)) {
                        $p = self::relative(self::relative($position, $rotation, 'south', 1), $rotation, 'east', 5);
                        self::add('carpet_east', [$p[0], $p[1] + 1, $p[2]], $rotation);
                    }
                    if ($joins($x, $y + 1)) {
                        self::add($southPiece, self::relative(self::relative($position, $rotation, 'south', 5), $rotation, 'west', 1), $rotation);
                    }
                    if ($joins($x - 1, $y)) {
                        self::add($westPiece, self::relative(self::relative($position, $rotation, 'west', 1), $rotation, 'north', 1), $rotation);
                    }
                }
            }
            $wallPiece = $floor === 0 ? 'indoors_wall_1' : 'indoors_wall_2';
            $doorPiece = $floor === 0 ? 'indoors_door_1' : 'indoors_door_2';
            for ($y = 0; $y < $grid->height; ++$y) {
                for ($x = 0; $x < $grid->width; ++$x) {
                    $thirdFloorStart = $floor === 2 && $grid->get($x, $y) === MansionGrid::START_ROOM;
                    if ($grid->get($x, $y) !== MansionGrid::ROOM && !$thirdFloorStart) {
                        continue;
                    }
                    $roomData = $rooms->get($x, $y);
                    $roomType = $roomData & MansionGrid::ROOM_TYPE_MASK;
                    $roomId = $roomData & MansionGrid::ROOM_ID_MASK;
                    $thirdFloorStart = $thirdFloorStart && ($roomData & MansionGrid::ROOM_CORRIDOR_FLAG) === MansionGrid::ROOM_CORRIDOR_FLAG;
                    $doorDirs = [];
                    if (($roomData & MansionGrid::ROOM_DOOR_FLAG) === MansionGrid::ROOM_DOOR_FLAG) {
                        foreach (MansionGrid::STEPS as $direction => [$sx, $sz]) {
                            if ($grid->get($x + $sx, $y + $sz) === MansionGrid::CORRIDOR) {
                                $doorDirs[] = $direction;
                            }
                        }
                    }
                    $doorDir = null;
                    if ($doorDirs !== []) {
                        $doorDir = $doorDirs[$random->nextInt(count($doorDirs))];
                    } elseif (($roomData & MansionGrid::ROOM_ORIGIN_FLAG) === MansionGrid::ROOM_ORIGIN_FLAG) {
                        $doorDir = 'up';
                    }
                    $roomPos = self::cell($floorOrigin, $rotation, $x, $y, -1);
                    if (MansionGrid::isHouse($grid, $x - 1, $y) && !$mansion->isRoomId($x - 1, $y, $floor, $roomId)) {
                        self::add($doorDir === 'west' ? $doorPiece : $wallPiece, $roomPos, $rotation);
                    }
                    if ($grid->get($x + 1, $y) === MansionGrid::CORRIDOR && !$thirdFloorStart) {
                        self::add($doorDir === 'east' ? $doorPiece : $wallPiece, self::relative($roomPos, $rotation, 'east', 8), $rotation);
                    }
                    if (MansionGrid::isHouse($grid, $x, $y + 1) && !$mansion->isRoomId($x, $y + 1, $floor, $roomId)) {
                        self::add($doorDir === 'south' ? $doorPiece : $wallPiece, self::relative(self::relative($roomPos, $rotation, 'south', 7), $rotation, 'east', 7), $rotation + self::CW90);
                    }
                    if ($grid->get($x, $y - 1) === MansionGrid::CORRIDOR && !$thirdFloorStart) {
                        self::add($doorDir === 'north' ? $doorPiece : $wallPiece, self::relative(self::relative($roomPos, $rotation, 'north', 1), $rotation, 'east', 7), $rotation + self::CW90);
                    }
                    $collection = self::ROOMS[min($floor, 1)];
                    if ($roomType === MansionGrid::ROOM_1X1) {
                        self::addRoom1x1($roomPos, $rotation, $doorDir, $collection);
                    } elseif ($roomType === MansionGrid::ROOM_1X2 && $doorDir !== null) {
                        $roomDir = $mansion->get1x2RoomDirection($x, $y, $floor, $roomId);
                        self::addRoom1x2($roomPos, $rotation, $roomDir, $doorDir, $collection, ($roomData & MansionGrid::ROOM_STAIRS_FLAG) === MansionGrid::ROOM_STAIRS_FLAG);
                    } elseif ($roomType === MansionGrid::ROOM_2X2 && $doorDir !== null && $doorDir !== 'up') {
                        $roomDir = MansionGrid::CLOCKWISE[$doorDir];
                        [$sx, $sz] = MansionGrid::STEPS[$roomDir];
                        if (!$mansion->isRoomId($x + $sx, $y + $sz, $floor, $roomId)) {
                            $roomDir = MansionGrid::OPPOSITE[$roomDir];
                        }
                        self::addRoom2x2($roomPos, $rotation, $roomDir, $doorDir, $collection);
                    } elseif ($roomType === MansionGrid::ROOM_2X2 && $doorDir === 'up') {
                        self::add('2x2_s1', self::relative($roomPos, $rotation, 'east', 1), $rotation);
                    }
                }
            }
        }
        return self::$pieces;
    }

    private static function traverseOuterWalls(array &$data, MansionSimpleGrid $grid, string $direction, int $startX, int $startY, int $endX, int $endY): void
    {
        $x = $startX;
        $y = $startY;
        $startDirection = $direction;
        do {
            [$sx, $sz] = MansionGrid::STEPS[$direction];
            [$cx, $cz] = MansionGrid::STEPS[MansionGrid::COUNTER_CLOCKWISE[$direction]];
            if (!MansionGrid::isHouse($grid, $x + $sx, $y + $sz)) {
                self::traverseTurn($data);
                $direction = MansionGrid::CLOCKWISE[$direction];
                if ($x !== $endX || $y !== $endY || $startDirection !== $direction) {
                    self::traverseWallPiece($data);
                }
            } elseif (MansionGrid::isHouse($grid, $x + $sx + $cx, $y + $sz + $cz)) {
                self::traverseInnerTurn($data);
                $x += $sx;
                $y += $sz;
                $direction = MansionGrid::COUNTER_CLOCKWISE[$direction];
            } else {
                $x += $sx;
                $y += $sz;
                if ($x !== $endX || $y !== $endY || $startDirection !== $direction) {
                    self::traverseWallPiece($data);
                }
            }
        } while ($x !== $endX || $y !== $endY || $startDirection !== $direction);
    }

    private static function traverseWallPiece(array &$data): void
    {
        self::add($data['wall'], self::relative($data['position'], $data['rotation'], 'east', 7), $data['rotation']);
        $data['position'] = self::relative($data['position'], $data['rotation'], 'south', 8);
    }

    private static function traverseTurn(array &$data): void
    {
        $data['position'] = self::relative($data['position'], $data['rotation'], 'south', -1);
        self::add('wall_corner', $data['position'], $data['rotation']);
        $data['position'] = self::relative($data['position'], $data['rotation'], 'south', -7);
        $data['position'] = self::relative($data['position'], $data['rotation'], 'west', -6);
        $data['rotation'] = ($data['rotation'] + self::CW90) & 3;
    }

    private static function traverseInnerTurn(array &$data): void
    {
        $data['position'] = self::relative($data['position'], $data['rotation'], 'south', 6);
        $data['position'] = self::relative($data['position'], $data['rotation'], 'east', 8);
        $data['rotation'] = ($data['rotation'] + self::CCW90) & 3;
    }

    private static function createRoof(array $roofOrigin, int $rotation, MansionSimpleGrid $grid, ?MansionSimpleGrid $above): void
    {
        $house = static fn (int $x, int $y): bool => MansionGrid::isHouse($grid, $x, $y);
        $isAbove = static fn (int $x, int $y): bool => $above !== null && MansionGrid::isHouse($above, $x, $y);
        $at = static fn (array $p, string $d1, int $n1, string $d2 = 'east', int $n2 = 0): array => self::relative(self::relative($p, $rotation, $d1, $n1), $rotation, $d2, $n2);
        for ($y = 0; $y < $grid->height; ++$y) {
            for ($x = 0; $x < $grid->width; ++$x) {
                $position = self::cell($roofOrigin, $rotation, $x, $y);
                if (!$house($x, $y) || $isAbove($x, $y)) {
                    continue;
                }
                self::add('roof', [$position[0], $position[1] + 3, $position[2]], $rotation);
                if (!$house($x + 1, $y)) {
                    self::add('roof_front', $at($position, 'east', 6), $rotation);
                }
                if (!$house($x - 1, $y)) {
                    self::add('roof_front', $at($position, 'east', 0, 'south', 7), $rotation + self::CW180);
                }
                if (!$house($x, $y - 1)) {
                    self::add('roof_front', $at($position, 'west', 1), $rotation + self::CCW90);
                }
                if (!$house($x, $y + 1)) {
                    self::add('roof_front', $at($position, 'east', 6, 'south', 6), $rotation + self::CW90);
                }
            }
        }
        if ($above !== null) {
            for ($y = 0; $y < $grid->height; ++$y) {
                for ($x = 0; $x < $grid->width; ++$x) {
                    $position = self::cell($roofOrigin, $rotation, $x, $y);
                    if (!$house($x, $y) || !$isAbove($x, $y)) {
                        continue;
                    }
                    if (!$house($x + 1, $y)) {
                        self::add('small_wall', $at($position, 'east', 7), $rotation);
                    }
                    if (!$house($x - 1, $y)) {
                        self::add('small_wall', $at($position, 'west', 1, 'south', 6), $rotation + self::CW180);
                    }
                    if (!$house($x, $y - 1)) {
                        self::add('small_wall', $at($position, 'west', 0, 'north', 1), $rotation + self::CCW90);
                    }
                    if (!$house($x, $y + 1)) {
                        self::add('small_wall', $at($position, 'east', 6, 'south', 7), $rotation + self::CW90);
                    }
                    if (!$house($x + 1, $y)) {
                        if (!$house($x, $y - 1)) {
                            self::add('small_wall_corner', $at($position, 'east', 7, 'north', 2), $rotation);
                        }
                        if (!$house($x, $y + 1)) {
                            self::add('small_wall_corner', $at($position, 'east', 8, 'south', 7), $rotation + self::CW90);
                        }
                    }
                    if (!$house($x - 1, $y)) {
                        if (!$house($x, $y - 1)) {
                            self::add('small_wall_corner', $at($position, 'west', 2, 'north', 1), $rotation + self::CCW90);
                        }
                        if (!$house($x, $y + 1)) {
                            self::add('small_wall_corner', $at($position, 'west', 1, 'south', 8), $rotation + self::CW180);
                        }
                    }
                }
            }
        }
        for ($y = 0; $y < $grid->height; ++$y) {
            for ($x = 0; $x < $grid->width; ++$x) {
                $position = self::cell($roofOrigin, $rotation, $x, $y);
                if (!$house($x, $y) || $isAbove($x, $y)) {
                    continue;
                }
                if (!$house($x + 1, $y)) {
                    $p2 = $at($position, 'east', 6);
                    if (!$house($x, $y + 1)) {
                        self::add('roof_corner', $at($p2, 'south', 6), $rotation);
                    } elseif ($house($x + 1, $y + 1)) {
                        self::add('roof_inner_corner', $at($p2, 'south', 5), $rotation);
                    }
                    if (!$house($x, $y - 1)) {
                        self::add('roof_corner', $p2, $rotation + self::CCW90);
                    } elseif ($house($x + 1, $y - 1)) {
                        self::add('roof_inner_corner', $at($position, 'east', 9, 'north', 2), $rotation + self::CW90);
                    }
                }
                if (!$house($x - 1, $y)) {
                    $p2 = $position;
                    if (!$house($x, $y + 1)) {
                        self::add('roof_corner', $at($p2, 'south', 6), $rotation + self::CW90);
                    } elseif ($house($x - 1, $y + 1)) {
                        self::add('roof_inner_corner', $at($p2, 'south', 8, 'west', 3), $rotation + self::CCW90);
                    }
                    if (!$house($x, $y - 1)) {
                        self::add('roof_corner', $p2, $rotation + self::CW180);
                    } elseif ($house($x - 1, $y - 1)) {
                        self::add('roof_inner_corner', $at($p2, 'south', 1), $rotation + self::CW180);
                    }
                }
            }
        }
    }

    /** A room template name from a floor's collection: the prefix and a random number up to the count. */
    private static function pick(array $collection, int $index): string
    {
        return $collection[$index] . (self::$random->nextInt($collection[$index + 1]) + 1);
    }

    private static function addRoom1x1(array $roomPos, int $rotation, ?string $doorDir, array $collection): void
    {
        $pieceRot = 0;
        $name = self::pick($collection, 0);
        if ($doorDir !== 'east') {
            if ($doorDir === 'north') {
                $pieceRot = self::CCW90;
            } elseif ($doorDir === 'west') {
                $pieceRot = self::CW180;
            } elseif ($doorDir === 'south') {
                $pieceRot = self::CW90;
            } else {
                $name = '1x1_as' . (self::$random->nextInt(4) + 1);
            }
        }
        // StructureTemplate.getZeroPositionWithTransform(1, 0, 0) for a 7 by 7 room, without a mirror.
        [$ox, $oz] = match ($pieceRot) {
            self::CCW90 => [1, 6],
            self::CW90 => [7, 0],
            self::CW180 => [7, 6],
            default => [1, 0],
        };
        // BlockPos.rotate
        [$ox, $oz] = match ($rotation & 3) {
            self::CW90 => [-$oz, $ox],
            self::CW180 => [-$ox, -$oz],
            self::CCW90 => [$oz, -$ox],
            default => [$ox, $oz],
        };
        self::add($name, [$roomPos[0] + $ox, $roomPos[1], $roomPos[2] + $oz], $pieceRot + $rotation);
    }

    private static function addRoom1x2(array $roomPos, int $rotation, ?string $roomDir, string $doorDir, array $collection, bool $stairs): void
    {
        // The first floor has no stairs rooms; the upper floors' stairs rooms draw no random.
        $stairs = $stairs && $collection[0] !== '1x1_a';
        $side = static fn (): string => $stairs ? '1x2_c_stairs' : self::pick($collection, 2);
        $front = static fn (): string => $stairs ? '1x2_d_stairs' : self::pick($collection, 4);
        $at = static fn (string $d1, int $n1, string $d2 = 'south', int $n2 = 0): array => self::relative(self::relative($roomPos, $rotation, $d1, $n1), $rotation, $d2, $n2);
        match ($doorDir . ' ' . $roomDir) {
            'east south' => self::add($side(), $at('east', 1), $rotation),
            'east north' => self::add($side(), $at('east', 1, 'south', 6), $rotation, BlockTransform::LEFT_RIGHT),
            'west north' => self::add($side(), $at('east', 7, 'south', 6), $rotation + self::CW180),
            'west south' => self::add($side(), $at('east', 7), $rotation, BlockTransform::FRONT_BACK),
            'south east' => self::add($side(), $at('east', 1), $rotation + self::CW90, BlockTransform::LEFT_RIGHT),
            'south west' => self::add($side(), $at('east', 7), $rotation + self::CW90),
            'north west' => self::add($side(), $at('east', 7, 'south', 6), $rotation + self::CW90, BlockTransform::FRONT_BACK),
            'north east' => self::add($side(), $at('east', 1, 'south', 6), $rotation + self::CCW90),
            'south north' => self::add($front(), $at('east', 1, 'north', 8), $rotation),
            'north south' => self::add($front(), $at('east', 7, 'south', 14), $rotation + self::CW180),
            'west east' => self::add($front(), $at('east', 15), $rotation + self::CW90),
            'east west' => self::add($front(), $at('west', 7, 'south', 6), $rotation + self::CCW90),
            'up east' => self::add(self::pick($collection, 6), $at('east', 15), $rotation + self::CW90),
            'up south' => self::add(self::pick($collection, 6), $at('east', 1, 'north', 0), $rotation),
            default => null,
        };
    }

    private static function addRoom2x2(array $roomPos, int $rotation, string $roomDir, string $doorDir, array $collection): void
    {
        [$east, $south, $rot, $mirror] = match ($doorDir . ' ' . $roomDir) {
            'east south' => [-7, 0, $rotation, BlockTransform::NONE],
            'east north' => [-7, 6, $rotation, BlockTransform::LEFT_RIGHT],
            'north east' => [1, 14, $rotation + self::CCW90, BlockTransform::NONE],
            'north west' => [7, 14, $rotation + self::CCW90, BlockTransform::LEFT_RIGHT],
            'south west' => [7, -8, $rotation + self::CW90, BlockTransform::NONE],
            'south east' => [1, -8, $rotation + self::CW90, BlockTransform::LEFT_RIGHT],
            'west north' => [15, 6, $rotation + self::CW180, BlockTransform::NONE],
            'west south' => [15, 0, $rotation, BlockTransform::FRONT_BACK],
            default => [0, 0, $rotation, BlockTransform::NONE],
        };
        self::add(self::pick($collection, 8), self::relative(self::relative($roomPos, $rotation, 'east', $east), $rotation, 'south', $south), $rot, $mirror);
    }

    protected function handleDataMarker(StructureContext $context, Region $region, RandomSource $random, array $chunkBox, string $marker, int $x, int $y, int $z): void
    {
        if (str_starts_with($marker, 'Chest')) {
            $facing = ['ChestWest' => 'west', 'ChestEast' => 'east', 'ChestSouth' => 'south', 'ChestNorth' => 'north'][$marker] ?? null;
            $state = $context->state('chest', $facing === null ? [] : ['facing' => self::rotate($this->rotation, $facing)]);
            // StructurePiece.createChest at a world position.
            if (Piece::inside($chunkBox, $x, $y, $z) && $context->blocks->name($region->get($x, $y, $z)) !== 'chest') {
                $region->set($x, $y, $z, $state);
                $context->blockEntities["$x,$y,$z"] = ['id' => 'chest', 'loot_table' => 'minecraft:chests/woodland_mansion', 'seed' => $random->nextLong()];
            }
            return;
        }
        // The mob markers clear their block once the mob is spawned.
        if (in_array($marker, ['Mage', 'Warrior', 'Group of Allays'], true) && Piece::inside($chunkBox, $x, $y, $z)) {
            $region->set($x, $y, $z, $context->state('air'));
        }
    }

    /**
     * WoodlandMansionStructure.afterPlace: columns under the mansion's lowest floor are filled
     * with cobblestone down to the ground.
     */
    public function afterPlace(StructureContext $context, Region $region, array $chunkBox, array $pieces): void
    {
        $box = $pieces[0]->box;
        foreach ($pieces as $piece) {
            $box = [min($box[0], $piece->box[0]), min($box[1], $piece->box[1]), min($box[2], $piece->box[2]), max($box[3], $piece->box[3]), max($box[4], $piece->box[4]), max($box[5], $piece->box[5])];
        }
        $cobblestone = $context->state('cobblestone');
        $yStart = $box[1];
        for ($x = $chunkBox[0]; $x <= $chunkBox[3]; ++$x) {
            for ($z = $chunkBox[2]; $z <= $chunkBox[5]; ++$z) {
                if ($context->blocks->isAir($region->get($x, $yStart, $z)) || !Piece::inside($box, $x, $yStart, $z) || !self::insidePiece($pieces, $x, $yStart, $z)) {
                    continue;
                }
                for ($y = $yStart - 1; $y > $region->minY; --$y) {
                    $state = $region->get($x, $y, $z);
                    if (!$context->blocks->isAir($state) && !in_array($context->blocks->name($state), ['water', 'lava', 'bubble_column'], true)) {
                        break;
                    }
                    $region->set($x, $y, $z, $cobblestone);
                }
            }
        }
    }

    /** PiecesContainer.isInsidePiece */
    private static function insidePiece(array $pieces, int $x, int $y, int $z): bool
    {
        foreach ($pieces as $piece) {
            if (Piece::inside($piece->box, $x, $y, $z)) {
                return true;
            }
        }
        return false;
    }
}
