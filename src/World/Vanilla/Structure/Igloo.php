<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/** IglooPieces.IglooPiece: the igloo, and sometimes a ladder shaft down to its laboratory. */
final class Igloo extends TemplatePiece
{
    private const PIVOTS = ['igloo/top' => [3, 5, 5], 'igloo/middle' => [1, 3, 1], 'igloo/bottom' => [3, 6, 7]];

    private const OFFSETS = ['igloo/top' => [0, 0, 0], 'igloo/middle' => [2, -3, 4], 'igloo/bottom' => [0, -3, -2]];

    /** IglooPieces.addPieces from the chunk's start position at y 90. */
    public static function pieces(Blocks $blocks, string $directory, array $start, int $rotation, RandomSource $random): array
    {
        $pieces = [];
        if ($random->nextDouble() < 0.5) {
            $depth = $random->nextInt(8) + 4;
            $pieces[] = self::create($blocks, $directory, 'igloo/bottom', $start, $rotation, $depth * 3);
            for ($i = 0; $i < $depth - 1; ++$i) {
                $pieces[] = self::create($blocks, $directory, 'igloo/middle', $start, $rotation, $i * 3);
            }
        }
        $pieces[] = self::create($blocks, $directory, 'igloo/top', $start, $rotation, 0);
        return $pieces;
    }

    private static function create(Blocks $blocks, string $directory, string $template, array $start, int $rotation, int $depth): self
    {
        $offset = self::OFFSETS[$template];
        $position = [$start[0] + $offset[0], $start[1] + $offset[1] - $depth, $start[2] + $offset[2]];
        $box = Template::load($blocks, $directory, $template)->boundingBox($position, BlockTransform::NONE, $rotation, self::PIVOTS[$template]);
        return new self($box, $template, $position, $rotation, self::PIVOTS[$template]);
    }

    public static function restoreWith(array $piece): self
    {
        return new self($piece['box'], $piece['template'], $piece['position'], $piece['rotation'], $piece['pivot']);
    }

    public function type(): string
    {
        return 'igloo';
    }

    protected function waterlogging(): bool
    {
        return false;
    }

    protected function handleDataMarker(StructureContext $context, Region $region, RandomSource $random, array $chunkBox, string $marker, int $x, int $y, int $z): void
    {
        if ($marker !== 'chest') {
            return;
        }
        if (Piece::inside($chunkBox, $x, $y, $z)) {
            $region->set($x, $y, $z, $context->state('air'));
        }
        if ($context->blocks->name($region->get($x, $y - 1, $z)) === 'chest') {
            $context->blockEntities[$x . ',' . ($y - 1) . ',' . $z] = ['id' => 'chest', 'loot_table' => 'minecraft:chests/igloo_chest', 'seed' => $random->nextLong()];
        }
    }

    public function postProcess(StructureContext $context, Region $region, RandomSource $random, array $chunkBox): void
    {
        $offset = self::OFFSETS[$this->template];
        [$ex, , $ez] = Template::transform(3 - $offset[0], 0, -$offset[2], BlockTransform::NONE, $this->rotation, $this->pivot);
        $height = $region->height(Region::WORLD_SURFACE_WG, $this->position[0] + $ex, $this->position[2] + $ez);
        $original = $this->position;
        $this->position[1] += $height - 90 - 1;
        parent::postProcess($context, $region, $random, $chunkBox);
        if ($this->template === 'igloo/top') {
            [$tx, $ty, $tz] = Template::transform(3, 0, 5, BlockTransform::NONE, $this->rotation, $this->pivot);
            [$tx, $ty, $tz] = [$tx + $this->position[0], $ty + $this->position[1], $tz + $this->position[2]];
            $below = $region->get($tx, $ty - 1, $tz);
            if (!$context->blocks->isAir($below) && $context->blocks->name($below) !== 'ladder' && Piece::inside($chunkBox, $tx, $ty, $tz)) {
                $region->set($tx, $ty, $tz, $context->state('snow_block'));
            }
        }
        $this->position = $original;
    }
}
