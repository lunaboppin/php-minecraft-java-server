<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/**
 * TemplateStructurePiece: a template placed at a position with a mirror and a rotation about a
 * pivot, whose structure blocks in data mode are handed to the piece as data markers.
 */
abstract class TemplatePiece extends Piece
{
    /**
     * @param array{0: int, 1: int, 2: int} $position
     * @param array{0: int, 1: int, 2: int} $pivot
     */
    public function __construct(array $box, public readonly string $template, public array $position, public readonly int $rotation, public readonly array $pivot, public readonly int $mirror = BlockTransform::NONE, int $genDepth = 0)
    {
        parent::__construct($box, 'north', $genDepth);
    }

    public function data(): array
    {
        return ['template' => $this->template, 'position' => $this->position, 'rotation' => $this->rotation, 'pivot' => $this->pivot, 'mirror' => $this->mirror];
    }

    /** A template's blocks after the piece's processors, or null to skip them. */
    protected function process(StructureContext $context, array $info, array $original): ?array
    {
        return $context->blocks->name($info[3]) === 'structure_block' ? null : $info;
    }

    /** Whether a processor evaluates the entire piece (StructureProcessor.evaluatesEntirePieceState). */
    protected function wholePiece(): bool
    {
        return false;
    }

    /** StructureProcessor.finalizeProcessing over the piece's processed blocks. */
    protected function finalize(StructureContext $context, Region $region, array $processed, array $originals): array
    {
        return $processed;
    }

    protected function waterlogging(): bool
    {
        return true;
    }

    protected function handleDataMarker(StructureContext $context, Region $region, RandomSource $random, array $chunkBox, string $marker, int $x, int $y, int $z): void
    {
    }

    public function postProcess(StructureContext $context, Region $region, RandomSource $random, array $chunkBox): void
    {
        $template = Template::load($context->blocks, $context->directory, $this->template);
        $this->box = $template->boundingBox($this->position, $this->mirror, $this->rotation, $this->pivot);
        $process = fn (array $info, array $original): ?array => $this->process($context, $info, $original);
        $finalize = $this->wholePiece() ? fn (array $processed, array $originals): array => $this->finalize($context, $region, $processed, $originals) : null;
        if (!$template->place($context, $region, $this->position, $this->mirror, $this->rotation, $this->pivot, $chunkBox, $random, $process, $this->waterlogging(), $finalize !== null, true, $finalize)) {
            return;
        }
        foreach ($template->filter($context->blocks, $this->position, $this->mirror, $this->rotation, $this->pivot, 'structure_block') as [$x, $y, $z, , $nbt]) {
            if (($nbt['mode'] ?? null) === 'DATA') {
                $this->handleDataMarker($context, $region, $random, $chunkBox, (string) ($nbt['metadata'] ?? ''), $x, $y, $z);
            }
        }
        foreach ($template->filter($context->blocks, $this->position, $this->mirror, $this->rotation, $this->pivot, 'jigsaw') as [$x, $y, $z, , $nbt]) {
            if ($nbt !== null && Piece::inside($chunkBox, $x, $y, $z)) {
                $region->set($x, $y, $z, $context->blocks->parse($nbt['final_state'] ?? 'minecraft:air'));
            }
        }
    }
}
