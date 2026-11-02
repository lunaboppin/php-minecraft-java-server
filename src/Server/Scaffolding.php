<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

/** ScaffoldingBlock: distance from support kept up a tick after each change, and unsupported scaffolding broken or let fall. */
trait Scaffolding
{
    /** ScaffoldingBlock.onPlace, and updateShape for the scaffolding beside a change: a tick to recheck its distance. */
    private function scaffoldingChanged(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        foreach ([[0, 0, 0], [0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]] as [$dx, $dy, $dz]) {
            if ($catalog->blockName($this->world->get($x + $dx, $y + $dy, $z + $dz)) === 'scaffolding') {
                $this->scheduleBlock('scaffolding', $x + $dx, $y + $dy, $z + $dz);
            }
        }
    }

    /**
     * ScaffoldingBlock.tick: the distance and bottom flag are updated; scaffolding 7 from support
     * is destroyed with its drops, or falls if it already was 7.
     */
    private function tickScaffolding(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        if ($catalog->blockName($state) !== 'scaffolding') {
            return;
        }
        $properties = $catalog->properties($state);
        $distance = $this->scaffoldingDistance([$x, $y, $z]);
        $updated = $catalog->state('scaffolding', [
            'distance' => (string) $distance,
            // ScaffoldingBlock.isBottom.
            'bottom' => $distance !== 0 && $catalog->blockName($this->world->get($x, $y - 1, $z)) !== 'scaffolding' ? 'true' : 'false',
        ] + $properties);
        if ($distance === 7) {
            if ($properties['distance'] === '7') {
                $this->startFalling($x, $y, $z, $updated);
            } else {
                // Level.destroyBlock: the break event, the drops, then the fluid it held.
                $this->levelEvent(2001, [$x, $y, $z], $state);
                $this->dropBlockResources($x, $y, $z, $state);
                $this->setBlock($x, $y, $z, $catalog->state($properties['waterlogged'] === 'true' ? 'water' : 'air'));
            }
        } elseif ($updated !== $state) {
            $this->setBlock($x, $y, $z, $updated);
        }
    }
}
