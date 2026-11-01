<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

/**
 * BaseRailBlock.onPlace and RailState: a new rail turns toward the rails beside it, which in turn
 * bend toward it. Powered, detector and activator rails stay straight.
 */
trait Rails
{
    /** RailState.updateConnections: the two positions each shape joins. */
    private const RAIL_CONNECTIONS = [
        'north_south' => [[0, 0, -1], [0, 0, 1]], 'east_west' => [[-1, 0, 0], [1, 0, 0]],
        'ascending_east' => [[-1, 0, 0], [1, 1, 0]], 'ascending_west' => [[-1, 1, 0], [1, 0, 0]],
        'ascending_north' => [[0, 1, -1], [0, 0, 1]], 'ascending_south' => [[0, 0, -1], [0, 1, 1]],
        'south_east' => [[1, 0, 0], [0, 0, 1]], 'south_west' => [[-1, 0, 0], [0, 0, 1]],
        'north_west' => [[-1, 0, 0], [0, 0, -1]], 'north_east' => [[1, 0, 0], [0, 0, -1]],
    ];

    /** BaseRailBlock.isRail: the rails tag. */
    private function isRail(int $state): bool
    {
        return $this->fireBlocks()->inTag($state, 'rails');
    }

    private function railAt(int $x, int $y, int $z): bool
    {
        return $this->isRail($this->world->get($x, $y, $z));
    }

    /** A RailState: position, state, straightness and connections. */
    private function railState(int $x, int $y, int $z, int $state): array
    {
        $rail = ['pos' => [$x, $y, $z], 'state' => $state, 'straight' => $this->version->catalog->blockName($state) !== 'rail'];
        $rail['connections'] = $this->railConnections($rail['pos'], $this->version->catalog->properties($state)['shape']);
        return $rail;
    }

    /** @return list<array{int, int, int}> */
    private function railConnections(array $pos, string $shape): array
    {
        return array_map(static fn (array $o): array => [$pos[0] + $o[0], $pos[1] + $o[1], $pos[2] + $o[2]], self::RAIL_CONNECTIONS[$shape]);
    }

    /** RailState.getRail: a rail at the position, or one block above or below it. */
    private function railNear(array $pos): ?array
    {
        foreach ([0, 1, -1] as $dy) {
            $state = $this->world->get($pos[0], $pos[1] + $dy, $pos[2]);
            if ($this->isRail($state)) {
                return $this->railState($pos[0], $pos[1] + $dy, $pos[2], $state);
            }
        }
        return null;
    }

    /** RailState.removeSoftConnections: keep only connections to rails that connect back. */
    private function removeSoftConnections(array &$rail): void
    {
        $kept = [];
        foreach ($rail['connections'] as $pos) {
            $other = $this->railNear($pos);
            if ($other !== null && $this->railHasConnection($other, $rail['pos'])) {
                $kept[] = $other['pos'];
            }
        }
        $rail['connections'] = $kept;
    }

    private function railHasConnection(array $rail, array $pos): bool
    {
        foreach ($rail['connections'] as $c) {
            if ($c[0] === $pos[0] && $c[2] === $pos[2]) {
                return true;
            }
        }
        return false;
    }

    private function railCanConnectTo(array $rail, array $other): bool
    {
        return $this->railHasConnection($rail, $other['pos']) || count($rail['connections']) !== 2;
    }

    /** Ascending toward a rail one block up along a straight shape. */
    private function railAscending(array $pos, ?string $shape): ?string
    {
        [$x, $y, $z] = $pos;
        if ($shape === 'north_south') {
            $shape = $this->railAt($x, $y + 1, $z - 1) ? 'ascending_north' : $shape;
            $shape = $this->railAt($x, $y + 1, $z + 1) ? 'ascending_south' : $shape;
        } elseif ($shape === 'east_west') {
            $shape = $this->railAt($x + 1, $y + 1, $z) ? 'ascending_east' : $shape;
            $shape = $this->railAt($x - 1, $y + 1, $z) ? 'ascending_west' : $shape;
        }
        return $shape;
    }

    /** RailState.connectTo: a neighbour turns toward the new rail. */
    private function railConnectTo(array $rail, array $other): void
    {
        $rail['connections'][] = $other['pos'];
        [$x, $y, $z] = $rail['pos'];
        [$n, $s, $w, $e] = [$this->railHasConnection($rail, [$x, $y, $z - 1]), $this->railHasConnection($rail, [$x, $y, $z + 1]),
            $this->railHasConnection($rail, [$x - 1, $y, $z]), $this->railHasConnection($rail, [$x + 1, $y, $z])];
        $shape = null;
        if ($n || $s) {
            $shape = 'north_south';
        }
        if ($w || $e) {
            $shape = 'east_west';
        }
        if (!$rail['straight']) {
            $shape = $s && $e && !$n && !$w ? 'south_east' : $shape;
            $shape = $s && $w && !$n && !$e ? 'south_west' : $shape;
            $shape = $n && $w && !$s && !$e ? 'north_west' : $shape;
            $shape = $n && $e && !$s && !$w ? 'north_east' : $shape;
        }
        $shape = $this->railAscending($rail['pos'], $shape) ?? 'north_south';
        $catalog = $this->version->catalog;
        $this->setBlock($x, $y, $z, $catalog->state($catalog->blockName($rail['state']), ['shape' => $shape] + $catalog->properties($rail['state'])));
    }

    /** BaseRailBlock.onPlace for a rail that was not there before: RailState.place with its own shape as the default. */
    private function placeRail(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $rail = $this->railState($x, $y, $z, $state);
        $default = $catalog->properties($state)['shape'];
        $near = function (array $pos) use (&$rail): bool {
            $other = $this->railNear($pos);
            if ($other === null) {
                return false;
            }
            $this->removeSoftConnections($other);
            return $this->railCanConnectTo($other, $rail);
        };
        [$n, $s, $w, $e] = [$near([$x, $y, $z - 1]), $near([$x, $y, $z + 1]), $near([$x - 1, $y, $z]), $near([$x + 1, $y, $z])];
        $shape = null;
        [$northOrSouth, $westOrEast] = [$n || $s, $w || $e];
        if ($northOrSouth && !$westOrEast) {
            $shape = 'north_south';
        }
        if ($westOrEast && !$northOrSouth) {
            $shape = 'east_west';
        }
        [$se, $sw, $ne, $nw] = [$s && $e, $s && $w, $n && $e, $n && $w];
        if (!$rail['straight']) {
            $shape = $se && !$n && !$w ? 'south_east' : $shape;
            $shape = $sw && !$n && !$e ? 'south_west' : $shape;
            $shape = $nw && !$s && !$e ? 'north_west' : $shape;
            $shape = $ne && !$s && !$w ? 'north_east' : $shape;
        }
        if ($shape === null) {
            $shape = $northOrSouth && $westOrEast ? $default : ($northOrSouth ? 'north_south' : ($westOrEast ? 'east_west' : null));
            if (!$rail['straight']) {
                // Level.hasNeighborSignal decides which corner wins among several.
                $order = $this->powerAt($x, $y, $z) > 0 ? [[$se, 'south_east'], [$sw, 'south_west'], [$ne, 'north_east'], [$nw, 'north_west']]
                    : [[$nw, 'north_west'], [$ne, 'north_east'], [$sw, 'south_west'], [$se, 'south_east']];
                foreach ($order as [$yes, $corner]) {
                    $shape = $yes ? $corner : $shape;
                }
            }
        }
        $shape = $this->railAscending($rail['pos'], $shape) ?? $default;
        $rail['connections'] = $this->railConnections($rail['pos'], $shape);
        $placed = $catalog->state($catalog->blockName($state), ['shape' => $shape] + $catalog->properties($state));
        $rail['state'] = $placed;
        $this->setBlock($x, $y, $z, $placed);
        foreach ($rail['connections'] as $pos) {
            $other = $this->railNear($pos);
            if ($other !== null) {
                $this->removeSoftConnections($other);
                if ($this->railCanConnectTo($other, $rail)) {
                    $this->railConnectTo($other, $rail);
                }
            }
        }
    }
}
