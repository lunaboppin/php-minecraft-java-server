<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/**
 * NetherFossilPieces.NetherFossilPiece: one of fourteen bone fossils, placed whole by every
 * chunk it overlaps, and sometimes a dried ghast on its floor.
 */
final class NetherFossil extends TemplatePiece
{
    public static function restoreWith(array $piece): self
    {
        return new self($piece['box'], $piece['template'], $piece['position'], $piece['rotation'], [0, 0, 0]);
    }

    public function type(): string
    {
        return 'nether_fossil';
    }

    /** NetherFossilPieces.addPieces */
    public static function create(Blocks $blocks, string $directory, array $position, RandomSource $random): self
    {
        $rotation = $random->nextInt(4);
        $template = 'nether_fossils/fossil_' . ($random->nextInt(14) + 1);
        $box = Template::load($blocks, $directory, $template)->boundingBox($position, BlockTransform::NONE, $rotation, [0, 0, 0]);
        return new self($box, $template, $position, $rotation, [0, 0, 0]);
    }

    /** BlockIgnoreProcessor.STRUCTURE_AND_AIR */
    protected function process(StructureContext $context, array $info, array $original): ?array
    {
        $name = $context->blocks->name($info[3]);
        return $name === 'structure_block' || $name === 'air' ? null : $info;
    }

    public function postProcess(StructureContext $context, Region $region, RandomSource $random, array $chunkBox): void
    {
        // The chunk's box is widened to the whole fossil (BoundingBox.encapsulate).
        $fossil = Template::load($context->blocks, $context->directory, $this->template)->boundingBox($this->position, BlockTransform::NONE, $this->rotation, [0, 0, 0]);
        $chunkBox = [min($chunkBox[0], $fossil[0]), min($chunkBox[1], $fossil[1]), min($chunkBox[2], $fossil[2]), max($chunkBox[3], $fossil[3]), max($chunkBox[4], $fossil[4]), max($chunkBox[5], $fossil[5])];
        parent::postProcess($context, $region, $random, $chunkBox);
        // placeDriedGhast: a positional random from the world seed at the fossil's centre.
        $center = [$fossil[0] + intdiv($fossil[3] - $fossil[0] + 1, 2), $fossil[1] + intdiv($fossil[4] - $fossil[1] + 1, 2), $fossil[2] + intdiv($fossil[5] - $fossil[2] + 1, 2)];
        $positional = (new LegacyRandom($region->seed))->forkPositional()->at(...$center);
        if ($positional->nextFloat() >= 0.5) {
            return;
        }
        $x = $fossil[0] + $positional->nextInt($fossil[3] - $fossil[0] + 1);
        $y = $fossil[1];
        $z = $fossil[2] + $positional->nextInt($fossil[5] - $fossil[2] + 1);
        if ($context->blocks->isAir($region->get($x, $y, $z)) && Piece::inside($chunkBox, $x, $y, $z)) {
            $region->set($x, $y, $z, $context->transform->apply($context->state('dried_ghast'), BlockTransform::NONE, $positional->nextInt(4)));
        }
    }
}
