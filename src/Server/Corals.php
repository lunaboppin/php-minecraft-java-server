<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

/**
 * CoralBlock, CoralPlantBlock, CoralFanBlock and CoralWallFanBlock: living coral with no water
 * touching it (and, for the plants and fans, not waterlogged) dies 60 to 99 ticks after it is
 * placed or a neighbour changes, unless water has reached it by then.
 */
trait Corals
{
    /** 'block' for a living coral block, 'plant' for a living coral plant or fan, null otherwise. */
    private function coralKind(int $state): ?string
    {
        $name = $this->version->catalog->blockName($state);
        if (str_starts_with($name, 'dead_') || !str_contains($name, 'coral')) {
            return null;
        }
        return str_ends_with($name, '_coral_block') ? 'block' : 'plant';
    }

    /** CoralBlock.scanForWater and BaseCoralPlantTypeBlock.scanForWater. */
    private function coralHasWater(int $state, int $x, int $y, int $z): bool
    {
        if (($this->version->catalog->properties($state)['waterlogged'] ?? 'false') === 'true') {
            return true;
        }
        foreach ([[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]] as [$dx, $dy, $dz]) {
            if ($this->fireBlocks()->isWater($this->world->get($x + $dx, $y + $dy, $z + $dz))) {
                return true;
            }
        }
        return false;
    }

    private function scheduleCoralDeath(int $state, int $x, int $y, int $z): void
    {
        if (!$this->coralHasWater($state, $x, $y, $z)) {
            $this->scheduleBlock('coral', $x, $y, $z, 60 + random_int(0, 39));
        }
    }

    /**
     * A coral just set checks itself (plants' and fans' onPlace; a coral block's placement, or the
     * setblock and fill commands' updateFromNeighbourShapes), and every coral's updateShape checks
     * the neighbours of a changed block.
     */
    private function coralsChanged(int $x, int $y, int $z): void
    {
        $state = $this->world->get($x, $y, $z);
        if ($this->coralKind($state) !== null) {
            $this->scheduleCoralDeath($state, $x, $y, $z);
        }
        foreach ([[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]] as [$dx, $dy, $dz]) {
            $neighbour = $this->world->get($x + $dx, $y + $dy, $z + $dz);
            if ($this->coralKind($neighbour) !== null) {
                $this->scheduleCoralDeath($neighbour, $x + $dx, $y + $dy, $z + $dz);
            }
        }
    }

    /** The coral's tick: still dry, it becomes its dead form, keeping its facing but not its water. */
    private function tickCoral(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        if ($this->coralKind($state) === null || $this->coralHasWater($state, $x, $y, $z)) {
            return;
        }
        $properties = $catalog->properties($state);
        if (isset($properties['waterlogged'])) {
            $properties['waterlogged'] = 'false';
        }
        $this->setBlock($x, $y, $z, $catalog->state('dead_' . $catalog->blockName($state), $properties));
    }
}
