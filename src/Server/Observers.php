<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

/**
 * ObserverBlock: a change to the block an observer faces starts a pulse two ticks later; it
 * stays powered for two ticks, strongly powering the block behind it.
 */
trait Observers
{
    private const OBSERVER_FACES = [
        'down' => [0, -1, 0], 'up' => [0, 1, 0], 'north' => [0, 0, -1], 'south' => [0, 0, 1], 'west' => [-1, 0, 0], 'east' => [1, 0, 0],
    ];

    /**
     * ObserverBlock.updateShape for the observers around a changed block, and onPlace for one
     * just set: an unpowered observer facing the change schedules its pulse unless one is due;
     * one set already powered with no pulse due switches off.
     */
    private function observersChanged(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        if ($catalog->blockName($state) === 'observer' && $catalog->properties($state)['powered'] === 'true' && !isset($this->blockTicks['observer:' . $x . ':' . $y . ':' . $z])) {
            $this->setBlock($x, $y, $z, $catalog->state('observer', ['powered' => 'false'] + $catalog->properties($state)));
        }
        foreach (self::OBSERVER_FACES as [$dx, $dy, $dz]) {
            [$a, $b, $c] = [$x - $dx, $y - $dy, $z - $dz];
            $neighbour = $this->world->get($a, $b, $c);
            if ($catalog->blockName($neighbour) !== 'observer') {
                continue;
            }
            $p = $catalog->properties($neighbour);
            if (self::OBSERVER_FACES[$p['facing']] === [$dx, $dy, $dz] && $p['powered'] === 'false') {
                $this->scheduleBlock('observer', $a, $b, $c, 2);
            }
        }
    }

    /** ObserverBlock.updateShape from Block.updateFromNeighbourShapes: an unpowered observer a command sets sees its front and pulses. */
    private function observerSetByCommand(int $x, int $y, int $z): void
    {
        $state = $this->world->get($x, $y, $z);
        if ($this->version->catalog->blockName($state) === 'observer' && $this->version->catalog->properties($state)['powered'] === 'false') {
            $this->scheduleBlock('observer', $x, $y, $z, 2);
        }
    }

    /** ObserverBlock.tick: switch on for two ticks, then off. */
    private function tickObserver(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        if ($catalog->blockName($state) !== 'observer') {
            return;
        }
        $p = $catalog->properties($state);
        if ($p['powered'] === 'true') {
            $this->setBlock($x, $y, $z, $catalog->state('observer', ['powered' => 'false'] + $p));
        } else {
            // Scheduled first, so the observer does not take itself for one set already powered.
            $this->scheduleBlock('observer', $x, $y, $z, 2);
            $this->setBlock($x, $y, $z, $catalog->state('observer', ['powered' => 'true'] + $p));
        }
        // updateNeighborsInFront: the block behind and its neighbours hear of the change.
        [$dx, $dy, $dz] = self::OBSERVER_FACES[$p['facing']];
        $this->changedSimulation($x - $dx, $y - $dy, $z - $dz);
    }
}
