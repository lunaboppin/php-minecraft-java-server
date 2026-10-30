<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/** MineshaftPieces.MineShaftCorridor: sections of supports, sometimes rails, or cobwebs and a cave spider spawner. */
final class MineshaftCorridor extends MineshaftPiece
{
    /** Direction.values(): down, up, north, south, west, east, as steps and Blocks::sturdy indexes of their opposites. */
    private const NEIGHBOURS = [[0, -1, 0, 1], [0, 1, 0, 0], [0, 0, -1, 3], [0, 0, 1, 2], [-1, 0, 0, 5], [1, 0, 0, 4]];

    public bool $hasRails = false;

    public bool $spiderCorridor = false;

    public bool $hasPlacedSpider = false;

    public int $numSections = 0;

    public static function create(array $box, string $direction, int $depth, string $kind, RandomSource $random): self
    {
        $corridor = new self($box, $direction, $depth, $kind);
        $corridor->hasRails = $random->nextInt(3) === 0;
        $corridor->spiderCorridor = !$corridor->hasRails && $random->nextInt(23) === 0;
        $corridor->numSections = intdiv($direction === 'north' || $direction === 'south' ? $box[5] - $box[2] + 1 : $box[3] - $box[0] + 1, 5);
        return $corridor;
    }

    public static function restoreWith(array $piece): self
    {
        $corridor = new self($piece['box'], $piece['orientation'], $piece['depth'], $piece['kind']);
        [$corridor->hasRails, $corridor->spiderCorridor, $corridor->hasPlacedSpider, $corridor->numSections] = $piece['corridor'];
        return $corridor;
    }

    public function type(): string
    {
        return 'mineshaft_corridor';
    }

    public function data(): array
    {
        return parent::data() + ['corridor' => [$this->hasRails, $this->spiderCorridor, $this->hasPlacedSpider, $this->numSections]];
    }

    /** MineShaftCorridor.findCorridorSize: the longest of 2 to 4 sections that collides with nothing. */
    public static function find(array $pieces, RandomSource $random, int $x, int $y, int $z, string $direction): ?array
    {
        for ($sections = $random->nextInt(3) + 2; $sections > 0; --$sections) {
            $length = $sections * 5;
            $box = match ($direction) {
                'south' => [0, 0, 0, 2, 2, $length - 1],
                'west' => [-($length - 1), 0, 0, 0, 2, 2],
                'east' => [0, 0, 0, $length - 1, 2, 2],
                default => [0, 0, -($length - 1), 2, 2, 0],
            };
            $box = [$box[0] + $x, $box[1] + $y, $box[2] + $z, $box[3] + $x, $box[4] + $y, $box[5] + $z];
            if (!self::collides($pieces, $box)) {
                return $box;
            }
        }
        return null;
    }

    public function addChildren(MineshaftPiece $start, array &$pieces, RandomSource $random): void
    {
        $depth = $this->genDepth;
        $end = $random->nextInt(4);
        [$minX, $minY, $minZ, $maxX, , $maxZ] = $this->box;
        switch ($this->orientation) {
            case 'south':
                if ($end <= 1) {
                    self::generateAndAddPiece($start, $pieces, $random, $minX, $minY - 1 + $random->nextInt(3), $maxZ + 1, 'south', $depth);
                } elseif ($end === 2) {
                    self::generateAndAddPiece($start, $pieces, $random, $minX - 1, $minY - 1 + $random->nextInt(3), $maxZ - 3, 'west', $depth);
                } else {
                    self::generateAndAddPiece($start, $pieces, $random, $maxX + 1, $minY - 1 + $random->nextInt(3), $maxZ - 3, 'east', $depth);
                }
                break;
            case 'west':
                if ($end <= 1) {
                    self::generateAndAddPiece($start, $pieces, $random, $minX - 1, $minY - 1 + $random->nextInt(3), $minZ, 'west', $depth);
                } elseif ($end === 2) {
                    self::generateAndAddPiece($start, $pieces, $random, $minX, $minY - 1 + $random->nextInt(3), $minZ - 1, 'north', $depth);
                } else {
                    self::generateAndAddPiece($start, $pieces, $random, $minX, $minY - 1 + $random->nextInt(3), $maxZ + 1, 'south', $depth);
                }
                break;
            case 'east':
                if ($end <= 1) {
                    self::generateAndAddPiece($start, $pieces, $random, $maxX + 1, $minY - 1 + $random->nextInt(3), $minZ, 'east', $depth);
                } elseif ($end === 2) {
                    self::generateAndAddPiece($start, $pieces, $random, $maxX - 3, $minY - 1 + $random->nextInt(3), $minZ - 1, 'north', $depth);
                } else {
                    self::generateAndAddPiece($start, $pieces, $random, $maxX - 3, $minY - 1 + $random->nextInt(3), $maxZ + 1, 'south', $depth);
                }
                break;
            default:
                if ($end <= 1) {
                    self::generateAndAddPiece($start, $pieces, $random, $minX, $minY - 1 + $random->nextInt(3), $minZ - 1, 'north', $depth);
                } elseif ($end === 2) {
                    self::generateAndAddPiece($start, $pieces, $random, $minX - 1, $minY - 1 + $random->nextInt(3), $minZ, 'west', $depth);
                } else {
                    self::generateAndAddPiece($start, $pieces, $random, $maxX + 1, $minY - 1 + $random->nextInt(3), $minZ, 'east', $depth);
                }
        }
        if ($depth >= 8) {
            return;
        }
        [$minX, $minY, $minZ, $maxX, , $maxZ] = $this->box;
        if ($this->orientation !== 'north' && $this->orientation !== 'south') {
            for ($x = $minX + 3; $x + 3 <= $maxX; $x += 5) {
                $selection = $random->nextInt(5);
                if ($selection === 0) {
                    self::generateAndAddPiece($start, $pieces, $random, $x, $minY, $minZ - 1, 'north', $depth + 1);
                } elseif ($selection === 1) {
                    self::generateAndAddPiece($start, $pieces, $random, $x, $minY, $maxZ + 1, 'south', $depth + 1);
                }
            }
        } else {
            for ($z = $minZ + 3; $z + 3 <= $maxZ; $z += 5) {
                $selection = $random->nextInt(5);
                if ($selection === 0) {
                    self::generateAndAddPiece($start, $pieces, $random, $minX - 1, $minY, $z, 'west', $depth + 1);
                } elseif ($selection === 1) {
                    self::generateAndAddPiece($start, $pieces, $random, $maxX + 1, $minY, $z, 'east', $depth + 1);
                }
            }
        }
    }

    /** MineShaftCorridor.createChest: a rail with a chest minecart. */
    private function minecartChest(StructureContext $c, Region $r, array $b, RandomSource $random, int $x, int $y, int $z): void
    {
        [$wx, $wy, $wz] = $this->worldPos($x, $y, $z);
        if (!self::inside($b, $wx, $wy, $wz) || !$c->blocks->isAir($r->get($wx, $wy, $wz)) || $c->blocks->isAir($r->get($wx, $wy - 1, $wz))) {
            return;
        }
        $this->placeBlock($c, $r, $c->state('rail', ['shape' => $random->nextBoolean() ? 'north_south' : 'east_west']), $x, $y, $z, $b);
        // The minecart's loot seed; the minecart entity itself is not generated yet.
        $random->nextLong();
    }

    public function postProcess(StructureContext $c, Region $r, RandomSource $random, array $b): void
    {
        if ($this->isInInvalidLocation($c, $r, $b)) {
            return;
        }
        $air = $c->state('cave_air');
        $length = $this->numSections * 5 - 1;
        $planks = $this->planks($c);
        $this->generateBox($c, $r, $b, 0, 0, 0, 2, 1, $length, $air, $air, false);
        $this->generateMaybeBox($c, $r, $b, $random, F32::r(0.8), 0, 2, 0, 2, 2, $length, $air, $air, false, false);
        if ($this->spiderCorridor) {
            $this->generateMaybeBox($c, $r, $b, $random, F32::r(0.6), 0, 0, 0, 2, 1, $length, $c->state('cobweb'), $air, false, true);
        }
        for ($section = 0; $section < $this->numSections; ++$section) {
            $z = 2 + $section * 5;
            $this->placeSupport($c, $r, $b, 0, 0, $z, 2, 2, $random);
            foreach ([[0.1, 0, -1], [0.1, 2, -1], [0.1, 0, 1], [0.1, 2, 1], [0.05, 0, -2], [0.05, 2, -2], [0.05, 0, 2], [0.05, 2, 2]] as [$chance, $x, $dz]) {
                $this->maybePlaceCobWeb($c, $r, $b, $random, F32::r($chance), $x, 2, $z + $dz);
            }
            if ($random->nextInt(100) === 0) {
                $this->minecartChest($c, $r, $b, $random, 2, 0, $z - 1);
            }
            if ($random->nextInt(100) === 0) {
                $this->minecartChest($c, $r, $b, $random, 0, 0, $z + 1);
            }
            if ($this->spiderCorridor && !$this->hasPlacedSpider) {
                $newZ = $z - 1 + $random->nextInt(3);
                [$wx, $wy, $wz] = $this->worldPos(1, 0, $newZ);
                if (self::inside($b, $wx, $wy, $wz) && $this->isInterior($r, 1, 0, $newZ, $b)) {
                    $this->hasPlacedSpider = true;
                    $r->set($wx, $wy, $wz, $c->state('spawner'));
                    $c->blockEntities["$wx,$wy,$wz"] = ['id' => 'mob_spawner', 'entity' => 'minecraft:cave_spider'];
                }
            }
        }
        for ($x = 0; $x <= 2; ++$x) {
            for ($z = 0; $z <= $length; ++$z) {
                $this->setPlanksBlock($c, $r, $b, $planks, $x, -1, $z);
            }
        }
        $this->placeDoubleLowerOrUpperSupport($c, $r, $b, 0, -1, 2);
        if ($this->numSections > 1) {
            $this->placeDoubleLowerOrUpperSupport($c, $r, $b, 0, -1, $length - 2);
        }
        if ($this->hasRails) {
            $rail = $c->state('rail', ['shape' => 'north_south']);
            for ($z = 0; $z <= $length; ++$z) {
                $floor = $this->getBlock($r, 1, -1, $z, $b);
                if (!$c->blocks->isAir($floor) && self::solidRender($c, $floor)) {
                    $this->maybeGenerateBlock($c, $r, $b, $random, $this->isInterior($r, 1, 0, $z, $b) ? F32::r(0.7) : F32::r(0.9), 1, 0, $z, $rail);
                }
            }
        }
    }

    private function placeDoubleLowerOrUpperSupport(StructureContext $c, Region $r, array $b, int $x, int $y, int $z): void
    {
        $planks = $c->blocks->name($this->planks($c));
        if ($c->blocks->name($this->getBlock($r, $x, $y, $z, $b)) === $planks) {
            $this->fillPillarDownOrChainUp($c, $r, $b, $this->wood($c), $x, $y, $z);
        }
        if ($c->blocks->name($this->getBlock($r, $x + 2, $y, $z, $b)) === $planks) {
            $this->fillPillarDownOrChainUp($c, $r, $b, $this->wood($c), $x + 2, $y, $z);
        }
    }

    /** MineShaftCorridor.fillColumnDown: a column only where it would stand on a sturdy block. */
    protected function fillColumnDown(StructureContext $context, Region $region, int $state, int $x, int $startY, int $z, array $chunkBox): void
    {
        [$wx, $wy, $wz] = $this->worldPos($x, $startY, $z);
        if (!self::inside($chunkBox, $wx, $wy, $wz)) {
            return;
        }
        $y = $wy;
        while (self::replaceableByStructures($context, $region->get($wx, $y, $wz)) && $y > $region->minY + 1) {
            --$y;
        }
        if ($context->blocks->sturdy($region->get($wx, $y, $wz), 1)) {
            while ($y < $wy) {
                ++$y;
                $region->set($wx, $y, $wz, $state);
            }
        }
    }

    /** MineShaftCorridor.fillPillarDownOrChainUp: a pillar to the ground within 20, or a chain from the ceiling within 50. */
    private function fillPillarDownOrChainUp(StructureContext $c, Region $r, array $b, int $pillar, int $x, int $y, int $z): void
    {
        [$wx, $worldY, $wz] = $this->worldPos($x, $y, $z);
        if (!self::inside($b, $wx, $worldY, $wz)) {
            return;
        }
        $checkBelow = true;
        $checkAbove = true;
        for ($distance = 1; $checkBelow || $checkAbove; ++$distance) {
            if ($checkBelow) {
                $py = $worldY - $distance;
                $below = $r->get($wx, $py, $wz);
                $emptyBelow = self::replaceableByStructures($c, $below) && $c->blocks->name($below) !== 'lava';
                if (!$emptyBelow && $c->blocks->sturdy($below, 1)) {
                    for ($pillarY = $worldY - $distance + 1; $pillarY < $worldY; ++$pillarY) {
                        $r->set($wx, $pillarY, $wz, $pillar);
                    }
                    return;
                }
                $checkBelow = $distance <= 20 && $emptyBelow && $py > $r->minY + 1;
            }
            if ($checkAbove) {
                $py = $worldY + $distance;
                $above = $r->get($wx, $py, $wz);
                $emptyAbove = self::replaceableByStructures($c, $above);
                // Block.canSupportCenter(DOWN) and not a FallingBlock.
                if (!$emptyAbove && $c->blocks->sturdyCenter($above, 0) && !$c->survival->isA($above, 'FallingBlock')) {
                    $r->set($wx, $worldY + 1, $wz, $this->fence($c));
                    for ($chainY = $worldY + 2; $chainY < $worldY + $distance; ++$chainY) {
                        $r->set($wx, $chainY, $wz, $c->state('iron_chain'));
                    }
                    return;
                }
                $checkAbove = $distance <= 50 && $emptyAbove && $py < $r->maxY();
            }
        }
    }

    private function placeSupport(StructureContext $c, Region $r, array $b, int $x0, int $y0, int $z, int $y1, int $x1, RandomSource $random): void
    {
        if (!$this->isSupportingBox($r, $b, $x0, $x1, $y1, $z, $c)) {
            return;
        }
        $planks = $this->planks($c);
        $fence = $this->fence($c);
        $air = $c->state('cave_air');
        $this->generateBox($c, $r, $b, $x0, $y0, $z, $x0, $y1 - 1, $z, $c->with($fence, 'west', 'true'), $air, false);
        $this->generateBox($c, $r, $b, $x1, $y0, $z, $x1, $y1 - 1, $z, $c->with($fence, 'east', 'true'), $air, false);
        if ($random->nextInt(4) === 0) {
            $this->generateBox($c, $r, $b, $x0, $y1, $z, $x0, $y1, $z, $planks, $air, false);
            $this->generateBox($c, $r, $b, $x1, $y1, $z, $x1, $y1, $z, $planks, $air, false);
        } else {
            $this->generateBox($c, $r, $b, $x0, $y1, $z, $x1, $y1, $z, $planks, $air, false);
            $this->maybeGenerateBlock($c, $r, $b, $random, F32::r(0.05), $x0 + 1, $y1, $z - 1, $c->state('wall_torch', ['facing' => 'south']));
            $this->maybeGenerateBlock($c, $r, $b, $random, F32::r(0.05), $x0 + 1, $y1, $z + 1, $c->state('wall_torch', ['facing' => 'north']));
        }
    }

    private function maybePlaceCobWeb(StructureContext $c, Region $r, array $b, RandomSource $random, float $probability, int $x, int $y, int $z): void
    {
        if ($this->isInterior($r, $x, $y, $z, $b) && $random->nextFloat() < $probability && $this->hasSturdyNeighbours($c, $r, $b, $x, $y, $z, 2)) {
            $this->placeBlock($c, $r, $c->state('cobweb'), $x, $y, $z, $b);
        }
    }

    private function hasSturdyNeighbours(StructureContext $c, Region $r, array $b, int $x, int $y, int $z, int $count): bool
    {
        [$wx, $wy, $wz] = $this->worldPos($x, $y, $z);
        $sturdy = 0;
        foreach (self::NEIGHBOURS as [$dx, $dy, $dz, $face]) {
            if (self::inside($b, $wx + $dx, $wy + $dy, $wz + $dz) && $c->blocks->sturdy($r->get($wx + $dx, $wy + $dy, $wz + $dz), $face) && ++$sturdy >= $count) {
                return true;
            }
        }
        return false;
    }
}
