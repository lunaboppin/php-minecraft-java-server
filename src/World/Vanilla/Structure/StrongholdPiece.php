<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/**
 * StrongholdPieces: every stronghold piece kind (corridors, turns, stairs, crossings, rooms, the
 * prison hall, libraries and the portal room) and the generation that grows them from the start
 * stairs by piece weight until a portal room is placed.
 */
final class StrongholdPiece extends Piece
{
    /** STRONGHOLD_PIECE_WEIGHTS: kind, weight, maximum count (0 for any). */
    private const WEIGHTS = [['straight', 40, 0], ['prison_hall', 5, 5], ['left_turn', 20, 0], ['right_turn', 20, 0], ['room_crossing', 10, 6], ['straight_stairs_down', 5, 5], ['stairs_down', 5, 5], ['five_crossing', 5, 4], ['chest_corridor', 5, 4], ['library', 10, 2], ['portal_room', 20, 1]];

    /** BoundingBox.orientBox offsets and sizes of each kind. */
    private const BOXES = [
        'straight' => [-1, -1, 0, 5, 5, 7], 'prison_hall' => [-1, -1, 0, 9, 5, 11], 'left_turn' => [-1, -1, 0, 5, 5, 5], 'right_turn' => [-1, -1, 0, 5, 5, 5],
        'room_crossing' => [-4, -1, 0, 11, 7, 11], 'straight_stairs_down' => [-1, -7, 0, 5, 11, 8], 'stairs_down' => [-1, -7, 0, 5, 11, 5], 'five_crossing' => [-4, -3, 0, 10, 9, 11],
        'chest_corridor' => [-1, -1, 0, 5, 5, 7], 'portal_room' => [-4, -1, 0, 11, 8, 16],
    ];

    /** @var array<string, mixed> the kind's own state: entry door, child flags, room type, placed chest or spawner */
    public array $f = [];

    public function __construct(array $box, ?string $orientation, int $genDepth, public readonly string $kind)
    {
        parent::__construct($box, $orientation, $genDepth);
    }

    public static function restoreWith(array $piece): self
    {
        $restored = new self($piece['box'], $piece['orientation'], $piece['depth'], $piece['kind']);
        $restored->f = $piece['f'];
        return $restored;
    }

    public function type(): string
    {
        return 'stronghold';
    }

    public function data(): array
    {
        return ['depth' => $this->genDepth, 'kind' => $this->kind, 'f' => $this->f];
    }

    public function move(int $dy): void
    {
        $this->box[1] += $dy;
        $this->box[4] += $dy;
    }

    /** BoundingBox.orientBox */
    private static function orientBox(int $x, int $y, int $z, int $offX, int $offY, int $offZ, int $width, int $height, int $depth, string $direction): array
    {
        return match ($direction) {
            'north' => [$x + $offX, $y + $offY, $z - $depth + 1 + $offZ, $x + $width - 1 + $offX, $y + $height - 1 + $offY, $z + $offZ],
            'west' => [$x - $depth + 1 + $offZ, $y + $offY, $z + $offX, $x + $offZ, $y + $height - 1 + $offY, $z + $width - 1 + $offX],
            'east' => [$x + $offZ, $y + $offY, $z + $offX, $x + $depth - 1 + $offZ, $y + $height - 1 + $offY, $z + $width - 1 + $offX],
            default => [$x + $offX, $y + $offY, $z + $offZ, $x + $width - 1 + $offX, $y + $height - 1 + $offY, $z + $depth - 1 + $offZ],
        };
    }

    private static function collision(array $pieces, array $box): ?self
    {
        foreach ($pieces as $piece) {
            if (self::intersects($piece->box, $box)) {
                return $piece;
            }
        }
        return null;
    }

    /** StrongholdPiece.randomSmallDoor */
    private static function randomDoor(RandomSource $random): string
    {
        return match ($random->nextInt(5)) {
            2 => 'wood_door',
            3 => 'grates',
            4 => 'iron_door',
            default => 'opening',
        };
    }

    /**
     * StrongholdStructure.generatePieces: retried with the next seed until there is a portal room.
     *
     * @return list<self>
     */
    public static function generate(int $seed, int $cx, int $cz, int $seaLevel, int $minY): array
    {
        for ($tries = 0; ; ++$tries) {
            $random = Structures::largeFeatureRandom($seed + $tries, $cx, $cz);
            $state = ['weights' => array_map(static fn (array $w): array => [...$w, 0], self::WEIGHTS), 'imposed' => null, 'previous' => null, 'pending' => [], 'portal' => false];
            $direction = self::HORIZONTAL[$random->nextInt(4)];
            $start = new self(self::makeBox($cx * 16 + 2, 64, $cz * 16 + 2, $direction, 5, 11, 5), $direction, 0, 'stairs_down');
            $start->f = ['door' => 'opening', 'source' => true];
            $pieces = [$start];
            $start->addChildren($start, $pieces, $random, $state);
            while ($state['pending'] !== []) {
                $index = $random->nextInt(count($state['pending']));
                $piece = array_splice($state['pending'], $index, 1)[0];
                $piece->addChildren($start, $pieces, $random, $state);
            }
            // StructurePiecesBuilder.moveBelowSeaLevel
            $box = $pieces[0]->box;
            foreach ($pieces as $piece) {
                $box = [min($box[0], $piece->box[0]), min($box[1], $piece->box[1]), min($box[2], $piece->box[2]), max($box[3], $piece->box[3]), max($box[4], $piece->box[4]), max($box[5], $piece->box[5])];
            }
            $maxY = $seaLevel - 10;
            $y = $box[4] - $box[1] + 1 + $minY + 1;
            if ($y < $maxY) {
                $y += $random->nextInt($maxY - $y);
            }
            foreach ($pieces as $piece) {
                $piece->move($y - $box[4]);
            }
            if ($state['portal']) {
                return $pieces;
            }
        }
    }

    /** StrongholdPieces.generatePieceFromSmallDoor */
    private static function pieceFromSmallDoor(array &$pieces, RandomSource $random, int $x, int $y, int $z, string $direction, int $depth, array &$state): ?self
    {
        $any = false;
        $total = 0;
        foreach ($state['weights'] as [$kind, $weight, $max, $count]) {
            $any = $any || $max > 0 && $count < $max;
            $total += $weight;
        }
        if (!$any) {
            return null;
        }
        if ($state['imposed'] !== null) {
            $piece = self::create($state['imposed'], $pieces, $random, $x, $y, $z, $direction, $depth);
            $state['imposed'] = null;
            if ($piece !== null) {
                return $piece;
            }
        }
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $selection = $random->nextInt($total);
            foreach ($state['weights'] as $index => [$kind, $weight, $max, $count]) {
                $selection -= $weight;
                if ($selection >= 0) {
                    continue;
                }
                $place = ($max === 0 || $count < $max) && ($kind !== 'library' || $depth > 4) && ($kind !== 'portal_room' || $depth > 5);
                if (!$place || $kind === $state['previous']) {
                    break;
                }
                $piece = self::create($kind, $pieces, $random, $x, $y, $z, $direction, $depth);
                if ($piece !== null) {
                    ++$state['weights'][$index][3];
                    $state['previous'] = $kind;
                    if ($max !== 0 && $state['weights'][$index][3] >= $max) {
                        array_splice($state['weights'], $index, 1);
                    }
                    return $piece;
                }
            }
        }
        // FillerCorridor.findPieceBox
        $box = self::orientBox($x, $y, $z, -1, -1, 0, 5, 5, 4, $direction);
        $collision = self::collision($pieces, $box);
        if ($collision === null || $collision->box[1] !== $box[1]) {
            return null;
        }
        for ($length = 2; $length >= 1; --$length) {
            if (!self::intersects($collision->box, self::orientBox($x, $y, $z, -1, -1, 0, 5, 5, $length, $direction))) {
                $box = self::orientBox($x, $y, $z, -1, -1, 0, 5, 5, $length + 1, $direction);
                if ($box[1] <= 1) {
                    return null;
                }
                $filler = new self($box, $direction, $depth, 'filler_corridor');
                $filler->f = ['steps' => $direction === 'north' || $direction === 'south' ? $box[5] - $box[2] + 1 : $box[3] - $box[0] + 1];
                return $filler;
            }
        }
        return null;
    }

    /** The kind's createPiece: its box if free and above y 10, and its random state. */
    private static function create(string $kind, array $pieces, RandomSource $random, int $x, int $y, int $z, string $direction, int $depth): ?self
    {
        if ($kind === 'library') {
            $box = self::orientBox($x, $y, $z, -4, -1, 0, 14, 11, 15, $direction);
            if ($box[1] <= 10 || self::collision($pieces, $box) !== null) {
                $box = self::orientBox($x, $y, $z, -4, -1, 0, 14, 6, 15, $direction);
                if ($box[1] <= 10 || self::collision($pieces, $box) !== null) {
                    return null;
                }
            }
        } else {
            $box = self::orientBox($x, $y, $z, ...[...self::BOXES[$kind], $direction]);
            if ($box[1] <= 10 || self::collision($pieces, $box) !== null) {
                return null;
            }
        }
        $piece = new self($box, $direction, $depth, $kind);
        if ($kind !== 'portal_room') {
            $piece->f['door'] = self::randomDoor($random);
        }
        match ($kind) {
            'five_crossing' => $piece->f += ['left_low' => $random->nextBoolean(), 'left_high' => $random->nextBoolean(), 'right_low' => $random->nextBoolean(), 'right_high' => $random->nextInt(3) > 0],
            'room_crossing' => $piece->f['room'] = $random->nextInt(5),
            'straight' => $piece->f += ['left' => $random->nextInt(2) === 0, 'right' => $random->nextInt(2) === 0],
            'library' => $piece->f['tall'] = $box[4] - $box[1] + 1 > 6,
            'stairs_down' => $piece->f['source'] = false,
            default => null,
        };
        return $piece;
    }

    /** StrongholdPieces.generateAndAddPiece */
    private static function addPiece(self $start, array &$pieces, RandomSource $random, int $x, int $y, int $z, string $direction, int $depth, array &$state): ?self
    {
        if ($depth > 50 || abs($x - $start->box[0]) > 112 || abs($z - $start->box[2]) > 112) {
            return null;
        }
        $piece = self::pieceFromSmallDoor($pieces, $random, $x, $y, $z, $direction, $depth + 1, $state);
        if ($piece !== null) {
            $pieces[] = $piece;
            $state['pending'][] = $piece;
        }
        return $piece;
    }

    private function childForward(self $start, array &$pieces, RandomSource $random, int $xOff, int $yOff, array &$state): void
    {
        [$minX, $minY, $minZ, $maxX, , $maxZ] = $this->box;
        match ($this->orientation) {
            'north' => self::addPiece($start, $pieces, $random, $minX + $xOff, $minY + $yOff, $minZ - 1, 'north', $this->genDepth, $state),
            'south' => self::addPiece($start, $pieces, $random, $minX + $xOff, $minY + $yOff, $maxZ + 1, 'south', $this->genDepth, $state),
            'west' => self::addPiece($start, $pieces, $random, $minX - 1, $minY + $yOff, $minZ + $xOff, 'west', $this->genDepth, $state),
            'east' => self::addPiece($start, $pieces, $random, $maxX + 1, $minY + $yOff, $minZ + $xOff, 'east', $this->genDepth, $state),
        };
    }

    private function childLeft(self $start, array &$pieces, RandomSource $random, int $yOff, int $zOff, array &$state): void
    {
        [$minX, $minY, $minZ] = $this->box;
        $this->orientation === 'north' || $this->orientation === 'south'
            ? self::addPiece($start, $pieces, $random, $minX - 1, $minY + $yOff, $minZ + $zOff, 'west', $this->genDepth, $state)
            : self::addPiece($start, $pieces, $random, $minX + $zOff, $minY + $yOff, $minZ - 1, 'north', $this->genDepth, $state);
    }

    private function childRight(self $start, array &$pieces, RandomSource $random, int $yOff, int $zOff, array &$state): void
    {
        [$minX, $minY, $minZ, $maxX, , $maxZ] = $this->box;
        $this->orientation === 'north' || $this->orientation === 'south'
            ? self::addPiece($start, $pieces, $random, $maxX + 1, $minY + $yOff, $minZ + $zOff, 'east', $this->genDepth, $state)
            : self::addPiece($start, $pieces, $random, $minX + $zOff, $minY + $yOff, $maxZ + 1, 'south', $this->genDepth, $state);
    }

    /** StructurePiece.addChildren of each kind. */
    public function addChildren(self $start, array &$pieces, RandomSource $random, array &$state): void
    {
        $o = $this->orientation;
        switch ($this->kind) {
            case 'stairs_down':
                if ($this->f['source']) {
                    $state['imposed'] = 'five_crossing';
                }
                $this->childForward($start, $pieces, $random, 1, 1, $state);
                return;
            case 'straight':
                $this->childForward($start, $pieces, $random, 1, 1, $state);
                if ($this->f['left']) {
                    $this->childLeft($start, $pieces, $random, 1, 2, $state);
                }
                if ($this->f['right']) {
                    $this->childRight($start, $pieces, $random, 1, 2, $state);
                }
                return;
            case 'chest_corridor':
            case 'prison_hall':
            case 'straight_stairs_down':
                $this->childForward($start, $pieces, $random, 1, 1, $state);
                return;
            case 'left_turn':
                $o !== 'north' && $o !== 'east' ? $this->childRight($start, $pieces, $random, 1, 1, $state) : $this->childLeft($start, $pieces, $random, 1, 1, $state);
                return;
            case 'right_turn':
                $o !== 'north' && $o !== 'east' ? $this->childLeft($start, $pieces, $random, 1, 1, $state) : $this->childRight($start, $pieces, $random, 1, 1, $state);
                return;
            case 'room_crossing':
                $this->childForward($start, $pieces, $random, 4, 1, $state);
                $this->childLeft($start, $pieces, $random, 1, 4, $state);
                $this->childRight($start, $pieces, $random, 1, 4, $state);
                return;
            case 'five_crossing':
                [$a, $b] = $o === 'west' || $o === 'north' ? [5, 3] : [3, 5];
                $this->childForward($start, $pieces, $random, 5, 1, $state);
                if ($this->f['left_low']) {
                    $this->childLeft($start, $pieces, $random, $a, 1, $state);
                }
                if ($this->f['left_high']) {
                    $this->childLeft($start, $pieces, $random, $b, 7, $state);
                }
                if ($this->f['right_low']) {
                    $this->childRight($start, $pieces, $random, $a, 1, $state);
                }
                if ($this->f['right_high']) {
                    $this->childRight($start, $pieces, $random, $b, 7, $state);
                }
                return;
            case 'portal_room':
                $state['portal'] = true;
                return;
        }
    }

    /** StrongholdPieces.SmoothStoneSelector */
    private static function stone(StructureContext $c): \Closure
    {
        return static function (RandomSource $random, bool $edge) use ($c): int {
            if (!$edge) {
                return $c->state('cave_air');
            }
            $selection = $random->nextFloat();
            return $c->state($selection < 0.2 ? 'cracked_stone_bricks' : ($selection < 0.5 ? 'mossy_stone_bricks' : ($selection < 0.55 ? 'infested_stone_bricks' : 'stone_bricks')));
        };
    }

    /** StrongholdPiece.generateSmallDoor */
    private function door(StructureContext $c, Region $r, array $b, string $type, int $x, int $y, int $z): void
    {
        $air = $c->state('cave_air');
        $bricks = $c->state('stone_bricks');
        if ($type === 'opening') {
            $this->generateBox($c, $r, $b, $x, $y, $z, $x + 2, $y + 2, $z, $air, $air, false);
            return;
        }
        if ($type === 'grates') {
            $this->placeBlock($c, $r, $air, $x + 1, $y, $z, $b);
            $this->placeBlock($c, $r, $air, $x + 1, $y + 1, $z, $b);
            $west = $c->state('iron_bars', ['west' => 'true']);
            $both = $c->state('iron_bars', ['east' => 'true', 'west' => 'true']);
            $east = $c->state('iron_bars', ['east' => 'true']);
            $this->placeBlock($c, $r, $west, $x, $y, $z, $b);
            $this->placeBlock($c, $r, $west, $x, $y + 1, $z, $b);
            $this->placeBlock($c, $r, $both, $x, $y + 2, $z, $b);
            $this->placeBlock($c, $r, $both, $x + 1, $y + 2, $z, $b);
            $this->placeBlock($c, $r, $both, $x + 2, $y + 2, $z, $b);
            $this->placeBlock($c, $r, $east, $x + 2, $y + 1, $z, $b);
            $this->placeBlock($c, $r, $east, $x + 2, $y, $z, $b);
            return;
        }
        foreach ([[0, 0], [0, 1], [0, 2], [1, 2], [2, 2], [2, 1], [2, 0]] as [$dx, $dy]) {
            $this->placeBlock($c, $r, $bricks, $x + $dx, $y + $dy, $z, $b);
        }
        $doorBlock = $type === 'wood_door' ? 'oak_door' : 'iron_door';
        $this->placeBlock($c, $r, $c->state($doorBlock), $x + 1, $y, $z, $b);
        $this->placeBlock($c, $r, $c->state($doorBlock, ['half' => 'upper']), $x + 1, $y + 1, $z, $b);
        if ($type === 'iron_door') {
            $this->placeBlock($c, $r, $c->state('stone_button', ['facing' => 'north']), $x + 2, $y + 1, $z + 1, $b);
            $this->placeBlock($c, $r, $c->state('stone_button', ['facing' => 'south']), $x + 2, $y + 1, $z - 1, $b);
        }
    }

    private function maybeBlock(StructureContext $c, Region $r, array $b, RandomSource $random, float $probability, int $x, int $y, int $z, int $state): void
    {
        if ($random->nextFloat() < $probability) {
            $this->placeBlock($c, $r, $state, $x, $y, $z, $b);
        }
    }

    public function postProcess(StructureContext $c, Region $r, RandomSource $random, array $b): void
    {
        $stone = self::stone($c);
        $air = $c->state('cave_air');
        $bricks = $c->state('stone_bricks');
        $slab = $c->state('smooth_stone_slab');
        $torch = static fn (string $facing): int => $c->state('wall_torch', ['facing' => $facing]);
        switch ($this->kind) {
            case 'straight':
                $this->generateSelectedBox($c, $r, $b, 0, 0, 0, 4, 4, 6, true, $random, $stone);
                $this->door($c, $r, $b, $this->f['door'], 1, 1, 0);
                $this->door($c, $r, $b, 'opening', 1, 1, 6);
                $chance = F32::r(0.1);
                $this->maybeBlock($c, $r, $b, $random, $chance, 1, 2, 1, $torch('east'));
                $this->maybeBlock($c, $r, $b, $random, $chance, 3, 2, 1, $torch('west'));
                $this->maybeBlock($c, $r, $b, $random, $chance, 1, 2, 5, $torch('east'));
                $this->maybeBlock($c, $r, $b, $random, $chance, 3, 2, 5, $torch('west'));
                if ($this->f['left']) {
                    $this->generateBox($c, $r, $b, 0, 1, 2, 0, 3, 4, $air, $air, false);
                }
                if ($this->f['right']) {
                    $this->generateBox($c, $r, $b, 4, 1, 2, 4, 3, 4, $air, $air, false);
                }
                return;
            case 'filler_corridor':
                for ($i = 0; $i < $this->f['steps']; ++$i) {
                    for ($x = 0; $x <= 4; ++$x) {
                        $this->placeBlock($c, $r, $bricks, $x, 0, $i, $b);
                    }
                    for ($y = 1; $y <= 3; ++$y) {
                        $this->placeBlock($c, $r, $bricks, 0, $y, $i, $b);
                        $this->placeBlock($c, $r, $air, 1, $y, $i, $b);
                        $this->placeBlock($c, $r, $air, 2, $y, $i, $b);
                        $this->placeBlock($c, $r, $air, 3, $y, $i, $b);
                        $this->placeBlock($c, $r, $bricks, 4, $y, $i, $b);
                    }
                    for ($x = 0; $x <= 4; ++$x) {
                        $this->placeBlock($c, $r, $bricks, $x, 4, $i, $b);
                    }
                }
                return;
            case 'chest_corridor':
                $this->generateSelectedBox($c, $r, $b, 0, 0, 0, 4, 4, 6, true, $random, $stone);
                $this->door($c, $r, $b, $this->f['door'], 1, 1, 0);
                $this->door($c, $r, $b, 'opening', 1, 1, 6);
                $this->generateBox($c, $r, $b, 3, 1, 2, 3, 1, 4, $bricks, $bricks, false);
                $brickSlab = $c->state('stone_brick_slab');
                foreach ([[3, 1, 1], [3, 1, 5], [3, 2, 2], [3, 2, 4], [2, 1, 2], [2, 1, 3], [2, 1, 4]] as [$x, $y, $z]) {
                    $this->placeBlock($c, $r, $brickSlab, $x, $y, $z, $b);
                }
                if (!($this->f['chest'] ?? false) && self::inside($b, ...$this->worldPos(3, 2, 3))) {
                    $this->f['chest'] = true;
                    $this->createChest($c, $r, $b, $random, 3, 2, 3, 'stronghold_corridor');
                }
                return;
            case 'left_turn':
            case 'right_turn':
                $this->generateSelectedBox($c, $r, $b, 0, 0, 0, 4, 4, 4, true, $random, $stone);
                $this->door($c, $r, $b, $this->f['door'], 1, 1, 0);
                $flip = $this->orientation !== 'north' && $this->orientation !== 'east';
                $side = ($this->kind === 'left_turn') === $flip ? 4 : 0;
                $this->generateBox($c, $r, $b, $side, 1, 1, $side, 3, 3, $air, $air, false);
                return;
            case 'stairs_down':
                $this->generateSelectedBox($c, $r, $b, 0, 0, 0, 4, 10, 4, true, $random, $stone);
                $this->door($c, $r, $b, $this->f['door'], 1, 7, 0);
                $this->door($c, $r, $b, 'opening', 1, 1, 4);
                foreach ([[$bricks, 2, 6, 1], [$bricks, 1, 5, 1], [$slab, 1, 6, 1], [$bricks, 1, 5, 2], [$bricks, 1, 4, 3], [$slab, 1, 5, 3], [$bricks, 2, 4, 3], [$bricks, 3, 3, 3], [$slab, 3, 4, 3], [$bricks, 3, 3, 2], [$bricks, 3, 2, 1], [$slab, 3, 3, 1], [$bricks, 2, 2, 1], [$bricks, 1, 1, 1], [$slab, 1, 2, 1], [$bricks, 1, 1, 2], [$slab, 1, 1, 3]] as [$state, $x, $y, $z]) {
                    $this->placeBlock($c, $r, $state, $x, $y, $z, $b);
                }
                return;
            case 'straight_stairs_down':
                $this->generateSelectedBox($c, $r, $b, 0, 0, 0, 4, 10, 7, true, $random, $stone);
                $this->door($c, $r, $b, $this->f['door'], 1, 7, 0);
                $this->door($c, $r, $b, 'opening', 1, 1, 7);
                $stairs = $c->state('cobblestone_stairs', ['facing' => 'south']);
                for ($i = 0; $i < 6; ++$i) {
                    for ($x = 1; $x <= 3; ++$x) {
                        $this->placeBlock($c, $r, $stairs, $x, 6 - $i, 1 + $i, $b);
                    }
                    if ($i < 5) {
                        for ($x = 1; $x <= 3; ++$x) {
                            $this->placeBlock($c, $r, $bricks, $x, 5 - $i, 1 + $i, $b);
                        }
                    }
                }
                return;
            case 'five_crossing':
                $this->fiveCrossing($c, $r, $b, $random, $stone, $air, $slab, $torch);
                return;
            case 'room_crossing':
                $this->roomCrossing($c, $r, $b, $random, $stone, $air, $bricks, $slab, $torch);
                return;
            case 'prison_hall':
                $this->prisonHall($c, $r, $b, $random, $stone, $air);
                return;
            case 'library':
                $this->library($c, $r, $b, $random, $stone, $air);
                return;
            case 'portal_room':
                $this->portalRoom($c, $r, $b, $random, $stone);
                return;
        }
    }

    private function fiveCrossing(StructureContext $c, Region $r, array $b, RandomSource $random, \Closure $stone, int $air, int $slab, \Closure $torch): void
    {
        $this->generateSelectedBox($c, $r, $b, 0, 0, 0, 9, 8, 10, true, $random, $stone);
        $this->door($c, $r, $b, $this->f['door'], 4, 3, 0);
        if ($this->f['left_low']) {
            $this->generateBox($c, $r, $b, 0, 3, 1, 0, 5, 3, $air, $air, false);
        }
        if ($this->f['right_low']) {
            $this->generateBox($c, $r, $b, 9, 3, 1, 9, 5, 3, $air, $air, false);
        }
        if ($this->f['left_high']) {
            $this->generateBox($c, $r, $b, 0, 5, 7, 0, 7, 9, $air, $air, false);
        }
        if ($this->f['right_high']) {
            $this->generateBox($c, $r, $b, 9, 5, 7, 9, 7, 9, $air, $air, false);
        }
        $this->generateBox($c, $r, $b, 5, 1, 10, 7, 3, 10, $air, $air, false);
        foreach ([[1, 2, 1, 8, 2, 6], [4, 1, 5, 4, 4, 9], [8, 1, 5, 8, 4, 9], [1, 4, 7, 3, 4, 9], [1, 3, 5, 3, 3, 6]] as [$x0, $y0, $z0, $x1, $y1, $z1]) {
            $this->generateSelectedBox($c, $r, $b, $x0, $y0, $z0, $x1, $y1, $z1, false, $random, $stone);
        }
        $this->generateBox($c, $r, $b, 1, 3, 4, 3, 3, 4, $slab, $slab, false);
        $this->generateBox($c, $r, $b, 1, 4, 6, 3, 4, 6, $slab, $slab, false);
        $this->generateSelectedBox($c, $r, $b, 5, 1, 7, 7, 1, 8, false, $random, $stone);
        $this->generateBox($c, $r, $b, 5, 1, 9, 7, 1, 9, $slab, $slab, false);
        $this->generateBox($c, $r, $b, 5, 2, 7, 7, 2, 7, $slab, $slab, false);
        $this->generateBox($c, $r, $b, 4, 5, 7, 4, 5, 9, $slab, $slab, false);
        $this->generateBox($c, $r, $b, 8, 5, 7, 8, 5, 9, $slab, $slab, false);
        $double = $c->state('smooth_stone_slab', ['type' => 'double']);
        $this->generateBox($c, $r, $b, 5, 5, 7, 7, 5, 9, $double, $double, false);
        $this->placeBlock($c, $r, $torch('south'), 6, 5, 6, $b);
    }

    private function roomCrossing(StructureContext $c, Region $r, array $b, RandomSource $random, \Closure $stone, int $air, int $bricks, int $slab, \Closure $torch): void
    {
        $this->generateSelectedBox($c, $r, $b, 0, 0, 0, 10, 6, 10, true, $random, $stone);
        $this->door($c, $r, $b, $this->f['door'], 4, 1, 0);
        $this->generateBox($c, $r, $b, 4, 1, 10, 6, 3, 10, $air, $air, false);
        $this->generateBox($c, $r, $b, 0, 1, 4, 0, 3, 6, $air, $air, false);
        $this->generateBox($c, $r, $b, 10, 1, 4, 10, 3, 6, $air, $air, false);
        switch ($this->f['room']) {
            case 0:
                foreach ([[$bricks, 5, 1, 5], [$bricks, 5, 2, 5], [$bricks, 5, 3, 5], [$torch('west'), 4, 3, 5], [$torch('east'), 6, 3, 5], [$torch('south'), 5, 3, 4], [$torch('north'), 5, 3, 6],
                    [$slab, 4, 1, 4], [$slab, 4, 1, 5], [$slab, 4, 1, 6], [$slab, 6, 1, 4], [$slab, 6, 1, 5], [$slab, 6, 1, 6], [$slab, 5, 1, 4], [$slab, 5, 1, 6]] as [$state, $x, $y, $z]) {
                    $this->placeBlock($c, $r, $state, $x, $y, $z, $b);
                }
                return;
            case 1:
                for ($i = 0; $i < 5; ++$i) {
                    $this->placeBlock($c, $r, $bricks, 3, 1, 3 + $i, $b);
                    $this->placeBlock($c, $r, $bricks, 7, 1, 3 + $i, $b);
                    $this->placeBlock($c, $r, $bricks, 3 + $i, 1, 3, $b);
                    $this->placeBlock($c, $r, $bricks, 3 + $i, 1, 7, $b);
                }
                $this->placeBlock($c, $r, $bricks, 5, 1, 5, $b);
                $this->placeBlock($c, $r, $bricks, 5, 2, 5, $b);
                $this->placeBlock($c, $r, $bricks, 5, 3, 5, $b);
                $this->placeBlock($c, $r, $c->state('water'), 5, 4, 5, $b);
                return;
            case 2:
                $cobble = $c->state('cobblestone');
                for ($z = 1; $z <= 9; ++$z) {
                    $this->placeBlock($c, $r, $cobble, 1, 3, $z, $b);
                    $this->placeBlock($c, $r, $cobble, 9, 3, $z, $b);
                }
                for ($x = 1; $x <= 9; ++$x) {
                    $this->placeBlock($c, $r, $cobble, $x, 3, 1, $b);
                    $this->placeBlock($c, $r, $cobble, $x, 3, 9, $b);
                }
                foreach ([[5, 1, 4], [5, 1, 6], [5, 3, 4], [5, 3, 6], [4, 1, 5], [6, 1, 5], [4, 3, 5], [6, 3, 5]] as [$x, $y, $z]) {
                    $this->placeBlock($c, $r, $cobble, $x, $y, $z, $b);
                }
                for ($y = 1; $y <= 3; ++$y) {
                    $this->placeBlock($c, $r, $cobble, 4, $y, 4, $b);
                    $this->placeBlock($c, $r, $cobble, 6, $y, 4, $b);
                    $this->placeBlock($c, $r, $cobble, 4, $y, 6, $b);
                    $this->placeBlock($c, $r, $cobble, 6, $y, 6, $b);
                }
                $this->placeBlock($c, $r, $c->state('wall_torch'), 5, 3, 5, $b);
                $planks = $c->state('oak_planks');
                for ($z = 2; $z <= 8; ++$z) {
                    $this->placeBlock($c, $r, $planks, 2, 3, $z, $b);
                    $this->placeBlock($c, $r, $planks, 3, 3, $z, $b);
                    if ($z <= 3 || $z >= 7) {
                        $this->placeBlock($c, $r, $planks, 4, 3, $z, $b);
                        $this->placeBlock($c, $r, $planks, 5, 3, $z, $b);
                        $this->placeBlock($c, $r, $planks, 6, 3, $z, $b);
                    }
                    $this->placeBlock($c, $r, $planks, 7, 3, $z, $b);
                    $this->placeBlock($c, $r, $planks, 8, 3, $z, $b);
                }
                $ladder = $c->state('ladder', ['facing' => 'west']);
                $this->placeBlock($c, $r, $ladder, 9, 1, 3, $b);
                $this->placeBlock($c, $r, $ladder, 9, 2, 3, $b);
                $this->placeBlock($c, $r, $ladder, 9, 3, 3, $b);
                $this->createChest($c, $r, $b, $random, 3, 4, 8, 'stronghold_crossing');
                return;
        }
    }

    private function prisonHall(StructureContext $c, Region $r, array $b, RandomSource $random, \Closure $stone, int $air): void
    {
        $this->generateSelectedBox($c, $r, $b, 0, 0, 0, 8, 4, 10, true, $random, $stone);
        $this->door($c, $r, $b, $this->f['door'], 1, 1, 0);
        $this->generateBox($c, $r, $b, 1, 1, 10, 3, 3, 10, $air, $air, false);
        foreach ([1, 3, 7, 9] as $z) {
            $this->generateSelectedBox($c, $r, $b, 4, 1, $z, 4, 3, $z, false, $random, $stone);
        }
        $ns = $c->state('iron_bars', ['north' => 'true', 'south' => 'true']);
        $nse = $c->state('iron_bars', ['north' => 'true', 'south' => 'true', 'east' => 'true']);
        $we = $c->state('iron_bars', ['west' => 'true', 'east' => 'true']);
        for ($y = 1; $y <= 3; ++$y) {
            $this->placeBlock($c, $r, $ns, 4, $y, 4, $b);
            $this->placeBlock($c, $r, $nse, 4, $y, 5, $b);
            $this->placeBlock($c, $r, $ns, 4, $y, 6, $b);
            $this->placeBlock($c, $r, $we, 5, $y, 5, $b);
            $this->placeBlock($c, $r, $we, 6, $y, 5, $b);
            $this->placeBlock($c, $r, $we, 7, $y, 5, $b);
        }
        $this->placeBlock($c, $r, $ns, 4, 3, 2, $b);
        $this->placeBlock($c, $r, $ns, 4, 3, 8, $b);
        $bottom = $c->state('iron_door', ['facing' => 'west']);
        $top = $c->state('iron_door', ['facing' => 'west', 'half' => 'upper']);
        $this->placeBlock($c, $r, $bottom, 4, 1, 2, $b);
        $this->placeBlock($c, $r, $top, 4, 2, 2, $b);
        $this->placeBlock($c, $r, $bottom, 4, 1, 8, $b);
        $this->placeBlock($c, $r, $top, 4, 2, 8, $b);
    }

    private function library(StructureContext $c, Region $r, array $b, RandomSource $random, \Closure $stone, int $air): void
    {
        $tall = $this->f['tall'];
        $this->generateSelectedBox($c, $r, $b, 0, 0, 0, 13, ($tall ? 11 : 6) - 1, 14, true, $random, $stone);
        $this->door($c, $r, $b, $this->f['door'], 4, 1, 0);
        // StructurePiece.generateMaybeBox with cobwebs.
        $web = $c->state('cobweb');
        $chance = F32::r(0.07);
        for ($y = 1; $y <= 4; ++$y) {
            for ($x = 2; $x <= 11; ++$x) {
                for ($z = 1; $z <= 13; ++$z) {
                    if (!($random->nextFloat() > $chance)) {
                        $this->placeBlock($c, $r, $web, $x, $y, $z, $b);
                    }
                }
            }
        }
        $planks = $c->state('oak_planks');
        $shelf = $c->state('bookshelf');
        for ($d = 1; $d <= 13; ++$d) {
            if (($d - 1) % 4 === 0) {
                $this->generateBox($c, $r, $b, 1, 1, $d, 1, 4, $d, $planks, $planks, false);
                $this->generateBox($c, $r, $b, 12, 1, $d, 12, 4, $d, $planks, $planks, false);
                $this->placeBlock($c, $r, $c->state('wall_torch', ['facing' => 'east']), 2, 3, $d, $b);
                $this->placeBlock($c, $r, $c->state('wall_torch', ['facing' => 'west']), 11, 3, $d, $b);
                if ($tall) {
                    $this->generateBox($c, $r, $b, 1, 6, $d, 1, 9, $d, $planks, $planks, false);
                    $this->generateBox($c, $r, $b, 12, 6, $d, 12, 9, $d, $planks, $planks, false);
                }
            } else {
                $this->generateBox($c, $r, $b, 1, 1, $d, 1, 4, $d, $shelf, $shelf, false);
                $this->generateBox($c, $r, $b, 12, 1, $d, 12, 4, $d, $shelf, $shelf, false);
                if ($tall) {
                    $this->generateBox($c, $r, $b, 1, 6, $d, 1, 9, $d, $shelf, $shelf, false);
                    $this->generateBox($c, $r, $b, 12, 6, $d, 12, 9, $d, $shelf, $shelf, false);
                }
            }
        }
        for ($z = 3; $z < 12; $z += 2) {
            $this->generateBox($c, $r, $b, 3, 1, $z, 4, 3, $z, $shelf, $shelf, false);
            $this->generateBox($c, $r, $b, 6, 1, $z, 7, 3, $z, $shelf, $shelf, false);
            $this->generateBox($c, $r, $b, 9, 1, $z, 10, 3, $z, $shelf, $shelf, false);
        }
        if ($tall) {
            $this->generateBox($c, $r, $b, 1, 5, 1, 3, 5, 13, $planks, $planks, false);
            $this->generateBox($c, $r, $b, 10, 5, 1, 12, 5, 13, $planks, $planks, false);
            $this->generateBox($c, $r, $b, 4, 5, 1, 9, 5, 2, $planks, $planks, false);
            $this->generateBox($c, $r, $b, 4, 5, 12, 9, 5, 13, $planks, $planks, false);
            $this->placeBlock($c, $r, $planks, 9, 5, 11, $b);
            $this->placeBlock($c, $r, $planks, 8, 5, 11, $b);
            $this->placeBlock($c, $r, $planks, 9, 5, 10, $b);
            $fence = static fn (array $sides): int => $c->state('oak_fence', array_fill_keys($sides, 'true'));
            $we = $fence(['west', 'east']);
            $ns = $fence(['north', 'south']);
            $this->generateBox($c, $r, $b, 3, 6, 3, 3, 6, 11, $ns, $ns, false);
            $this->generateBox($c, $r, $b, 10, 6, 3, 10, 6, 9, $ns, $ns, false);
            $this->generateBox($c, $r, $b, 4, 6, 2, 9, 6, 2, $we, $we, false);
            $this->generateBox($c, $r, $b, 4, 6, 12, 7, 6, 12, $we, $we, false);
            $this->placeBlock($c, $r, $fence(['north', 'east']), 3, 6, 2, $b);
            $this->placeBlock($c, $r, $fence(['south', 'east']), 3, 6, 12, $b);
            $this->placeBlock($c, $r, $fence(['north', 'west']), 10, 6, 2, $b);
            for ($i = 0; $i <= 2; ++$i) {
                $this->placeBlock($c, $r, $fence(['south', 'west']), 8 + $i, 6, 12 - $i, $b);
                if ($i !== 2) {
                    $this->placeBlock($c, $r, $fence(['north', 'east']), 8 + $i, 6, 11 - $i, $b);
                }
            }
            $ladder = $c->state('ladder', ['facing' => 'south']);
            for ($y = 1; $y <= 7; ++$y) {
                $this->placeBlock($c, $r, $ladder, 10, $y, 13, $b);
            }
            $east = $fence(['east']);
            $west = $fence(['west']);
            $this->placeBlock($c, $r, $east, 6, 9, 7, $b);
            $this->placeBlock($c, $r, $west, 7, 9, 7, $b);
            $this->placeBlock($c, $r, $east, 6, 8, 7, $b);
            $this->placeBlock($c, $r, $west, 7, 8, 7, $b);
            $all = $fence(['north', 'south', 'west', 'east']);
            $this->placeBlock($c, $r, $all, 6, 7, 7, $b);
            $this->placeBlock($c, $r, $all, 7, 7, 7, $b);
            $this->placeBlock($c, $r, $east, 5, 7, 7, $b);
            $this->placeBlock($c, $r, $west, 8, 7, 7, $b);
            $this->placeBlock($c, $r, $fence(['east', 'north']), 6, 7, 6, $b);
            $this->placeBlock($c, $r, $fence(['east', 'south']), 6, 7, 8, $b);
            $this->placeBlock($c, $r, $fence(['west', 'north']), 7, 7, 6, $b);
            $this->placeBlock($c, $r, $fence(['west', 'south']), 7, 7, 8, $b);
            $torch = $c->state('torch');
            foreach ([[5, 8, 7], [8, 8, 7], [6, 8, 6], [6, 8, 8], [7, 8, 6], [7, 8, 8]] as [$x, $y, $z]) {
                $this->placeBlock($c, $r, $torch, $x, $y, $z, $b);
            }
        }
        $this->createChest($c, $r, $b, $random, 3, 3, 5, 'stronghold_library');
        if ($tall) {
            $this->placeBlock($c, $r, $air, 12, 9, 1, $b);
            $this->createChest($c, $r, $b, $random, 12, 8, 1, 'stronghold_library');
        }
    }

    private function portalRoom(StructureContext $c, Region $r, array $b, RandomSource $random, \Closure $stone): void
    {
        $this->generateSelectedBox($c, $r, $b, 0, 0, 0, 10, 7, 15, false, $random, $stone);
        $this->door($c, $r, $b, 'grates', 4, 1, 0);
        foreach ([[1, 6, 1, 1, 6, 14], [9, 6, 1, 9, 6, 14], [2, 6, 1, 8, 6, 2], [2, 6, 14, 8, 6, 14], [1, 1, 1, 2, 1, 4], [8, 1, 1, 9, 1, 4]] as [$x0, $y0, $z0, $x1, $y1, $z1]) {
            $this->generateSelectedBox($c, $r, $b, $x0, $y0, $z0, $x1, $y1, $z1, false, $random, $stone);
        }
        $lava = $c->state('lava');
        $this->generateBox($c, $r, $b, 1, 1, 1, 1, 1, 3, $lava, $lava, false);
        $this->generateBox($c, $r, $b, 9, 1, 1, 9, 1, 3, $lava, $lava, false);
        $this->generateSelectedBox($c, $r, $b, 3, 1, 8, 7, 1, 12, false, $random, $stone);
        $this->generateBox($c, $r, $b, 4, 1, 9, 6, 1, 11, $lava, $lava, false);
        $ns = $c->state('iron_bars', ['north' => 'true', 'south' => 'true']);
        $we = $c->state('iron_bars', ['west' => 'true', 'east' => 'true']);
        for ($z = 3; $z < 14; $z += 2) {
            $this->generateBox($c, $r, $b, 0, 3, $z, 0, 4, $z, $ns, $ns, false);
            $this->generateBox($c, $r, $b, 10, 3, $z, 10, 4, $z, $ns, $ns, false);
        }
        for ($x = 2; $x < 9; $x += 2) {
            $this->generateBox($c, $r, $b, $x, 3, 15, $x, 4, 15, $we, $we, false);
        }
        $stairs = $c->state('stone_brick_stairs', ['facing' => 'north']);
        $this->generateSelectedBox($c, $r, $b, 4, 1, 5, 6, 1, 7, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 4, 2, 6, 6, 2, 7, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 4, 3, 7, 6, 3, 7, false, $random, $stone);
        for ($x = 4; $x <= 6; ++$x) {
            $this->placeBlock($c, $r, $stairs, $x, 1, 4, $b);
            $this->placeBlock($c, $r, $stairs, $x, 2, 5, $b);
            $this->placeBlock($c, $r, $stairs, $x, 3, 6, $b);
        }
        $eyes = [];
        $all = true;
        $chance = F32::r(0.9);
        for ($i = 0; $i < 12; ++$i) {
            $eyes[$i] = $random->nextFloat() > $chance;
            $all = $all && $eyes[$i];
        }
        foreach ([['north', 4, 8], ['north', 5, 8], ['north', 6, 8], ['south', 4, 12], ['south', 5, 12], ['south', 6, 12], ['east', 3, 9], ['east', 3, 10], ['east', 3, 11], ['west', 7, 9], ['west', 7, 10], ['west', 7, 11]] as $i => [$facing, $x, $z]) {
            $this->placeBlock($c, $r, $c->state('end_portal_frame', ['facing' => $facing, 'eye' => $eyes[$i] ? 'true' : 'false']), $x, 3, $z, $b);
        }
        if ($all) {
            $portal = $c->state('end_portal');
            for ($z = 9; $z <= 11; ++$z) {
                for ($x = 4; $x <= 6; ++$x) {
                    $this->placeBlock($c, $r, $portal, $x, 3, $z, $b);
                }
            }
        }
        if (!($this->f['spawner'] ?? false)) {
            [$wx, $wy, $wz] = $this->worldPos(5, 3, 6);
            if (self::inside($b, $wx, $wy, $wz)) {
                $this->f['spawner'] = true;
                $r->set($wx, $wy, $wz, $c->state('spawner'));
                $c->blockEntities["$wx,$wy,$wz"] = ['id' => 'mob_spawner', 'entity' => 'minecraft:silverfish'];
            }
        }
    }
}
