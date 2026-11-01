<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

/**
 * PoweredRailBlock for powered and activator rails: a rail is powered by a signal beside it, or
 * through up to eight rails of its own kind in line that are powered and lead to one.
 */
trait PoweredRails
{
    private function isPoweredRail(string $name): bool
    {
        return $name === 'powered_rail' || $name === 'activator_rail';
    }

    /** PoweredRailBlock.findPoweredRailSignal: look one rail further along the line. */
    private function poweredRailSignal(array $pos, string $name, string $shape, bool $forward, int $depth): bool
    {
        if ($depth >= 8) {
            return false;
        }
        [$x, $y, $z] = $pos;
        $below = true;
        switch ($shape) {
            case 'north_south':
                $z += $forward ? 1 : -1;
                break;
            case 'east_west':
                $x += $forward ? -1 : 1;
                break;
            case 'ascending_east':
                if ($forward) {
                    --$x;
                } else {
                    ++$x;
                    ++$y;
                    $below = false;
                }
                $shape = 'east_west';
                break;
            case 'ascending_west':
                if ($forward) {
                    --$x;
                    ++$y;
                    $below = false;
                } else {
                    ++$x;
                }
                $shape = 'east_west';
                break;
            case 'ascending_north':
                if ($forward) {
                    ++$z;
                } else {
                    --$z;
                    ++$y;
                    $below = false;
                }
                $shape = 'north_south';
                break;
            case 'ascending_south':
                if ($forward) {
                    ++$z;
                    ++$y;
                    $below = false;
                } else {
                    --$z;
                }
                $shape = 'north_south';
                break;
        }
        return $this->samePoweredRail([$x, $y, $z], $name, $forward, $depth, $shape)
            || ($below && $this->samePoweredRail([$x, $y - 1, $z], $name, $forward, $depth, $shape));
    }

    /** PoweredRailBlock.isSameRailWithPower: a powered rail of the same kind, not across the line, with power of its own or further on. */
    private function samePoweredRail(array $pos, string $name, bool $forward, int $depth, string $direction): bool
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get(...$pos);
        if ($catalog->blockName($state) !== $name) {
            return false;
        }
        $p = $catalog->properties($state);
        $shape = $p['shape'];
        if (($direction === 'east_west' && in_array($shape, ['north_south', 'ascending_north', 'ascending_south'], true))
            || ($direction === 'north_south' && in_array($shape, ['east_west', 'ascending_east', 'ascending_west'], true))
            || $p['powered'] !== 'true') {
            return false;
        }
        return $this->powerAt(...$pos) > 0 || $this->poweredRailSignal($pos, $name, $shape, $forward, $depth + 1);
    }

    /** PoweredRailBlock.updateState for the powered and activator rails at and beside a changed block. */
    private function poweredRailsChanged(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        foreach ([[0, 0, 0], [0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]] as [$dx, $dy, $dz]) {
            $pos = [$x + $dx, $y + $dy, $z + $dz];
            $state = $this->world->get(...$pos);
            $name = $catalog->blockName($state);
            if (!$this->isPoweredRail($name)) {
                continue;
            }
            $p = $catalog->properties($state);
            $should = $this->powerAt(...$pos) > 0 || $this->poweredRailSignal($pos, $name, $p['shape'], true, 0) || $this->poweredRailSignal($pos, $name, $p['shape'], false, 0);
            if ($should !== ($p['powered'] === 'true')) {
                $this->setBlock(...[...$pos, $catalog->state($name, ['powered' => $should ? 'true' : 'false'] + $p)]);
                // updateNeighborsAt below, and above a slope.
                $this->changedSimulation($pos[0], $pos[1] - 1, $pos[2]);
                if (str_starts_with($p['shape'], 'ascending')) {
                    $this->changedSimulation($pos[0], $pos[1] + 1, $pos[2]);
                }
            }
        }
    }
}
