<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/**
 * OceanMonumentPieces: the monument building, a 58 by 58 prismarine shell whose rooms are fitted
 * to a random graph of 5 by 4 by 3 cells, with two wings and a penthouse. The building is the
 * start's only piece and places its rooms itself. Elder guardians are not spawned yet.
 */
final class OceanMonument extends Piece
{
    /** Direction.get3DDataValue */
    private const DOWN = 0;
    private const UP = 1;
    private const NORTH = 2;
    private const SOUTH = 3;
    private const WEST = 4;
    private const EAST = 5;

    /** Direction step x, y, z by get3DDataValue. */
    private const STEPS = [[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]];

    /** MonumentBuilding's room fitters, in the order they are tried. */
    private const FITTERS = ['double_xy', 'double_yz', 'double_z', 'double_x', 'double_y', 'simple_top', 'simple'];

    /** @var list<self> the building's rooms, wings and penthouse */
    public array $children = [];

    public ?MonumentRoom $room = null;

    /** SimpleRoom and WingRoom.mainDesign */
    public int $design = 0;

    public function __construct(array $box, string $orientation, public readonly string $kind)
    {
        parent::__construct($box, $orientation, $kind === 'building' ? 0 : 1);
    }

    public function type(): string
    {
        return 'ocean_monument';
    }

    /** The building saves its room graph and its rooms; vanilla regenerates them from the seed. */
    public function data(): array
    {
        $rooms = [];
        $collect = static function (?MonumentRoom $room) use (&$rooms, &$collect): void {
            if ($room === null || isset($rooms[$room->index])) {
                return;
            }
            $rooms[$room->index] = [array_map(intval(...), $room->hasOpening), array_map(static fn (?MonumentRoom $next): ?int => $next?->index, $room->connections)];
            foreach ($room->connections as $next) {
                $collect($next);
            }
        };
        foreach ($this->children as $child) {
            $collect($child->room);
        }
        return ['rooms' => $rooms, 'children' => array_map(static fn (self $child): array => [$child->kind, $child->box, $child->room?->index, $child->design], $this->children)];
    }

    public static function restoreWith(array $piece): self
    {
        $building = new self($piece['box'], $piece['orientation'], 'building');
        $rooms = [];
        foreach ($piece['rooms'] as $index => [$openings]) {
            $rooms[$index] = new MonumentRoom((int) $index);
            $rooms[$index]->hasOpening = array_map(boolval(...), $openings);
        }
        foreach ($piece['rooms'] as $index => [, $connections]) {
            $rooms[$index]->connections = array_map(static fn (?int $next): ?MonumentRoom => $next === null ? null : $rooms[$next], $connections);
        }
        foreach ($piece['children'] as [$kind, $box, $room, $design]) {
            $child = new self($box, $piece['orientation'], $kind);
            $child->room = $room === null ? null : $rooms[$room];
            $child->design = $design;
            $building->children[] = $child;
        }
        return $building;
    }

    private static function roomIndex(int $x, int $y, int $z): int
    {
        return $y * 25 + $z * 5 + $x;
    }

    /** MonumentBuilding's constructor: the room graph, the rooms fitted to it, the wings and the penthouse. */
    public static function create(RandomSource $random, int $west, int $north, string $direction): self
    {
        $building = new self(self::makeBox($west, 39, $north, $direction, 58, 23, 58), $direction, 'building');
        $rooms = self::roomGraph($random, $source, $core);
        $source->claimed = true;
        $building->children[] = self::room('entry_room', $direction, $source);
        $building->children[] = self::room('core_room', $direction, $core);
        foreach ($rooms as $room) {
            if ($room->claimed || $room->isSpecial()) {
                continue;
            }
            foreach (self::FITTERS as $fitter) {
                if (self::fits($fitter, $room)) {
                    $building->children[] = self::fit($fitter, $direction, $room, $random);
                    break;
                }
            }
        }
        [$dx, $dy, $dz] = $building->worldPos(9, 0, 22);
        foreach ($building->children as $child) {
            $child->box = [$child->box[0] + $dx, $child->box[1] + $dy, $child->box[2] + $dz, $child->box[3] + $dx, $child->box[4] + $dy, $child->box[5] + $dz];
        }
        $wingRandom = $random->nextInt();
        foreach ([[1, 1, 1, 23, 8, 21], [34, 1, 1, 56, 8, 21]] as $corners) {
            $wing = new self($building->corners(...$corners), $direction, 'wing');
            $wing->design = $wingRandom++ & 1;
            $building->children[] = $wing;
        }
        $building->children[] = new self($building->corners(22, 13, 22, 35, 17, 35), $direction, 'penthouse');
        return $building;
    }

    /** BoundingBox.fromCorners of two local positions. */
    private function corners(int $x0, int $y0, int $z0, int $x1, int $y1, int $z1): array
    {
        $a = $this->worldPos($x0, $y0, $z0);
        $b = $this->worldPos($x1, $y1, $z1);
        return [min($a[0], $b[0]), min($a[1], $b[1]), min($a[2], $b[2]), max($a[0], $b[0]), max($a[1], $b[1]), max($a[2], $b[2])];
    }

    /**
     * MonumentBuilding.generateRoomGraph
     *
     * @return list<MonumentRoom>
     */
    private static function roomGraph(RandomSource $random, ?MonumentRoom &$source, ?MonumentRoom &$core): array
    {
        $grid = [];
        foreach ([[0, 5, 0, 4, 0], [0, 5, 0, 4, 1], [1, 4, 0, 2, 2]] as [$x0, $x1, $z0, $z1, $y]) {
            for ($x = $x0; $x < $x1; ++$x) {
                for ($z = $z0; $z < $z1; ++$z) {
                    $grid[self::roomIndex($x, $y, $z)] = new MonumentRoom(self::roomIndex($x, $y, $z));
                }
            }
        }
        ksort($grid);
        $source = $grid[self::roomIndex(2, 0, 0)];
        for ($x = 0; $x < 5; ++$x) {
            for ($z = 0; $z < 5; ++$z) {
                for ($y = 0; $y < 3; ++$y) {
                    $room = $grid[self::roomIndex($x, $y, $z)] ?? null;
                    if ($room === null) {
                        continue;
                    }
                    foreach (self::STEPS as $direction => [$sx, $sy, $sz]) {
                        [$nx, $ny, $nz] = [$x + $sx, $y + $sy, $z + $sz];
                        if ($nx < 0 || $nx >= 5 || $nz < 0 || $nz >= 5 || $ny < 0 || $ny >= 3 || !isset($grid[self::roomIndex($nx, $ny, $nz)])) {
                            continue;
                        }
                        // Neighbours along z are connected the opposite way, as vanilla does.
                        $room->setConnection($nz === $z ? $direction : $direction ^ 1, $grid[self::roomIndex($nx, $ny, $nz)]);
                    }
                }
            }
        }
        $roof = new MonumentRoom(1003);
        $leftWing = new MonumentRoom(1001);
        $rightWing = new MonumentRoom(1002);
        $grid[self::roomIndex(2, 2, 0)]->setConnection(self::UP, $roof);
        $grid[self::roomIndex(0, 1, 0)]->setConnection(self::SOUTH, $leftWing);
        $grid[self::roomIndex(4, 1, 0)]->setConnection(self::SOUTH, $rightWing);
        $roof->claimed = $leftWing->claimed = $rightWing->claimed = true;
        $source->isSource = true;
        $core = $grid[self::roomIndex($random->nextInt(4), 0, 2)];
        $east = $core->connections[self::EAST];
        $northRoom = $core->connections[self::NORTH];
        foreach ([$core, $east, $northRoom, $east->connections[self::NORTH], $core->connections[self::UP], $east->connections[self::UP], $northRoom->connections[self::UP], $east->connections[self::NORTH]->connections[self::UP]] as $claimed) {
            $claimed->claimed = true;
        }
        $rooms = [];
        foreach ($grid as $room) {
            $room->updateOpenings();
            $rooms[] = $room;
        }
        $roof->updateOpenings();
        // Util.shuffle
        for ($i = count($rooms); $i > 1; --$i) {
            $j = $random->nextInt($i);
            [$rooms[$i - 1], $rooms[$j]] = [$rooms[$j], $rooms[$i - 1]];
        }
        $scanIndex = 1;
        foreach ($rooms as $room) {
            $closed = 0;
            $attempts = 0;
            while ($closed < 2 && $attempts < 5) {
                ++$attempts;
                $face = $random->nextInt(6);
                if (!$room->hasOpening[$face]) {
                    continue;
                }
                $opposite = $face ^ 1;
                $room->hasOpening[$face] = false;
                $room->connections[$face]->hasOpening[$opposite] = false;
                if ($room->findSource($scanIndex++) && $room->connections[$face]->findSource($scanIndex++)) {
                    ++$closed;
                } else {
                    $room->hasOpening[$face] = true;
                    $room->connections[$face]->hasOpening[$opposite] = true;
                }
            }
        }
        $rooms[] = $roof;
        $rooms[] = $leftWing;
        $rooms[] = $rightWing;
        return $rooms;
    }

    /** MonumentRoomFitter.fits */
    private static function fits(string $fitter, MonumentRoom $room): bool
    {
        $open = static fn (MonumentRoom $room, int $direction): bool => $room->hasOpening[$direction] && !$room->connections[$direction]->claimed;
        return match ($fitter) {
            'double_x' => $open($room, self::EAST),
            'double_xy' => $open($room, self::EAST) && $open($room, self::UP) && $open($room->connections[self::EAST], self::UP),
            'double_y' => $open($room, self::UP),
            'double_yz' => $open($room, self::NORTH) && $open($room, self::UP) && $open($room->connections[self::NORTH], self::UP),
            'double_z' => $open($room, self::NORTH),
            'simple_top' => !$room->hasOpening[self::WEST] && !$room->hasOpening[self::EAST] && !$room->hasOpening[self::NORTH] && !$room->hasOpening[self::SOUTH] && !$room->hasOpening[self::UP],
            default => true,
        };
    }

    /** MonumentRoomFitter.create: claims the cells the room covers. */
    private static function fit(string $fitter, string $direction, MonumentRoom $room, RandomSource $random): self
    {
        if ($fitter === 'double_z' && (!$room->hasOpening[self::NORTH] || $room->connections[self::NORTH]->claimed)) {
            $room = $room->connections[self::SOUTH];
        }
        $room->claimed = true;
        $claim = match ($fitter) {
            'double_x' => [[self::EAST]],
            'double_xy' => [[self::EAST], [self::UP], [self::EAST, self::UP]],
            'double_y' => [[self::UP]],
            'double_yz' => [[self::NORTH], [self::UP], [self::NORTH, self::UP]],
            'double_z' => [[self::NORTH]],
            default => [],
        };
        foreach ($claim as $path) {
            $cell = $room;
            foreach ($path as $step) {
                $cell = $cell->connections[$step];
            }
            $cell->claimed = true;
        }
        $piece = self::room($fitter, $direction, $room);
        if ($fitter === 'simple') {
            $piece->design = $random->nextInt(3);
        }
        return $piece;
    }

    /** OceanMonumentPiece's room constructor: the room's box in grid cells, before the building moves it into place. */
    private static function room(string $kind, string $direction, MonumentRoom $room): self
    {
        [$width, $height, $depth] = match ($kind) {
            'core_room' => [2, 2, 2],
            'double_x' => [2, 1, 1],
            'double_xy' => [2, 2, 1],
            'double_y' => [1, 2, 1],
            'double_yz' => [1, 2, 2],
            'double_z' => [1, 1, 2],
            default => [1, 1, 1],
        };
        $roomX = $room->index % 5;
        $roomZ = intdiv($room->index, 5) % 5;
        $roomY = intdiv($room->index, 25);
        $box = self::makeBox(0, 0, 0, $direction, $width * 8, $height * 4, $depth * 8);
        [$dx, $dy, $dz] = match ($direction) {
            'north' => [$roomX * 8, $roomY * 4, -($roomZ + $depth) * 8 + 1],
            'south' => [$roomX * 8, $roomY * 4, $roomZ * 8],
            'west' => [-($roomZ + $depth) * 8 + 1, $roomY * 4, $roomX * 8],
            default => [$roomZ * 8, $roomY * 4, $roomX * 8],
        };
        $piece = new self([$box[0] + $dx, $box[1] + $dy, $box[2] + $dz, $box[3] + $dx, $box[4] + $dy, $box[5] + $dz], $direction, $kind);
        $piece->room = $room;
        return $piece;
    }

    public function postProcess(StructureContext $c, Region $r, RandomSource $random, array $b): void
    {
        match ($this->kind) {
            'building' => $this->building($c, $r, $b, $random),
            'core_room' => $this->core_room($c, $r, $b, $random),
            'double_x' => $this->double_x($c, $r, $b, $random),
            'double_xy' => $this->double_xy($c, $r, $b, $random),
            'double_y' => $this->double_y($c, $r, $b, $random),
            'double_yz' => $this->double_yz($c, $r, $b, $random),
            'double_z' => $this->double_z($c, $r, $b, $random),
            'entry_room' => $this->entry_room($c, $r, $b, $random),
            'penthouse' => $this->penthouse($c, $r, $b, $random),
            'simple' => $this->simple($c, $r, $b, $random),
            'simple_top' => $this->simple_top($c, $r, $b, $random),
            'wing' => $this->wing($c, $r, $b, $random),
        };
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} BASE_GRAY, BASE_LIGHT (and DOT_DECO_DATA), BASE_BLACK and LAMP_BLOCK */
    private static function palette(StructureContext $c): array
    {
        return [$c->state('prismarine'), $c->state('prismarine_bricks'), $c->state('dark_prismarine'), $c->state('sea_lantern')];
    }

    /** OceanMonumentPiece.generateWaterBox: water below sea level and air above, keeping ice and water. */
    private function waterBox(StructureContext $c, Region $r, array $b, int $x0, int $y0, int $z0, int $x1, int $y1, int $z1): void
    {
        $water = $c->state('water');
        $air = $c->state('air');
        for ($y = $y0; $y <= $y1; ++$y) {
            for ($x = $x0; $x <= $x1; ++$x) {
                for ($z = $z0; $z <= $z1; ++$z) {
                    $state = $this->getBlock($r, $x, $y, $z, $b);
                    if (in_array($c->blocks->name($state), ['ice', 'packed_ice', 'blue_ice', 'water'], true)) {
                        continue;
                    }
                    $this->placeBlock($c, $r, $this->worldY($y) >= $r->seaLevel && $state !== $water ? $air : $water, $x, $y, $z, $b);
                }
            }
        }
    }

    /** OceanMonumentPiece.generateDefaultFloor */
    private function defaultFloor(StructureContext $c, Region $r, array $b, int $xOff, int $zOff, bool $downOpening): void
    {
        [$gray, $light] = self::palette($c);
        if (!$downOpening) {
            $this->generateBox($c, $r, $b, $xOff, 0, $zOff, $xOff + 7, 0, $zOff + 7, $gray, $gray, false);
            return;
        }
        $this->generateBox($c, $r, $b, $xOff, 0, $zOff, $xOff + 2, 0, $zOff + 7, $gray, $gray, false);
        $this->generateBox($c, $r, $b, $xOff + 5, 0, $zOff, $xOff + 7, 0, $zOff + 7, $gray, $gray, false);
        $this->generateBox($c, $r, $b, $xOff + 3, 0, $zOff, $xOff + 4, 0, $zOff + 2, $gray, $gray, false);
        $this->generateBox($c, $r, $b, $xOff + 3, 0, $zOff + 5, $xOff + 4, 0, $zOff + 7, $gray, $gray, false);
        $this->generateBox($c, $r, $b, $xOff + 3, 0, $zOff + 2, $xOff + 4, 0, $zOff + 2, $light, $light, false);
        $this->generateBox($c, $r, $b, $xOff + 3, 0, $zOff + 5, $xOff + 4, 0, $zOff + 5, $light, $light, false);
        $this->generateBox($c, $r, $b, $xOff + 2, 0, $zOff + 3, $xOff + 2, 0, $zOff + 4, $light, $light, false);
        $this->generateBox($c, $r, $b, $xOff + 5, 0, $zOff + 3, $xOff + 5, 0, $zOff + 4, $light, $light, false);
    }

    /** OceanMonumentPiece.generateBoxOnFillOnly: replaces only water. */
    private function boxOnFillOnly(StructureContext $c, Region $r, array $b, int $x0, int $y0, int $z0, int $x1, int $y1, int $z1, int $state): void
    {
        $water = $c->state('water');
        for ($y = $y0; $y <= $y1; ++$y) {
            for ($x = $x0; $x <= $x1; ++$x) {
                for ($z = $z0; $z <= $z1; ++$z) {
                    if ($this->getBlock($r, $x, $y, $z, $b) === $water) {
                        $this->placeBlock($c, $r, $state, $x, $y, $z, $b);
                    }
                }
            }
        }
    }

    /** OceanMonumentPiece.chunkIntersects: whether a local area overlaps the chunk in x and z. */
    private function chunkIntersects(array $b, int $x0, int $z0, int $x1, int $z1): bool
    {
        [$wx0, $wz0, $wx1, $wz1] = [$this->worldX($x0, $z0), $this->worldZ($x0, $z0), $this->worldX($x1, $z1), $this->worldZ($x1, $z1)];
        return $b[3] >= min($wx0, $wx1) && $b[0] <= max($wx0, $wx1) && $b[5] >= min($wz0, $wz1) && $b[2] <= max($wz0, $wz1);
    }

    /** OceanMonumentPiece.spawnElder: elder guardians are not spawned yet. */
    private function spawnElder(StructureContext $c, array $b, int $x, int $y, int $z): void
    {
    }

    private function building(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        $waterHeight = max($r->seaLevel, 64) - $this->box[1];
        $this->waterBox($c, $r, $b, 0, 0, 0, 58, $waterHeight, 58);
        $this->generateWing($c, $r, $b, false, 0);
        $this->generateWing($c, $r, $b, true, 33);
        $this->generateEntranceArchs($c, $r, $b);
        $this->generateEntranceWall($c, $r, $b);
        $this->generateRoofPiece($c, $r, $b);
        $this->generateLowerWall($c, $r, $b);
        $this->generateMiddleWall($c, $r, $b);
        $this->generateUpperWall($c, $r, $b);
        for ($pillarX = 0; $pillarX < 7; ++$pillarX) {
            $pillarZ = 0;
            while ($pillarZ < 7) {
                if ($pillarZ === 0 && $pillarX === 3) {
                    $pillarZ = 6;
                }
                $bx = $pillarX * 9;
                $bz = $pillarZ * 9;
                for ($w = 0; $w < 4; ++$w) {
                    for ($d = 0; $d < 4; ++$d) {
                        $this->placeBlock($c, $r, $light, $bx + $w, 0, $bz + $d, $b);
                        $this->fillColumnDown($c, $r, $light, $bx + $w, -1, $bz + $d, $b);
                    }
                }
                if ($pillarX !== 0 && $pillarX !== 6) {
                    $pillarZ += 6;
                } else {
                    $pillarZ++;
                }
            }
        }
        for ($i = 0; $i < 5; ++$i) {
            $this->waterBox($c, $r, $b, -1 - $i, 0 + $i * 2, -1 - $i, -1 - $i, 23, 58 + $i);
            $this->waterBox($c, $r, $b, 58 + $i, 0 + $i * 2, -1 - $i, 58 + $i, 23, 58 + $i);
            $this->waterBox($c, $r, $b, 0 - $i, 0 + $i * 2, -1 - $i, 57 + $i, 23, -1 - $i);
            $this->waterBox($c, $r, $b, 0 - $i, 0 + $i * 2, 58 + $i, 57 + $i, 23, 58 + $i);
        }
        foreach ($this->children as $child) {
            if (self::intersects($child->box, $b)) {
                $child->postProcess($c, $r, $random, $b);
            }
        }
    }

    private function generateWing(StructureContext $c, Region $r, array $b, bool $isFlipped, int $xoff): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        if ($this->chunkIntersects($b, $xoff, 0, $xoff + 23, 20)) {
            $this->generateBox($c, $r, $b, $xoff + 0, 0, 0, $xoff + 24, 0, 20, $gray, $gray, false);
            $this->waterBox($c, $r, $b, $xoff + 0, 1, 0, $xoff + 24, 10, 20);
            for ($i = 0; $i < 4; ++$i) {
                $this->generateBox($c, $r, $b, $xoff + $i, $i + 1, $i, $xoff + $i, $i + 1, 20, $light, $light, false);
                $this->generateBox($c, $r, $b, $xoff + $i + 7, $i + 5, $i + 7, $xoff + $i + 7, $i + 5, 20, $light, $light, false);
                $this->generateBox($c, $r, $b, $xoff + 17 - $i, $i + 5, $i + 7, $xoff + 17 - $i, $i + 5, 20, $light, $light, false);
                $this->generateBox($c, $r, $b, $xoff + 24 - $i, $i + 1, $i, $xoff + 24 - $i, $i + 1, 20, $light, $light, false);
                $this->generateBox($c, $r, $b, $xoff + $i + 1, $i + 1, $i, $xoff + 23 - $i, $i + 1, $i, $light, $light, false);
                $this->generateBox($c, $r, $b, $xoff + $i + 8, $i + 5, $i + 7, $xoff + 16 - $i, $i + 5, $i + 7, $light, $light, false);
            }
            $this->generateBox($c, $r, $b, $xoff + 4, 4, 4, $xoff + 6, 4, 20, $gray, $gray, false);
            $this->generateBox($c, $r, $b, $xoff + 7, 4, 4, $xoff + 17, 4, 6, $gray, $gray, false);
            $this->generateBox($c, $r, $b, $xoff + 18, 4, 4, $xoff + 20, 4, 20, $gray, $gray, false);
            $this->generateBox($c, $r, $b, $xoff + 11, 8, 11, $xoff + 13, 8, 20, $gray, $gray, false);
            $this->placeBlock($c, $r, $light, $xoff + 12, 9, 12, $b);
            $this->placeBlock($c, $r, $light, $xoff + 12, 9, 15, $b);
            $this->placeBlock($c, $r, $light, $xoff + 12, 9, 18, $b);
            $leftPos = $xoff + ($isFlipped ? 19 : 5);
            $rightPos = $xoff + ($isFlipped ? 5 : 19);
            for ($z = 20; $z >= 5; $z -= 3) {
                $this->placeBlock($c, $r, $light, $leftPos, 5, $z, $b);
            }
            for ($z = 19; $z >= 7; $z -= 3) {
                $this->placeBlock($c, $r, $light, $rightPos, 5, $z, $b);
            }
            for ($i = 0; $i < 4; ++$i) {
                $pos = $isFlipped ? $xoff + 24 - (17 - $i * 3) : $xoff + 17 - $i * 3;
                $this->placeBlock($c, $r, $light, $pos, 5, 5, $b);
            }
            $this->placeBlock($c, $r, $light, $rightPos, 5, 5, $b);
            $this->generateBox($c, $r, $b, $xoff + 11, 1, 12, $xoff + 13, 7, 12, $gray, $gray, false);
            $this->generateBox($c, $r, $b, $xoff + 12, 1, 11, $xoff + 12, 7, 13, $gray, $gray, false);
        }
    }

    private function generateEntranceArchs(StructureContext $c, Region $r, array $b): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        if ($this->chunkIntersects($b, 22, 5, 35, 17)) {
            $this->waterBox($c, $r, $b, 25, 0, 0, 32, 8, 20);
            for ($i = 0; $i < 4; ++$i) {
                $this->generateBox($c, $r, $b, 24, 2, 5 + $i * 4, 24, 4, 5 + $i * 4, $light, $light, false);
                $this->generateBox($c, $r, $b, 22, 4, 5 + $i * 4, 23, 4, 5 + $i * 4, $light, $light, false);
                $this->placeBlock($c, $r, $light, 25, 5, 5 + $i * 4, $b);
                $this->placeBlock($c, $r, $light, 26, 6, 5 + $i * 4, $b);
                $this->placeBlock($c, $r, $lamp, 26, 5, 5 + $i * 4, $b);
                $this->generateBox($c, $r, $b, 33, 2, 5 + $i * 4, 33, 4, 5 + $i * 4, $light, $light, false);
                $this->generateBox($c, $r, $b, 34, 4, 5 + $i * 4, 35, 4, 5 + $i * 4, $light, $light, false);
                $this->placeBlock($c, $r, $light, 32, 5, 5 + $i * 4, $b);
                $this->placeBlock($c, $r, $light, 31, 6, 5 + $i * 4, $b);
                $this->placeBlock($c, $r, $lamp, 31, 5, 5 + $i * 4, $b);
                $this->generateBox($c, $r, $b, 27, 6, 5 + $i * 4, 30, 6, 5 + $i * 4, $gray, $gray, false);
            }
        }
    }

    private function generateEntranceWall(StructureContext $c, Region $r, array $b): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        if ($this->chunkIntersects($b, 15, 20, 42, 21)) {
            $this->generateBox($c, $r, $b, 15, 0, 21, 42, 0, 21, $gray, $gray, false);
            $this->waterBox($c, $r, $b, 26, 1, 21, 31, 3, 21);
            $this->generateBox($c, $r, $b, 21, 12, 21, 36, 12, 21, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 17, 11, 21, 40, 11, 21, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 16, 10, 21, 41, 10, 21, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 15, 7, 21, 42, 9, 21, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 16, 6, 21, 41, 6, 21, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 17, 5, 21, 40, 5, 21, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 21, 4, 21, 36, 4, 21, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 22, 3, 21, 26, 3, 21, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 31, 3, 21, 35, 3, 21, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 23, 2, 21, 25, 2, 21, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 32, 2, 21, 34, 2, 21, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 28, 4, 20, 29, 4, 21, $light, $light, false);
            $this->placeBlock($c, $r, $light, 27, 3, 21, $b);
            $this->placeBlock($c, $r, $light, 30, 3, 21, $b);
            $this->placeBlock($c, $r, $light, 26, 2, 21, $b);
            $this->placeBlock($c, $r, $light, 31, 2, 21, $b);
            $this->placeBlock($c, $r, $light, 25, 1, 21, $b);
            $this->placeBlock($c, $r, $light, 32, 1, 21, $b);
            for ($i = 0; $i < 7; ++$i) {
                $this->placeBlock($c, $r, $black, 28 - $i, 6 + $i, 21, $b);
                $this->placeBlock($c, $r, $black, 29 + $i, 6 + $i, 21, $b);
            }
            for ($i = 0; $i < 4; ++$i) {
                $this->placeBlock($c, $r, $black, 28 - $i, 9 + $i, 21, $b);
                $this->placeBlock($c, $r, $black, 29 + $i, 9 + $i, 21, $b);
            }
            $this->placeBlock($c, $r, $black, 28, 12, 21, $b);
            $this->placeBlock($c, $r, $black, 29, 12, 21, $b);
            for ($i = 0; $i < 3; ++$i) {
                $this->placeBlock($c, $r, $black, 22 - $i * 2, 8, 21, $b);
                $this->placeBlock($c, $r, $black, 22 - $i * 2, 9, 21, $b);
                $this->placeBlock($c, $r, $black, 35 + $i * 2, 8, 21, $b);
                $this->placeBlock($c, $r, $black, 35 + $i * 2, 9, 21, $b);
            }
            $this->waterBox($c, $r, $b, 15, 13, 21, 42, 15, 21);
            $this->waterBox($c, $r, $b, 15, 1, 21, 15, 6, 21);
            $this->waterBox($c, $r, $b, 16, 1, 21, 16, 5, 21);
            $this->waterBox($c, $r, $b, 17, 1, 21, 20, 4, 21);
            $this->waterBox($c, $r, $b, 21, 1, 21, 21, 3, 21);
            $this->waterBox($c, $r, $b, 22, 1, 21, 22, 2, 21);
            $this->waterBox($c, $r, $b, 23, 1, 21, 24, 1, 21);
            $this->waterBox($c, $r, $b, 42, 1, 21, 42, 6, 21);
            $this->waterBox($c, $r, $b, 41, 1, 21, 41, 5, 21);
            $this->waterBox($c, $r, $b, 37, 1, 21, 40, 4, 21);
            $this->waterBox($c, $r, $b, 36, 1, 21, 36, 3, 21);
            $this->waterBox($c, $r, $b, 33, 1, 21, 34, 1, 21);
            $this->waterBox($c, $r, $b, 35, 1, 21, 35, 2, 21);
        }
    }

    private function generateRoofPiece(StructureContext $c, Region $r, array $b): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        if ($this->chunkIntersects($b, 21, 21, 36, 36)) {
            $this->generateBox($c, $r, $b, 21, 0, 22, 36, 0, 36, $gray, $gray, false);
            $this->waterBox($c, $r, $b, 21, 1, 22, 36, 23, 36);
            for ($i = 0; $i < 4; ++$i) {
                $this->generateBox($c, $r, $b, 21 + $i, 13 + $i, 21 + $i, 36 - $i, 13 + $i, 21 + $i, $light, $light, false);
                $this->generateBox($c, $r, $b, 21 + $i, 13 + $i, 36 - $i, 36 - $i, 13 + $i, 36 - $i, $light, $light, false);
                $this->generateBox($c, $r, $b, 21 + $i, 13 + $i, 22 + $i, 21 + $i, 13 + $i, 35 - $i, $light, $light, false);
                $this->generateBox($c, $r, $b, 36 - $i, 13 + $i, 22 + $i, 36 - $i, 13 + $i, 35 - $i, $light, $light, false);
            }
            $this->generateBox($c, $r, $b, 25, 16, 25, 32, 16, 32, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 25, 17, 25, 25, 19, 25, $light, $light, false);
            $this->generateBox($c, $r, $b, 32, 17, 25, 32, 19, 25, $light, $light, false);
            $this->generateBox($c, $r, $b, 25, 17, 32, 25, 19, 32, $light, $light, false);
            $this->generateBox($c, $r, $b, 32, 17, 32, 32, 19, 32, $light, $light, false);
            $this->placeBlock($c, $r, $light, 26, 20, 26, $b);
            $this->placeBlock($c, $r, $light, 27, 21, 27, $b);
            $this->placeBlock($c, $r, $lamp, 27, 20, 27, $b);
            $this->placeBlock($c, $r, $light, 26, 20, 31, $b);
            $this->placeBlock($c, $r, $light, 27, 21, 30, $b);
            $this->placeBlock($c, $r, $lamp, 27, 20, 30, $b);
            $this->placeBlock($c, $r, $light, 31, 20, 31, $b);
            $this->placeBlock($c, $r, $light, 30, 21, 30, $b);
            $this->placeBlock($c, $r, $lamp, 30, 20, 30, $b);
            $this->placeBlock($c, $r, $light, 31, 20, 26, $b);
            $this->placeBlock($c, $r, $light, 30, 21, 27, $b);
            $this->placeBlock($c, $r, $lamp, 30, 20, 27, $b);
            $this->generateBox($c, $r, $b, 28, 21, 27, 29, 21, 27, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 27, 21, 28, 27, 21, 29, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 28, 21, 30, 29, 21, 30, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 30, 21, 28, 30, 21, 29, $gray, $gray, false);
        }
    }

    private function generateLowerWall(StructureContext $c, Region $r, array $b): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        if ($this->chunkIntersects($b, 0, 21, 6, 58)) {
            $this->generateBox($c, $r, $b, 0, 0, 21, 6, 0, 57, $gray, $gray, false);
            $this->waterBox($c, $r, $b, 0, 1, 21, 6, 7, 57);
            $this->generateBox($c, $r, $b, 4, 4, 21, 6, 4, 53, $gray, $gray, false);
            for ($i = 0; $i < 4; ++$i) {
                $this->generateBox($c, $r, $b, $i, $i + 1, 21, $i, $i + 1, 57 - $i, $light, $light, false);
            }
            for ($z = 23; $z < 53; $z += 3) {
                $this->placeBlock($c, $r, $light, 5, 5, $z, $b);
            }
            $this->placeBlock($c, $r, $light, 5, 5, 52, $b);
            for ($i = 0; $i < 4; ++$i) {
                $this->generateBox($c, $r, $b, $i, $i + 1, 21, $i, $i + 1, 57 - $i, $light, $light, false);
            }
            $this->generateBox($c, $r, $b, 4, 1, 52, 6, 3, 52, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 5, 1, 51, 5, 3, 53, $gray, $gray, false);
        }
        if ($this->chunkIntersects($b, 51, 21, 58, 58)) {
            $this->generateBox($c, $r, $b, 51, 0, 21, 57, 0, 57, $gray, $gray, false);
            $this->waterBox($c, $r, $b, 51, 1, 21, 57, 7, 57);
            $this->generateBox($c, $r, $b, 51, 4, 21, 53, 4, 53, $gray, $gray, false);
            for ($i = 0; $i < 4; ++$i) {
                $this->generateBox($c, $r, $b, 57 - $i, $i + 1, 21, 57 - $i, $i + 1, 57 - $i, $light, $light, false);
            }
            for ($z = 23; $z < 53; $z += 3) {
                $this->placeBlock($c, $r, $light, 52, 5, $z, $b);
            }
            $this->placeBlock($c, $r, $light, 52, 5, 52, $b);
            $this->generateBox($c, $r, $b, 51, 1, 52, 53, 3, 52, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 52, 1, 51, 52, 3, 53, $gray, $gray, false);
        }
        if ($this->chunkIntersects($b, 0, 51, 57, 57)) {
            $this->generateBox($c, $r, $b, 7, 0, 51, 50, 0, 57, $gray, $gray, false);
            $this->waterBox($c, $r, $b, 7, 1, 51, 50, 10, 57);
            for ($i = 0; $i < 4; ++$i) {
                $this->generateBox($c, $r, $b, $i + 1, $i + 1, 57 - $i, 56 - $i, $i + 1, 57 - $i, $light, $light, false);
            }
        }
    }

    private function generateMiddleWall(StructureContext $c, Region $r, array $b): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        if ($this->chunkIntersects($b, 7, 21, 13, 50)) {
            $this->generateBox($c, $r, $b, 7, 0, 21, 13, 0, 50, $gray, $gray, false);
            $this->waterBox($c, $r, $b, 7, 1, 21, 13, 10, 50);
            $this->generateBox($c, $r, $b, 11, 8, 21, 13, 8, 53, $gray, $gray, false);
            for ($i = 0; $i < 4; ++$i) {
                $this->generateBox($c, $r, $b, $i + 7, $i + 5, 21, $i + 7, $i + 5, 54, $light, $light, false);
            }
            for ($z = 21; $z <= 45; $z += 3) {
                $this->placeBlock($c, $r, $light, 12, 9, $z, $b);
            }
        }
        if ($this->chunkIntersects($b, 44, 21, 50, 54)) {
            $this->generateBox($c, $r, $b, 44, 0, 21, 50, 0, 50, $gray, $gray, false);
            $this->waterBox($c, $r, $b, 44, 1, 21, 50, 10, 50);
            $this->generateBox($c, $r, $b, 44, 8, 21, 46, 8, 53, $gray, $gray, false);
            for ($i = 0; $i < 4; ++$i) {
                $this->generateBox($c, $r, $b, 50 - $i, $i + 5, 21, 50 - $i, $i + 5, 54, $light, $light, false);
            }
            for ($z = 21; $z <= 45; $z += 3) {
                $this->placeBlock($c, $r, $light, 45, 9, $z, $b);
            }
        }
        if ($this->chunkIntersects($b, 8, 44, 49, 54)) {
            $this->generateBox($c, $r, $b, 14, 0, 44, 43, 0, 50, $gray, $gray, false);
            $this->waterBox($c, $r, $b, 14, 1, 44, 43, 10, 50);
            for ($x = 12; $x <= 45; $x += 3) {
                $this->placeBlock($c, $r, $light, $x, 9, 45, $b);
                $this->placeBlock($c, $r, $light, $x, 9, 52, $b);
                if ($x === 12 || $x === 18 || $x === 24 || $x === 33 || $x === 39 || $x === 45) {
                    $this->placeBlock($c, $r, $light, $x, 9, 47, $b);
                    $this->placeBlock($c, $r, $light, $x, 9, 50, $b);
                    $this->placeBlock($c, $r, $light, $x, 10, 45, $b);
                    $this->placeBlock($c, $r, $light, $x, 10, 46, $b);
                    $this->placeBlock($c, $r, $light, $x, 10, 51, $b);
                    $this->placeBlock($c, $r, $light, $x, 10, 52, $b);
                    $this->placeBlock($c, $r, $light, $x, 11, 47, $b);
                    $this->placeBlock($c, $r, $light, $x, 11, 50, $b);
                    $this->placeBlock($c, $r, $light, $x, 12, 48, $b);
                    $this->placeBlock($c, $r, $light, $x, 12, 49, $b);
                }
            }
            for ($i = 0; $i < 3; ++$i) {
                $this->generateBox($c, $r, $b, 8 + $i, 5 + $i, 54, 49 - $i, 5 + $i, 54, $gray, $gray, false);
            }
            $this->generateBox($c, $r, $b, 11, 8, 54, 46, 8, 54, $light, $light, false);
            $this->generateBox($c, $r, $b, 14, 8, 44, 43, 8, 53, $gray, $gray, false);
        }
    }

    private function generateUpperWall(StructureContext $c, Region $r, array $b): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        if ($this->chunkIntersects($b, 14, 21, 20, 43)) {
            $this->generateBox($c, $r, $b, 14, 0, 21, 20, 0, 43, $gray, $gray, false);
            $this->waterBox($c, $r, $b, 14, 1, 22, 20, 14, 43);
            $this->generateBox($c, $r, $b, 18, 12, 22, 20, 12, 39, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 18, 12, 21, 20, 12, 21, $light, $light, false);
            for ($i = 0; $i < 4; ++$i) {
                $this->generateBox($c, $r, $b, $i + 14, $i + 9, 21, $i + 14, $i + 9, 43 - $i, $light, $light, false);
            }
            for ($z = 23; $z <= 39; $z += 3) {
                $this->placeBlock($c, $r, $light, 19, 13, $z, $b);
            }
        }
        if ($this->chunkIntersects($b, 37, 21, 43, 43)) {
            $this->generateBox($c, $r, $b, 37, 0, 21, 43, 0, 43, $gray, $gray, false);
            $this->waterBox($c, $r, $b, 37, 1, 22, 43, 14, 43);
            $this->generateBox($c, $r, $b, 37, 12, 22, 39, 12, 39, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 37, 12, 21, 39, 12, 21, $light, $light, false);
            for ($i = 0; $i < 4; ++$i) {
                $this->generateBox($c, $r, $b, 43 - $i, $i + 9, 21, 43 - $i, $i + 9, 43 - $i, $light, $light, false);
            }
            for ($z = 23; $z <= 39; $z += 3) {
                $this->placeBlock($c, $r, $light, 38, 13, $z, $b);
            }
        }
        if ($this->chunkIntersects($b, 15, 37, 42, 43)) {
            $this->generateBox($c, $r, $b, 21, 0, 37, 36, 0, 43, $gray, $gray, false);
            $this->waterBox($c, $r, $b, 21, 1, 37, 36, 14, 43);
            $this->generateBox($c, $r, $b, 21, 12, 37, 36, 12, 39, $gray, $gray, false);
            for ($i = 0; $i < 4; ++$i) {
                $this->generateBox($c, $r, $b, 15 + $i, $i + 9, 43 - $i, 42 - $i, $i + 9, 43 - $i, $light, $light, false);
            }
            for ($x = 21; $x <= 36; $x += 3) {
                $this->placeBlock($c, $r, $light, $x, 13, 38, $b);
            }
        }
    }

    private function core_room(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        $this->boxOnFillOnly($c, $r, $b, 1, 8, 0, 14, 8, 14, $gray);
        $y = 7;
        $block = $light;
        $this->generateBox($c, $r, $b, 0, 7, 0, 0, 7, 15, $block, $block, false);
        $this->generateBox($c, $r, $b, 15, 7, 0, 15, 7, 15, $block, $block, false);
        $this->generateBox($c, $r, $b, 1, 7, 0, 15, 7, 0, $block, $block, false);
        $this->generateBox($c, $r, $b, 1, 7, 15, 14, 7, 15, $block, $block, false);
        for ($yx = 1; $yx <= 6; ++$yx) {
            $block = $light;
            if ($yx === 2 || $yx === 6) {
                $block = $gray;
            }
            for ($x = 0; $x <= 15; $x += 15) {
                $this->generateBox($c, $r, $b, $x, $yx, 0, $x, $yx, 1, $block, $block, false);
                $this->generateBox($c, $r, $b, $x, $yx, 6, $x, $yx, 9, $block, $block, false);
                $this->generateBox($c, $r, $b, $x, $yx, 14, $x, $yx, 15, $block, $block, false);
            }
            $this->generateBox($c, $r, $b, 1, $yx, 0, 1, $yx, 0, $block, $block, false);
            $this->generateBox($c, $r, $b, 6, $yx, 0, 9, $yx, 0, $block, $block, false);
            $this->generateBox($c, $r, $b, 14, $yx, 0, 14, $yx, 0, $block, $block, false);
            $this->generateBox($c, $r, $b, 1, $yx, 15, 14, $yx, 15, $block, $block, false);
        }
        $this->generateBox($c, $r, $b, 6, 3, 6, 9, 6, 9, $black, $black, false);
        $this->generateBox($c, $r, $b, 7, 4, 7, 8, 5, 8, $c->state('gold_block'), $c->state('gold_block'), false);
        for ($yx = 3; $yx <= 6; $yx += 3) {
            for ($x = 6; $x <= 9; $x += 3) {
                $this->placeBlock($c, $r, $lamp, $x, $yx, 6, $b);
                $this->placeBlock($c, $r, $lamp, $x, $yx, 9, $b);
            }
        }
        $this->generateBox($c, $r, $b, 5, 1, 6, 5, 2, 6, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 1, 9, 5, 2, 9, $light, $light, false);
        $this->generateBox($c, $r, $b, 10, 1, 6, 10, 2, 6, $light, $light, false);
        $this->generateBox($c, $r, $b, 10, 1, 9, 10, 2, 9, $light, $light, false);
        $this->generateBox($c, $r, $b, 6, 1, 5, 6, 2, 5, $light, $light, false);
        $this->generateBox($c, $r, $b, 9, 1, 5, 9, 2, 5, $light, $light, false);
        $this->generateBox($c, $r, $b, 6, 1, 10, 6, 2, 10, $light, $light, false);
        $this->generateBox($c, $r, $b, 9, 1, 10, 9, 2, 10, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 2, 5, 5, 6, 5, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 2, 10, 5, 6, 10, $light, $light, false);
        $this->generateBox($c, $r, $b, 10, 2, 5, 10, 6, 5, $light, $light, false);
        $this->generateBox($c, $r, $b, 10, 2, 10, 10, 6, 10, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 7, 1, 5, 7, 6, $light, $light, false);
        $this->generateBox($c, $r, $b, 10, 7, 1, 10, 7, 6, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 7, 9, 5, 7, 14, $light, $light, false);
        $this->generateBox($c, $r, $b, 10, 7, 9, 10, 7, 14, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 7, 5, 6, 7, 5, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 7, 10, 6, 7, 10, $light, $light, false);
        $this->generateBox($c, $r, $b, 9, 7, 5, 14, 7, 5, $light, $light, false);
        $this->generateBox($c, $r, $b, 9, 7, 10, 14, 7, 10, $light, $light, false);
        $this->generateBox($c, $r, $b, 2, 1, 2, 2, 1, 3, $light, $light, false);
        $this->generateBox($c, $r, $b, 3, 1, 2, 3, 1, 2, $light, $light, false);
        $this->generateBox($c, $r, $b, 13, 1, 2, 13, 1, 3, $light, $light, false);
        $this->generateBox($c, $r, $b, 12, 1, 2, 12, 1, 2, $light, $light, false);
        $this->generateBox($c, $r, $b, 2, 1, 12, 2, 1, 13, $light, $light, false);
        $this->generateBox($c, $r, $b, 3, 1, 13, 3, 1, 13, $light, $light, false);
        $this->generateBox($c, $r, $b, 13, 1, 12, 13, 1, 13, $light, $light, false);
        $this->generateBox($c, $r, $b, 12, 1, 13, 12, 1, 13, $light, $light, false);
    }

    private function double_x(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        $east = $this->room->connections[5];
        $west = $this->room;
        if ($this->room->index >= 25) {
            $this->defaultFloor($c, $r, $b, 8, 0, $east->hasOpening[0]);
            $this->defaultFloor($c, $r, $b, 0, 0, $west->hasOpening[0]);
        }
        if ($west->connections[1] === null) {
            $this->boxOnFillOnly($c, $r, $b, 1, 4, 1, 7, 4, 6, $gray);
        }
        if ($east->connections[1] === null) {
            $this->boxOnFillOnly($c, $r, $b, 8, 4, 1, 14, 4, 6, $gray);
        }
        $this->generateBox($c, $r, $b, 0, 3, 0, 0, 3, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 15, 3, 0, 15, 3, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 3, 0, 15, 3, 0, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 3, 7, 14, 3, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 0, 2, 7, $gray, $gray, false);
        $this->generateBox($c, $r, $b, 15, 2, 0, 15, 2, 7, $gray, $gray, false);
        $this->generateBox($c, $r, $b, 1, 2, 0, 15, 2, 0, $gray, $gray, false);
        $this->generateBox($c, $r, $b, 1, 2, 7, 14, 2, 7, $gray, $gray, false);
        $this->generateBox($c, $r, $b, 0, 1, 0, 0, 1, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 15, 1, 0, 15, 1, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 1, 0, 15, 1, 0, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 1, 7, 14, 1, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 1, 0, 10, 1, 4, $light, $light, false);
        $this->generateBox($c, $r, $b, 6, 2, 0, 9, 2, 3, $gray, $gray, false);
        $this->generateBox($c, $r, $b, 5, 3, 0, 10, 3, 4, $light, $light, false);
        $this->placeBlock($c, $r, $lamp, 6, 2, 3, $b);
        $this->placeBlock($c, $r, $lamp, 9, 2, 3, $b);
        if ($west->hasOpening[3]) {
            $this->waterBox($c, $r, $b, 3, 1, 0, 4, 2, 0);
        }
        if ($west->hasOpening[2]) {
            $this->waterBox($c, $r, $b, 3, 1, 7, 4, 2, 7);
        }
        if ($west->hasOpening[4]) {
            $this->waterBox($c, $r, $b, 0, 1, 3, 0, 2, 4);
        }
        if ($east->hasOpening[3]) {
            $this->waterBox($c, $r, $b, 11, 1, 0, 12, 2, 0);
        }
        if ($east->hasOpening[2]) {
            $this->waterBox($c, $r, $b, 11, 1, 7, 12, 2, 7);
        }
        if ($east->hasOpening[5]) {
            $this->waterBox($c, $r, $b, 15, 1, 3, 15, 2, 4);
        }
    }

    private function double_xy(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        $east = $this->room->connections[5];
        $west = $this->room;
        $westUp = $west->connections[1];
        $eastUp = $east->connections[1];
        if ($this->room->index >= 25) {
            $this->defaultFloor($c, $r, $b, 8, 0, $east->hasOpening[0]);
            $this->defaultFloor($c, $r, $b, 0, 0, $west->hasOpening[0]);
        }
        if ($westUp->connections[1] === null) {
            $this->boxOnFillOnly($c, $r, $b, 1, 8, 1, 7, 8, 6, $gray);
        }
        if ($eastUp->connections[1] === null) {
            $this->boxOnFillOnly($c, $r, $b, 8, 8, 1, 14, 8, 6, $gray);
        }
        for ($y = 1; $y <= 7; ++$y) {
            $block = $light;
            if ($y === 2 || $y === 6) {
                $block = $gray;
            }
            $this->generateBox($c, $r, $b, 0, $y, 0, 0, $y, 7, $block, $block, false);
            $this->generateBox($c, $r, $b, 15, $y, 0, 15, $y, 7, $block, $block, false);
            $this->generateBox($c, $r, $b, 1, $y, 0, 15, $y, 0, $block, $block, false);
            $this->generateBox($c, $r, $b, 1, $y, 7, 14, $y, 7, $block, $block, false);
        }
        $this->generateBox($c, $r, $b, 2, 1, 3, 2, 7, 4, $light, $light, false);
        $this->generateBox($c, $r, $b, 3, 1, 2, 4, 7, 2, $light, $light, false);
        $this->generateBox($c, $r, $b, 3, 1, 5, 4, 7, 5, $light, $light, false);
        $this->generateBox($c, $r, $b, 13, 1, 3, 13, 7, 4, $light, $light, false);
        $this->generateBox($c, $r, $b, 11, 1, 2, 12, 7, 2, $light, $light, false);
        $this->generateBox($c, $r, $b, 11, 1, 5, 12, 7, 5, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 1, 3, 5, 3, 4, $light, $light, false);
        $this->generateBox($c, $r, $b, 10, 1, 3, 10, 3, 4, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 7, 2, 10, 7, 5, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 5, 2, 5, 7, 2, $light, $light, false);
        $this->generateBox($c, $r, $b, 10, 5, 2, 10, 7, 2, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 5, 5, 5, 7, 5, $light, $light, false);
        $this->generateBox($c, $r, $b, 10, 5, 5, 10, 7, 5, $light, $light, false);
        $this->placeBlock($c, $r, $light, 6, 6, 2, $b);
        $this->placeBlock($c, $r, $light, 9, 6, 2, $b);
        $this->placeBlock($c, $r, $light, 6, 6, 5, $b);
        $this->placeBlock($c, $r, $light, 9, 6, 5, $b);
        $this->generateBox($c, $r, $b, 5, 4, 3, 6, 4, 4, $light, $light, false);
        $this->generateBox($c, $r, $b, 9, 4, 3, 10, 4, 4, $light, $light, false);
        $this->placeBlock($c, $r, $lamp, 5, 4, 2, $b);
        $this->placeBlock($c, $r, $lamp, 5, 4, 5, $b);
        $this->placeBlock($c, $r, $lamp, 10, 4, 2, $b);
        $this->placeBlock($c, $r, $lamp, 10, 4, 5, $b);
        if ($west->hasOpening[3]) {
            $this->waterBox($c, $r, $b, 3, 1, 0, 4, 2, 0);
        }
        if ($west->hasOpening[2]) {
            $this->waterBox($c, $r, $b, 3, 1, 7, 4, 2, 7);
        }
        if ($west->hasOpening[4]) {
            $this->waterBox($c, $r, $b, 0, 1, 3, 0, 2, 4);
        }
        if ($east->hasOpening[3]) {
            $this->waterBox($c, $r, $b, 11, 1, 0, 12, 2, 0);
        }
        if ($east->hasOpening[2]) {
            $this->waterBox($c, $r, $b, 11, 1, 7, 12, 2, 7);
        }
        if ($east->hasOpening[5]) {
            $this->waterBox($c, $r, $b, 15, 1, 3, 15, 2, 4);
        }
        if ($westUp->hasOpening[3]) {
            $this->waterBox($c, $r, $b, 3, 5, 0, 4, 6, 0);
        }
        if ($westUp->hasOpening[2]) {
            $this->waterBox($c, $r, $b, 3, 5, 7, 4, 6, 7);
        }
        if ($westUp->hasOpening[4]) {
            $this->waterBox($c, $r, $b, 0, 5, 3, 0, 6, 4);
        }
        if ($eastUp->hasOpening[3]) {
            $this->waterBox($c, $r, $b, 11, 5, 0, 12, 6, 0);
        }
        if ($eastUp->hasOpening[2]) {
            $this->waterBox($c, $r, $b, 11, 5, 7, 12, 6, 7);
        }
        if ($eastUp->hasOpening[5]) {
            $this->waterBox($c, $r, $b, 15, 5, 3, 15, 6, 4);
        }
    }

    private function double_y(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        if ($this->room->index >= 25) {
            $this->defaultFloor($c, $r, $b, 0, 0, $this->room->hasOpening[0]);
        }
        $above = $this->room->connections[1];
        if ($above->connections[1] === null) {
            $this->boxOnFillOnly($c, $r, $b, 1, 8, 1, 6, 8, 6, $gray);
        }
        $this->generateBox($c, $r, $b, 0, 4, 0, 0, 4, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 7, 4, 0, 7, 4, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 4, 0, 6, 4, 0, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 4, 7, 6, 4, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 2, 4, 1, 2, 4, 2, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 4, 2, 1, 4, 2, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 4, 1, 5, 4, 2, $light, $light, false);
        $this->generateBox($c, $r, $b, 6, 4, 2, 6, 4, 2, $light, $light, false);
        $this->generateBox($c, $r, $b, 2, 4, 5, 2, 4, 6, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 4, 5, 1, 4, 5, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 4, 5, 5, 4, 6, $light, $light, false);
        $this->generateBox($c, $r, $b, 6, 4, 5, 6, 4, 5, $light, $light, false);
        $definition = $this->room;
        for ($y = 1; $y <= 5; $y += 4) {
            $z = 0;
            if ($definition->hasOpening[3]) {
                $this->generateBox($c, $r, $b, 2, $y, $z, 2, $y + 2, $z, $light, $light, false);
                $this->generateBox($c, $r, $b, 5, $y, $z, 5, $y + 2, $z, $light, $light, false);
                $this->generateBox($c, $r, $b, 3, $y + 2, $z, 4, $y + 2, $z, $light, $light, false);
            } else {
                $this->generateBox($c, $r, $b, 0, $y, $z, 7, $y + 2, $z, $light, $light, false);
                $this->generateBox($c, $r, $b, 0, $y + 1, $z, 7, $y + 1, $z, $gray, $gray, false);
            }
            $var13 = 7;
            if ($definition->hasOpening[2]) {
                $this->generateBox($c, $r, $b, 2, $y, $var13, 2, $y + 2, $var13, $light, $light, false);
                $this->generateBox($c, $r, $b, 5, $y, $var13, 5, $y + 2, $var13, $light, $light, false);
                $this->generateBox($c, $r, $b, 3, $y + 2, $var13, 4, $y + 2, $var13, $light, $light, false);
            } else {
                $this->generateBox($c, $r, $b, 0, $y, $var13, 7, $y + 2, $var13, $light, $light, false);
                $this->generateBox($c, $r, $b, 0, $y + 1, $var13, 7, $y + 1, $var13, $gray, $gray, false);
            }
            $x = 0;
            if ($definition->hasOpening[4]) {
                $this->generateBox($c, $r, $b, $x, $y, 2, $x, $y + 2, 2, $light, $light, false);
                $this->generateBox($c, $r, $b, $x, $y, 5, $x, $y + 2, 5, $light, $light, false);
                $this->generateBox($c, $r, $b, $x, $y + 2, 3, $x, $y + 2, 4, $light, $light, false);
            } else {
                $this->generateBox($c, $r, $b, $x, $y, 0, $x, $y + 2, 7, $light, $light, false);
                $this->generateBox($c, $r, $b, $x, $y + 1, 0, $x, $y + 1, 7, $gray, $gray, false);
            }
            $var14 = 7;
            if ($definition->hasOpening[5]) {
                $this->generateBox($c, $r, $b, $var14, $y, 2, $var14, $y + 2, 2, $light, $light, false);
                $this->generateBox($c, $r, $b, $var14, $y, 5, $var14, $y + 2, 5, $light, $light, false);
                $this->generateBox($c, $r, $b, $var14, $y + 2, 3, $var14, $y + 2, 4, $light, $light, false);
            } else {
                $this->generateBox($c, $r, $b, $var14, $y, 0, $var14, $y + 2, 7, $light, $light, false);
                $this->generateBox($c, $r, $b, $var14, $y + 1, 0, $var14, $y + 1, 7, $gray, $gray, false);
            }
            $definition = $above;
        }
    }

    private function double_yz(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        $north = $this->room->connections[2];
        $south = $this->room;
        $northUp = $north->connections[1];
        $southUp = $south->connections[1];
        if ($this->room->index >= 25) {
            $this->defaultFloor($c, $r, $b, 0, 8, $north->hasOpening[0]);
            $this->defaultFloor($c, $r, $b, 0, 0, $south->hasOpening[0]);
        }
        if ($southUp->connections[1] === null) {
            $this->boxOnFillOnly($c, $r, $b, 1, 8, 1, 6, 8, 7, $gray);
        }
        if ($northUp->connections[1] === null) {
            $this->boxOnFillOnly($c, $r, $b, 1, 8, 8, 6, 8, 14, $gray);
        }
        for ($y = 1; $y <= 7; ++$y) {
            $block = $light;
            if ($y === 2 || $y === 6) {
                $block = $gray;
            }
            $this->generateBox($c, $r, $b, 0, $y, 0, 0, $y, 15, $block, $block, false);
            $this->generateBox($c, $r, $b, 7, $y, 0, 7, $y, 15, $block, $block, false);
            $this->generateBox($c, $r, $b, 1, $y, 0, 6, $y, 0, $block, $block, false);
            $this->generateBox($c, $r, $b, 1, $y, 15, 6, $y, 15, $block, $block, false);
        }
        for ($y = 1; $y <= 7; ++$y) {
            $block = $black;
            if ($y === 2 || $y === 6) {
                $block = $lamp;
            }
            $this->generateBox($c, $r, $b, 3, $y, 7, 4, $y, 8, $block, $block, false);
        }
        if ($south->hasOpening[3]) {
            $this->waterBox($c, $r, $b, 3, 1, 0, 4, 2, 0);
        }
        if ($south->hasOpening[5]) {
            $this->waterBox($c, $r, $b, 7, 1, 3, 7, 2, 4);
        }
        if ($south->hasOpening[4]) {
            $this->waterBox($c, $r, $b, 0, 1, 3, 0, 2, 4);
        }
        if ($north->hasOpening[2]) {
            $this->waterBox($c, $r, $b, 3, 1, 15, 4, 2, 15);
        }
        if ($north->hasOpening[4]) {
            $this->waterBox($c, $r, $b, 0, 1, 11, 0, 2, 12);
        }
        if ($north->hasOpening[5]) {
            $this->waterBox($c, $r, $b, 7, 1, 11, 7, 2, 12);
        }
        if ($southUp->hasOpening[3]) {
            $this->waterBox($c, $r, $b, 3, 5, 0, 4, 6, 0);
        }
        if ($southUp->hasOpening[5]) {
            $this->waterBox($c, $r, $b, 7, 5, 3, 7, 6, 4);
            $this->generateBox($c, $r, $b, 5, 4, 2, 6, 4, 5, $light, $light, false);
            $this->generateBox($c, $r, $b, 6, 1, 2, 6, 3, 2, $light, $light, false);
            $this->generateBox($c, $r, $b, 6, 1, 5, 6, 3, 5, $light, $light, false);
        }
        if ($southUp->hasOpening[4]) {
            $this->waterBox($c, $r, $b, 0, 5, 3, 0, 6, 4);
            $this->generateBox($c, $r, $b, 1, 4, 2, 2, 4, 5, $light, $light, false);
            $this->generateBox($c, $r, $b, 1, 1, 2, 1, 3, 2, $light, $light, false);
            $this->generateBox($c, $r, $b, 1, 1, 5, 1, 3, 5, $light, $light, false);
        }
        if ($northUp->hasOpening[2]) {
            $this->waterBox($c, $r, $b, 3, 5, 15, 4, 6, 15);
        }
        if ($northUp->hasOpening[4]) {
            $this->waterBox($c, $r, $b, 0, 5, 11, 0, 6, 12);
            $this->generateBox($c, $r, $b, 1, 4, 10, 2, 4, 13, $light, $light, false);
            $this->generateBox($c, $r, $b, 1, 1, 10, 1, 3, 10, $light, $light, false);
            $this->generateBox($c, $r, $b, 1, 1, 13, 1, 3, 13, $light, $light, false);
        }
        if ($northUp->hasOpening[5]) {
            $this->waterBox($c, $r, $b, 7, 5, 11, 7, 6, 12);
            $this->generateBox($c, $r, $b, 5, 4, 10, 6, 4, 13, $light, $light, false);
            $this->generateBox($c, $r, $b, 6, 1, 10, 6, 3, 10, $light, $light, false);
            $this->generateBox($c, $r, $b, 6, 1, 13, 6, 3, 13, $light, $light, false);
        }
    }

    private function double_z(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        $north = $this->room->connections[2];
        $south = $this->room;
        if ($this->room->index >= 25) {
            $this->defaultFloor($c, $r, $b, 0, 8, $north->hasOpening[0]);
            $this->defaultFloor($c, $r, $b, 0, 0, $south->hasOpening[0]);
        }
        if ($south->connections[1] === null) {
            $this->boxOnFillOnly($c, $r, $b, 1, 4, 1, 6, 4, 7, $gray);
        }
        if ($north->connections[1] === null) {
            $this->boxOnFillOnly($c, $r, $b, 1, 4, 8, 6, 4, 14, $gray);
        }
        $this->generateBox($c, $r, $b, 0, 3, 0, 0, 3, 15, $light, $light, false);
        $this->generateBox($c, $r, $b, 7, 3, 0, 7, 3, 15, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 3, 0, 7, 3, 0, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 3, 15, 6, 3, 15, $light, $light, false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 0, 2, 15, $gray, $gray, false);
        $this->generateBox($c, $r, $b, 7, 2, 0, 7, 2, 15, $gray, $gray, false);
        $this->generateBox($c, $r, $b, 1, 2, 0, 7, 2, 0, $gray, $gray, false);
        $this->generateBox($c, $r, $b, 1, 2, 15, 6, 2, 15, $gray, $gray, false);
        $this->generateBox($c, $r, $b, 0, 1, 0, 0, 1, 15, $light, $light, false);
        $this->generateBox($c, $r, $b, 7, 1, 0, 7, 1, 15, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 1, 0, 7, 1, 0, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 1, 15, 6, 1, 15, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 1, 1, 1, 1, 2, $light, $light, false);
        $this->generateBox($c, $r, $b, 6, 1, 1, 6, 1, 2, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 3, 1, 1, 3, 2, $light, $light, false);
        $this->generateBox($c, $r, $b, 6, 3, 1, 6, 3, 2, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 1, 13, 1, 1, 14, $light, $light, false);
        $this->generateBox($c, $r, $b, 6, 1, 13, 6, 1, 14, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 3, 13, 1, 3, 14, $light, $light, false);
        $this->generateBox($c, $r, $b, 6, 3, 13, 6, 3, 14, $light, $light, false);
        $this->generateBox($c, $r, $b, 2, 1, 6, 2, 3, 6, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 1, 6, 5, 3, 6, $light, $light, false);
        $this->generateBox($c, $r, $b, 2, 1, 9, 2, 3, 9, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 1, 9, 5, 3, 9, $light, $light, false);
        $this->generateBox($c, $r, $b, 3, 2, 6, 4, 2, 6, $light, $light, false);
        $this->generateBox($c, $r, $b, 3, 2, 9, 4, 2, 9, $light, $light, false);
        $this->generateBox($c, $r, $b, 2, 2, 7, 2, 2, 8, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 2, 7, 5, 2, 8, $light, $light, false);
        $this->placeBlock($c, $r, $lamp, 2, 2, 5, $b);
        $this->placeBlock($c, $r, $lamp, 5, 2, 5, $b);
        $this->placeBlock($c, $r, $lamp, 2, 2, 10, $b);
        $this->placeBlock($c, $r, $lamp, 5, 2, 10, $b);
        $this->placeBlock($c, $r, $light, 2, 3, 5, $b);
        $this->placeBlock($c, $r, $light, 5, 3, 5, $b);
        $this->placeBlock($c, $r, $light, 2, 3, 10, $b);
        $this->placeBlock($c, $r, $light, 5, 3, 10, $b);
        if ($south->hasOpening[3]) {
            $this->waterBox($c, $r, $b, 3, 1, 0, 4, 2, 0);
        }
        if ($south->hasOpening[5]) {
            $this->waterBox($c, $r, $b, 7, 1, 3, 7, 2, 4);
        }
        if ($south->hasOpening[4]) {
            $this->waterBox($c, $r, $b, 0, 1, 3, 0, 2, 4);
        }
        if ($north->hasOpening[2]) {
            $this->waterBox($c, $r, $b, 3, 1, 15, 4, 2, 15);
        }
        if ($north->hasOpening[4]) {
            $this->waterBox($c, $r, $b, 0, 1, 11, 0, 2, 12);
        }
        if ($north->hasOpening[5]) {
            $this->waterBox($c, $r, $b, 7, 1, 11, 7, 2, 12);
        }
    }

    private function entry_room(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        $this->generateBox($c, $r, $b, 0, 3, 0, 2, 3, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 3, 0, 7, 3, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 1, 2, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 6, 2, 0, 7, 2, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 0, 1, 0, 0, 1, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 7, 1, 0, 7, 1, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 0, 1, 7, 7, 3, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 1, 0, 2, 3, 0, $light, $light, false);
        $this->generateBox($c, $r, $b, 5, 1, 0, 6, 3, 0, $light, $light, false);
        if ($this->room->hasOpening[2]) {
            $this->waterBox($c, $r, $b, 3, 1, 7, 4, 2, 7);
        }
        if ($this->room->hasOpening[4]) {
            $this->waterBox($c, $r, $b, 0, 1, 3, 1, 2, 4);
        }
        if ($this->room->hasOpening[5]) {
            $this->waterBox($c, $r, $b, 6, 1, 3, 7, 2, 4);
        }
    }

    private function penthouse(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        $this->generateBox($c, $r, $b, 2, -1, 2, 11, -1, 11, $light, $light, false);
        $this->generateBox($c, $r, $b, 0, -1, 0, 1, -1, 11, $gray, $gray, false);
        $this->generateBox($c, $r, $b, 12, -1, 0, 13, -1, 11, $gray, $gray, false);
        $this->generateBox($c, $r, $b, 2, -1, 0, 11, -1, 1, $gray, $gray, false);
        $this->generateBox($c, $r, $b, 2, -1, 12, 11, -1, 13, $gray, $gray, false);
        $this->generateBox($c, $r, $b, 0, 0, 0, 0, 0, 13, $light, $light, false);
        $this->generateBox($c, $r, $b, 13, 0, 0, 13, 0, 13, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 0, 0, 12, 0, 0, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 0, 13, 12, 0, 13, $light, $light, false);
        for ($i = 2; $i <= 11; $i += 3) {
            $this->placeBlock($c, $r, $lamp, 0, 0, $i, $b);
            $this->placeBlock($c, $r, $lamp, 13, 0, $i, $b);
            $this->placeBlock($c, $r, $lamp, $i, 0, 0, $b);
        }
        $this->generateBox($c, $r, $b, 2, 0, 3, 4, 0, 9, $light, $light, false);
        $this->generateBox($c, $r, $b, 9, 0, 3, 11, 0, 9, $light, $light, false);
        $this->generateBox($c, $r, $b, 4, 0, 9, 9, 0, 11, $light, $light, false);
        $this->placeBlock($c, $r, $light, 5, 0, 8, $b);
        $this->placeBlock($c, $r, $light, 8, 0, 8, $b);
        $this->placeBlock($c, $r, $light, 10, 0, 10, $b);
        $this->placeBlock($c, $r, $light, 3, 0, 10, $b);
        $this->generateBox($c, $r, $b, 3, 0, 3, 3, 0, 7, $black, $black, false);
        $this->generateBox($c, $r, $b, 10, 0, 3, 10, 0, 7, $black, $black, false);
        $this->generateBox($c, $r, $b, 6, 0, 10, 7, 0, 10, $black, $black, false);
        $x = 3;
        for ($i = 0; $i < 2; ++$i) {
            for ($z = 2; $z <= 8; $z += 3) {
                $this->generateBox($c, $r, $b, $x, 0, $z, $x, 2, $z, $light, $light, false);
            }
            $x = 10;
        }
        $this->generateBox($c, $r, $b, 5, 0, 10, 5, 2, 10, $light, $light, false);
        $this->generateBox($c, $r, $b, 8, 0, 10, 8, 2, 10, $light, $light, false);
        $this->generateBox($c, $r, $b, 6, -1, 7, 7, -1, 8, $black, $black, false);
        $this->waterBox($c, $r, $b, 6, -1, 3, 7, -1, 4);
        $this->spawnElder($c, $b, 6, 1, 6);
    }

    private function simple(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        if ($this->room->index >= 25) {
            $this->defaultFloor($c, $r, $b, 0, 0, $this->room->hasOpening[0]);
        }
        if ($this->room->connections[1] === null) {
            $this->boxOnFillOnly($c, $r, $b, 1, 4, 1, 6, 4, 6, $gray);
        }
        $centerPillar = $this->design !== 0
            && $random->nextBoolean()
            && !$this->room->hasOpening[0]
            && !$this->room->hasOpening[1]
            && $this->room->countOpenings() > 1;
        if ($this->design === 0) {
            $this->generateBox($c, $r, $b, 0, 1, 0, 2, 1, 2, $light, $light, false);
            $this->generateBox($c, $r, $b, 0, 3, 0, 2, 3, 2, $light, $light, false);
            $this->generateBox($c, $r, $b, 0, 2, 0, 0, 2, 2, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 1, 2, 0, 2, 2, 0, $gray, $gray, false);
            $this->placeBlock($c, $r, $lamp, 1, 2, 1, $b);
            $this->generateBox($c, $r, $b, 5, 1, 0, 7, 1, 2, $light, $light, false);
            $this->generateBox($c, $r, $b, 5, 3, 0, 7, 3, 2, $light, $light, false);
            $this->generateBox($c, $r, $b, 7, 2, 0, 7, 2, 2, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 5, 2, 0, 6, 2, 0, $gray, $gray, false);
            $this->placeBlock($c, $r, $lamp, 6, 2, 1, $b);
            $this->generateBox($c, $r, $b, 0, 1, 5, 2, 1, 7, $light, $light, false);
            $this->generateBox($c, $r, $b, 0, 3, 5, 2, 3, 7, $light, $light, false);
            $this->generateBox($c, $r, $b, 0, 2, 5, 0, 2, 7, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 1, 2, 7, 2, 2, 7, $gray, $gray, false);
            $this->placeBlock($c, $r, $lamp, 1, 2, 6, $b);
            $this->generateBox($c, $r, $b, 5, 1, 5, 7, 1, 7, $light, $light, false);
            $this->generateBox($c, $r, $b, 5, 3, 5, 7, 3, 7, $light, $light, false);
            $this->generateBox($c, $r, $b, 7, 2, 5, 7, 2, 7, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 5, 2, 7, 6, 2, 7, $gray, $gray, false);
            $this->placeBlock($c, $r, $lamp, 6, 2, 6, $b);
            if ($this->room->hasOpening[3]) {
                $this->generateBox($c, $r, $b, 3, 3, 0, 4, 3, 0, $light, $light, false);
            } else {
                $this->generateBox($c, $r, $b, 3, 3, 0, 4, 3, 1, $light, $light, false);
                $this->generateBox($c, $r, $b, 3, 2, 0, 4, 2, 0, $gray, $gray, false);
                $this->generateBox($c, $r, $b, 3, 1, 0, 4, 1, 1, $light, $light, false);
            }
            if ($this->room->hasOpening[2]) {
                $this->generateBox($c, $r, $b, 3, 3, 7, 4, 3, 7, $light, $light, false);
            } else {
                $this->generateBox($c, $r, $b, 3, 3, 6, 4, 3, 7, $light, $light, false);
                $this->generateBox($c, $r, $b, 3, 2, 7, 4, 2, 7, $gray, $gray, false);
                $this->generateBox($c, $r, $b, 3, 1, 6, 4, 1, 7, $light, $light, false);
            }
            if ($this->room->hasOpening[4]) {
                $this->generateBox($c, $r, $b, 0, 3, 3, 0, 3, 4, $light, $light, false);
            } else {
                $this->generateBox($c, $r, $b, 0, 3, 3, 1, 3, 4, $light, $light, false);
                $this->generateBox($c, $r, $b, 0, 2, 3, 0, 2, 4, $gray, $gray, false);
                $this->generateBox($c, $r, $b, 0, 1, 3, 1, 1, 4, $light, $light, false);
            }
            if ($this->room->hasOpening[5]) {
                $this->generateBox($c, $r, $b, 7, 3, 3, 7, 3, 4, $light, $light, false);
            } else {
                $this->generateBox($c, $r, $b, 6, 3, 3, 7, 3, 4, $light, $light, false);
                $this->generateBox($c, $r, $b, 7, 2, 3, 7, 2, 4, $gray, $gray, false);
                $this->generateBox($c, $r, $b, 6, 1, 3, 7, 1, 4, $light, $light, false);
            }
        } elseif ($this->design === 1) {
            $this->generateBox($c, $r, $b, 2, 1, 2, 2, 3, 2, $light, $light, false);
            $this->generateBox($c, $r, $b, 2, 1, 5, 2, 3, 5, $light, $light, false);
            $this->generateBox($c, $r, $b, 5, 1, 5, 5, 3, 5, $light, $light, false);
            $this->generateBox($c, $r, $b, 5, 1, 2, 5, 3, 2, $light, $light, false);
            $this->placeBlock($c, $r, $lamp, 2, 2, 2, $b);
            $this->placeBlock($c, $r, $lamp, 2, 2, 5, $b);
            $this->placeBlock($c, $r, $lamp, 5, 2, 5, $b);
            $this->placeBlock($c, $r, $lamp, 5, 2, 2, $b);
            $this->generateBox($c, $r, $b, 0, 1, 0, 1, 3, 0, $light, $light, false);
            $this->generateBox($c, $r, $b, 0, 1, 1, 0, 3, 1, $light, $light, false);
            $this->generateBox($c, $r, $b, 0, 1, 7, 1, 3, 7, $light, $light, false);
            $this->generateBox($c, $r, $b, 0, 1, 6, 0, 3, 6, $light, $light, false);
            $this->generateBox($c, $r, $b, 6, 1, 7, 7, 3, 7, $light, $light, false);
            $this->generateBox($c, $r, $b, 7, 1, 6, 7, 3, 6, $light, $light, false);
            $this->generateBox($c, $r, $b, 6, 1, 0, 7, 3, 0, $light, $light, false);
            $this->generateBox($c, $r, $b, 7, 1, 1, 7, 3, 1, $light, $light, false);
            $this->placeBlock($c, $r, $gray, 1, 2, 0, $b);
            $this->placeBlock($c, $r, $gray, 0, 2, 1, $b);
            $this->placeBlock($c, $r, $gray, 1, 2, 7, $b);
            $this->placeBlock($c, $r, $gray, 0, 2, 6, $b);
            $this->placeBlock($c, $r, $gray, 6, 2, 7, $b);
            $this->placeBlock($c, $r, $gray, 7, 2, 6, $b);
            $this->placeBlock($c, $r, $gray, 6, 2, 0, $b);
            $this->placeBlock($c, $r, $gray, 7, 2, 1, $b);
            if (!$this->room->hasOpening[3]) {
                $this->generateBox($c, $r, $b, 1, 3, 0, 6, 3, 0, $light, $light, false);
                $this->generateBox($c, $r, $b, 1, 2, 0, 6, 2, 0, $gray, $gray, false);
                $this->generateBox($c, $r, $b, 1, 1, 0, 6, 1, 0, $light, $light, false);
            }
            if (!$this->room->hasOpening[2]) {
                $this->generateBox($c, $r, $b, 1, 3, 7, 6, 3, 7, $light, $light, false);
                $this->generateBox($c, $r, $b, 1, 2, 7, 6, 2, 7, $gray, $gray, false);
                $this->generateBox($c, $r, $b, 1, 1, 7, 6, 1, 7, $light, $light, false);
            }
            if (!$this->room->hasOpening[4]) {
                $this->generateBox($c, $r, $b, 0, 3, 1, 0, 3, 6, $light, $light, false);
                $this->generateBox($c, $r, $b, 0, 2, 1, 0, 2, 6, $gray, $gray, false);
                $this->generateBox($c, $r, $b, 0, 1, 1, 0, 1, 6, $light, $light, false);
            }
            if (!$this->room->hasOpening[5]) {
                $this->generateBox($c, $r, $b, 7, 3, 1, 7, 3, 6, $light, $light, false);
                $this->generateBox($c, $r, $b, 7, 2, 1, 7, 2, 6, $gray, $gray, false);
                $this->generateBox($c, $r, $b, 7, 1, 1, 7, 1, 6, $light, $light, false);
            }
        } elseif ($this->design === 2) {
            $this->generateBox($c, $r, $b, 0, 1, 0, 0, 1, 7, $light, $light, false);
            $this->generateBox($c, $r, $b, 7, 1, 0, 7, 1, 7, $light, $light, false);
            $this->generateBox($c, $r, $b, 1, 1, 0, 6, 1, 0, $light, $light, false);
            $this->generateBox($c, $r, $b, 1, 1, 7, 6, 1, 7, $light, $light, false);
            $this->generateBox($c, $r, $b, 0, 2, 0, 0, 2, 7, $black, $black, false);
            $this->generateBox($c, $r, $b, 7, 2, 0, 7, 2, 7, $black, $black, false);
            $this->generateBox($c, $r, $b, 1, 2, 0, 6, 2, 0, $black, $black, false);
            $this->generateBox($c, $r, $b, 1, 2, 7, 6, 2, 7, $black, $black, false);
            $this->generateBox($c, $r, $b, 0, 3, 0, 0, 3, 7, $light, $light, false);
            $this->generateBox($c, $r, $b, 7, 3, 0, 7, 3, 7, $light, $light, false);
            $this->generateBox($c, $r, $b, 1, 3, 0, 6, 3, 0, $light, $light, false);
            $this->generateBox($c, $r, $b, 1, 3, 7, 6, 3, 7, $light, $light, false);
            $this->generateBox($c, $r, $b, 0, 1, 3, 0, 2, 4, $black, $black, false);
            $this->generateBox($c, $r, $b, 7, 1, 3, 7, 2, 4, $black, $black, false);
            $this->generateBox($c, $r, $b, 3, 1, 0, 4, 2, 0, $black, $black, false);
            $this->generateBox($c, $r, $b, 3, 1, 7, 4, 2, 7, $black, $black, false);
            if ($this->room->hasOpening[3]) {
                $this->waterBox($c, $r, $b, 3, 1, 0, 4, 2, 0);
            }
            if ($this->room->hasOpening[2]) {
                $this->waterBox($c, $r, $b, 3, 1, 7, 4, 2, 7);
            }
            if ($this->room->hasOpening[4]) {
                $this->waterBox($c, $r, $b, 0, 1, 3, 0, 2, 4);
            }
            if ($this->room->hasOpening[5]) {
                $this->waterBox($c, $r, $b, 7, 1, 3, 7, 2, 4);
            }
        }
        if ($centerPillar) {
            $this->generateBox($c, $r, $b, 3, 1, 3, 4, 1, 4, $light, $light, false);
            $this->generateBox($c, $r, $b, 3, 2, 3, 4, 2, 4, $gray, $gray, false);
            $this->generateBox($c, $r, $b, 3, 3, 3, 4, 3, 4, $light, $light, false);
        }
    }

    private function simple_top(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        if ($this->room->index >= 25) {
            $this->defaultFloor($c, $r, $b, 0, 0, $this->room->hasOpening[0]);
        }
        if ($this->room->connections[1] === null) {
            $this->boxOnFillOnly($c, $r, $b, 1, 4, 1, 6, 4, 6, $gray);
        }
        for ($x = 1; $x <= 6; ++$x) {
            for ($z = 1; $z <= 6; ++$z) {
                if ($random->nextInt(3) !== 0) {
                    $y0 = 2 + ($random->nextInt(4) === 0 ? 0 : 1);
                    $wetSponge = $c->state('wet_sponge');
                    $this->generateBox($c, $r, $b, $x, $y0, $z, $x, 3, $z, $wetSponge, $wetSponge, false);
                }
            }
        }
        $this->generateBox($c, $r, $b, 0, 1, 0, 0, 1, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 7, 1, 0, 7, 1, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 1, 0, 6, 1, 0, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 1, 7, 6, 1, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 0, 2, 7, $black, $black, false);
        $this->generateBox($c, $r, $b, 7, 2, 0, 7, 2, 7, $black, $black, false);
        $this->generateBox($c, $r, $b, 1, 2, 0, 6, 2, 0, $black, $black, false);
        $this->generateBox($c, $r, $b, 1, 2, 7, 6, 2, 7, $black, $black, false);
        $this->generateBox($c, $r, $b, 0, 3, 0, 0, 3, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 7, 3, 0, 7, 3, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 3, 0, 6, 3, 0, $light, $light, false);
        $this->generateBox($c, $r, $b, 1, 3, 7, 6, 3, 7, $light, $light, false);
        $this->generateBox($c, $r, $b, 0, 1, 3, 0, 2, 4, $black, $black, false);
        $this->generateBox($c, $r, $b, 7, 1, 3, 7, 2, 4, $black, $black, false);
        $this->generateBox($c, $r, $b, 3, 1, 0, 4, 2, 0, $black, $black, false);
        $this->generateBox($c, $r, $b, 3, 1, 7, 4, 2, 7, $black, $black, false);
        if ($this->room->hasOpening[3]) {
            $this->waterBox($c, $r, $b, 3, 1, 0, 4, 2, 0);
        }
    }

    private function wing(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        [$gray, $light, $black, $lamp] = self::palette($c);
        if ($this->design === 0) {
            for ($i = 0; $i < 4; ++$i) {
                $this->generateBox($c, $r, $b, 10 - $i, 3 - $i, 20 - $i, 12 + $i, 3 - $i, 20, $light, $light, false);
            }
            $this->generateBox($c, $r, $b, 7, 0, 6, 15, 0, 16, $light, $light, false);
            $this->generateBox($c, $r, $b, 6, 0, 6, 6, 3, 20, $light, $light, false);
            $this->generateBox($c, $r, $b, 16, 0, 6, 16, 3, 20, $light, $light, false);
            $this->generateBox($c, $r, $b, 7, 1, 7, 7, 1, 20, $light, $light, false);
            $this->generateBox($c, $r, $b, 15, 1, 7, 15, 1, 20, $light, $light, false);
            $this->generateBox($c, $r, $b, 7, 1, 6, 9, 3, 6, $light, $light, false);
            $this->generateBox($c, $r, $b, 13, 1, 6, 15, 3, 6, $light, $light, false);
            $this->generateBox($c, $r, $b, 8, 1, 7, 9, 1, 7, $light, $light, false);
            $this->generateBox($c, $r, $b, 13, 1, 7, 14, 1, 7, $light, $light, false);
            $this->generateBox($c, $r, $b, 9, 0, 5, 13, 0, 5, $light, $light, false);
            $this->generateBox($c, $r, $b, 10, 0, 7, 12, 0, 7, $black, $black, false);
            $this->generateBox($c, $r, $b, 8, 0, 10, 8, 0, 12, $black, $black, false);
            $this->generateBox($c, $r, $b, 14, 0, 10, 14, 0, 12, $black, $black, false);
            for ($z = 18; $z >= 7; $z -= 3) {
                $this->placeBlock($c, $r, $lamp, 6, 3, $z, $b);
                $this->placeBlock($c, $r, $lamp, 16, 3, $z, $b);
            }
            $this->placeBlock($c, $r, $lamp, 10, 0, 10, $b);
            $this->placeBlock($c, $r, $lamp, 12, 0, 10, $b);
            $this->placeBlock($c, $r, $lamp, 10, 0, 12, $b);
            $this->placeBlock($c, $r, $lamp, 12, 0, 12, $b);
            $this->placeBlock($c, $r, $lamp, 8, 3, 6, $b);
            $this->placeBlock($c, $r, $lamp, 14, 3, 6, $b);
            $this->placeBlock($c, $r, $light, 4, 2, 4, $b);
            $this->placeBlock($c, $r, $lamp, 4, 1, 4, $b);
            $this->placeBlock($c, $r, $light, 4, 0, 4, $b);
            $this->placeBlock($c, $r, $light, 18, 2, 4, $b);
            $this->placeBlock($c, $r, $lamp, 18, 1, 4, $b);
            $this->placeBlock($c, $r, $light, 18, 0, 4, $b);
            $this->placeBlock($c, $r, $light, 4, 2, 18, $b);
            $this->placeBlock($c, $r, $lamp, 4, 1, 18, $b);
            $this->placeBlock($c, $r, $light, 4, 0, 18, $b);
            $this->placeBlock($c, $r, $light, 18, 2, 18, $b);
            $this->placeBlock($c, $r, $lamp, 18, 1, 18, $b);
            $this->placeBlock($c, $r, $light, 18, 0, 18, $b);
            $this->placeBlock($c, $r, $light, 9, 7, 20, $b);
            $this->placeBlock($c, $r, $light, 13, 7, 20, $b);
            $this->generateBox($c, $r, $b, 6, 0, 21, 7, 4, 21, $light, $light, false);
            $this->generateBox($c, $r, $b, 15, 0, 21, 16, 4, 21, $light, $light, false);
            $this->spawnElder($c, $b, 11, 2, 16);
        } elseif ($this->design === 1) {
            $this->generateBox($c, $r, $b, 9, 3, 18, 13, 3, 20, $light, $light, false);
            $this->generateBox($c, $r, $b, 9, 0, 18, 9, 2, 18, $light, $light, false);
            $this->generateBox($c, $r, $b, 13, 0, 18, 13, 2, 18, $light, $light, false);
            $x = 9;
            $z = 20;
            $y = 5;
            for ($i = 0; $i < 2; ++$i) {
                $this->placeBlock($c, $r, $light, $x, 6, 20, $b);
                $this->placeBlock($c, $r, $lamp, $x, 5, 20, $b);
                $this->placeBlock($c, $r, $light, $x, 4, 20, $b);
                $x = 13;
            }
            $this->generateBox($c, $r, $b, 7, 3, 7, 15, 3, 14, $light, $light, false);
            $var14 = 10;
            for ($i = 0; $i < 2; ++$i) {
                $this->generateBox($c, $r, $b, $var14, 0, 10, $var14, 6, 10, $light, $light, false);
                $this->generateBox($c, $r, $b, $var14, 0, 12, $var14, 6, 12, $light, $light, false);
                $this->placeBlock($c, $r, $lamp, $var14, 0, 10, $b);
                $this->placeBlock($c, $r, $lamp, $var14, 0, 12, $b);
                $this->placeBlock($c, $r, $lamp, $var14, 4, 10, $b);
                $this->placeBlock($c, $r, $lamp, $var14, 4, 12, $b);
                $var14 = 12;
            }
            $var14 = 8;
            for ($i = 0; $i < 2; ++$i) {
                $this->generateBox($c, $r, $b, $var14, 0, 7, $var14, 2, 7, $light, $light, false);
                $this->generateBox($c, $r, $b, $var14, 0, 14, $var14, 2, 14, $light, $light, false);
                $var14 = 14;
            }
            $this->generateBox($c, $r, $b, 8, 3, 8, 8, 3, 13, $black, $black, false);
            $this->generateBox($c, $r, $b, 14, 3, 8, 14, 3, 13, $black, $black, false);
            $this->spawnElder($c, $b, 11, 5, 13);
        }
    }
}
