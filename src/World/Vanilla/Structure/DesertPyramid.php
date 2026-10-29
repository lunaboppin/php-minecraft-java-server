<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\PositionalRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/**
 * DesertPyramidPiece and DesertPyramidStructure.afterPlace: the pyramid, its trapped treasure room
 * with four chests, and the cellar whose sand hides suspicious sand.
 */
final class DesertPyramid extends ScatteredPiece
{
    /** @var list<bool> chests placed, by the 2D data value of their side */
    public array $hasPlacedChest = [false, false, false, false];

    /** @var array<string, array{0: int, 1: int, 2: int}> world positions where the cellar may hide suspicious sand */
    private array $sand = [];

    /** @var array{0: int, 1: int, 2: int} */
    private array $collapsedRoof = [0, 0, 0];

    public static function create(RandomSource $random, int $west, int $north): self
    {
        $direction = self::HORIZONTAL[$random->nextInt(4)];
        return new self(self::makeBox($west, 64, $north, $direction, 21, 15, 21), $direction, 21, 15, 21);
    }

    public static function restoreWith(array $piece): self
    {
        $pyramid = new self($piece['box'], $piece['orientation'], $piece['width'], $piece['height'], $piece['depth'], $piece['hpos']);
        $pyramid->hasPlacedChest = $piece['chests'];
        return $pyramid;
    }

    public function type(): string
    {
        return 'desert_pyramid';
    }

    public function data(): array
    {
        return parent::data() + ['chests' => $this->hasPlacedChest];
    }

    public function postProcess(StructureContext $c, Region $r, RandomSource $random, array $b): void
    {
        if (!$this->updateHeightPositionToLowestGroundHeight($r, -$random->nextInt(3))) {
            return;
        }
        $this->generateBox(
            $c,
            $r,
            $b,
            0,
            -4,
            0,
            $this->width - 1,
            0,
            $this->depth - 1,
            $c->state('sandstone'),
            $c->state('sandstone'),
            false
        );

        for ($pos = 1; $pos <= 9; ++$pos) {
            $this->generateBox(
                $c,
                $r,
                $b,
                $pos,
                $pos,
                $pos,
                $this->width - 1 - $pos,
                $pos,
                $this->depth - 1 - $pos,
                $c->state('sandstone'),
                $c->state('sandstone'),
                false
            );
            $this->generateBox(
                $c,
                $r,
                $b,
                $pos + 1,
                $pos,
                $pos + 1,
                $this->width - 2 - $pos,
                $pos,
                $this->depth - 2 - $pos,
                $c->state('air'),
                $c->state('air'),
                false
            );
        }

        for ($x = 0; $x < $this->width; ++$x) {
            for ($z = 0; $z < $this->depth; ++$z) {
                $startY = -5;
                $this->fillColumnDown($c, $r, $c->state('sandstone'), $x, -5, $z, $b);
            }
        }

        $northStairs = $c->with($c->state('sandstone_stairs'), 'facing', 'north');
        $southStairs = $c->with($c->state('sandstone_stairs'), 'facing', 'south');
        $eastStairs = $c->with($c->state('sandstone_stairs'), 'facing', 'east');
        $westStairs = $c->with($c->state('sandstone_stairs'), 'facing', 'west');
        $this->generateBox($c, $r, $b, 0, 0, 0, 4, 9, 4, $c->state('sandstone'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 1, 10, 1, 3, 10, 3, $c->state('sandstone'), $c->state('sandstone'), false);
        $this->placeBlock($c, $r, $northStairs, 2, 10, 0, $b);
        $this->placeBlock($c, $r, $southStairs, 2, 10, 4, $b);
        $this->placeBlock($c, $r, $eastStairs, 0, 10, 2, $b);
        $this->placeBlock($c, $r, $westStairs, 4, 10, 2, $b);
        $this->generateBox(
            $c,
            $r,
            $b,
            $this->width - 5,
            0,
            0,
            $this->width - 1,
            9,
            4,
            $c->state('sandstone'),
            $c->state('air'),
            false
        );
        $this->generateBox(
            $c,
            $r,
            $b,
            $this->width - 4,
            10,
            1,
            $this->width - 2,
            10,
            3,
            $c->state('sandstone'),
            $c->state('sandstone'),
            false
        );
        $this->placeBlock($c, $r, $northStairs, $this->width - 3, 10, 0, $b);
        $this->placeBlock($c, $r, $southStairs, $this->width - 3, 10, 4, $b);
        $this->placeBlock($c, $r, $eastStairs, $this->width - 5, 10, 2, $b);
        $this->placeBlock($c, $r, $westStairs, $this->width - 1, 10, 2, $b);
        $this->generateBox($c, $r, $b, 8, 0, 0, 12, 4, 4, $c->state('sandstone'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 9, 1, 0, 11, 3, 4, $c->state('air'), $c->state('air'), false);
        $this->placeBlock($c, $r, $c->state('cut_sandstone'), 9, 1, 1, $b);
        $this->placeBlock($c, $r, $c->state('cut_sandstone'), 9, 2, 1, $b);
        $this->placeBlock($c, $r, $c->state('cut_sandstone'), 9, 3, 1, $b);
        $this->placeBlock($c, $r, $c->state('cut_sandstone'), 10, 3, 1, $b);
        $this->placeBlock($c, $r, $c->state('cut_sandstone'), 11, 3, 1, $b);
        $this->placeBlock($c, $r, $c->state('cut_sandstone'), 11, 2, 1, $b);
        $this->placeBlock($c, $r, $c->state('cut_sandstone'), 11, 1, 1, $b);
        $this->generateBox($c, $r, $b, 4, 1, 1, 8, 3, 3, $c->state('sandstone'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 4, 1, 2, 8, 2, 2, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 12, 1, 1, 16, 3, 3, $c->state('sandstone'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 12, 1, 2, 16, 2, 2, $c->state('air'), $c->state('air'), false);
        $this->generateBox(
            $c,
            $r,
            $b,
            5,
            4,
            5,
            $this->width - 6,
            4,
            $this->depth - 6,
            $c->state('sandstone'),
            $c->state('sandstone'),
            false
        );
        $this->generateBox($c, $r, $b, 9, 4, 9, 11, 4, 11, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, 8, 1, 8, 8, 3, 8, $c->state('cut_sandstone'), $c->state('cut_sandstone'), false);
        $this->generateBox($c, $r, $b, 12, 1, 8, 12, 3, 8, $c->state('cut_sandstone'), $c->state('cut_sandstone'), false);
        $this->generateBox($c, $r, $b, 8, 1, 12, 8, 3, 12, $c->state('cut_sandstone'), $c->state('cut_sandstone'), false);
        $this->generateBox($c, $r, $b, 12, 1, 12, 12, 3, 12, $c->state('cut_sandstone'), $c->state('cut_sandstone'), false);
        $this->generateBox($c, $r, $b, 1, 1, 5, 4, 4, 11, $c->state('sandstone'), $c->state('sandstone'), false);
        $this->generateBox(
            $c,
            $r,
            $b,
            $this->width - 5,
            1,
            5,
            $this->width - 2,
            4,
            11,
            $c->state('sandstone'),
            $c->state('sandstone'),
            false
        );
        $this->generateBox($c, $r, $b, 6, 7, 9, 6, 7, 11, $c->state('sandstone'), $c->state('sandstone'), false);
        $this->generateBox(
            $c,
            $r,
            $b,
            $this->width - 7,
            7,
            9,
            $this->width - 7,
            7,
            11,
            $c->state('sandstone'),
            $c->state('sandstone'),
            false
        );
        $this->generateBox($c, $r, $b, 5, 5, 9, 5, 7, 11, $c->state('cut_sandstone'), $c->state('cut_sandstone'), false);
        $this->generateBox(
            $c,
            $r,
            $b,
            $this->width - 6,
            5,
            9,
            $this->width - 6,
            7,
            11,
            $c->state('cut_sandstone'),
            $c->state('cut_sandstone'),
            false
        );
        $this->placeBlock($c, $r, $c->state('air'), 5, 5, 10, $b);
        $this->placeBlock($c, $r, $c->state('air'), 5, 6, 10, $b);
        $this->placeBlock($c, $r, $c->state('air'), 6, 6, 10, $b);
        $this->placeBlock($c, $r, $c->state('air'), $this->width - 6, 5, 10, $b);
        $this->placeBlock($c, $r, $c->state('air'), $this->width - 6, 6, 10, $b);
        $this->placeBlock($c, $r, $c->state('air'), $this->width - 7, 6, 10, $b);
        $this->generateBox($c, $r, $b, 2, 4, 4, 2, 6, 4, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, $this->width - 3, 4, 4, $this->width - 3, 6, 4, $c->state('air'), $c->state('air'), false);
        $this->placeBlock($c, $r, $northStairs, 2, 4, 5, $b);
        $this->placeBlock($c, $r, $northStairs, 2, 3, 4, $b);
        $this->placeBlock($c, $r, $northStairs, $this->width - 3, 4, 5, $b);
        $this->placeBlock($c, $r, $northStairs, $this->width - 3, 3, 4, $b);
        $this->generateBox($c, $r, $b, 1, 1, 3, 2, 2, 3, $c->state('sandstone'), $c->state('sandstone'), false);
        $this->generateBox(
            $c,
            $r,
            $b,
            $this->width - 3,
            1,
            3,
            $this->width - 2,
            2,
            3,
            $c->state('sandstone'),
            $c->state('sandstone'),
            false
        );
        $this->placeBlock($c, $r, $c->state('sandstone'), 1, 1, 2, $b);
        $this->placeBlock($c, $r, $c->state('sandstone'), $this->width - 2, 1, 2, $b);
        $this->placeBlock($c, $r, $c->state('sandstone_slab'), 1, 2, 2, $b);
        $this->placeBlock($c, $r, $c->state('sandstone_slab'), $this->width - 2, 2, 2, $b);
        $this->placeBlock($c, $r, $westStairs, 2, 1, 2, $b);
        $this->placeBlock($c, $r, $eastStairs, $this->width - 3, 1, 2, $b);
        $this->generateBox($c, $r, $b, 4, 3, 5, 4, 3, 17, $c->state('sandstone'), $c->state('sandstone'), false);
        $this->generateBox(
            $c,
            $r,
            $b,
            $this->width - 5,
            3,
            5,
            $this->width - 5,
            3,
            17,
            $c->state('sandstone'),
            $c->state('sandstone'),
            false
        );
        $this->generateBox($c, $r, $b, 3, 1, 5, 4, 2, 16, $c->state('air'), $c->state('air'), false);
        $this->generateBox($c, $r, $b, $this->width - 6, 1, 5, $this->width - 5, 2, 16, $c->state('air'), $c->state('air'), false);

        for ($z = 5; $z <= 17; $z += 2) {
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), 4, 1, $z, $b);
            $this->placeBlock($c, $r, $c->state('chiseled_sandstone'), 4, 2, $z, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $this->width - 5, 1, $z, $b);
            $this->placeBlock($c, $r, $c->state('chiseled_sandstone'), $this->width - 5, 2, $z, $b);
        }

        $this->placeBlock($c, $r, $c->state('orange_terracotta'), 10, 0, 7, $b);
        $this->placeBlock($c, $r, $c->state('orange_terracotta'), 10, 0, 8, $b);
        $this->placeBlock($c, $r, $c->state('orange_terracotta'), 9, 0, 9, $b);
        $this->placeBlock($c, $r, $c->state('orange_terracotta'), 11, 0, 9, $b);
        $this->placeBlock($c, $r, $c->state('orange_terracotta'), 8, 0, 10, $b);
        $this->placeBlock($c, $r, $c->state('orange_terracotta'), 12, 0, 10, $b);
        $this->placeBlock($c, $r, $c->state('orange_terracotta'), 7, 0, 10, $b);
        $this->placeBlock($c, $r, $c->state('orange_terracotta'), 13, 0, 10, $b);
        $this->placeBlock($c, $r, $c->state('orange_terracotta'), 9, 0, 11, $b);
        $this->placeBlock($c, $r, $c->state('orange_terracotta'), 11, 0, 11, $b);
        $this->placeBlock($c, $r, $c->state('orange_terracotta'), 10, 0, 12, $b);
        $this->placeBlock($c, $r, $c->state('orange_terracotta'), 10, 0, 13, $b);
        $this->placeBlock($c, $r, $c->state('blue_terracotta'), 10, 0, 10, $b);

        for ($x = 0; $x <= $this->width - 1; $x += $this->width - 1) {
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x, 2, 1, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x, 2, 2, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x, 2, 3, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x, 3, 1, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x, 3, 2, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x, 3, 3, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x, 4, 1, $b);
            $this->placeBlock($c, $r, $c->state('chiseled_sandstone'), $x, 4, 2, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x, 4, 3, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x, 5, 1, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x, 5, 2, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x, 5, 3, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x, 6, 1, $b);
            $this->placeBlock($c, $r, $c->state('chiseled_sandstone'), $x, 6, 2, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x, 6, 3, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x, 7, 1, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x, 7, 2, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x, 7, 3, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x, 8, 1, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x, 8, 2, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x, 8, 3, $b);
        }

        for ($x = 2; $x <= $this->width - 3; $x += $this->width - 3 - 2) {
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x - 1, 2, 0, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x, 2, 0, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x + 1, 2, 0, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x - 1, 3, 0, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x, 3, 0, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x + 1, 3, 0, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x - 1, 4, 0, $b);
            $this->placeBlock($c, $r, $c->state('chiseled_sandstone'), $x, 4, 0, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x + 1, 4, 0, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x - 1, 5, 0, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x, 5, 0, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x + 1, 5, 0, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x - 1, 6, 0, $b);
            $this->placeBlock($c, $r, $c->state('chiseled_sandstone'), $x, 6, 0, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x + 1, 6, 0, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x - 1, 7, 0, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x, 7, 0, $b);
            $this->placeBlock($c, $r, $c->state('orange_terracotta'), $x + 1, 7, 0, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x - 1, 8, 0, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x, 8, 0, $b);
            $this->placeBlock($c, $r, $c->state('cut_sandstone'), $x + 1, 8, 0, $b);
        }

        $this->generateBox($c, $r, $b, 8, 4, 0, 12, 6, 0, $c->state('cut_sandstone'), $c->state('cut_sandstone'), false);
        $this->placeBlock($c, $r, $c->state('air'), 8, 6, 0, $b);
        $this->placeBlock($c, $r, $c->state('air'), 12, 6, 0, $b);
        $this->placeBlock($c, $r, $c->state('orange_terracotta'), 9, 5, 0, $b);
        $this->placeBlock($c, $r, $c->state('chiseled_sandstone'), 10, 5, 0, $b);
        $this->placeBlock($c, $r, $c->state('orange_terracotta'), 11, 5, 0, $b);
        $this->generateBox($c, $r, $b, 8, -14, 8, 12, -11, 12, $c->state('cut_sandstone'), $c->state('cut_sandstone'), false);
        $this->generateBox(
            $c,
            $r,
            $b,
            8,
            -10,
            8,
            12,
            -10,
            12,
            $c->state('chiseled_sandstone'),
            $c->state('chiseled_sandstone'),
            false
        );
        $this->generateBox($c, $r, $b, 8, -9, 8, 12, -9, 12, $c->state('cut_sandstone'), $c->state('cut_sandstone'), false);
        $this->generateBox($c, $r, $b, 8, -8, 8, 12, -1, 12, $c->state('sandstone'), $c->state('sandstone'), false);
        $this->generateBox($c, $r, $b, 9, -11, 9, 11, -1, 11, $c->state('air'), $c->state('air'), false);
        $this->placeBlock($c, $r, $c->state('stone_pressure_plate'), 10, -11, 10, $b);
        $this->generateBox($c, $r, $b, 9, -13, 9, 11, -13, 11, $c->state('tnt'), $c->state('air'), false);
        $this->placeBlock($c, $r, $c->state('air'), 8, -11, 10, $b);
        $this->placeBlock($c, $r, $c->state('air'), 8, -10, 10, $b);
        $this->placeBlock($c, $r, $c->state('chiseled_sandstone'), 7, -10, 10, $b);
        $this->placeBlock($c, $r, $c->state('cut_sandstone'), 7, -11, 10, $b);
        $this->placeBlock($c, $r, $c->state('air'), 12, -11, 10, $b);
        $this->placeBlock($c, $r, $c->state('air'), 12, -10, 10, $b);
        $this->placeBlock($c, $r, $c->state('chiseled_sandstone'), 13, -10, 10, $b);
        $this->placeBlock($c, $r, $c->state('cut_sandstone'), 13, -11, 10, $b);
        $this->placeBlock($c, $r, $c->state('air'), 10, -11, 8, $b);
        $this->placeBlock($c, $r, $c->state('air'), 10, -10, 8, $b);
        $this->placeBlock($c, $r, $c->state('chiseled_sandstone'), 10, -10, 7, $b);
        $this->placeBlock($c, $r, $c->state('cut_sandstone'), 10, -11, 7, $b);
        $this->placeBlock($c, $r, $c->state('air'), 10, -11, 12, $b);
        $this->placeBlock($c, $r, $c->state('air'), 10, -10, 12, $b);
        $this->placeBlock($c, $r, $c->state('chiseled_sandstone'), 10, -10, 13, $b);
        $this->placeBlock($c, $r, $c->state('cut_sandstone'), 10, -11, 13, $b);

        // Plane.HORIZONTAL in its 2D data value order: south, west, north, east.
        foreach ([[0, 0, 1], [1, -1, 0], [2, 0, -1], [3, 1, 0]] as [$index, $dx, $dz]) {
            if (!$this->hasPlacedChest[$index]) {
                $this->hasPlacedChest[$index] = $this->createChest($c, $r, $b, $random, 10 + $dx * 2, -11, 10 + $dz * 2, 'desert_pyramid');
            }
        }
        $this->addCellar($c, $r, $b);
    }

    private function addCellar(StructureContext $c, Region $r, array $b): void
    {

        $x = 16;
        $y = -4;
        $z = 13;
        $sandStoneStairs = $c->state('sandstone_stairs');
        $this->placeBlock($c, $r, $c->transform->rotate($sandStoneStairs, 3), 13, -1, 17, $b);
        $this->placeBlock($c, $r, $c->transform->rotate($sandStoneStairs, 3), 14, -2, 17, $b);
        $this->placeBlock($c, $r, $c->transform->rotate($sandStoneStairs, 3), 15, -3, 17, $b);
        $sand = $c->state('sand');
        $sandStone = $c->state('sandstone');
        $variant = $c->regionRandom->nextBoolean();
        $this->placeBlock($c, $r, $sand, $x - 4, $y + 4, $z + 4, $b);
        $this->placeBlock($c, $r, $sand, $x - 3, $y + 4, $z + 4, $b);
        $this->placeBlock($c, $r, $sand, $x - 2, $y + 4, $z + 4, $b);
        $this->placeBlock($c, $r, $sand, $x - 1, $y + 4, $z + 4, $b);
        $this->placeBlock($c, $r, $sand, $x, $y + 4, $z + 4, $b);
        $this->placeBlock($c, $r, $sand, $x - 2, $y + 3, $z + 4, $b);
        $this->placeBlock($c, $r, $variant ? $sand : $sandStone, $x - 1, $y + 3, $z + 4, $b);
        $this->placeBlock($c, $r, !$variant ? $sand : $sandStone, $x, $y + 3, $z + 4, $b);
        $this->placeBlock($c, $r, $sand, $x - 1, $y + 2, $z + 4, $b);
        $this->placeBlock($c, $r, $sandStone, $x, $y + 2, $z + 4, $b);
        $this->placeBlock($c, $r, $sand, $x, $y + 1, $z + 4, $b);


        $x = 16;
        $y = -4;
        $z = 13;
        $cutSandStone = $c->state('cut_sandstone');
        $hieroglyphsSandStone = $c->state('chiseled_sandstone');
        $this->generateBox($c, $r, $b, $x - 3, $y + 1, $z - 3, $x - 3, $y + 1, $z + 2, $cutSandStone, $cutSandStone, true);
        $this->generateBox($c, $r, $b, $x + 3, $y + 1, $z - 3, $x + 3, $y + 1, $z + 2, $cutSandStone, $cutSandStone, true);
        $this->generateBox($c, $r, $b, $x - 3, $y + 1, $z - 3, $x + 3, $y + 1, $z - 2, $cutSandStone, $cutSandStone, true);
        $this->generateBox($c, $r, $b, $x - 3, $y + 1, $z + 3, $x + 3, $y + 1, $z + 3, $cutSandStone, $cutSandStone, true);
        $this->generateBox($c, $r, $b, $x - 3, $y + 2, $z - 3, $x - 3, $y + 2, $z + 2, $hieroglyphsSandStone, $hieroglyphsSandStone, true);
        $this->generateBox($c, $r, $b, $x + 3, $y + 2, $z - 3, $x + 3, $y + 2, $z + 2, $hieroglyphsSandStone, $hieroglyphsSandStone, true);
        $this->generateBox($c, $r, $b, $x - 3, $y + 2, $z - 3, $x + 3, $y + 2, $z - 2, $hieroglyphsSandStone, $hieroglyphsSandStone, true);
        $this->generateBox($c, $r, $b, $x - 3, $y + 2, $z + 3, $x + 3, $y + 2, $z + 3, $hieroglyphsSandStone, $hieroglyphsSandStone, true);
        $this->generateBox($c, $r, $b, $x - 3, -1, $z - 3, $x - 3, -1, $z + 2, $cutSandStone, $cutSandStone, true);
        $this->generateBox($c, $r, $b, $x + 3, -1, $z - 3, $x + 3, -1, $z + 2, $cutSandStone, $cutSandStone, true);
        $this->generateBox($c, $r, $b, $x - 3, -1, $z - 3, $x + 3, -1, $z - 2, $cutSandStone, $cutSandStone, true);
        $this->generateBox($c, $r, $b, $x - 3, -1, $z + 3, $x + 3, -1, $z + 3, $cutSandStone, $cutSandStone, true);
        $this->placeSandBox($x - 2, $y + 1, $z - 2, $x + 2, $y + 3, $z + 2);
        $this->placeCollapsedRoof($c, $r, $b, $x - 2, $y + 4, $z - 2, $x + 2, $z + 2);
        $orangeTeracotta = $c->state('orange_terracotta');
        $blueTeracotta = $c->state('blue_terracotta');
        $this->placeBlock($c, $r, $blueTeracotta, $x, $y, $z, $b);
        $this->placeBlock($c, $r, $orangeTeracotta, $x + 1, $y, $z - 1, $b);
        $this->placeBlock($c, $r, $orangeTeracotta, $x + 1, $y, $z + 1, $b);
        $this->placeBlock($c, $r, $orangeTeracotta, $x - 1, $y, $z - 1, $b);
        $this->placeBlock($c, $r, $orangeTeracotta, $x - 1, $y, $z + 1, $b);
        $this->placeBlock($c, $r, $orangeTeracotta, $x + 2, $y, $z, $b);
        $this->placeBlock($c, $r, $orangeTeracotta, $x - 2, $y, $z, $b);
        $this->placeBlock($c, $r, $orangeTeracotta, $x, $y, $z + 2, $b);
        $this->placeBlock($c, $r, $orangeTeracotta, $x, $y, $z - 2, $b);
        $this->placeBlock($c, $r, $orangeTeracotta, $x + 3, $y, $z, $b);
        $this->placeSand($x + 3, $y + 1, $z);
        $this->placeSand($x + 3, $y + 2, $z);
        $this->placeBlock($c, $r, $cutSandStone, $x + 4, $y + 1, $z, $b);
        $this->placeBlock($c, $r, $hieroglyphsSandStone, $x + 4, $y + 2, $z, $b);
        $this->placeBlock($c, $r, $orangeTeracotta, $x - 3, $y, $z, $b);
        $this->placeSand($x - 3, $y + 1, $z);
        $this->placeSand($x - 3, $y + 2, $z);
        $this->placeBlock($c, $r, $cutSandStone, $x - 4, $y + 1, $z, $b);
        $this->placeBlock($c, $r, $hieroglyphsSandStone, $x - 4, $y + 2, $z, $b);
        $this->placeBlock($c, $r, $orangeTeracotta, $x, $y, $z + 3, $b);
        $this->placeSand($x, $y + 1, $z + 3);
        $this->placeSand($x, $y + 2, $z + 3);
        $this->placeBlock($c, $r, $orangeTeracotta, $x, $y, $z - 3, $b);
        $this->placeSand($x, $y + 1, $z - 3);
        $this->placeSand($x, $y + 2, $z - 3);
        $this->placeBlock($c, $r, $cutSandStone, $x, $y + 1, $z - 4, $b);
        $this->placeBlock($c, $r, $hieroglyphsSandStone, $x, -2, $z - 4, $b);

    }

    private function placeSand(int $x, int $y, int $z): void
    {
        $position = $this->worldPos($x, $y, $z);
        $this->sand[implode(',', $position)] = $position;
    }

    private function placeSandBox(int $x0, int $y0, int $z0, int $x1, int $y1, int $z1): void
    {
        for ($y = $y0; $y <= $y1; ++$y) {
            for ($x = $x0; $x <= $x1; ++$x) {
                for ($z = $z0; $z <= $z1; ++$z) {
                    $this->placeSand($x, $y, $z);
                }
            }
        }
    }

    private function placeCollapsedRoofPiece(StructureContext $c, Region $r, array $b, int $x, int $y, int $z): void
    {

        if ($c->regionRandom->nextFloat() < 0.33) {
            $blockState = $c->state('sandstone');
            $this->placeBlock($c, $r, $blockState, $x, $y, $z, $b);
        } else {
            $blockState = $c->state('sand');
            $this->placeBlock($c, $r, $blockState, $x, $y, $z, $b);
        }

    }

    private function placeCollapsedRoof(StructureContext $c, Region $r, array $b, int $x0, int $y0, int $z0, int $x1, int $z1): void
    {

        for ($x = $x0; $x <= $x1; ++$x) {
            for ($z = $z0; $z <= $z1; ++$z) {
                $this->placeCollapsedRoofPiece($c, $r, $b, $x, $y0, $z);
            }
        }

        $random = (new PositionalRandom(false, (new LegacyRandom($c->seed))->nextLong()))->at(...$this->worldPos($x0, $y0, $z0));
        $roofX = $random->nextInt($x1 - $x0 + 1) + $x0;
        $roofZ = $random->nextInt($z1 - $z0 + 1) + $z0;
        $this->collapsedRoof = [$this->worldX($roofX, $roofZ), $this->worldY($y0), $this->worldZ($roofX, $roofZ)];
    }

    /** DesertPyramidStructure.afterPlace: five to seven of the cellar's sand blocks become suspicious sand. */
    public function afterPlace(StructureContext $c, Region $r, array $b): void
    {
        $this->placeSuspiciousSand($c, $r, $b, $this->collapsedRoof);
        $positions = array_values($this->sand);
        // SortedArraySet over Vec3i.compareTo: by y, then z, then x.
        usort($positions, static fn (array $p, array $q): int => [$p[1], $p[2], $p[0]] <=> [$q[1], $q[2], $q[0]]);
        $box = $this->box;
        // BoundingBox.getCenter
        $random = (new PositionalRandom(false, (new LegacyRandom($c->seed))->nextLong()))->at($box[0] + intdiv($box[3] - $box[0] + 1, 2), $box[1] + intdiv($box[4] - $box[1] + 1, 2), $box[2] + intdiv($box[5] - $box[2] + 1, 2));
        for ($i = count($positions); $i > 1; --$i) {
            $j = $random->nextInt($i);
            [$positions[$i - 1], $positions[$j]] = [$positions[$j], $positions[$i - 1]];
        }
        $count = min(count($positions), 5 + $random->nextInt(3));
        foreach ($positions as $position) {
            if ($count > 0) {
                --$count;
                $this->placeSuspiciousSand($c, $r, $b, $position);
            } elseif (self::inside($b, ...$position)) {
                $r->set($position[0], $position[1], $position[2], $c->state('sand'));
            }
        }
    }

    private function placeSuspiciousSand(StructureContext $c, Region $r, array $b, array $position): void
    {
        if (self::inside($b, ...$position)) {
            $r->set($position[0], $position[1], $position[2], $c->state('suspicious_sand'));
            $c->blockEntities[implode(',', $position)] = ['id' => 'brushable_block', 'loot_table' => 'minecraft:archaeology/desert_pyramid', 'seed' => $c->blockPosLong(...$position)];
        }
    }
}
