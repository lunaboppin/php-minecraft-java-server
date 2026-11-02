<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Packets;

/**
 * TripWireBlock and TripWireHookBlock: two hooks facing each other across a line of string are
 * attached; an entity in the string presses it, powering both hooks (strongly the block each
 * hangs on) until the string is clear again.
 */
trait Tripwires
{
    private const TRIPWIRE_SIDES = ['north' => [0, 0, -1], 'east' => [1, 0, 0], 'south' => [0, 0, 1], 'west' => [-1, 0, 0]];

    private const TRIPWIRE_OPPOSITE = ['north' => 'south', 'south' => 'north', 'east' => 'west', 'west' => 'east'];

    private function tripwireName(int $state): string
    {
        return $this->version->catalog->blockName($state);
    }

    private function tripwireSet(array $pos, string $name, array $properties): void
    {
        $catalog = $this->version->catalog;
        $this->setBlock(...[...$pos, $catalog->state($name, array_map(static fn (mixed $v): string => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $properties))]);
    }

    /** TripWireBlock.shouldConnectTo: string, or a hook facing back toward it. */
    private function tripwireConnects(int $state, string $direction): bool
    {
        $name = $this->tripwireName($state);
        if ($name === 'tripwire_hook') {
            return $this->version->catalog->properties($state)['facing'] === self::TRIPWIRE_OPPOSITE[$direction];
        }
        return $name === 'tripwire';
    }

    /** TripWireBlock.getStateForPlacement and updateShape: its four connections. */
    private function tripwireConnected(int $state, int $x, int $y, int $z): int
    {
        $p = $this->version->catalog->properties($state);
        foreach (self::TRIPWIRE_SIDES as $side => [$dx, , $dz]) {
            $p[$side] = $this->tripwireConnects($this->world->get($x + $dx, $y, $z + $dz), $side) ? 'true' : 'false';
        }
        return $this->version->catalog->state('tripwire', $p);
    }

    private function tripwireSound(array $pos, string $sound, float $pitch): void
    {
        $payload = Packets::sound($this->version->blocks['sounds'][$sound], 4, $pos[0] + 0.5, $pos[1] + 0.5, $pos[2] + 0.5, 0.4, $pitch, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $pos[0] - 0.5) ** 2 + ($player->y - $pos[1] - 0.5) ** 2 + ($player->z - $pos[2] - 0.5) ** 2 < 256.0) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    /** TripWireHookBlock.emitState: the click, attach or detach sound. */
    private function tripwireEmit(array $pos, bool $attached, bool $powered, bool $wasAttached, bool $wasPowered): void
    {
        if ($powered && !$wasPowered) {
            $this->tripwireSound($pos, 'block.tripwire.click_on', 0.6);
        } elseif (!$powered && $wasPowered) {
            $this->tripwireSound($pos, 'block.tripwire.click_off', 0.5);
        } elseif ($attached && !$wasAttached) {
            $this->tripwireSound($pos, 'block.tripwire.attach', 0.7);
        } elseif (!$attached && $wasAttached) {
            $this->tripwireSound($pos, 'block.tripwire.detach', \PhpMinecraftJavaServer\World\Generation\Float32::round(1.2 / (self::randomUnit() * 0.2 + 0.9)));
        }
    }

    /** TripWireHookBlock.notifyNeighbors: around the hook and the block it hangs on. */
    private function tripwireNotify(array $pos, string $facing): void
    {
        [$dx, , $dz] = self::TRIPWIRE_SIDES[$facing];
        $this->changedSimulation($pos[0], $pos[1], $pos[2]);
        $this->changedSimulation($pos[0] - $dx, $pos[1], $pos[2] - $dz);
    }

    /** TripWireHookBlock.calculateState. */
    private function hookCalculate(array $pos, int $state, bool $destroyed, bool $canUpdate, int $wireSource, ?int $wireSourceState): void
    {
        $catalog = $this->version->catalog;
        $p = $catalog->properties($state);
        $direction = $p['facing'];
        [$dx, , $dz] = self::TRIPWIRE_SIDES[$direction];
        $wasAttached = $p['attached'] === 'true';
        $wasPowered = $p['powered'] === 'true';
        $attached = !$destroyed;
        $powered = false;
        $receiver = 0;
        $wires = [];
        for ($i = 1; $i < 42; ++$i) {
            $test = [$pos[0] + $dx * $i, $pos[1], $pos[2] + $dz * $i];
            $wire = $this->world->get(...$test);
            if ($this->tripwireName($wire) === 'tripwire_hook') {
                if ($catalog->properties($wire)['facing'] === self::TRIPWIRE_OPPOSITE[$direction]) {
                    $receiver = $i;
                }
                break;
            }
            if ($this->tripwireName($wire) !== 'tripwire' && $i !== $wireSource) {
                $wires[$i] = null;
                $attached = false;
            } else {
                if ($i === $wireSource) {
                    $wire = $wireSourceState ?? $wire;
                }
                $wp = $catalog->properties($wire);
                $armed = ($wp['disarmed'] ?? 'false') === 'false';
                $powered = $powered || ($armed && ($wp['powered'] ?? 'false') === 'true');
                $wires[$i] = $wire;
                if ($i === $wireSource) {
                    $this->scheduleBlock('tripwire_hook', $pos[0], $pos[1], $pos[2], 10);
                    $attached = $attached && $armed;
                }
            }
        }
        $attached = $attached && $receiver > 1;
        $powered = $powered && $attached;
        if ($receiver > 0) {
            $other = [$pos[0] + $dx * $receiver, $pos[1], $pos[2] + $dz * $receiver];
            $opposite = self::TRIPWIRE_OPPOSITE[$direction];
            $this->tripwireSet($other, 'tripwire_hook', ['facing' => $opposite, 'attached' => $attached, 'powered' => $powered]);
            $this->tripwireNotify($other, $opposite);
            if ($this->tripwireName($this->world->get(...$pos)) !== 'tripwire_hook') {
                $this->hookRemoved($pos, $catalog->state('tripwire_hook', ['facing' => $direction, 'attached' => $attached ? 'true' : 'false', 'powered' => $powered ? 'true' : 'false']));
                return;
            }
            $this->tripwireEmit($other, $attached, $powered, $wasAttached, $wasPowered);
        }
        $this->tripwireEmit($pos, $attached, $powered, $wasAttached, $wasPowered);
        if (!$destroyed) {
            $this->tripwireSet($pos, 'tripwire_hook', ['facing' => $direction, 'attached' => $attached, 'powered' => $powered]);
            if ($canUpdate) {
                $this->tripwireNotify($pos, $direction);
            }
        }
        if ($wasAttached !== $attached) {
            for ($i = 1; $i < $receiver; ++$i) {
                $test = [$pos[0] + $dx * $i, $pos[1], $pos[2] + $dz * $i];
                if (($wires[$i] ?? null) === null) {
                    continue;
                }
                $there = $this->tripwireName($this->world->get(...$test));
                if ($there === 'tripwire' || $there === 'tripwire_hook') {
                    $wp = $catalog->properties($wires[$i]);
                    $wp['attached'] = $attached ? 'true' : 'false';
                    $this->setBlock(...[...$test, $catalog->state($catalog->blockName($wires[$i]), $wp)]);
                }
            }
        }
    }

    /** TripWireHookBlock.onRemoved: a hook that was attached or powered recalculates its line as it goes. */
    private function hookRemoved(array $pos, int $state): void
    {
        $p = $this->version->catalog->properties($state);
        if ($p['attached'] === 'true' || $p['powered'] === 'true') {
            $this->hookCalculate($pos, $state, true, false, -1, null);
        }
        if ($p['powered'] === 'true') {
            $this->tripwireNotify($pos, $p['facing']);
        }
    }

    /** TripWireBlock.updateSource: the hooks south and west along the line hear of the string at $pos. */
    private function tripwireUpdateSource(array $pos, int $state): void
    {
        foreach (['south', 'west'] as $direction) {
            [$dx, , $dz] = self::TRIPWIRE_SIDES[$direction];
            for ($i = 1; $i < 42; ++$i) {
                $test = [$pos[0] + $dx * $i, $pos[1], $pos[2] + $dz * $i];
                $block = $this->world->get(...$test);
                $name = $this->tripwireName($block);
                if ($name === 'tripwire_hook') {
                    if ($this->version->catalog->properties($block)['facing'] === self::TRIPWIRE_OPPOSITE[$direction]) {
                        $this->hookCalculate($test, $block, false, true, $i, $state);
                    }
                    break;
                }
                if ($name !== 'tripwire') {
                    break;
                }
            }
        }
    }

    /**
     * Placing or removing string or a hook: string's onPlace and affectNeighborsAfterRemoval
     * update the hooks on its line; a hook removed recalculates its line.
     */
    private function tripwireBlockSwapped(int $x, int $y, int $z, int $previous, int $state): void
    {
        $catalog = $this->version->catalog;
        $old = $catalog->blockName($previous);
        $new = $catalog->blockName($state);
        if ($new === 'tripwire') {
            $this->tripwireUpdateSource([$x, $y, $z], $state);
        }
        if ($old === 'tripwire') {
            $this->tripwireUpdateSource([$x, $y, $z], $catalog->state('tripwire', ['powered' => 'true'] + $catalog->properties($previous)));
        }
        if ($old === 'tripwire_hook') {
            $this->hookRemoved([$x, $y, $z], $previous);
        }
    }

    /** TripWireBlock.updateShape: string beside a changed block reconnects to it. */
    private function tripwiresChanged(int $x, int $y, int $z): void
    {
        foreach (self::TRIPWIRE_SIDES as [$dx, , $dz]) {
            $state = $this->world->get($x + $dx, $y, $z + $dz);
            if ($this->tripwireName($state) === 'tripwire') {
                $connected = $this->tripwireConnected($state, $x + $dx, $y, $z + $dz);
                if ($connected !== $state) {
                    $this->setBlock($x + $dx, $y, $z + $dz, $connected);
                }
            }
        }
    }

    /** The entity boxes meeting the string's shape: 1 to 2.5 pixels up when attached, else the lower half. */
    private function tripwireEntities(array $pos, int $state): array
    {
        $attached = $this->version->catalog->properties($state)['attached'] === 'true';
        return $this->entitiesIn([$pos[0], $pos[1] + ($attached ? 0.0625 : 0.0), $pos[2], $pos[0] + 1.0, $pos[1] + ($attached ? 0.15625 : 0.5), $pos[2] + 1.0]);
    }

    /** TripWireBlock.checkPressed. */
    private function tripwireCheck(array $pos, ?bool $entityPresent = null): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get(...$pos);
        $p = $catalog->properties($state);
        $was = $p['powered'] === 'true';
        $pressed = $entityPresent ?? $this->tripwireEntities($pos, $state) !== [];
        if ($pressed !== $was) {
            $p['powered'] = $pressed ? 'true' : 'false';
            $state = $catalog->state('tripwire', $p);
            $this->setBlock(...[...$pos, $state]);
            $this->tripwireUpdateSource($pos, $state);
        }
        if ($pressed) {
            $this->scheduleBlock('tripwire', $pos[0], $pos[1], $pos[2], 10);
        } elseif ($was) {
            $this->scheduleBlock('tripwire', $pos[0], $pos[1], $pos[2], 1);
        }
    }

    /** TripWireBlock.tick and TripWireHookBlock.tick. */
    private function tickTripwire(string $kind, int $x, int $y, int $z): void
    {
        $state = $this->world->get($x, $y, $z);
        $name = $this->tripwireName($state);
        if ($kind === 'tripwire' && $name === 'tripwire' && $this->version->catalog->properties($state)['powered'] === 'true') {
            $this->tripwireCheck([$x, $y, $z]);
        } elseif ($kind === 'tripwire_hook' && $name === 'tripwire_hook') {
            $this->hookCalculate([$x, $y, $z], $state, false, true, -1, null);
        }
    }

    /** TripWireBlock.entityInside, after entities move: unpowered string with no tick due checks itself. */
    private function tickTripwireContacts(): void
    {
        $seen = [];
        foreach ($this->entitiesIn([-3.0E7, $this->world->minY - 64.0, -3.0E7, 3.0E7, 1.0E5, 3.0E7]) as [$box]) {
            for ($x = (int) floor($box[0]); $x <= (int) floor($box[3]); ++$x) {
                for ($y = (int) floor($box[1]); $y <= (int) floor($box[4]); ++$y) {
                    for ($z = (int) floor($box[2]); $z <= (int) floor($box[5]); ++$z) {
                        $key = $x . ':' . $y . ':' . $z;
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        $state = $this->world->get($x, $y, $z);
                        if ($this->tripwireName($state) !== 'tripwire' || $this->version->catalog->properties($state)['powered'] === 'true' || isset($this->blockTicks['tripwire:' . $key])) {
                            continue;
                        }
                        if ($this->tripwireEntities([$x, $y, $z], $state) !== []) {
                            $this->tripwireCheck([$x, $y, $z], true);
                        }
                    }
                }
            }
        }
    }

    /** TripWireHookBlock signals: 15 to every side when powered, strongly into the block it hangs on. */
    private function hookSignal(int $state): int
    {
        return $this->version->catalog->properties($state)['powered'] === 'true' ? 15 : 0;
    }
}
