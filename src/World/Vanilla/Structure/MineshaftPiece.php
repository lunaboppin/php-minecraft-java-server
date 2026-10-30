<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/**
 * MineshaftPieces.MineShaftPiece and the generation of a mineshaft's pieces: rooms, corridors,
 * crossings and stairs added one from another up to eight deep, within 80 blocks of the room,
 * wherever they do not collide with pieces already placed.
 */
abstract class MineshaftPiece extends Piece
{
    /** MineshaftStructure.Type: wood, planks and fence of each kind. */
    protected const TYPES = ['normal' => ['oak_log', 'oak_planks', 'oak_fence'], 'mesa' => ['dark_oak_log', 'dark_oak_planks', 'dark_oak_fence']];

    public function __construct(array $box, ?string $orientation, int $genDepth, public readonly string $kind)
    {
        parent::__construct($box, $orientation, $genDepth);
    }

    public function data(): array
    {
        return ['depth' => $this->genDepth, 'kind' => $this->kind];
    }

    /** @param list<MineshaftPiece> $pieces */
    abstract public function addChildren(MineshaftPiece $start, array &$pieces, RandomSource $random): void;

    public function move(int $dx, int $dy, int $dz): void
    {
        $this->box = [$this->box[0] + $dx, $this->box[1] + $dy, $this->box[2] + $dz, $this->box[3] + $dx, $this->box[4] + $dy, $this->box[5] + $dz];
    }

    protected function wood(StructureContext $c): int
    {
        return $c->state(self::TYPES[$this->kind][0]);
    }

    protected function planks(StructureContext $c): int
    {
        return $c->state(self::TYPES[$this->kind][1]);
    }

    protected function fence(StructureContext $c): int
    {
        return $c->state(self::TYPES[$this->kind][2]);
    }

    /** StructurePieceAccessor.findCollisionPiece */
    public static function collides(array $pieces, array $box): bool
    {
        foreach ($pieces as $piece) {
            if (self::intersects($piece->box, $box)) {
                return true;
            }
        }
        return false;
    }

    /** MineshaftPieces.createRandomShaftPiece */
    private static function createRandomShaftPiece(array &$pieces, RandomSource $random, int $x, int $y, int $z, string $direction, int $depth, string $kind): ?self
    {
        $selection = $random->nextInt(100);
        if ($selection >= 80) {
            $box = MineshaftCrossing::find($pieces, $random, $x, $y, $z, $direction);
            return $box === null ? null : new MineshaftCrossing($box, $depth, $kind, $direction);
        }
        if ($selection >= 70) {
            $box = MineshaftStairs::find($pieces, $x, $y, $z, $direction);
            return $box === null ? null : new MineshaftStairs($box, $direction, $depth, $kind);
        }
        $box = MineshaftCorridor::find($pieces, $random, $x, $y, $z, $direction);
        return $box === null ? null : MineshaftCorridor::create($box, $direction, $depth, $kind, $random);
    }

    /** MineshaftPieces.generateAndAddPiece */
    protected static function generateAndAddPiece(MineshaftPiece $start, array &$pieces, RandomSource $random, int $x, int $y, int $z, string $direction, int $depth): ?self
    {
        if ($depth > 8 || abs($x - $start->box[0]) > 80 || abs($z - $start->box[2]) > 80) {
            return null;
        }
        $piece = self::createRandomShaftPiece($pieces, $random, $x, $y, $z, $direction, $depth + 1, $start->kind);
        if ($piece !== null) {
            $pieces[] = $piece;
            $piece->addChildren($start, $pieces, $random);
        }
        return $piece;
    }

    /** BlockState.liquid: water and lava blocks. */
    protected static function liquid(StructureContext $c, int $state): bool
    {
        $name = $c->blocks->name($state);
        return $name === 'water' || $name === 'lava';
    }

    protected static function solidRender(StructureContext $c, int $state): bool
    {
        return $c->blocks->flag($state, 1 << 20) && $c->blocks->flag($state, Blocks::FULL_CUBE);
    }

    protected function canBeReplaced(StructureContext $context, Region $region, int $x, int $y, int $z, array $chunkBox): bool
    {
        $name = $context->blocks->name($this->getBlock($region, $x, $y, $z, $chunkBox));
        return !in_array($name, [...self::TYPES[$this->kind], 'iron_chain'], true);
    }

    protected function isSupportingBox(Region $region, array $chunkBox, int $x0, int $x1, int $y1, int $z0, StructureContext $c): bool
    {
        for ($x = $x0; $x <= $x1; ++$x) {
            if ($c->blocks->isAir($this->getBlock($region, $x, $y1 + 1, $z0, $chunkBox))) {
                return false;
            }
        }
        return true;
    }

    /** MineShaftPiece.isInInvalidLocation: a blocking biome, or liquid on the faces of the box around it. */
    protected function isInInvalidLocation(StructureContext $c, Region $region, array $chunkBox): bool
    {
        $x0 = max($this->box[0] - 1, $chunkBox[0]);
        $y0 = max($this->box[1] - 1, $chunkBox[1]);
        $z0 = max($this->box[2] - 1, $chunkBox[2]);
        $x1 = min($this->box[3] + 1, $chunkBox[3]);
        $y1 = min($this->box[4] + 1, $chunkBox[4]);
        $z1 = min($this->box[5] + 1, $chunkBox[5]);
        if (($c->biomeInTag)($region->biome(intdiv($x0 + $x1, 2), intdiv($y0 + $y1, 2), intdiv($z0 + $z1, 2)), 'mineshaft_blocking')) {
            return true;
        }
        for ($x = $x0; $x <= $x1; ++$x) {
            for ($z = $z0; $z <= $z1; ++$z) {
                if (self::liquid($c, $region->get($x, $y0, $z)) || self::liquid($c, $region->get($x, $y1, $z))) {
                    return true;
                }
            }
        }
        for ($x = $x0; $x <= $x1; ++$x) {
            for ($y = $y0; $y <= $y1; ++$y) {
                if (self::liquid($c, $region->get($x, $y, $z0)) || self::liquid($c, $region->get($x, $y, $z1))) {
                    return true;
                }
            }
        }
        for ($z = $z0; $z <= $z1; ++$z) {
            for ($y = $y0; $y <= $y1; ++$y) {
                if (self::liquid($c, $region->get($x0, $y, $z)) || self::liquid($c, $region->get($x1, $y, $z))) {
                    return true;
                }
            }
        }
        return false;
    }

    /** StructurePiece.isInterior: below the ocean floor heightmap, in this chunk. */
    protected function isInterior(Region $region, int $x, int $y, int $z, array $chunkBox): bool
    {
        [$wx, $wy, $wz] = $this->worldPos($x, $y + 1, $z);
        return self::inside($chunkBox, $wx, $wy, $wz) && $wy < $region->height(Region::OCEAN_FLOOR_WG, $wx, $wz);
    }

    protected function setPlanksBlock(StructureContext $c, Region $region, array $chunkBox, int $planks, int $x, int $y, int $z): void
    {
        if ($this->isInterior($region, $x, $y, $z, $chunkBox)) {
            [$wx, $wy, $wz] = $this->worldPos($x, $y, $z);
            if (!$c->blocks->sturdy($region->get($wx, $wy, $wz), 1)) {
                $region->set($wx, $wy, $wz, $planks);
            }
        }
    }

    /** StructurePiece.generateMaybeBox */
    protected function generateMaybeBox(StructureContext $c, Region $region, array $chunkBox, RandomSource $random, float $probability, int $x0, int $y0, int $z0, int $x1, int $y1, int $z1, int $edge, int $fill, bool $skipAir, bool $inside): void
    {
        for ($y = $y0; $y <= $y1; ++$y) {
            for ($x = $x0; $x <= $x1; ++$x) {
                for ($z = $z0; $z <= $z1; ++$z) {
                    if ($random->nextFloat() > $probability
                        || $skipAir && $c->blocks->isAir($this->getBlock($region, $x, $y, $z, $chunkBox))
                        || $inside && !$this->isInterior($region, $x, $y, $z, $chunkBox)) {
                        continue;
                    }
                    $interior = $y !== $y0 && $y !== $y1 && $x !== $x0 && $x !== $x1 && $z !== $z0 && $z !== $z1;
                    $this->placeBlock($c, $region, $interior ? $fill : $edge, $x, $y, $z, $chunkBox);
                }
            }
        }
    }

    /** StructurePiece.maybeGenerateBlock */
    protected function maybeGenerateBlock(StructureContext $c, Region $region, array $chunkBox, RandomSource $random, float $probability, int $x, int $y, int $z, int $state): void
    {
        if ($random->nextFloat() < $probability) {
            $this->placeBlock($c, $region, $state, $x, $y, $z, $chunkBox);
        }
    }
}
