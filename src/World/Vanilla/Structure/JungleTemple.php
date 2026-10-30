<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/** JungleTemplePiece: the mossy temple with its arrow traps, lever puzzle and two chests. */
final class JungleTemple extends ScatteredPiece
{
    private bool $placedMainChest = false;

    private bool $placedHiddenChest = false;

    private bool $placedTrap1 = false;

    private bool $placedTrap2 = false;

    public static function create(RandomSource $random, int $west, int $north): self
    {
        $direction = self::HORIZONTAL[$random->nextInt(4)];
        return new self(self::makeBox($west, 64, $north, $direction, 12, 10, 15), $direction, 12, 10, 15);
    }

    public static function restoreWith(array $piece): self
    {
        $temple = new self($piece['box'], $piece['orientation'], $piece['width'], $piece['height'], $piece['depth'], $piece['hpos']);
        [$temple->placedMainChest, $temple->placedHiddenChest, $temple->placedTrap1, $temple->placedTrap2] = $piece['placed'];
        return $temple;
    }

    public function type(): string
    {
        return 'jungle_temple';
    }

    public function data(): array
    {
        return parent::data() + ['placed' => [$this->placedMainChest, $this->placedHiddenChest, $this->placedTrap1, $this->placedTrap2]];
    }

    public function postProcess(StructureContext $c, Region $r, RandomSource $random, array $b): void
    {
        // JungleTemplePiece.MossStoneSelector
        $stone = static fn (RandomSource $random, bool $edge): int => $random->nextFloat() < 0.4 ? $c->state('cobblestone') : $c->state('mossy_cobblestone');
        if (!$this->updateAverageGroundHeight($r, $b, 0)) {
            return;
        }
        $this->generateSelectedBox($c, $r, $b, 0, -4, 0, $this->width - 1, 0, $this->depth - 1, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 2, 1, 2, 9, 2, 2, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 2, 1, 12, 9, 2, 12, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 2, 1, 3, 2, 2, 11, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 9, 1, 3, 9, 2, 11, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 1, 3, 1, 10, 6, 1, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 1, 3, 13, 10, 6, 13, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 1, 3, 2, 1, 6, 12, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 10, 3, 2, 10, 6, 12, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 2, 3, 2, 9, 3, 12, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 2, 6, 2, 9, 6, 12, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 3, 7, 3, 8, 7, 11, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 4, 8, 4, 7, 8, 10, false, $random, $stone);
        $this->generateAirBox($c, $r, $b, 3, 1, 3, 8, 2, 11);
        $this->generateAirBox($c, $r, $b, 4, 3, 6, 7, 3, 9);
        $this->generateAirBox($c, $r, $b, 2, 4, 2, 9, 5, 12);
        $this->generateAirBox($c, $r, $b, 4, 6, 5, 7, 6, 9);
        $this->generateAirBox($c, $r, $b, 5, 7, 6, 6, 7, 8);
        $this->generateAirBox($c, $r, $b, 5, 1, 2, 6, 2, 2);
        $this->generateAirBox($c, $r, $b, 5, 2, 12, 6, 2, 12);
        $this->generateAirBox($c, $r, $b, 5, 5, 1, 6, 5, 1);
        $this->generateAirBox($c, $r, $b, 5, 5, 13, 6, 5, 13);
        $this->placeBlock($c, $r, $c->state('air'), 1, 5, 5, $b);
        $this->placeBlock($c, $r, $c->state('air'), 10, 5, 5, $b);
        $this->placeBlock($c, $r, $c->state('air'), 1, 5, 9, $b);
        $this->placeBlock($c, $r, $c->state('air'), 10, 5, 9, $b);

        for ($z = 0; $z <= 14; $z += 14) {
            $this->generateSelectedBox($c, $r, $b, 2, 4, $z, 2, 5, $z, false, $random, $stone);
            $this->generateSelectedBox($c, $r, $b, 4, 4, $z, 4, 5, $z, false, $random, $stone);
            $this->generateSelectedBox($c, $r, $b, 7, 4, $z, 7, 5, $z, false, $random, $stone);
            $this->generateSelectedBox($c, $r, $b, 9, 4, $z, 9, 5, $z, false, $random, $stone);
        }

        $this->generateSelectedBox($c, $r, $b, 5, 6, 0, 6, 6, 0, false, $random, $stone);

        for ($x = 0; $x <= 11; $x += 11) {
            for ($z = 2; $z <= 12; $z += 2) {
                $this->generateSelectedBox($c, $r, $b, $x, 4, $z, $x, 5, $z, false, $random, $stone);
            }

            $this->generateSelectedBox($c, $r, $b, $x, 6, 5, $x, 6, 5, false, $random, $stone);
            $this->generateSelectedBox($c, $r, $b, $x, 6, 9, $x, 6, 9, false, $random, $stone);
        }

        $this->generateSelectedBox($c, $r, $b, 2, 7, 2, 2, 9, 2, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 9, 7, 2, 9, 9, 2, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 2, 7, 12, 2, 9, 12, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 9, 7, 12, 9, 9, 12, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 4, 9, 4, 4, 9, 4, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 7, 9, 4, 7, 9, 4, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 4, 9, 10, 4, 9, 10, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 7, 9, 10, 7, 9, 10, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 5, 9, 7, 6, 9, 7, false, $random, $stone);
        $eastStairs = $c->with($c->state('cobblestone_stairs'), 'facing', 'east');
        $westStairs = $c->with($c->state('cobblestone_stairs'), 'facing', 'west');
        $southStairs = $c->with($c->state('cobblestone_stairs'), 'facing', 'south');
        $northStairs = $c->with($c->state('cobblestone_stairs'), 'facing', 'north');
        $this->placeBlock($c, $r, $northStairs, 5, 9, 6, $b);
        $this->placeBlock($c, $r, $northStairs, 6, 9, 6, $b);
        $this->placeBlock($c, $r, $southStairs, 5, 9, 8, $b);
        $this->placeBlock($c, $r, $southStairs, 6, 9, 8, $b);
        $this->placeBlock($c, $r, $northStairs, 4, 0, 0, $b);
        $this->placeBlock($c, $r, $northStairs, 5, 0, 0, $b);
        $this->placeBlock($c, $r, $northStairs, 6, 0, 0, $b);
        $this->placeBlock($c, $r, $northStairs, 7, 0, 0, $b);
        $this->placeBlock($c, $r, $northStairs, 4, 1, 8, $b);
        $this->placeBlock($c, $r, $northStairs, 4, 2, 9, $b);
        $this->placeBlock($c, $r, $northStairs, 4, 3, 10, $b);
        $this->placeBlock($c, $r, $northStairs, 7, 1, 8, $b);
        $this->placeBlock($c, $r, $northStairs, 7, 2, 9, $b);
        $this->placeBlock($c, $r, $northStairs, 7, 3, 10, $b);
        $this->generateSelectedBox($c, $r, $b, 4, 1, 9, 4, 1, 9, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 7, 1, 9, 7, 1, 9, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 4, 1, 10, 7, 2, 10, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 5, 4, 5, 6, 4, 5, false, $random, $stone);
        $this->placeBlock($c, $r, $eastStairs, 4, 4, 5, $b);
        $this->placeBlock($c, $r, $westStairs, 7, 4, 5, $b);

        for ($i = 0; $i < 4; ++$i) {
            $this->placeBlock($c, $r, $southStairs, 5, 0 - $i, 6 + $i, $b);
            $this->placeBlock($c, $r, $southStairs, 6, 0 - $i, 6 + $i, $b);
            $this->generateAirBox($c, $r, $b, 5, 0 - $i, 7 + $i, 6, 0 - $i, 9 + $i);
        }

        $this->generateAirBox($c, $r, $b, 1, -3, 12, 10, -1, 13);
        $this->generateAirBox($c, $r, $b, 1, -3, 1, 3, -1, 13);
        $this->generateAirBox($c, $r, $b, 1, -3, 1, 9, -1, 5);

        for ($z = 1; $z <= 13; $z += 2) {
            $this->generateSelectedBox($c, $r, $b, 1, -3, $z, 1, -2, $z, false, $random, $stone);
        }

        for ($z = 2; $z <= 12; $z += 2) {
            $this->generateSelectedBox($c, $r, $b, 1, -1, $z, 3, -1, $z, false, $random, $stone);
        }

        $this->generateSelectedBox($c, $r, $b, 2, -2, 1, 5, -2, 1, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 7, -2, 1, 9, -2, 1, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 6, -3, 1, 6, -3, 1, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 6, -1, 1, 6, -1, 1, false, $random, $stone);
        $this->placeBlock(
            $c,
            $r,
            $c->with($c->with($c->state('tripwire_hook'), 'facing', 'east'), 'attached', 'true'),
            1,
            -3,
            8,
            $b
        );
        $this->placeBlock(
            $c,
            $r,
            $c->with($c->with($c->state('tripwire_hook'), 'facing', 'west'), 'attached', 'true'),
            4,
            -3,
            8,
            $b
        );
        $this->placeBlock(
            $c,
            $r,
            $c->with($c->with($c->with($c->state('tripwire'), 'east', 'true'), 'west', 'true'), 'attached', 'true'),
            2,
            -3,
            8,
            $b
        );
        $this->placeBlock(
            $c,
            $r,
            $c->with($c->with($c->with($c->state('tripwire'), 'east', 'true'), 'west', 'true'), 'attached', 'true'),
            3,
            -3,
            8,
            $b
        );
        $redstoneWireNS = $c->with($c->with($c->state('redstone_wire'), 'north', 'side'), 'south', 'side');
        $this->placeBlock($c, $r, $redstoneWireNS, 5, -3, 7, $b);
        $this->placeBlock($c, $r, $redstoneWireNS, 5, -3, 6, $b);
        $this->placeBlock($c, $r, $redstoneWireNS, 5, -3, 5, $b);
        $this->placeBlock($c, $r, $redstoneWireNS, 5, -3, 4, $b);
        $this->placeBlock($c, $r, $redstoneWireNS, 5, -3, 3, $b);
        $this->placeBlock($c, $r, $redstoneWireNS, 5, -3, 2, $b);
        $this->placeBlock(
            $c,
            $r,
            $c->with($c->with($c->state('redstone_wire'), 'north', 'side'), 'west', 'side'),
            5,
            -3,
            1,
            $b
        );
        $this->placeBlock(
            $c,
            $r,
            $c->with($c->with($c->state('redstone_wire'), 'east', 'side'), 'west', 'side'),
            4,
            -3,
            1,
            $b
        );
        $this->placeBlock($c, $r, $c->state('mossy_cobblestone'), 3, -3, 1, $b);
        if (!$this->placedTrap1) {
            $this->placedTrap1 = $this->createDispenser($c, $r, $b, $random, 3, -2, 1, 'north', 'jungle_temple');
        }

        $this->placeBlock($c, $r, $c->with($c->state('vine'), 'south', 'true'), 3, -2, 2, $b);
        $this->placeBlock(
            $c,
            $r,
            $c->with($c->with($c->state('tripwire_hook'), 'facing', 'north'), 'attached', 'true'),
            7,
            -3,
            1,
            $b
        );
        $this->placeBlock(
            $c,
            $r,
            $c->with($c->with($c->state('tripwire_hook'), 'facing', 'south'), 'attached', 'true'),
            7,
            -3,
            5,
            $b
        );
        $this->placeBlock(
            $c,
            $r,
            $c->with($c->with($c->with($c->state('tripwire'), 'north', 'true'), 'south', 'true'), 'attached', 'true'),
            7,
            -3,
            2,
            $b
        );
        $this->placeBlock(
            $c,
            $r,
            $c->with($c->with($c->with($c->state('tripwire'), 'north', 'true'), 'south', 'true'), 'attached', 'true'),
            7,
            -3,
            3,
            $b
        );
        $this->placeBlock(
            $c,
            $r,
            $c->with($c->with($c->with($c->state('tripwire'), 'north', 'true'), 'south', 'true'), 'attached', 'true'),
            7,
            -3,
            4,
            $b
        );
        $this->placeBlock(
            $c,
            $r,
            $c->with($c->with($c->state('redstone_wire'), 'east', 'side'), 'west', 'side'),
            8,
            -3,
            6,
            $b
        );
        $this->placeBlock(
            $c,
            $r,
            $c->with($c->with($c->state('redstone_wire'), 'west', 'side'), 'south', 'side'),
            9,
            -3,
            6,
            $b
        );
        $this->placeBlock(
            $c,
            $r,
            $c->with($c->with($c->state('redstone_wire'), 'north', 'side'), 'south', 'up'),
            9,
            -3,
            5,
            $b
        );
        $this->placeBlock($c, $r, $c->state('mossy_cobblestone'), 9, -3, 4, $b);
        $this->placeBlock($c, $r, $redstoneWireNS, 9, -2, 4, $b);
        if (!$this->placedTrap2) {
            $this->placedTrap2 = $this->createDispenser($c, $r, $b, $random, 9, -2, 3, 'west', 'jungle_temple');
        }

        $this->placeBlock($c, $r, $c->with($c->state('vine'), 'east', 'true'), 8, -1, 3, $b);
        $this->placeBlock($c, $r, $c->with($c->state('vine'), 'east', 'true'), 8, -2, 3, $b);
        if (!$this->placedMainChest) {
            $this->placedMainChest = $this->createChest($c, $r, $b, $random, 8, -3, 3, 'jungle_temple');
        }

        $this->placeBlock($c, $r, $c->state('mossy_cobblestone'), 9, -3, 2, $b);
        $this->placeBlock($c, $r, $c->state('mossy_cobblestone'), 8, -3, 1, $b);
        $this->placeBlock($c, $r, $c->state('mossy_cobblestone'), 4, -3, 5, $b);
        $this->placeBlock($c, $r, $c->state('mossy_cobblestone'), 5, -2, 5, $b);
        $this->placeBlock($c, $r, $c->state('mossy_cobblestone'), 5, -1, 5, $b);
        $this->placeBlock($c, $r, $c->state('mossy_cobblestone'), 6, -3, 5, $b);
        $this->placeBlock($c, $r, $c->state('mossy_cobblestone'), 7, -2, 5, $b);
        $this->placeBlock($c, $r, $c->state('mossy_cobblestone'), 7, -1, 5, $b);
        $this->placeBlock($c, $r, $c->state('mossy_cobblestone'), 8, -3, 5, $b);
        $this->generateSelectedBox($c, $r, $b, 9, -1, 1, 9, -1, 5, false, $random, $stone);
        $this->generateAirBox($c, $r, $b, 8, -3, 8, 10, -1, 10);
        $this->placeBlock($c, $r, $c->state('chiseled_stone_bricks'), 8, -2, 11, $b);
        $this->placeBlock($c, $r, $c->state('chiseled_stone_bricks'), 9, -2, 11, $b);
        $this->placeBlock($c, $r, $c->state('chiseled_stone_bricks'), 10, -2, 11, $b);
        $lever = $c->with($c->with($c->state('lever'), 'facing', 'north'), 'face', 'wall');
        $this->placeBlock($c, $r, $lever, 8, -2, 12, $b);
        $this->placeBlock($c, $r, $lever, 9, -2, 12, $b);
        $this->placeBlock($c, $r, $lever, 10, -2, 12, $b);
        $this->generateSelectedBox($c, $r, $b, 8, -3, 8, 8, -3, 10, false, $random, $stone);
        $this->generateSelectedBox($c, $r, $b, 10, -3, 8, 10, -3, 10, false, $random, $stone);
        $this->placeBlock($c, $r, $c->state('mossy_cobblestone'), 10, -2, 9, $b);
        $this->placeBlock($c, $r, $redstoneWireNS, 8, -2, 9, $b);
        $this->placeBlock($c, $r, $redstoneWireNS, 8, -2, 10, $b);
        $this->placeBlock(
            $c,
            $r,
            $c->state('redstone_wire', ['north' => 'side', 'south' => 'side', 'east' => 'side', 'west' => 'side']),
            10,
            -1,
            9,
            $b
        );
        $this->placeBlock($c, $r, $c->with($c->state('sticky_piston'), 'facing', 'up'), 9, -2, 8, $b);
        $this->placeBlock($c, $r, $c->with($c->state('sticky_piston'), 'facing', 'west'), 10, -2, 8, $b);
        $this->placeBlock($c, $r, $c->with($c->state('sticky_piston'), 'facing', 'west'), 10, -1, 8, $b);
        $this->placeBlock($c, $r, $c->with($c->state('repeater'), 'facing', 'north'), 10, -2, 10, $b);
        if (!$this->placedHiddenChest) {
            $this->placedHiddenChest = $this->createChest($c, $r, $b, $random, 9, -3, 10, 'jungle_temple');
        }
    }
}
