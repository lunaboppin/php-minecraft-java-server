<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/** MineshaftPieces.MineShaftCrossing: a one or two storey junction with corner pillars. */
final class MineshaftCrossing extends MineshaftPiece
{
    public function __construct(array $box, int $genDepth, string $kind, public readonly string $direction)
    {
        // The crossing has no orientation: its coordinates are world coordinates.
        parent::__construct($box, null, $genDepth, $kind);
    }

    public static function restoreWith(array $piece): self
    {
        return new self($piece['box'], $piece['depth'], $piece['kind'], $piece['direction']);
    }

    public function type(): string
    {
        return 'mineshaft_crossing';
    }

    public function data(): array
    {
        return parent::data() + ['direction' => $this->direction];
    }

    private function twoFloored(): bool
    {
        return $this->box[4] - $this->box[1] + 1 > 3;
    }

    public static function find(array $pieces, RandomSource $random, int $x, int $y, int $z, string $direction): ?array
    {
        $y1 = $random->nextInt(4) === 0 ? 6 : 2;
        $box = match ($direction) {
            'south' => [-1, 0, 0, 3, $y1, 4],
            'west' => [-4, 0, -1, 0, $y1, 3],
            'east' => [0, 0, -1, 4, $y1, 3],
            default => [-1, 0, -4, 3, $y1, 0],
        };
        $box = [$box[0] + $x, $box[1] + $y, $box[2] + $z, $box[3] + $x, $box[4] + $y, $box[5] + $z];
        return self::collides($pieces, $box) ? null : $box;
    }

    public function addChildren(MineshaftPiece $start, array &$pieces, RandomSource $random): void
    {
        $depth = $this->genDepth;
        [$minX, $minY, $minZ, $maxX, , $maxZ] = $this->box;
        // The sides away from where the crossing was entered, in vanilla's order.
        $sides = match ($this->direction) {
            'south' => ['south', 'west', 'east'],
            'west' => ['north', 'south', 'west'],
            'east' => ['north', 'south', 'east'],
            default => ['north', 'west', 'east'],
        };
        foreach ($sides as $side) {
            match ($side) {
                'north' => self::generateAndAddPiece($start, $pieces, $random, $minX + 1, $minY, $minZ - 1, 'north', $depth),
                'south' => self::generateAndAddPiece($start, $pieces, $random, $minX + 1, $minY, $maxZ + 1, 'south', $depth),
                'west' => self::generateAndAddPiece($start, $pieces, $random, $minX - 1, $minY, $minZ + 1, 'west', $depth),
                'east' => self::generateAndAddPiece($start, $pieces, $random, $maxX + 1, $minY, $minZ + 1, 'east', $depth),
            };
        }
        if ($this->twoFloored()) {
            if ($random->nextBoolean()) {
                self::generateAndAddPiece($start, $pieces, $random, $minX + 1, $minY + 3 + 1, $minZ - 1, 'north', $depth);
            }
            if ($random->nextBoolean()) {
                self::generateAndAddPiece($start, $pieces, $random, $minX - 1, $minY + 3 + 1, $minZ + 1, 'west', $depth);
            }
            if ($random->nextBoolean()) {
                self::generateAndAddPiece($start, $pieces, $random, $maxX + 1, $minY + 3 + 1, $minZ + 1, 'east', $depth);
            }
            if ($random->nextBoolean()) {
                self::generateAndAddPiece($start, $pieces, $random, $minX + 1, $minY + 3 + 1, $maxZ + 1, 'south', $depth);
            }
        }
    }

    public function postProcess(StructureContext $c, Region $r, RandomSource $random, array $b): void
    {
        if ($this->isInInvalidLocation($c, $r, $b)) {
            return;
        }
        $air = $c->state('cave_air');
        [$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = $this->box;
        if ($this->twoFloored()) {
            $this->generateBox($c, $r, $b, $minX + 1, $minY, $minZ, $maxX - 1, $minY + 3 - 1, $maxZ, $air, $air, false);
            $this->generateBox($c, $r, $b, $minX, $minY, $minZ + 1, $maxX, $minY + 3 - 1, $maxZ - 1, $air, $air, false);
            $this->generateBox($c, $r, $b, $minX + 1, $maxY - 2, $minZ, $maxX - 1, $maxY, $maxZ, $air, $air, false);
            $this->generateBox($c, $r, $b, $minX, $maxY - 2, $minZ + 1, $maxX, $maxY, $maxZ - 1, $air, $air, false);
            $this->generateBox($c, $r, $b, $minX + 1, $minY + 3, $minZ + 1, $maxX - 1, $minY + 3, $maxZ - 1, $air, $air, false);
        } else {
            $this->generateBox($c, $r, $b, $minX + 1, $minY, $minZ, $maxX - 1, $maxY, $maxZ, $air, $air, false);
            $this->generateBox($c, $r, $b, $minX, $minY, $minZ + 1, $maxX, $maxY, $maxZ - 1, $air, $air, false);
        }
        foreach ([[$minX + 1, $minZ + 1], [$minX + 1, $maxZ - 1], [$maxX - 1, $minZ + 1], [$maxX - 1, $maxZ - 1]] as [$x, $z]) {
            if (!$c->blocks->isAir($this->getBlock($r, $x, $maxY + 1, $z, $b))) {
                $this->generateBox($c, $r, $b, $x, $minY, $z, $x, $maxY, $z, $this->planks($c), $air, false);
            }
        }
        $planks = $this->planks($c);
        for ($x = $minX; $x <= $maxX; ++$x) {
            for ($z = $minZ; $z <= $maxZ; ++$z) {
                $this->setPlanksBlock($c, $r, $b, $planks, $x, $minY - 1, $z);
            }
        }
    }
}
