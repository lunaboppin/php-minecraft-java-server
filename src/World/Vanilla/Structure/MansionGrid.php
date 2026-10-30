<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;

/**
 * WoodlandMansionPieces.MansionGrid: the mansion's 11 by 11 cell plan of corridors and rooms,
 * grown from the entrance, and the rooms of each of its three floors.
 */
final class MansionGrid
{
    public const CORRIDOR = 1;
    public const ROOM = 2;
    public const START_ROOM = 3;
    public const BLOCKED = 5;
    public const ROOM_1X1 = 65536;
    public const ROOM_1X2 = 131072;
    public const ROOM_2X2 = 262144;
    public const ROOM_ORIGIN_FLAG = 1048576;
    public const ROOM_DOOR_FLAG = 2097152;
    public const ROOM_STAIRS_FLAG = 4194304;
    public const ROOM_CORRIDOR_FLAG = 8388608;
    public const ROOM_TYPE_MASK = 983040;
    public const ROOM_ID_MASK = 65535;

    /** Direction step x and z. */
    public const STEPS = ['north' => [0, -1], 'east' => [1, 0], 'south' => [0, 1], 'west' => [-1, 0]];

    /** Direction.from2DDataValue */
    private const BY_2D = ['south', 'west', 'north', 'east'];

    public const CLOCKWISE = ['north' => 'east', 'east' => 'south', 'south' => 'west', 'west' => 'north'];

    public const COUNTER_CLOCKWISE = ['north' => 'west', 'west' => 'south', 'south' => 'east', 'east' => 'north'];

    public const OPPOSITE = ['north' => 'south', 'south' => 'north', 'east' => 'west', 'west' => 'east'];

    public readonly MansionSimpleGrid $baseGrid;

    public readonly MansionSimpleGrid $thirdFloorGrid;

    /** @var array{0: MansionSimpleGrid, 1: MansionSimpleGrid, 2: MansionSimpleGrid} */
    public readonly array $floorRooms;

    public readonly int $entranceX;

    public readonly int $entranceY;

    public function __construct(private readonly RandomSource $random)
    {
        $this->entranceX = 7;
        $this->entranceY = 4;
        [$ex, $ey] = [$this->entranceX, $this->entranceY];
        $this->baseGrid = $base = new MansionSimpleGrid(11, 11, self::BLOCKED);
        $base->fill($ex, $ey, $ex + 1, $ey + 1, self::START_ROOM);
        $base->fill($ex - 1, $ey, $ex - 1, $ey + 1, self::ROOM);
        $base->fill($ex + 2, $ey - 2, $ex + 3, $ey + 3, self::BLOCKED);
        $base->fill($ex + 1, $ey - 2, $ex + 1, $ey - 1, self::CORRIDOR);
        $base->fill($ex + 1, $ey + 2, $ex + 1, $ey + 3, self::CORRIDOR);
        $base->set($ex - 1, $ey - 1, self::CORRIDOR);
        $base->set($ex - 1, $ey + 2, self::CORRIDOR);
        $base->fill(0, 0, 11, 1, self::BLOCKED);
        $base->fill(0, 9, 11, 11, self::BLOCKED);
        $this->recursiveCorridor($base, $ex, $ey - 2, 'west', 6);
        $this->recursiveCorridor($base, $ex, $ey + 3, 'west', 6);
        $this->recursiveCorridor($base, $ex - 2, $ey - 1, 'west', 3);
        $this->recursiveCorridor($base, $ex - 2, $ey + 2, 'west', 3);
        while ($this->cleanEdges($base)) {
        }
        $floors = [new MansionSimpleGrid(11, 11, self::BLOCKED), new MansionSimpleGrid(11, 11, self::BLOCKED), new MansionSimpleGrid(11, 11, self::BLOCKED)];
        $this->floorRooms = $floors;
        $this->identifyRooms($base, $floors[0]);
        $this->identifyRooms($base, $floors[1]);
        $floors[0]->fill($ex + 1, $ey, $ex + 1, $ey + 1, self::ROOM_CORRIDOR_FLAG);
        $floors[1]->fill($ex + 1, $ey, $ex + 1, $ey + 1, self::ROOM_CORRIDOR_FLAG);
        $this->thirdFloorGrid = new MansionSimpleGrid($base->width, $base->height, self::BLOCKED);
        $this->setupThirdFloor();
        $this->identifyRooms($this->thirdFloorGrid, $floors[2]);
    }

    public static function isHouse(MansionSimpleGrid $grid, int $x, int $y): bool
    {
        $value = $grid->get($x, $y);
        return $value === 1 || $value === 2 || $value === 3 || $value === 4;
    }

    public function isRoomId(int $x, int $y, int $floor, int $roomId): bool
    {
        return ($this->floorRooms[$floor]->get($x, $y) & self::ROOM_ID_MASK) === $roomId;
    }

    public function get1x2RoomDirection(int $x, int $y, int $floor, int $roomId): ?string
    {
        foreach (self::STEPS as $direction => [$sx, $sz]) {
            if ($this->isRoomId($x + $sx, $y + $sz, $floor, $roomId)) {
                return $direction;
            }
        }
        return null;
    }

    private function recursiveCorridor(MansionSimpleGrid $grid, int $x, int $y, string $heading, int $depth): void
    {
        if ($depth <= 0) {
            return;
        }
        [$hx, $hz] = self::STEPS[$heading];
        $grid->set($x, $y, self::CORRIDOR);
        $grid->setIf($x + $hx, $y + $hz, 0, self::CORRIDOR);
        for ($attempts = 0; $attempts < 8; ++$attempts) {
            $next = self::BY_2D[$this->random->nextInt(4)];
            if ($next !== self::OPPOSITE[$heading] && ($next !== 'east' || !$this->random->nextBoolean())) {
                [$nx, $nz] = self::STEPS[$next];
                $cx = $x + $hx;
                $cy = $y + $hz;
                if ($grid->get($cx + $nx, $cy + $nz) === 0 && $grid->get($cx + $nx * 2, $cy + $nz * 2) === 0) {
                    $this->recursiveCorridor($grid, $x + $hx + $nx, $y + $hz + $nz, $next, $depth - 1);
                    break;
                }
            }
        }
        [$cwx, $cwz] = self::STEPS[self::CLOCKWISE[$heading]];
        [$ccx, $ccz] = self::STEPS[self::COUNTER_CLOCKWISE[$heading]];
        $grid->setIf($x + $cwx, $y + $cwz, 0, self::ROOM);
        $grid->setIf($x + $ccx, $y + $ccz, 0, self::ROOM);
        $grid->setIf($x + $hx + $cwx, $y + $hz + $cwz, 0, self::ROOM);
        $grid->setIf($x + $hx + $ccx, $y + $hz + $ccz, 0, self::ROOM);
        $grid->setIf($x + $hx * 2, $y + $hz * 2, 0, self::ROOM);
        $grid->setIf($x + $cwx * 2, $y + $cwz * 2, 0, self::ROOM);
        $grid->setIf($x + $ccx * 2, $y + $ccz * 2, 0, self::ROOM);
    }

    private function cleanEdges(MansionSimpleGrid $grid): bool
    {
        $touched = false;
        for ($y = 0; $y < $grid->height; ++$y) {
            for ($x = 0; $x < $grid->width; ++$x) {
                if ($grid->get($x, $y) !== 0) {
                    continue;
                }
                $direct = (int) self::isHouse($grid, $x + 1, $y) + (int) self::isHouse($grid, $x - 1, $y) + (int) self::isHouse($grid, $x, $y + 1) + (int) self::isHouse($grid, $x, $y - 1);
                if ($direct >= 3) {
                    $grid->set($x, $y, self::ROOM);
                    $touched = true;
                } elseif ($direct === 2) {
                    $diagonal = (int) self::isHouse($grid, $x + 1, $y + 1) + (int) self::isHouse($grid, $x - 1, $y + 1) + (int) self::isHouse($grid, $x + 1, $y - 1) + (int) self::isHouse($grid, $x - 1, $y - 1);
                    if ($diagonal <= 1) {
                        $grid->set($x, $y, self::ROOM);
                        $touched = true;
                    }
                }
            }
        }
        return $touched;
    }

    private function setupThirdFloor(): void
    {
        $third = $this->thirdFloorGrid;
        $floor = $this->floorRooms[1];
        $potential = [];
        for ($y = 0; $y < $third->height; ++$y) {
            for ($x = 0; $x < $third->width; ++$x) {
                $data = $floor->get($x, $y);
                if (($data & self::ROOM_TYPE_MASK) === self::ROOM_1X2 && ($data & self::ROOM_DOOR_FLAG) === self::ROOM_DOOR_FLAG) {
                    $potential[] = [$x, $y];
                }
            }
        }
        if ($potential === []) {
            $third->fill(0, 0, $third->width, $third->height, self::BLOCKED);
            return;
        }
        [$rx, $ry] = $potential[$this->random->nextInt(count($potential))];
        $data = $floor->get($rx, $ry);
        $floor->set($rx, $ry, $data | self::ROOM_STAIRS_FLAG);
        [$dx, $dz] = self::STEPS[$this->get1x2RoomDirection($rx, $ry, 1, $data & self::ROOM_ID_MASK)];
        $endX = $rx + $dx;
        $endY = $ry + $dz;
        for ($y = 0; $y < $third->height; ++$y) {
            for ($x = 0; $x < $third->width; ++$x) {
                if (!self::isHouse($this->baseGrid, $x, $y)) {
                    $third->set($x, $y, self::BLOCKED);
                } elseif ($x === $rx && $y === $ry) {
                    $third->set($x, $y, self::START_ROOM);
                } elseif ($x === $endX && $y === $endY) {
                    $third->set($x, $y, self::START_ROOM);
                    $this->floorRooms[2]->set($x, $y, self::ROOM_CORRIDOR_FLAG);
                }
            }
        }
        $corridors = [];
        foreach (self::STEPS as $direction => [$sx, $sz]) {
            if ($third->get($endX + $sx, $endY + $sz) === 0) {
                $corridors[] = $direction;
            }
        }
        if ($corridors === []) {
            $third->fill(0, 0, $third->width, $third->height, self::BLOCKED);
            $floor->set($rx, $ry, $data);
            return;
        }
        $direction = $corridors[$this->random->nextInt(count($corridors))];
        [$sx, $sz] = self::STEPS[$direction];
        $this->recursiveCorridor($third, $endX + $sx, $endY + $sz, $direction, 4);
        while ($this->cleanEdges($third)) {
        }
    }

    private function identifyRooms(MansionSimpleGrid $from, MansionSimpleGrid $rooms): void
    {
        $positions = [];
        for ($y = 0; $y < $from->height; ++$y) {
            for ($x = 0; $x < $from->width; ++$x) {
                if ($from->get($x, $y) === self::ROOM) {
                    $positions[] = [$x, $y];
                }
            }
        }
        // Util.shuffle
        for ($i = count($positions); $i > 1; --$i) {
            $j = $this->random->nextInt($i);
            [$positions[$i - 1], $positions[$j]] = [$positions[$j], $positions[$i - 1]];
        }
        $roomId = 10;
        foreach ($positions as [$x, $y]) {
            if ($rooms->get($x, $y) !== 0) {
                continue;
            }
            [$x0, $x1, $y0, $y1] = [$x, $x, $y, $y];
            $type = self::ROOM_1X1;
            $free = static fn (int $cx, int $cy): bool => $rooms->get($cx, $cy) === 0 && $from->get($cx, $cy) === self::ROOM;
            if ($free($x + 1, $y) && $free($x, $y + 1) && $free($x + 1, $y + 1)) {
                [$x1, $y1, $type] = [$x + 1, $y + 1, self::ROOM_2X2];
            } elseif ($free($x - 1, $y) && $free($x, $y + 1) && $free($x - 1, $y + 1)) {
                [$x0, $y1, $type] = [$x - 1, $y + 1, self::ROOM_2X2];
            } elseif ($free($x - 1, $y) && $free($x, $y - 1) && $free($x - 1, $y - 1)) {
                [$x0, $y0, $type] = [$x - 1, $y - 1, self::ROOM_2X2];
            } elseif ($free($x + 1, $y)) {
                [$x1, $type] = [$x + 1, self::ROOM_1X2];
            } elseif ($free($x, $y + 1)) {
                [$y1, $type] = [$y + 1, self::ROOM_1X2];
            } elseif ($free($x - 1, $y)) {
                [$x0, $type] = [$x - 1, self::ROOM_1X2];
            } elseif ($free($x, $y - 1)) {
                [$y0, $type] = [$y - 1, self::ROOM_1X2];
            }
            $doorX = $this->random->nextBoolean() ? $x0 : $x1;
            $doorY = $this->random->nextBoolean() ? $y0 : $y1;
            $doorFlag = self::ROOM_DOOR_FLAG;
            if (!$from->edgesTo($doorX, $doorY, self::CORRIDOR)) {
                $doorX = $doorX === $x0 ? $x1 : $x0;
                $doorY = $doorY === $y0 ? $y1 : $y0;
                if (!$from->edgesTo($doorX, $doorY, self::CORRIDOR)) {
                    $doorY = $doorY === $y0 ? $y1 : $y0;
                    if (!$from->edgesTo($doorX, $doorY, self::CORRIDOR)) {
                        $doorX = $doorX === $x0 ? $x1 : $x0;
                        $doorY = $doorY === $y0 ? $y1 : $y0;
                        if (!$from->edgesTo($doorX, $doorY, self::CORRIDOR)) {
                            $doorFlag = 0;
                            $doorX = $x0;
                            $doorY = $y0;
                        }
                    }
                }
            }
            for ($ry = $y0; $ry <= $y1; ++$ry) {
                for ($rx = $x0; $rx <= $x1; ++$rx) {
                    $rooms->set($rx, $ry, $rx === $doorX && $ry === $doorY ? self::ROOM_ORIGIN_FLAG | $doorFlag | $type | $roomId : $type | $roomId);
                }
            }
            ++$roomId;
        }
    }
}
