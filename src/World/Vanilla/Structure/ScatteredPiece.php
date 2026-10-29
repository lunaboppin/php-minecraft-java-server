<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/**
 * ScatteredFeaturePiece: a single-piece structure that settles onto the ground the first time a
 * chunk it overlaps is decorated, and keeps that height for its other chunks.
 */
abstract class ScatteredPiece extends Piece
{
    public function __construct(array $box, ?string $orientation, public readonly int $width, public readonly int $height, public readonly int $depth, public int $heightPosition = -1)
    {
        parent::__construct($box, $orientation);
    }

    public function data(): array
    {
        return ['width' => $this->width, 'height' => $this->height, 'depth' => $this->depth, 'hpos' => $this->heightPosition];
    }

    /** ScatteredFeaturePiece.updateAverageGroundHeight over the part of the box in this chunk. */
    protected function updateAverageGroundHeight(Region $region, array $chunkBox, int $offset): bool
    {
        if ($this->heightPosition >= 0) {
            return true;
        }
        $total = 0;
        $count = 0;
        for ($z = $this->box[2]; $z <= $this->box[5]; ++$z) {
            for ($x = $this->box[0]; $x <= $this->box[3]; ++$x) {
                if (self::inside($chunkBox, $x, 64, $z)) {
                    $total += $region->height(Region::MOTION_BLOCKING_NO_LEAVES, $x, $z);
                    ++$count;
                }
            }
        }
        if ($count === 0) {
            return false;
        }
        $this->heightPosition = intdiv($total, $count);
        $this->moveTo($this->heightPosition + $offset);
        return true;
    }

    /** ScatteredFeaturePiece.updateHeightPositionToLowestGroundHeight over the whole box. */
    protected function updateHeightPositionToLowestGroundHeight(Region $region, int $offset): bool
    {
        if ($this->heightPosition >= 0) {
            return true;
        }
        $lowest = $region->maxY() + 1;
        for ($z = $this->box[2]; $z <= $this->box[5]; ++$z) {
            for ($x = $this->box[0]; $x <= $this->box[3]; ++$x) {
                $lowest = min($lowest, $region->height(Region::MOTION_BLOCKING_NO_LEAVES, $x, $z));
            }
        }
        $this->heightPosition = $lowest;
        $this->moveTo($this->heightPosition + $offset);
        return true;
    }

    private function moveTo(int $minY): void
    {
        $dy = $minY - $this->box[1];
        $this->box[1] += $dy;
        $this->box[4] += $dy;
    }
}
