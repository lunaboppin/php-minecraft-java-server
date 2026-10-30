<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/**
 * NetherFortressPieces: bridges and the castle, grown from the start crossing by bridge and
 * castle piece weights, with blaze spawners, nether wart rooms and loot chests.
 */
final class NetherFortressPiece extends Piece
{
    /** BRIDGE_PIECE_WEIGHTS and CASTLE_PIECE_WEIGHTS: kind, weight, maximum count, allowed twice in a row. */
    private const BRIDGE = [['bridge_straight', 30, 0, true], ['bridge_crossing', 10, 4, false], ['room_crossing', 10, 4, false], ['stairs_room', 10, 3, false], ['monster_throne', 5, 2, false], ['castle_entrance', 5, 1, false]];

    private const CASTLE = [['castle_small_corridor', 25, 0, true], ['castle_small_corridor_crossing', 15, 5, false], ['castle_small_corridor_right_turn', 5, 10, false], ['castle_small_corridor_left_turn', 5, 10, false], ['castle_corridor_stairs', 10, 3, true], ['castle_corridor_t_balcony', 7, 2, false], ['castle_stalk_room', 5, 2, false]];

    /** BoundingBox.orientBox offsets and sizes of each kind. */
    private const BOXES = [
        'bridge_crossing' => [-8, -3, 0, 19, 10, 19], 'bridge_end_filler' => [-1, -3, 0, 5, 10, 8], 'bridge_straight' => [-1, -3, 0, 5, 10, 19],
        'castle_corridor_stairs' => [-1, -7, 0, 5, 14, 10], 'castle_corridor_t_balcony' => [-3, 0, 0, 9, 7, 9], 'castle_entrance' => [-5, -3, 0, 13, 14, 13],
        'castle_small_corridor_crossing' => [-1, 0, 0, 5, 7, 5], 'castle_small_corridor_left_turn' => [-1, 0, 0, 5, 7, 5], 'castle_small_corridor' => [-1, 0, 0, 5, 7, 5],
        'castle_small_corridor_right_turn' => [-1, 0, 0, 5, 7, 5], 'castle_stalk_room' => [-5, -3, 0, 13, 14, 13], 'monster_throne' => [-2, 0, 0, 7, 8, 9],
        'room_crossing' => [-2, 0, 0, 7, 9, 7], 'stairs_room' => [-2, 0, 0, 7, 11, 7],
    ];

    /** @var array<string, mixed> */
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
        return 'nether_fortress';
    }

    public function data(): array
    {
        return ['depth' => $this->genDepth, 'kind' => $this->kind, 'f' => $this->f];
    }

    /** NetherFortressStructure.generatePieces */
    public static function generate(RandomSource $random, int $cx, int $cz): array
    {
        $direction = self::HORIZONTAL[$random->nextInt(4)];
        $start = new self(self::makeBox($cx * 16 + 2, 64, $cz * 16 + 2, $direction, 19, 10, 19), $direction, 0, 'bridge_crossing');
        $state = ['bridge' => array_map(static fn (array $w): array => [...$w, 0], self::BRIDGE), 'castle' => array_map(static fn (array $w): array => [...$w, 0], self::CASTLE), 'previous' => null, 'pending' => []];
        $pieces = [$start];
        $start->addChildren($start, $pieces, $random, $state);
        while ($state['pending'] !== []) {
            $piece = array_splice($state['pending'], $random->nextInt(count($state['pending'])), 1)[0];
            $piece->addChildren($start, $pieces, $random, $state);
        }
        // StructurePiecesBuilder.moveInsideHeights(random, 48, 70)
        $box = $pieces[0]->box;
        foreach ($pieces as $piece) {
            $box = [min($box[0], $piece->box[0]), min($box[1], $piece->box[1]), min($box[2], $piece->box[2]), max($box[3], $piece->box[3]), max($box[4], $piece->box[4]), max($box[5], $piece->box[5])];
        }
        $span = 70 - 48 + 1 - ($box[4] - $box[1] + 1);
        $y = $span > 1 ? 48 + $random->nextInt($span) : 48;
        foreach ($pieces as $piece) {
            $piece->box[1] += $y - $box[1];
            $piece->box[4] += $y - $box[1];
        }
        return $pieces;
    }

    private static function orientBox(int $x, int $y, int $z, string $kind, string $direction): array
    {
        [$offX, $offY, $offZ, $width, $height, $depth] = self::BOXES[$kind];
        return match ($direction) {
            'north' => [$x + $offX, $y + $offY, $z - $depth + 1 + $offZ, $x + $width - 1 + $offX, $y + $height - 1 + $offY, $z + $offZ],
            'west' => [$x - $depth + 1 + $offZ, $y + $offY, $z + $offX, $x + $offZ, $y + $height - 1 + $offY, $z + $width - 1 + $offX],
            'east' => [$x + $offZ, $y + $offY, $z + $offX, $x + $depth - 1 + $offZ, $y + $height - 1 + $offY, $z + $width - 1 + $offX],
            default => [$x + $offX, $y + $offY, $z + $offZ, $x + $width - 1 + $offX, $y + $height - 1 + $offY, $z + $depth - 1 + $offZ],
        };
    }

    /** The kind's createPiece: its box if above y 10 and free, with its random state. */
    private static function create(string $kind, array $pieces, RandomSource $random, int $x, int $y, int $z, string $direction, int $depth): ?self
    {
        $box = self::orientBox($x, $y, $z, $kind, $direction);
        if ($box[1] <= 10 || MineshaftPiece::collides($pieces, $box)) {
            return null;
        }
        $piece = new self($box, $direction, $depth, $kind);
        if ($kind === 'bridge_end_filler') {
            $piece->f['seed'] = $random->nextInt();
        } elseif ($kind === 'castle_small_corridor_left_turn' || $kind === 'castle_small_corridor_right_turn') {
            $piece->f['chest'] = $random->nextInt(3) === 0;
        }
        return $piece;
    }

    /** NetherBridgePiece.generatePiece: by weight, falling through as vanilla does, else a bridge end. */
    private static function generatePiece(string $list, array &$pieces, RandomSource $random, int $x, int $y, int $z, string $direction, int $depth, array &$state): ?self
    {
        $any = false;
        $total = 0;
        foreach ($state[$list] as [, $weight, $max, , $count]) {
            $any = $any || $max > 0 && $count < $max;
            $total += $weight;
        }
        if ($any && $total > 0 && $depth <= 30) {
            for ($attempt = 0; $attempt < 5; ++$attempt) {
                $selection = $random->nextInt($total);
                foreach ($state[$list] as $index => [$kind, $weight, $max, $inRow, $count]) {
                    $selection -= $weight;
                    if ($selection >= 0) {
                        continue;
                    }
                    if (!($max === 0 || $count < $max) || $kind === $state['previous'] && !$inRow) {
                        break;
                    }
                    $piece = self::create($kind, $pieces, $random, $x, $y, $z, $direction, $depth);
                    if ($piece !== null) {
                        ++$state[$list][$index][4];
                        $state['previous'] = $kind;
                        if ($max !== 0 && $state[$list][$index][4] >= $max) {
                            array_splice($state[$list], $index, 1);
                        }
                        return $piece;
                    }
                }
            }
        }
        return self::create('bridge_end_filler', $pieces, $random, $x, $y, $z, $direction, $depth);
    }

    /** NetherBridgePiece.generateAndAddPiece */
    private static function addPiece(self $start, array &$pieces, RandomSource $random, int $x, int $y, int $z, string $direction, int $depth, bool $castle, array &$state): void
    {
        if (abs($x - $start->box[0]) > 112 || abs($z - $start->box[2]) > 112) {
            // A bridge end, not added to the pieces (vanilla discards it).
            self::create('bridge_end_filler', $pieces, $random, $x, $y, $z, $direction, $depth);
            return;
        }
        $piece = self::generatePiece($castle ? 'castle' : 'bridge', $pieces, $random, $x, $y, $z, $direction, $depth + 1, $state);
        if ($piece !== null) {
            $pieces[] = $piece;
            $state['pending'][] = $piece;
        }
    }

    private function forward(self $start, array &$pieces, RandomSource $random, int $xOff, int $yOff, bool $castle, array &$state): void
    {
        [$minX, $minY, $minZ, $maxX, , $maxZ] = $this->box;
        match ($this->orientation) {
            'north' => self::addPiece($start, $pieces, $random, $minX + $xOff, $minY + $yOff, $minZ - 1, 'north', $this->genDepth, $castle, $state),
            'south' => self::addPiece($start, $pieces, $random, $minX + $xOff, $minY + $yOff, $maxZ + 1, 'south', $this->genDepth, $castle, $state),
            'west' => self::addPiece($start, $pieces, $random, $minX - 1, $minY + $yOff, $minZ + $xOff, 'west', $this->genDepth, $castle, $state),
            'east' => self::addPiece($start, $pieces, $random, $maxX + 1, $minY + $yOff, $minZ + $xOff, 'east', $this->genDepth, $castle, $state),
        };
    }

    private function left(self $start, array &$pieces, RandomSource $random, int $yOff, int $zOff, bool $castle, array &$state): void
    {
        [$minX, $minY, $minZ] = $this->box;
        $this->orientation === 'north' || $this->orientation === 'south'
            ? self::addPiece($start, $pieces, $random, $minX - 1, $minY + $yOff, $minZ + $zOff, 'west', $this->genDepth, $castle, $state)
            : self::addPiece($start, $pieces, $random, $minX + $zOff, $minY + $yOff, $minZ - 1, 'north', $this->genDepth, $castle, $state);
    }

    private function right(self $start, array &$pieces, RandomSource $random, int $yOff, int $zOff, bool $castle, array &$state): void
    {
        [$minX, $minY, $minZ, $maxX, , $maxZ] = $this->box;
        $this->orientation === 'north' || $this->orientation === 'south'
            ? self::addPiece($start, $pieces, $random, $maxX + 1, $minY + $yOff, $minZ + $zOff, 'east', $this->genDepth, $castle, $state)
            : self::addPiece($start, $pieces, $random, $minX + $zOff, $minY + $yOff, $maxZ + 1, 'south', $this->genDepth, $castle, $state);
    }

    public function addChildren(self $start, array &$pieces, RandomSource $random, array &$state): void
    {
        switch ($this->kind) {
            case 'bridge_crossing':
                $this->forward($start, $pieces, $random, 8, 3, false, $state);
                $this->left($start, $pieces, $random, 3, 8, false, $state);
                $this->right($start, $pieces, $random, 3, 8, false, $state);
                return;
            case 'bridge_straight':
                $this->forward($start, $pieces, $random, 1, 3, false, $state);
                return;
            case 'castle_corridor_stairs':
            case 'castle_small_corridor':
                $this->forward($start, $pieces, $random, 1, 0, true, $state);
                return;
            case 'castle_corridor_t_balcony':
                $zOff = $this->orientation === 'west' || $this->orientation === 'north' ? 5 : 1;
                $this->left($start, $pieces, $random, 0, $zOff, $random->nextInt(8) > 0, $state);
                $this->right($start, $pieces, $random, 0, $zOff, $random->nextInt(8) > 0, $state);
                return;
            case 'castle_entrance':
                $this->forward($start, $pieces, $random, 5, 3, true, $state);
                return;
            case 'castle_small_corridor_crossing':
                $this->forward($start, $pieces, $random, 1, 0, true, $state);
                $this->left($start, $pieces, $random, 0, 1, true, $state);
                $this->right($start, $pieces, $random, 0, 1, true, $state);
                return;
            case 'castle_small_corridor_left_turn':
                $this->left($start, $pieces, $random, 0, 1, true, $state);
                return;
            case 'castle_small_corridor_right_turn':
                $this->right($start, $pieces, $random, 0, 1, true, $state);
                return;
            case 'castle_stalk_room':
                $this->forward($start, $pieces, $random, 5, 3, true, $state);
                $this->forward($start, $pieces, $random, 5, 11, true, $state);
                return;
            case 'room_crossing':
                $this->forward($start, $pieces, $random, 2, 0, false, $state);
                $this->left($start, $pieces, $random, 0, 2, false, $state);
                $this->right($start, $pieces, $random, 0, 2, false, $state);
                return;
            case 'stairs_room':
                $this->right($start, $pieces, $random, 6, 2, false, $state);
                return;
        }
    }

    public function postProcess(StructureContext $c, Region $r, RandomSource $random, array $b): void
    {
        $method = str_replace(['castle_', '_corridor'], ['c_', '_cor'], $this->kind);
        $this->$method($c, $r, $b, $random);
    }

    private function bridge_crossing(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        $this->generateBox($c, $r, $b, 7, 3, 0, 11, 4, 18, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 3, 7, 18, 4, 11, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 8, 5, 0, 10, 7, 18, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 0, 5, 8, 18, 7, 10, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 7, 5, 0, 7, 5, 7, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 7, 5, 11, 7, 5, 18, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 11, 5, 0, 11, 5, 7, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 11, 5, 11, 11, 5, 18, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 5, 7, 7, 5, 7, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 11, 5, 7, 18, 5, 7, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 5, 11, 7, 5, 11, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 11, 5, 11, 18, 5, 11, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 7, 2, 0, 11, 2, 5, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 7, 2, 13, 11, 2, 18, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 7, 0, 0, 11, 1, 3, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 7, 0, 15, 11, 1, 18, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        for ($x = 7; $x <= 11; ++$x) {
            for ($z = 0; $z <= 2; ++$z) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, 18 - $z, $b);
            }
        }
        $this->generateBox($c, $r, $b, 0, 2, 7, 5, 2, 11, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 13, 2, 7, 18, 2, 11, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 0, 7, 3, 1, 11, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 15, 0, 7, 18, 1, 11, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        for ($x = 0; $x <= 2; ++$x) {
            for ($z = 7; $z <= 11; ++$z) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), 18 - $x, -1, $z, $b);
            }
        }

    }

    private function bridge_end_filler(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        $selfRandom = new LegacyRandom($this->f['seed']);
        for ($x = 0; $x <= 4; ++$x) {
            for ($y = 3; $y <= 4; ++$y) {
                $z = $selfRandom->nextInt(8);
                $this->generateBox($c, $r, $b, $x, $y, 0, $x, $y, $z, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
            }
        }
        $z = $selfRandom->nextInt(8);
        $this->generateBox($c, $r, $b, 0, 5, 0, 0, 5, $z, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $z = $selfRandom->nextInt(8);
        $this->generateBox($c, $r, $b, 4, 5, 0, 4, 5, $z, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        for ($x = 0; $x <= 4; ++$x) {
            $zx = $selfRandom->nextInt(5);
            $this->generateBox($c, $r, $b, $x, 2, 0, $x, 2, $zx, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        }
        for ($x = 0; $x <= 4; ++$x) {
            for ($y = 0; $y <= 1; ++$y) {
                $zx = $selfRandom->nextInt(3);
                $this->generateBox($c, $r, $b, $x, $y, 0, $x, $y, $zx, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
            }
        }

    }

    private function bridge_straight(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        $this->generateBox($c, $r, $b, 0, 3, 0, 4, 4, 18, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 5, 0, 3, 7, 18, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 0, 5, 0, 0, 5, 18, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 4, 5, 0, 4, 5, 18, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 4, 2, 5, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 13, 4, 2, 18, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 0, 0, 4, 1, 3, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 0, 15, 4, 1, 18, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        for ($x = 0; $x <= 4; ++$x) {
            for ($z = 0; $z <= 2; ++$z) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, 18 - $z, $b);
            }
        }
        $nsFence = $c->with($c->with($c->state('nether_brick_fence'), 'north', 'true'), 'south', 'true');
        $nseFence = $c->with($nsFence, 'east', 'true');
        $nswFence = $c->with($nsFence, 'west', 'true');
        $this->generateBox($c, $r, $b, 0, 1, 1, 0, 4, 1, $nseFence, $nseFence, false);
        $this->generateBox($c, $r, $b, 0, 3, 4, 0, 4, 4, $nseFence, $nseFence, false);
        $this->generateBox($c, $r, $b, 0, 3, 14, 0, 4, 14, $nseFence, $nseFence, false);
        $this->generateBox($c, $r, $b, 0, 1, 17, 0, 4, 17, $nseFence, $nseFence, false);
        $this->generateBox($c, $r, $b, 4, 1, 1, 4, 4, 1, $nswFence, $nswFence, false);
        $this->generateBox($c, $r, $b, 4, 3, 4, 4, 4, 4, $nswFence, $nswFence, false);
        $this->generateBox($c, $r, $b, 4, 3, 14, 4, 4, 14, $nswFence, $nswFence, false);
        $this->generateBox($c, $r, $b, 4, 1, 17, 4, 4, 17, $nswFence, $nswFence, false);

    }

    private function c_cor_stairs(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        $stairs = $c->with($c->state('nether_brick_stairs'), 'facing', 'south');
        $nsFence = $c->with($c->with($c->state('nether_brick_fence'), 'north', 'true'), 'south', 'true');
        for ($step = 0; $step <= 9; ++$step) {
            $floor = Math . max(1, 7 - $step);
            $roof = Math . min(Math . max($floor + 5, 14 - $step), 13);
            $z = $step;
            $this->generateBox(
                $c,
                $r,
                $b,
                0,
                0,
                $step,
                4,
                $floor,
                $step,
                $c->state('nether_bricks'),
                $c->state('nether_bricks'),
                false
            );
            $this->generateBox($c, $r, $b, 1, $floor + 1, $step, 3, $roof - 1, $step, $c->state('air'), $c->state('air'), false);
            if ($step <= 6) {
                $this->placeBlock($c, $r, $stairs, 1, $floor + 1, $step, $b);
                $this->placeBlock($c, $r, $stairs, 2, $floor + 1, $step, $b);
                $this->placeBlock($c, $r, $stairs, 3, $floor + 1, $step, $b);
            }
            $this->generateBox(
                $c,
                $r,
                $b,
                0,
                $roof,
                $step,
                4,
                $roof,
                $step,
                $c->state('nether_bricks'),
                $c->state('nether_bricks'),
                false
            );
            $this->generateBox(
                $c,
                $r,
                $b,
                0,
                $floor + 1,
                $step,
                0,
                $roof - 1,
                $step,
                $c->state('nether_bricks'),
                $c->state('nether_bricks'),
                false
            );
            $this->generateBox(
                $c,
                $r,
                $b,
                4,
                $floor + 1,
                $step,
                4,
                $roof - 1,
                $step,
                $c->state('nether_bricks'),
                $c->state('nether_bricks'),
                false
            );
            if (($step & 1) == 0) {
                $this->generateBox($c, $r, $b, 0, $floor + 2, $step, 0, $floor + 3, $step, $nsFence, $nsFence, false);
                $this->generateBox($c, $r, $b, 4, $floor + 2, $step, 4, $floor + 3, $step, $nsFence, $nsFence, false);
            }
            for ($x = 0; $x <= 4; ++$x) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
            }
        }

    }

    private function c_cor_t_balcony(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        $nsFence = $c->with($c->with($c->state('nether_brick_fence'), 'north', 'true'), 'south', 'true');
        $weFence = $c->with($c->with($c->state('nether_brick_fence'), 'west', 'true'), 'east', 'true');
        $this->generateBox($c, $r, $b, 0, 0, 0, 8, 1, 8, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 8, 5, 8, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 0, 6, 0, 8, 6, 5, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 2, 5, 0, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 6, 2, 0, 8, 5, 0, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 3, 0, 1, 4, 0, $weFence, $weFence, false);
        $this->generateBox($c, $r, $b, 7, 3, 0, 7, 4, 0, $weFence, $weFence, false);
        $this->generateBox($c, $r, $b, 0, 2, 4, 8, 2, 8, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 1, 4, 2, 2, 4, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 6, 1, 4, 7, 2, 4, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 1, 3, 8, 7, 3, 8, $weFence, $weFence, false);
        $this->placeBlock($c, $r, $c->with($c->with($c->state('nether_brick_fence'), 'east', 'true'), 'south', 'true'), 0, 3, 8, $b);
        $this->placeBlock($c, $r, $c->with($c->with($c->state('nether_brick_fence'), 'west', 'true'), 'south', 'true'), 8, 3, 8, $b);
        $this->generateBox($c, $r, $b, 0, 3, 6, 0, 3, 7, $nsFence, $nsFence, false);
        $this->generateBox($c, $r, $b, 8, 3, 6, 8, 3, 7, $nsFence, $nsFence, false);
        $this->generateBox($c, $r, $b, 0, 3, 4, 0, 5, 5, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 8, 3, 4, 8, 5, 5, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 3, 5, 2, 5, 5, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 6, 3, 5, 7, 5, 5, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 4, 5, 1, 5, 5, $weFence, $weFence, false);
        $this->generateBox($c, $r, $b, 7, 4, 5, 7, 5, 5, $weFence, $weFence, false);
        for ($z = 0; $z <= 5; ++$z) {
            for ($x = 0; $x <= 8; ++$x) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
            }
        }

    }

    private function c_entrance(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        $this->generateBox($c, $r, $b, 0, 3, 0, 12, 4, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 5, 0, 12, 13, 12, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 0, 5, 0, 1, 12, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 11, 5, 0, 12, 12, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 2, 5, 11, 4, 12, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 8, 5, 11, 10, 12, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 5, 9, 11, 7, 12, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 2, 5, 0, 4, 12, 1, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 8, 5, 0, 10, 12, 1, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 5, 9, 0, 7, 12, 1, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 2, 11, 2, 10, 12, 10, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 5, 8, 0, 7, 8, 0, $c->state('nether_brick_fence'), $c->state('nether_brick_fence'), false);
        $weFence = $c->with($c->with($c->state('nether_brick_fence'), 'west', 'true'), 'east', 'true');
        $nsFence = $c->with($c->with($c->state('nether_brick_fence'), 'north', 'true'), 'south', 'true');
        for ($i = 1; $i <= 11; $i += 2) {
            $this->generateBox($c, $r, $b, $i, 10, 0, $i, 11, 0, $weFence, $weFence, false);
            $this->generateBox($c, $r, $b, $i, 10, 12, $i, 11, 12, $weFence, $weFence, false);
            $this->generateBox($c, $r, $b, 0, 10, $i, 0, 11, $i, $nsFence, $nsFence, false);
            $this->generateBox($c, $r, $b, 12, 10, $i, 12, 11, $i, $nsFence, $nsFence, false);
            $this->placeBlock($c, $r, $c->state('nether_bricks'), $i, 13, 0, $b);
            $this->placeBlock($c, $r, $c->state('nether_bricks'), $i, 13, 12, $b);
            $this->placeBlock($c, $r, $c->state('nether_bricks'), 0, 13, $i, $b);
            $this->placeBlock($c, $r, $c->state('nether_bricks'), 12, 13, $i, $b);
            if ($i != 11) {
                $this->placeBlock($c, $r, $weFence, $i + 1, 13, 0, $b);
                $this->placeBlock($c, $r, $weFence, $i + 1, 13, 12, $b);
                $this->placeBlock($c, $r, $nsFence, 0, 13, $i + 1, $b);
                $this->placeBlock($c, $r, $nsFence, 12, 13, $i + 1, $b);
            }
        }
        $this->placeBlock($c, $r, $c->with($c->with($c->state('nether_brick_fence'), 'north', 'true'), 'east', 'true'), 0, 13, 0, $b);
        $this->placeBlock($c, $r, $c->with($c->with($c->state('nether_brick_fence'), 'south', 'true'), 'east', 'true'), 0, 13, 12, $b);
        $this->placeBlock($c, $r, $c->with($c->with($c->state('nether_brick_fence'), 'south', 'true'), 'west', 'true'), 12, 13, 12, $b);
        $this->placeBlock($c, $r, $c->with($c->with($c->state('nether_brick_fence'), 'north', 'true'), 'west', 'true'), 12, 13, 0, $b);
        for ($z = 3; $z <= 9; $z += 2) {
            $this->generateBox($c, $r, $b, 1, 7, $z, 1, 8, $z, $c->with($nsFence, 'west', 'true'), $c->with($nsFence, 'west', 'true'), false);
            $this->generateBox($c, $r, $b, 11, 7, $z, 11, 8, $z, $c->with($nsFence, 'east', 'true'), $c->with($nsFence, 'east', 'true'), false);
        }
        $this->generateBox($c, $r, $b, 4, 2, 0, 8, 2, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 4, 12, 2, 8, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 4, 0, 0, 8, 1, 3, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 4, 0, 9, 8, 1, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 0, 4, 3, 1, 8, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 9, 0, 4, 12, 1, 8, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        for ($x = 4; $x <= 8; ++$x) {
            for ($z = 0; $z <= 2; ++$z) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, 12 - $z, $b);
            }
        }
        for ($x = 0; $x <= 2; ++$x) {
            for ($z = 4; $z <= 8; ++$z) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), 12 - $x, -1, $z, $b);
            }
        }
        $this->generateBox($c, $r, $b, 5, 5, 5, 7, 5, 7, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 6, 1, 6, 6, 4, 6, $c->state('air'), $c->state('air'), false);
        $this->placeBlock($c, $r, $c->state('nether_bricks'), 6, 0, 6, $b);
        $this->placeBlock($c, $r, $c->state('lava'), 6, 5, 6, $b);

    }

    private function c_small_cor_crossing(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        $this->generateBox($c, $r, $b, 0, 0, 0, 4, 1, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 4, 5, 4, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 0, 5, 0, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 4, 2, 0, 4, 5, 0, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 4, 0, 5, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 4, 2, 4, 4, 5, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 6, 0, 4, 6, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        for ($x = 0; $x <= 4; ++$x) {
            for ($z = 0; $z <= 4; ++$z) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
            }
        }

    }

    private function c_small_cor_left_turn(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        $this->generateBox($c, $r, $b, 0, 0, 0, 4, 1, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 4, 5, 4, $c->state('air'), $c->state('air'), false);
        $weFence = $c->with($c->with($c->state('nether_brick_fence'), 'west', 'true'), 'east', 'true');
        $nsFence = $c->with($c->with($c->state('nether_brick_fence'), 'north', 'true'), 'south', 'true');
        $this->generateBox($c, $r, $b, 4, 2, 0, 4, 5, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 4, 3, 1, 4, 4, 1, $nsFence, $nsFence, false);
        $this->generateBox($c, $r, $b, 4, 3, 3, 4, 4, 3, $nsFence, $nsFence, false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 0, 5, 0, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 4, 3, 5, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 3, 4, 1, 4, 4, $weFence, $weFence, false);
        $this->generateBox($c, $r, $b, 3, 3, 4, 3, 4, 4, $weFence, $weFence, false);
        if (($this->f['chest'] ?? false) && self::inside($b, ...$this->worldPos(3, 2, 3))) {
            $this->f['chest'] = false;
            $this->createChest($c, $r, $b, $random, 3, 2, 3, 'nether_bridge');
        }
        $this->generateBox($c, $r, $b, 0, 6, 0, 4, 6, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        for ($x = 0; $x <= 4; ++$x) {
            for ($z = 0; $z <= 4; ++$z) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
            }
        }

    }

    private function c_small_cor(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        $this->generateBox($c, $r, $b, 0, 0, 0, 4, 1, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 4, 5, 4, $c->state('air'), $c->state('air'), false);
        $nsFence = $c->with($c->with($c->state('nether_brick_fence'), 'north', 'true'), 'south', 'true');
        $this->generateBox($c, $r, $b, 0, 2, 0, 0, 5, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 4, 2, 0, 4, 5, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 3, 1, 0, 4, 1, $nsFence, $nsFence, false);
        $this->generateBox($c, $r, $b, 0, 3, 3, 0, 4, 3, $nsFence, $nsFence, false);
        $this->generateBox($c, $r, $b, 4, 3, 1, 4, 4, 1, $nsFence, $nsFence, false);
        $this->generateBox($c, $r, $b, 4, 3, 3, 4, 4, 3, $nsFence, $nsFence, false);
        $this->generateBox($c, $r, $b, 0, 6, 0, 4, 6, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        for ($x = 0; $x <= 4; ++$x) {
            for ($z = 0; $z <= 4; ++$z) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
            }
        }

    }

    private function c_small_cor_right_turn(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        $this->generateBox($c, $r, $b, 0, 0, 0, 4, 1, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 4, 5, 4, $c->state('air'), $c->state('air'), false);
        $weFence = $c->with($c->with($c->state('nether_brick_fence'), 'west', 'true'), 'east', 'true');
        $nsFence = $c->with($c->with($c->state('nether_brick_fence'), 'north', 'true'), 'south', 'true');
        $this->generateBox($c, $r, $b, 0, 2, 0, 0, 5, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 3, 1, 0, 4, 1, $nsFence, $nsFence, false);
        $this->generateBox($c, $r, $b, 0, 3, 3, 0, 4, 3, $nsFence, $nsFence, false);
        $this->generateBox($c, $r, $b, 4, 2, 0, 4, 5, 0, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 2, 4, 4, 5, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 3, 4, 1, 4, 4, $weFence, $weFence, false);
        $this->generateBox($c, $r, $b, 3, 3, 4, 3, 4, 4, $weFence, $weFence, false);
        if (($this->f['chest'] ?? false) && self::inside($b, ...$this->worldPos(1, 2, 3))) {
            $this->f['chest'] = false;
            $this->createChest($c, $r, $b, $random, 1, 2, 3, 'nether_bridge');
        }
        $this->generateBox($c, $r, $b, 0, 6, 0, 4, 6, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        for ($x = 0; $x <= 4; ++$x) {
            for ($z = 0; $z <= 4; ++$z) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
            }
        }

    }

    private function c_stalk_room(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        $this->generateBox($c, $r, $b, 0, 3, 0, 12, 4, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 5, 0, 12, 13, 12, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 0, 5, 0, 1, 12, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 11, 5, 0, 12, 12, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 2, 5, 11, 4, 12, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 8, 5, 11, 10, 12, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 5, 9, 11, 7, 12, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 2, 5, 0, 4, 12, 1, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 8, 5, 0, 10, 12, 1, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 5, 9, 0, 7, 12, 1, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 2, 11, 2, 10, 12, 10, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $weFence = $c->with($c->with($c->state('nether_brick_fence'), 'west', 'true'), 'east', 'true');
        $nsFence = $c->with($c->with($c->state('nether_brick_fence'), 'north', 'true'), 'south', 'true');
        $nswFence = $c->with($nsFence, 'west', 'true');
        $nseFence = $c->with($nsFence, 'east', 'true');
        for ($i = 1; $i <= 11; $i += 2) {
            $this->generateBox($c, $r, $b, $i, 10, 0, $i, 11, 0, $weFence, $weFence, false);
            $this->generateBox($c, $r, $b, $i, 10, 12, $i, 11, 12, $weFence, $weFence, false);
            $this->generateBox($c, $r, $b, 0, 10, $i, 0, 11, $i, $nsFence, $nsFence, false);
            $this->generateBox($c, $r, $b, 12, 10, $i, 12, 11, $i, $nsFence, $nsFence, false);
            $this->placeBlock($c, $r, $c->state('nether_bricks'), $i, 13, 0, $b);
            $this->placeBlock($c, $r, $c->state('nether_bricks'), $i, 13, 12, $b);
            $this->placeBlock($c, $r, $c->state('nether_bricks'), 0, 13, $i, $b);
            $this->placeBlock($c, $r, $c->state('nether_bricks'), 12, 13, $i, $b);
            if ($i != 11) {
                $this->placeBlock($c, $r, $weFence, $i + 1, 13, 0, $b);
                $this->placeBlock($c, $r, $weFence, $i + 1, 13, 12, $b);
                $this->placeBlock($c, $r, $nsFence, 0, 13, $i + 1, $b);
                $this->placeBlock($c, $r, $nsFence, 12, 13, $i + 1, $b);
            }
        }
        $this->placeBlock($c, $r, $c->with($c->with($c->state('nether_brick_fence'), 'north', 'true'), 'east', 'true'), 0, 13, 0, $b);
        $this->placeBlock($c, $r, $c->with($c->with($c->state('nether_brick_fence'), 'south', 'true'), 'east', 'true'), 0, 13, 12, $b);
        $this->placeBlock($c, $r, $c->with($c->with($c->state('nether_brick_fence'), 'south', 'true'), 'west', 'true'), 12, 13, 12, $b);
        $this->placeBlock($c, $r, $c->with($c->with($c->state('nether_brick_fence'), 'north', 'true'), 'west', 'true'), 12, 13, 0, $b);
        for ($z = 3; $z <= 9; $z += 2) {
            $this->generateBox($c, $r, $b, 1, 7, $z, 1, 8, $z, $nswFence, $nswFence, false);
            $this->generateBox($c, $r, $b, 11, 7, $z, 11, 8, $z, $nseFence, $nseFence, false);
        }
        $stairs = $c->with($c->state('nether_brick_stairs'), 'facing', 'north');
        for ($ix = 0; $ix <= 6; ++$ix) {
            $z = $ix + 4;
            for ($x = 5; $x <= 7; ++$x) {
                $this->placeBlock($c, $r, $stairs, $x, 5 + $ix, $z, $b);
            }
            if ($z >= 5 && $z <= 8) {
                $this->generateBox(
                    $c,
                    $r,
                    $b,
                    5,
                    5,
                    $z,
                    7,
                    $ix + 4,
                    $z,
                    $c->state('nether_bricks'),
                    $c->state('nether_bricks'),
                    false
                );
            } elseif ($z >= 9 && $z <= 10) {
                $this->generateBox(
                    $c,
                    $r,
                    $b,
                    5,
                    8,
                    $z,
                    7,
                    $ix + 4,
                    $z,
                    $c->state('nether_bricks'),
                    $c->state('nether_bricks'),
                    false
                );
            }
            if ($ix >= 1) {
                $this->generateBox($c, $r, $b, 5, 6 + $ix, $z, 7, 9 + $ix, $z, $c->state('air'), $c->state('air'), false);
            }
        }
        for ($x = 5; $x <= 7; ++$x) {
            $this->placeBlock($c, $r, $stairs, $x, 12, 11, $b);
        }
        $this->generateBox($c, $r, $b, 5, 6, 7, 5, 7, 7, $nseFence, $nseFence, false);
        $this->generateBox($c, $r, $b, 7, 6, 7, 7, 7, 7, $nswFence, $nswFence, false);
        $this->generateBox($c, $r, $b, 5, 13, 12, 7, 13, 12, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 2, 5, 2, 3, 5, 3, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 2, 5, 9, 3, 5, 10, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 2, 5, 4, 2, 5, 8, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 9, 5, 2, 10, 5, 3, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 9, 5, 9, 10, 5, 10, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 10, 5, 4, 10, 5, 8, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $eastStairs = $c->with($stairs, 'facing', 'east');
        $westStairs = $c->with($stairs, 'facing', 'west');
        $this->placeBlock($c, $r, $westStairs, 4, 5, 2, $b);
        $this->placeBlock($c, $r, $westStairs, 4, 5, 3, $b);
        $this->placeBlock($c, $r, $westStairs, 4, 5, 9, $b);
        $this->placeBlock($c, $r, $westStairs, 4, 5, 10, $b);
        $this->placeBlock($c, $r, $eastStairs, 8, 5, 2, $b);
        $this->placeBlock($c, $r, $eastStairs, 8, 5, 3, $b);
        $this->placeBlock($c, $r, $eastStairs, 8, 5, 9, $b);
        $this->placeBlock($c, $r, $eastStairs, 8, 5, 10, $b);
        $this->generateBox($c, $r, $b, 3, 4, 4, 4, 4, 8, $c->state('soul_sand'), $c->state('soul_sand'), false);
        $this->generateBox($c, $r, $b, 8, 4, 4, 9, 4, 8, $c->state('soul_sand'), $c->state('soul_sand'), false);
        $this->generateBox($c, $r, $b, 3, 5, 4, 4, 5, 8, $c->state('nether_wart'), $c->state('nether_wart'), false);
        $this->generateBox($c, $r, $b, 8, 5, 4, 9, 5, 8, $c->state('nether_wart'), $c->state('nether_wart'), false);
        $this->generateBox($c, $r, $b, 4, 2, 0, 8, 2, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 4, 12, 2, 8, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 4, 0, 0, 8, 1, 3, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 4, 0, 9, 8, 1, 12, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 0, 4, 3, 1, 8, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 9, 0, 4, 12, 1, 8, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        for ($x = 4; $x <= 8; ++$x) {
            for ($z = 0; $z <= 2; ++$z) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, 12 - $z, $b);
            }
        }
        for ($x = 0; $x <= 2; ++$x) {
            for ($z = 4; $z <= 8; ++$z) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), 12 - $x, -1, $z, $b);
            }
        }

    }

    private function monster_throne(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        $this->generateBox($c, $r, $b, 0, 2, 0, 6, 7, 7, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 1, 0, 0, 5, 1, 7, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 2, 1, 5, 2, 7, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 3, 2, 5, 3, 7, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 4, 3, 5, 4, 7, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 2, 0, 1, 4, 2, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 5, 2, 0, 5, 4, 2, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 5, 2, 1, 5, 3, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 5, 5, 2, 5, 5, 3, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 5, 3, 0, 5, 8, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 6, 5, 3, 6, 5, 8, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 5, 8, 5, 5, 8, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $weFence = $c->with($c->with($c->state('nether_brick_fence'), 'west', 'true'), 'east', 'true');
        $nsFence = $c->with($c->with($c->state('nether_brick_fence'), 'north', 'true'), 'south', 'true');
        $this->placeBlock($c, $r, $c->with($c->state('nether_brick_fence'), 'west', 'true'), 1, 6, 3, $b);
        $this->placeBlock($c, $r, $c->with($c->state('nether_brick_fence'), 'east', 'true'), 5, 6, 3, $b);
        $this->placeBlock($c, $r, $c->with($c->with($c->state('nether_brick_fence'), 'east', 'true'), 'north', 'true'), 0, 6, 3, $b);
        $this->placeBlock($c, $r, $c->with($c->with($c->state('nether_brick_fence'), 'west', 'true'), 'north', 'true'), 6, 6, 3, $b);
        $this->generateBox($c, $r, $b, 0, 6, 4, 0, 6, 7, $nsFence, $nsFence, false);
        $this->generateBox($c, $r, $b, 6, 6, 4, 6, 6, 7, $nsFence, $nsFence, false);
        $this->placeBlock($c, $r, $c->with($c->with($c->state('nether_brick_fence'), 'east', 'true'), 'south', 'true'), 0, 6, 8, $b);
        $this->placeBlock($c, $r, $c->with($c->with($c->state('nether_brick_fence'), 'west', 'true'), 'south', 'true'), 6, 6, 8, $b);
        $this->generateBox($c, $r, $b, 1, 6, 8, 5, 6, 8, $weFence, $weFence, false);
        $this->placeBlock($c, $r, $c->with($c->state('nether_brick_fence'), 'east', 'true'), 1, 7, 8, $b);
        $this->generateBox($c, $r, $b, 2, 7, 8, 4, 7, 8, $weFence, $weFence, false);
        $this->placeBlock($c, $r, $c->with($c->state('nether_brick_fence'), 'west', 'true'), 5, 7, 8, $b);
        $this->placeBlock($c, $r, $c->with($c->state('nether_brick_fence'), 'east', 'true'), 2, 8, 8, $b);
        $this->placeBlock($c, $r, $weFence, 3, 8, 8, $b);
        $this->placeBlock($c, $r, $c->with($c->state('nether_brick_fence'), 'west', 'true'), 4, 8, 8, $b);
        if (!($this->f['spawner'] ?? false)) {
            [$wx, $wy, $wz] = $this->worldPos(3, 5, 5);
            if (self::inside($b, $wx, $wy, $wz)) {
                $this->f['spawner'] = true;
                $r->set($wx, $wy, $wz, $c->state('spawner'));
                $c->blockEntities["$wx,$wy,$wz"] = ['id' => 'mob_spawner', 'entity' => 'minecraft:blaze'];
            }
        }
        for ($x = 0; $x <= 6; ++$x) {
            for ($z = 0; $z <= 6; ++$z) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
            }
        }

    }

    private function room_crossing(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        $this->generateBox($c, $r, $b, 0, 0, 0, 6, 1, 6, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 6, 7, 6, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 1, 6, 0, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 6, 1, 6, 6, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 5, 2, 0, 6, 6, 0, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 5, 2, 6, 6, 6, 6, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 0, 6, 1, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 5, 0, 6, 6, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 6, 2, 0, 6, 6, 1, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 6, 2, 5, 6, 6, 6, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $weFence = $c->with($c->with($c->state('nether_brick_fence'), 'west', 'true'), 'east', 'true');
        $nsFence = $c->with($c->with($c->state('nether_brick_fence'), 'north', 'true'), 'south', 'true');
        $this->generateBox($c, $r, $b, 2, 6, 0, 4, 6, 0, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 2, 5, 0, 4, 5, 0, $weFence, $weFence, false);
        $this->generateBox($c, $r, $b, 2, 6, 6, 4, 6, 6, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 2, 5, 6, 4, 5, 6, $weFence, $weFence, false);
        $this->generateBox($c, $r, $b, 0, 6, 2, 0, 6, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 5, 2, 0, 5, 4, $nsFence, $nsFence, false);
        $this->generateBox($c, $r, $b, 6, 6, 2, 6, 6, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 6, 5, 2, 6, 5, 4, $nsFence, $nsFence, false);
        for ($x = 0; $x <= 6; ++$x) {
            for ($z = 0; $z <= 6; ++$z) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
            }
        }

    }

    private function stairs_room(StructureContext $c, Region $r, array $b, RandomSource $random): void
    {
        $this->generateBox($c, $r, $b, 0, 0, 0, 6, 1, 6, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 6, 10, 6, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 0, 2, 0, 1, 8, 0, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 5, 2, 0, 6, 8, 0, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 0, 2, 1, 0, 8, 6, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 6, 2, 1, 6, 8, 6, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 2, 6, 5, 8, 6, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $weFence = $c->with($c->with($c->state('nether_brick_fence'), 'west', 'true'), 'east', 'true');
        $nsFence = $c->with($c->with($c->state('nether_brick_fence'), 'north', 'true'), 'south', 'true');
        $this->generateBox($c, $r, $b, 0, 3, 2, 0, 5, 4, $nsFence, $nsFence, false);
        $this->generateBox($c, $r, $b, 6, 3, 2, 6, 5, 2, $nsFence, $nsFence, false);
        $this->generateBox($c, $r, $b, 6, 3, 4, 6, 5, 4, $nsFence, $nsFence, false);
        $this->placeBlock($c, $r, $c->state('nether_bricks'), 5, 2, 5, $b);
        $this->generateBox($c, $r, $b, 4, 2, 5, 4, 3, 5, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 3, 2, 5, 3, 4, 5, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 2, 2, 5, 2, 5, 5, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 2, 5, 1, 6, 5, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 1, 7, 1, 5, 7, 4, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 6, 8, 2, 6, 8, 4, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 2, 6, 0, 4, 8, 0, $c->state('nether_bricks'), $c->state('nether_bricks'), false);
        $this->generateBox($c, $r, $b, 2, 5, 0, 4, 5, 0, $weFence, $weFence, false);
        for ($x = 0; $x <= 6; ++$x) {
            for ($z = 0; $z <= 6; ++$z) {
                $this->fillColumnDown($c, $r, $c->state('nether_bricks'), $x, -1, $z, $b);
            }
        }

    }
}
