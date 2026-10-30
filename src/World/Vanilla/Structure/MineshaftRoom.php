<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/** MineshaftPieces.MineShaftRoom: the dirt-floored room a mineshaft starts from, with a domed ceiling. */
final class MineshaftRoom extends MineshaftPiece
{
    /** @var list<array> entrances cut where corridors leave the room */
    public array $entrances = [];

    public static function create(RandomSource $random, int $west, int $north, string $kind): self
    {
        $maxX = $west + 7 + $random->nextInt(6);
        $maxY = 54 + $random->nextInt(6);
        $maxZ = $north + 7 + $random->nextInt(6);
        return new self([$west, 50, $north, $maxX, $maxY, $maxZ], null, 0, $kind);
    }

    public static function restoreWith(array $piece): self
    {
        $room = new self($piece['box'], null, $piece['depth'], $piece['kind']);
        $room->entrances = $piece['entrances'];
        return $room;
    }

    public function type(): string
    {
        return 'mineshaft_room';
    }

    public function data(): array
    {
        return parent::data() + ['entrances' => $this->entrances];
    }

    public function move(int $dx, int $dy, int $dz): void
    {
        parent::move($dx, $dy, $dz);
        foreach ($this->entrances as &$box) {
            $box = [$box[0] + $dx, $box[1] + $dy, $box[2] + $dz, $box[3] + $dx, $box[4] + $dy, $box[5] + $dz];
        }
    }

    public function addChildren(MineshaftPiece $start, array &$pieces, RandomSource $random): void
    {
        $depth = $this->genDepth;
        [$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = $this->box;
        $spanX = $maxX - $minX + 1;
        $spanZ = $maxZ - $minZ + 1;
        $heightSpace = max(1, $maxY - $minY + 1 - 3 - 1);
        foreach (['north', 'south'] as $side) {
            for ($pos = 0; $pos < $spanX; $pos += 4) {
                $pos += $random->nextInt($spanX);
                if ($pos + 3 > $spanX) {
                    break;
                }
                $child = self::generateAndAddPiece($start, $pieces, $random, $minX + $pos, $minY + $random->nextInt($heightSpace) + 1, $side === 'north' ? $minZ - 1 : $maxZ + 1, $side, $depth);
                if ($child !== null) {
                    $box = $child->box;
                    $this->entrances[] = $side === 'north' ? [$box[0], $box[1], $minZ, $box[3], $box[4], $minZ + 1] : [$box[0], $box[1], $maxZ - 1, $box[3], $box[4], $maxZ];
                }
            }
        }
        foreach (['west', 'east'] as $side) {
            for ($pos = 0; $pos < $spanZ; $pos += 4) {
                $pos += $random->nextInt($spanZ);
                if ($pos + 3 > $spanZ) {
                    break;
                }
                $child = self::generateAndAddPiece($start, $pieces, $random, $side === 'west' ? $minX - 1 : $maxX + 1, $minY + $random->nextInt($heightSpace) + 1, $minZ + $pos, $side, $depth);
                if ($child !== null) {
                    $box = $child->box;
                    $this->entrances[] = $side === 'west' ? [$minX, $box[1], $box[2], $minX + 1, $box[4], $box[5]] : [$maxX - 1, $box[1], $box[2], $maxX, $box[4], $box[5]];
                }
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
        $this->generateBox($c, $r, $b, $minX, $minY + 1, $minZ, $maxX, min($minY + 3, $maxY), $maxZ, $air, $air, false);
        foreach ($this->entrances as $box) {
            $this->generateBox($c, $r, $b, $box[0], $box[4] - 2, $box[2], $box[3], $box[4], $box[5], $air, $air, false);
        }
        // StructurePiece.generateUpperHalfSphere
        [$x0, $y0, $z0, $x1, $y1, $z1] = [$minX, $minY + 4, $minZ, $maxX, $maxY, $maxZ];
        $diagX = (float) ($x1 - $x0 + 1);
        $diagY = (float) ($y1 - $y0 + 1);
        $diagZ = (float) ($z1 - $z0 + 1);
        $cx = F32::r($x0 + F32::r($diagX / 2.0));
        $cz = F32::r($z0 + F32::r($diagZ / 2.0));
        for ($y = $y0; $y <= $y1; ++$y) {
            $ny = F32::r(($y - $y0) / $diagY);
            for ($x = $x0; $x <= $x1; ++$x) {
                $nx = F32::r(F32::r($x - $cx) / F32::r($diagX * 0.5));
                for ($z = $z0; $z <= $z1; ++$z) {
                    $nz = F32::r(F32::r($z - $cz) / F32::r($diagZ * 0.5));
                    if (F32::r(F32::r(F32::r($nx * $nx) + F32::r($ny * $ny)) + F32::r($nz * $nz)) <= F32::r(1.05)) {
                        $this->placeBlock($c, $r, $air, $x, $y, $z, $b);
                    }
                }
            }
        }
    }
}
