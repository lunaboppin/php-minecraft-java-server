<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/** SwampHutPiece: a spruce hut on oak stilts, with a witch and a cat. */
final class SwampHut extends ScatteredPiece
{
    public static function create(RandomSource $random, int $west, int $north): self
    {
        $direction = self::HORIZONTAL[$random->nextInt(4)];
        return new self(self::makeBox($west, 64, $north, $direction, 7, 7, 9), $direction, 7, 7, 9);
    }

    public function type(): string
    {
        return 'swamp_hut';
    }

    public function postProcess(StructureContext $context, Region $region, RandomSource $random, array $chunkBox): void
    {
        if (!$this->updateAverageGroundHeight($region, $chunkBox, 0)) {
            return;
        }
        $s = $context->state(...);
        $planks = $s('spruce_planks');
        $log = $s('oak_log');
        $fence = $s('oak_fence');
        foreach ([[1, 1, 1, 5, 1, 7], [1, 4, 2, 5, 4, 7], [2, 1, 0, 4, 1, 0], [2, 2, 2, 3, 3, 2], [1, 2, 3, 1, 3, 6], [5, 2, 3, 5, 3, 6], [2, 2, 7, 4, 3, 7]] as [$x0, $y0, $z0, $x1, $y1, $z1]) {
            $this->generateBox($context, $region, $chunkBox, $x0, $y0, $z0, $x1, $y1, $z1, $planks, $planks, false);
        }
        foreach ([[1, 0, 2, 1, 3, 2], [5, 0, 2, 5, 3, 2], [1, 0, 7, 1, 3, 7], [5, 0, 7, 5, 3, 7]] as [$x0, $y0, $z0, $x1, $y1, $z1]) {
            $this->generateBox($context, $region, $chunkBox, $x0, $y0, $z0, $x1, $y1, $z1, $log, $log, false);
        }
        $this->placeBlock($context, $region, $fence, 2, 3, 2, $chunkBox);
        $this->placeBlock($context, $region, $fence, 3, 3, 7, $chunkBox);
        $this->placeBlock($context, $region, $s('air'), 1, 3, 4, $chunkBox);
        $this->placeBlock($context, $region, $s('air'), 5, 3, 4, $chunkBox);
        $this->placeBlock($context, $region, $s('air'), 5, 3, 5, $chunkBox);
        $this->placeBlock($context, $region, $s('potted_red_mushroom'), 1, 3, 5, $chunkBox);
        $this->placeBlock($context, $region, $s('crafting_table'), 3, 2, 6, $chunkBox);
        $this->placeBlock($context, $region, $s('cauldron'), 4, 2, 6, $chunkBox);
        $this->placeBlock($context, $region, $fence, 1, 2, 1, $chunkBox);
        $this->placeBlock($context, $region, $fence, 5, 2, 1, $chunkBox);
        $stairs = static fn (string $facing, string $shape = 'straight'): int => $s('spruce_stairs', ['facing' => $facing, 'shape' => $shape]);
        $this->generateBox($context, $region, $chunkBox, 0, 4, 1, 6, 4, 1, $stairs('north'), $stairs('north'), false);
        $this->generateBox($context, $region, $chunkBox, 0, 4, 2, 0, 4, 7, $stairs('east'), $stairs('east'), false);
        $this->generateBox($context, $region, $chunkBox, 6, 4, 2, 6, 4, 7, $stairs('west'), $stairs('west'), false);
        $this->generateBox($context, $region, $chunkBox, 0, 4, 8, 6, 4, 8, $stairs('south'), $stairs('south'), false);
        $this->placeBlock($context, $region, $stairs('north', 'outer_right'), 0, 4, 1, $chunkBox);
        $this->placeBlock($context, $region, $stairs('north', 'outer_left'), 6, 4, 1, $chunkBox);
        $this->placeBlock($context, $region, $stairs('south', 'outer_left'), 0, 4, 8, $chunkBox);
        $this->placeBlock($context, $region, $stairs('south', 'outer_right'), 6, 4, 8, $chunkBox);
        for ($z = 2; $z <= 7; $z += 5) {
            for ($x = 1; $x <= 5; $x += 4) {
                $this->fillColumnDown($context, $region, $log, $x, -1, $z, $chunkBox);
            }
        }
        // The witch, then SwampHutPiece.spawnCat, both persistent, inside the hut.
        [$x, $y, $z] = $this->worldPos(2, 2, 5);
        if (self::inside($chunkBox, $x, $y, $z)) {
            foreach (['witch', 'cat'] as $id) {
                $context->addEntity($id, [$x + 0.5, (float) $y, $z + 0.5], ['PersistenceRequired' => 1], true);
            }
        }
    }
}
