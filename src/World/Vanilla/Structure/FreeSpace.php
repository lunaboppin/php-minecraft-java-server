<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

/**
 * The VoxelShape JigsawPlacement keeps of where pieces may still go: a box with the boxes of
 * placed pieces taken out. A piece fits when its box, shrunk by a quarter block, is inside.
 */
final class FreeSpace
{
    /** @var list<array> */
    private array $taken = [];

    public function __construct(private readonly array $box)
    {
    }

    public function subtract(array $box): void
    {
        $this->taken[] = $box;
    }

    /** !Shapes.joinIsNotEmpty(free, AABB.of(box).deflate(0.25), ONLY_SECOND) */
    public function fits(array $box): bool
    {
        if ($box[0] < $this->box[0] || $box[1] < $this->box[1] || $box[2] < $this->box[2] || $box[3] > $this->box[3] || $box[4] > $this->box[4] || $box[5] > $this->box[5]) {
            return false;
        }
        foreach ($this->taken as $taken) {
            if (Piece::intersects($taken, $box)) {
                return false;
            }
        }
        return true;
    }
}
