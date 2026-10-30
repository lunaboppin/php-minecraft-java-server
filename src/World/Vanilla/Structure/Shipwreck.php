<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/** ShipwreckPieces.ShipwreckPiece: a wreck on the ocean floor, or half buried on a beach. */
final class Shipwreck extends TemplatePiece
{
    private const PIVOT = [4, 0, 15];

    private const BEACHED = ['with_mast', 'sideways_full', 'sideways_fronthalf', 'sideways_backhalf', 'rightsideup_full', 'rightsideup_fronthalf', 'rightsideup_backhalf', 'with_mast_degraded', 'rightsideup_full_degraded', 'rightsideup_fronthalf_degraded', 'rightsideup_backhalf_degraded'];

    private const OCEAN = ['with_mast', 'upsidedown_full', 'upsidedown_fronthalf', 'upsidedown_backhalf', 'sideways_full', 'sideways_fronthalf', 'sideways_backhalf', 'rightsideup_full', 'rightsideup_fronthalf', 'rightsideup_backhalf', 'with_mast_degraded', 'upsidedown_full_degraded', 'upsidedown_fronthalf_degraded', 'upsidedown_backhalf_degraded', 'sideways_full_degraded', 'sideways_fronthalf_degraded', 'sideways_backhalf_degraded', 'rightsideup_full_degraded', 'rightsideup_fronthalf_degraded', 'rightsideup_backhalf_degraded'];

    private const MARKERS = ['map_chest' => 'shipwreck_map', 'treasure_chest' => 'shipwreck_treasure', 'supply_chest' => 'shipwreck_supply'];

    public bool $beached = false;

    public bool $heightAdjusted = false;

    /** ShipwreckStructure.generatePieces up to the height of wrecks too big for one region. */
    public static function create(Blocks $blocks, string $directory, int $cx, int $cz, int $rotation, RandomSource $random, bool $beached): self
    {
        $names = $beached ? self::BEACHED : self::OCEAN;
        $template = 'shipwreck/' . $names[$random->nextInt(count($names))];
        $position = [$cx * 16, 90, $cz * 16];
        $box = Template::load($blocks, $directory, $template)->boundingBox($position, BlockTransform::NONE, $rotation, self::PIVOT);
        $piece = new self($box, $template, $position, $rotation, self::PIVOT);
        $piece->beached = $beached;
        return $piece;
    }

    public static function restoreWith(array $piece): self
    {
        $wreck = new self($piece['box'], $piece['template'], $piece['position'], $piece['rotation'], $piece['pivot']);
        $wreck->beached = $piece['beached'];
        $wreck->heightAdjusted = $piece['adjusted'];
        return $wreck;
    }

    public function type(): string
    {
        return 'shipwreck';
    }

    public function data(): array
    {
        return parent::data() + ['beached' => $this->beached, 'adjusted' => $this->heightAdjusted];
    }

    public function tooBig(StructureContext|Blocks $blocks, string $directory = ''): bool
    {
        $context = $blocks instanceof StructureContext ? $blocks : null;
        $template = Template::load($context?->blocks ?? $blocks, $context?->directory ?? $directory, $this->template);
        return $template->size[0] > 32 || $template->size[1] > 32;
    }

    public function beachedHeight(Blocks $blocks, string $directory, int $minY, RandomSource $random): int
    {
        return $minY - intdiv(Template::load($blocks, $directory, $this->template)->size[1], 2) - $random->nextInt(3);
    }

    public function adjustHeight(int $height): void
    {
        $this->heightAdjusted = true;
        $this->position[1] = $height;
    }

    protected function process(StructureContext $context, array $info, array $original): ?array
    {
        // BlockIgnoreProcessor.STRUCTURE_AND_AIR
        $name = $context->blocks->name($info[3]);
        return $name === 'structure_block' || $name === 'air' ? null : $info;
    }

    protected function handleDataMarker(StructureContext $context, Region $region, RandomSource $random, array $chunkBox, string $marker, int $x, int $y, int $z): void
    {
        $table = self::MARKERS[$marker] ?? null;
        // RandomizableContainer.setBlockEntityLootTable: only where a chest stands below the marker.
        if ($table !== null && $context->blocks->name($region->get($x, $y - 1, $z)) === 'chest') {
            $context->blockEntities[$x . ',' . ($y - 1) . ',' . $z] = ['id' => 'chest', 'loot_table' => 'minecraft:chests/' . $table, 'seed' => $random->nextLong()];
        }
    }

    public function postProcess(StructureContext $context, Region $region, RandomSource $random, array $chunkBox): void
    {
        if (!$this->heightAdjusted && !$this->tooBig($context)) {
            $template = Template::load($context->blocks, $context->directory, $this->template);
            $type = $this->beached ? Region::WORLD_SURFACE_WG : Region::OCEAN_FLOOR_WG;
            [$sizeX, , $sizeZ] = $template->size;
            $minY = $region->maxY() + 1;
            $mean = 0;
            if ($sizeX * $sizeZ === 0) {
                $mean = $region->height($type, $this->position[0], $this->position[2]);
            } else {
                // BlockPos.betweenClosed: x fastest, then y, then z.
                for ($z = $this->position[2]; $z <= $this->position[2] + $sizeZ - 1; ++$z) {
                    for ($x = $this->position[0]; $x <= $this->position[0] + $sizeX - 1; ++$x) {
                        $height = $region->height($type, $x, $z);
                        $mean += $height;
                        $minY = min($minY, $height);
                    }
                }
                $mean = intdiv($mean, $sizeX * $sizeZ);
            }
            $this->adjustHeight($this->beached ? $this->beachedHeight($context->blocks, $context->directory, $minY, $random) : $mean);
        }
        parent::postProcess($context, $region, $random, $chunkBox);
    }
}
