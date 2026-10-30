<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/** MineshaftPieces.MineShaftStairs: a passage stepping down five blocks. */
final class MineshaftStairs extends MineshaftPiece
{
    public static function restoreWith(array $piece): self
    {
        return new self($piece['box'], $piece['orientation'], $piece['depth'], $piece['kind']);
    }

    public function type(): string
    {
        return 'mineshaft_stairs';
    }

    public static function find(array $pieces, int $x, int $y, int $z, string $direction): ?array
    {
        $box = match ($direction) {
            'south' => [0, -5, 0, 2, 2, 8],
            'west' => [-8, -5, 0, 0, 2, 2],
            'east' => [0, -5, 0, 8, 2, 2],
            default => [0, -5, -8, 2, 2, 0],
        };
        $box = [$box[0] + $x, $box[1] + $y, $box[2] + $z, $box[3] + $x, $box[4] + $y, $box[5] + $z];
        return self::collides($pieces, $box) ? null : $box;
    }

    public function addChildren(MineshaftPiece $start, array &$pieces, RandomSource $random): void
    {
        [$minX, $minY, $minZ, $maxX, , $maxZ] = $this->box;
        match ($this->orientation) {
            'south' => self::generateAndAddPiece($start, $pieces, $random, $minX, $minY, $maxZ + 1, 'south', $this->genDepth),
            'west' => self::generateAndAddPiece($start, $pieces, $random, $minX - 1, $minY, $minZ, 'west', $this->genDepth),
            'east' => self::generateAndAddPiece($start, $pieces, $random, $maxX + 1, $minY, $minZ, 'east', $this->genDepth),
            default => self::generateAndAddPiece($start, $pieces, $random, $minX, $minY, $minZ - 1, 'north', $this->genDepth),
        };
    }

    public function postProcess(StructureContext $c, Region $r, RandomSource $random, array $b): void
    {
        if ($this->isInInvalidLocation($c, $r, $b)) {
            return;
        }
        $air = $c->state('cave_air');
        $this->generateBox($c, $r, $b, 0, 5, 0, 2, 7, 1, $air, $air, false);
        $this->generateBox($c, $r, $b, 0, 0, 7, 2, 2, 8, $air, $air, false);
        for ($i = 0; $i < 5; ++$i) {
            $this->generateBox($c, $r, $b, 0, 5 - $i - ($i < 4 ? 1 : 0), 2 + $i, 2, 7 - $i, 2 + $i, $air, $air, false);
        }
    }
}
