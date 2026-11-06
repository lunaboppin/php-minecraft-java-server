<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Tracking\EntityMovementTracker;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Combat;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * Minecart and OldMinecartBehavior, the movement used without the minecart_improvements
 * experiment: a minecart follows the rail under it, slides down slopes, is boosted or braked by
 * powered rails and is hurt by powered activator rails; off the rails it rolls and falls.
 */
trait Minecarts
{
    /** @var array<int, array> minecarts by entity ID */
    private array $minecarts = [];

    /** @var array<int, EntityMovementTracker> */
    private array $minecartTrackers = [];

    /** AbstractMinecart.EXITS: the two ends of each rail shape, as [x, y, z] offsets. */
    private const RAIL_EXITS = [
        'north_south' => [[0, 0, -1], [0, 0, 1]],
        'east_west' => [[-1, 0, 0], [1, 0, 0]],
        'ascending_east' => [[-1, -1, 0], [1, 0, 0]],
        'ascending_west' => [[-1, 0, 0], [1, -1, 0]],
        'ascending_north' => [[0, 0, -1], [0, -1, 1]],
        'ascending_south' => [[0, -1, -1], [0, 0, 1]],
        'south_east' => [[0, 0, 1], [1, 0, 0]],
        'south_west' => [[0, 0, 1], [-1, 0, 0]],
        'north_west' => [[0, 0, -1], [-1, 0, 0]],
        'north_east' => [[0, 0, -1], [1, 0, 0]],
    ];

    /** Every minecart type, and the item its getDropItem leaves when it breaks. */
    private const MINECART_DROPS = ['minecart' => 'minecart', 'chest_minecart' => 'chest_minecart', 'furnace_minecart' => 'furnace_minecart',
        'tnt_minecart' => 'tnt_minecart', 'hopper_minecart' => 'hopper_minecart', 'command_block_minecart' => 'minecart', 'spawner_minecart' => 'minecart'];

    /** The minecart items MinecartItem places, each as its own type. */
    private const MINECART_ITEMS = ['minecart', 'chest_minecart', 'furnace_minecart', 'tnt_minecart', 'hopper_minecart', 'command_block_minecart'];

    private function addMinecart(float $x, float $y, float $z, array $velocity, float $yaw = 0.0, array $saved = []): int
    {
        $id = $this->nextEntity++;
        $this->minecarts[$id] = $saved + ['kind' => 'minecart', 'uuid' => random_bytes(16), 'x' => $x, 'y' => $y, 'z' => $z, 'velocity' => $velocity, 'yaw' => $yaw,
            'flipped' => false, 'hurtTime' => 0, 'hurtDir' => 1, 'damage' => 0.0, 'ground' => false, 'inWater' => false, 'dataDirty' => []];
        $this->minecarts[$id]['id'] = $id;
        return $id;
    }

    /** Minecarts saved with the level roll on from where they were. */
    private function restoreMinecarts(): void
    {
        foreach ($this->world->setting('minecarts') ?? [] as $saved) {
            if (!isset($saved['uuid'], $saved['x'], $saved['y'], $saved['z'])) {
                continue;
            }
            $saved['uuid'] = hex2bin($saved['uuid']);
            $saved['velocity'] = array_map('floatval', $saved['velocity'] ?? [0.0, 0.0, 0.0]);
            if (!isset(self::MINECART_DROPS[$saved['kind'] ?? 'minecart'])) {
                $saved['kind'] = 'minecart';
            }
            if (isset($saved['push'])) {
                $saved['push'] = array_map('floatval', $saved['push']);
            }
            $this->addMinecart((float) $saved['x'], (float) $saved['y'], (float) $saved['z'], $saved['velocity'], (float) ($saved['yaw'] ?? 0.0), $saved);
        }
    }

    /** AbstractMinecart.addAdditionalSaveData: FlippedRotation, with the position, motion and rotation every entity saves. */
    private function savedMinecarts(): array
    {
        return array_values(array_map(static fn (array $cart): array => ['uuid' => bin2hex($cart['uuid'])]
            + array_intersect_key($cart, array_flip(['kind', 'x', 'y', 'z', 'velocity', 'yaw', 'flipped', 'fuel', 'push', 'enabled', 'fuse', 'items'])), $this->minecarts));
    }

    /** The selector-shaped reference a command names a minecart by. */
    private function minecartReference(int $id): array
    {
        $cart = $this->minecarts[$id];
        return ['minecart' => $id, 'x' => $cart['x'], 'y' => $cart['y'], 'z' => $cart['z'], 'yaw' => (float) $cart['yaw'], 'pitch' => 0.0, 'type' => 'minecraft:' . $cart['kind'],
            'width' => 0.9800000190734863, 'height' => 0.699999988079071, 'id' => $id, 'uuid' => $cart['uuid']];
    }

    private function removeMinecart(int $id): void
    {
        $this->ejectPassengers($id);
        unset($this->minecarts[$id], $this->minecartTrackers[$id]);
        foreach ($this->localPlayers() as $player) {
            $this->forgetEntity($player, $id);
        }
    }

    /** ChunkMap.tick sends what changed before the entities tick; then AbstractMinecart.tick for each. */
    private function tickMinecarts(): void
    {
        foreach (array_keys($this->minecarts) as $id) {
            $this->synchronizeMinecart($id);
        }
        $collision = new Collision($this->version->catalog);
        foreach (array_keys($this->minecarts) as $id) {
            if (!isset($this->minecarts[$id])) {
                continue;
            }
            $cart = &$this->minecarts[$id];
            [$xo, , $zo] = [$cart['x'], $cart['y'], $cart['z']];
            $yawO = $cart['yaw'];
            $cart['firstTick'] = false;
            if ($cart['hurtTime'] > 0) {
                --$cart['hurtTime'];
                $cart['dataDirty'][8] = true;
            }
            if ($cart['damage'] > 0.0) {
                $cart['damage'] = Float32::round($cart['damage'] - 1.0);
                $cart['dataDirty'][10] = true;
            }
            // Entity.checkBelowWorld.
            if ($cart['y'] < $this->world->minY - 64) {
                unset($cart);
                $this->removeMinecart($id);
                continue;
            }
            // OldMinecartBehavior.tick: gravity, then along the rail or off it.
            $cart['velocity'][1] -= $cart['inWater'] ? 0.005 : 0.04;
            $pos = $this->minecartRailPos($cart);
            $state = $this->world->get(...$pos);
            if ($this->isRail($state)) {
                $this->moveMinecartAlongTrack($cart, $pos, $state, $collision);
                $properties = $this->version->catalog->properties($state);
                if ($this->version->catalog->blockName($state) === 'activator_rail' && $cart['kind'] === 'tnt_minecart') {
                    // MinecartTNT.activateMinecart: a powered rail primes it.
                    if (($properties['powered'] ?? 'false') === 'true' && ($cart['fuse'] ?? -1) < 0) {
                        $this->primeMinecartTnt($cart);
                    }
                } elseif ($this->version->catalog->blockName($state) === 'activator_rail' && $cart['kind'] === 'hopper_minecart') {
                    // MinecartHopper.activateMinecart: a powered rail turns it off, an unpowered one on.
                    $cart['enabled'] = ($properties['powered'] ?? 'false') !== 'true';
                } elseif ($this->version->catalog->blockName($state) === 'activator_rail' && ($properties['powered'] ?? 'false') === 'true' && $cart['kind'] === 'minecart') {
                    $this->activateMinecart($cart);
                    // Minecart.activateMinecart: the rider is put off.
                    foreach ($cart['passengers'] ?? [] as $passenger) {
                        $reference = $this->riderReference($passenger);
                        if ($reference !== null) {
                            unset($cart);
                            $this->stopRiding($reference);
                            $cart = &$this->minecarts[$id];
                        }
                    }
                }
            } else {
                $this->minecartComeOffTrack($cart, $collision);
            }
            // AbstractMinecart.applyEffectsFromBlocks: an unpowered detector rail the cart is in looks for it.
            $box = $this->minecartBox($cart);
            unset($cart);
            for ($bx = (int) floor($box[0] + 1.0E-5); $bx <= (int) floor($box[3] - 1.0E-5); ++$bx) {
                for ($by = (int) floor($box[1] + 1.0E-5); $by <= (int) floor($box[4] - 1.0E-5); ++$by) {
                    for ($bz = (int) floor($box[2] + 1.0E-5); $bz <= (int) floor($box[5] - 1.0E-5); ++$bz) {
                        $state = $this->world->get($bx, $by, $bz);
                        if ($this->version->catalog->blockName($state) === 'detector_rail' && ($this->version->catalog->properties($state)['powered'] ?? 'false') === 'false') {
                            $this->checkDetectorRail($bx, $by, $bz);
                        }
                    }
                }
            }
            $cart = &$this->minecarts[$id];
            $xDiff = $xo - $cart['x'];
            $zDiff = $zo - $cart['z'];
            if ($xDiff * $xDiff + $zDiff * $zDiff > 0.001) {
                $cart['yaw'] = Float32::round(atan2($zDiff, $xDiff) * 180.0 / M_PI);
                if ($cart['flipped']) {
                    $cart['yaw'] = Float32::round($cart['yaw'] + 180.0);
                }
            }
            $rotDiff = Float32::wrapDegrees(Float32::round($cart['yaw'] - $yawO));
            if ($rotDiff < -170.0 || $rotDiff >= 170.0) {
                $cart['yaw'] = Float32::round($cart['yaw'] + 180.0);
                $cart['flipped'] = !$cart['flipped'];
            }
            $cart['yaw'] = fmod($cart['yaw'], 360.0);
            // Entity.updateFluidInteraction: water pushes the minecart and slows it next tick.
            $fluids = $this->entityFluidCurrent($this->minecartBox($cart), $cart['velocity']);
            $cart['inWater'] = $fluids['water'] > 0.0;
            if ($cart['kind'] === 'furnace_minecart') {
                // MinecartFurnace.tick: the fuel burns down, the push stops with it, and the lit flag follows.
                $cart['fuel'] = max(0, ($cart['fuel'] ?? 0) - 1);
                if ($cart['fuel'] <= 0) {
                    $cart['push'] = [0.0, 0.0, 0.0];
                }
                if (($cart['lit'] ?? false) !== $cart['fuel'] > 0) {
                    $cart['lit'] = $cart['fuel'] > 0;
                    $cart['dataDirty'][13] = true;
                }
            }
            if ($cart['kind'] === 'tnt_minecart') {
                // MinecartTNT.tick: the fuse burns down and goes off at nought, and a hard knock sets it off too.
                $speed = $cart['velocity'][0] ** 2 + $cart['velocity'][2] ** 2;
                if (($cart['fuse'] ?? -1) > 0) {
                    --$cart['fuse'];
                } elseif (($cart['fuse'] ?? -1) === 0) {
                    unset($cart);
                    $this->explodeMinecartTnt($id, $speed);
                    continue;
                }
                if (($cart['horizontalCollision'] ?? false) && $speed >= Float32::round(0.01)) {
                    unset($cart);
                    $this->explodeMinecartTnt($id, $speed);
                    continue;
                }
            }
            $passengers = $cart['passengers'] ?? [];
            $sucks = $cart['kind'] === 'hopper_minecart' && ($cart['enabled'] ?? true);
            unset($cart);
            if ($sucks) {
                $this->hopperMinecartSuck($id);
            }
            // ServerLevel.tickPassenger: Player.rideTick lets a sneaking player off, then the seat moves the rider.
            foreach ($passengers as $passenger) {
                $reference = $this->riderReference($passenger);
                if (isset($reference['client']) && $reference['client']->sneaking) {
                    $this->stopRiding($reference);
                } elseif ($reference !== null) {
                    $this->positionRider($id, $passenger);
                }
            }
        }
    }

    /** EntityDimensions.makeBoundingBox: half the float width 0.98F, and the float height 0.7F. */
    private function minecartBox(array $cart): array
    {
        $half = 0.49000000953674316;
        return [$cart['x'] - $half, $cart['y'], $cart['z'] - $half, $cart['x'] + $half, $cart['y'] + 0.699999988079071, $cart['z'] + $half];
    }

    /** AbstractMinecart.getCurrentBlockPosOrRailBelow. */
    private function minecartRailPos(array $cart): array
    {
        $pos = [(int) floor($cart['x']), (int) floor($cart['y']), (int) floor($cart['z'])];
        if ($this->railAt($pos[0], $pos[1] - 1, $pos[2])) {
            --$pos[1];
        }
        return $pos;
    }

    private function railShape(int $state): string
    {
        return $this->version->catalog->properties($state)['shape'] ?? 'north_south';
    }

    /** OldMinecartBehavior.moveAlongTrack. */
    private function moveMinecartAlongTrack(array &$cart, array $pos, int $state, Collision $collision): void
    {
        [$x, , $z] = [$cart['x'], $cart['y'], $cart['z']];
        $oldPos = $this->minecartTrackPos($x, $cart['y'], $z);
        $y = (float) $pos[1];
        $name = $this->version->catalog->blockName($state);
        $powerTrack = false;
        $haltTrack = false;
        if ($name === 'powered_rail') {
            $powerTrack = ($this->version->catalog->properties($state)['powered'] ?? 'false') === 'true';
            $haltTrack = !$powerTrack;
        }
        $slide = $cart['inWater'] ? 0.0078125 * 0.2 : 0.0078125;
        $shape = $this->railShape($state);
        $slope = match ($shape) {
            'ascending_east' => [-$slide, 0.0],
            'ascending_west' => [$slide, 0.0],
            'ascending_north' => [0.0, $slide],
            'ascending_south' => [0.0, -$slide],
            default => null,
        };
        if ($slope !== null) {
            $cart['velocity'][0] += $slope[0];
            $cart['velocity'][2] += $slope[1];
            ++$y;
        }
        [$exit0, $exit1] = self::RAIL_EXITS[$shape];
        $xD = (float) ($exit1[0] - $exit0[0]);
        $zD = (float) ($exit1[2] - $exit0[2]);
        $length = sqrt($xD * $xD + $zD * $zD);
        if ($cart['velocity'][0] * $xD + $cart['velocity'][2] * $zD < 0.0) {
            $xD = -$xD;
            $zD = -$zD;
        }
        $pow = min(2.0, sqrt($cart['velocity'][0] ** 2 + $cart['velocity'][2] ** 2));
        $cart['velocity'] = [$pow * $xD / $length, $cart['velocity'][1], $pow * $zD / $length];
        // A riding player's movement keys nudge a cart that is all but still.
        $intent = $this->minecartMoveIntent($cart);
        if ($intent !== null && $cart['velocity'][0] ** 2 + $cart['velocity'][2] ** 2 < 0.01) {
            $cart['velocity'][0] += $intent[0] * 0.001;
            $cart['velocity'][2] += $intent[1] * 0.001;
            $haltTrack = false;
        }
        if ($haltTrack) {
            $speed = sqrt($cart['velocity'][0] ** 2 + $cart['velocity'][2] ** 2);
            $cart['velocity'] = $speed < 0.03 ? [0.0, 0.0, 0.0] : [$cart['velocity'][0] * 0.5, 0.0, $cart['velocity'][2] * 0.5];
        }
        $x0 = $pos[0] + 0.5 + $exit0[0] * 0.5;
        $z0 = $pos[2] + 0.5 + $exit0[2] * 0.5;
        $x1 = $pos[0] + 0.5 + $exit1[0] * 0.5;
        $z1 = $pos[2] + 0.5 + $exit1[2] * 0.5;
        $xD = $x1 - $x0;
        $zD = $z1 - $z0;
        if ($xD === 0.0) {
            $progress = $z - $pos[2];
        } elseif ($zD === 0.0) {
            $progress = $x - $pos[0];
        } else {
            $progress = (($x - $x0) * $xD + ($z - $z0) * $zD) * 2.0;
        }
        [$cart['x'], $cart['y'], $cart['z']] = [$x0 + $xD * $progress, $y, $z0 + $zD * $progress];
        $max = $this->minecartMaxSpeed($cart);
        // A ridden minecart covers three quarters of its speed each tick.
        $scale = ($cart['passengers'] ?? []) === [] ? 1.0 : 0.75;
        $clamp = static fn (float $v): float => max(-$max, min($max, $scale * $v));
        $this->moveSimpleEntity($cart, $this->minecartBox($cart), $collision, $cart['inWater'] ? 0.005 : 0.04, [$clamp($cart['velocity'][0]), 0.0, $clamp($cart['velocity'][2])], true);
        foreach ([$exit0, $exit1] as $exit) {
            if ($exit[1] !== 0 && (int) floor($cart['x']) - $pos[0] === $exit[0] && (int) floor($cart['z']) - $pos[2] === $exit[2]) {
                $cart['y'] += $exit[1];
                break;
            }
        }
        if ($cart['kind'] === 'chest_minecart' || $cart['kind'] === 'hopper_minecart') {
            // AbstractMinecartContainer.applyNaturalSlowdown: the float 0.98 and a thousandth for
            // each step of emptiness (an empty one keeps 0.995), times 0.95 in water.
            $keep = Float32::round(Float32::round(0.98) + Float32::round((15 - $this->minecartSignal($cart)) * Float32::round(0.001)));
            if ($cart['inWater']) {
                $keep = Float32::round($keep * Float32::round(0.95));
            }
            $cart['velocity'] = [$cart['velocity'][0] * $keep, 0.0, $cart['velocity'][2] * $keep];
        } else {
            if ($cart['kind'] === 'furnace_minecart') {
                $this->furnaceMinecartSlowdown($cart);
            }
            // AbstractMinecart.applyNaturalSlowdown: 0.96, or 0.997 with a passenger, and the float 0.95 in water.
            $slowdown = ($cart['passengers'] ?? []) === [] ? 0.96 : 0.997;
            $cart['velocity'] = [$cart['velocity'][0] * $slowdown, 0.0, $cart['velocity'][2] * $slowdown];
            if ($cart['inWater']) {
                $cart['velocity'] = array_map(static fn (float $v): float => $v * 0.949999988079071, $cart['velocity']);
            }
        }
        $newPos = $this->minecartTrackPos($cart['x'], $cart['y'], $cart['z']);
        if ($newPos !== null && $oldPos !== null) {
            $speed = ($oldPos[1] - $newPos[1]) * 0.05;
            $otherPow = sqrt($cart['velocity'][0] ** 2 + $cart['velocity'][2] ** 2);
            if ($otherPow > 0.0) {
                $scale = ($otherPow + $speed) / $otherPow;
                $cart['velocity'] = [$cart['velocity'][0] * $scale, $cart['velocity'][1], $cart['velocity'][2] * $scale];
            }
            $cart['y'] = $newPos[1];
        }
        $xn = (int) floor($cart['x']);
        $zn = (int) floor($cart['z']);
        if ($xn !== $pos[0] || $zn !== $pos[2]) {
            $otherPow = sqrt($cart['velocity'][0] ** 2 + $cart['velocity'][2] ** 2);
            $cart['velocity'] = [$otherPow * ($xn - $pos[0]), $cart['velocity'][1], $otherPow * ($zn - $pos[2])];
        }
        if (!$powerTrack) {
            return;
        }
        $speed = sqrt($cart['velocity'][0] ** 2 + $cart['velocity'][2] ** 2);
        if ($speed > 0.01) {
            $cart['velocity'] = [$cart['velocity'][0] + $cart['velocity'][0] / $speed * 0.06, $cart['velocity'][1], $cart['velocity'][2] + $cart['velocity'][2] / $speed * 0.06];
            return;
        }
        // From standstill a powered rail pushes away from a conductor at one end.
        $conductor = fn (int $dx, int $dz): bool => $this->rsConductor($this->world->get($pos[0] + $dx, $pos[1], $pos[2] + $dz));
        if ($shape === 'east_west') {
            if ($conductor(-1, 0)) {
                $cart['velocity'][0] = 0.02;
            } elseif ($conductor(1, 0)) {
                $cart['velocity'][0] = -0.02;
            }
        } elseif ($shape === 'north_south') {
            if ($conductor(0, -1)) {
                $cart['velocity'][2] = 0.02;
            } elseif ($conductor(0, 1)) {
                $cart['velocity'][2] = -0.02;
            }
        }
    }

    /** OldMinecartBehavior.getPos: the point on the rail at or just under a position, or null off the rails. */
    private function minecartTrackPos(float $x, float $y, float $z): ?array
    {
        $xt = (int) floor($x);
        $yt = (int) floor($y);
        $zt = (int) floor($z);
        if ($this->railAt($xt, $yt - 1, $zt)) {
            --$yt;
        }
        $state = $this->world->get($xt, $yt, $zt);
        if (!$this->isRail($state)) {
            return null;
        }
        [$exit0, $exit1] = self::RAIL_EXITS[$this->railShape($state)];
        $x0 = $xt + 0.5 + $exit0[0] * 0.5;
        $y0 = $yt + 0.0625 + $exit0[1] * 0.5;
        $z0 = $zt + 0.5 + $exit0[2] * 0.5;
        $x1 = $xt + 0.5 + $exit1[0] * 0.5;
        $y1 = $yt + 0.0625 + $exit1[1] * 0.5;
        $z1 = $zt + 0.5 + $exit1[2] * 0.5;
        $xD = $x1 - $x0;
        $yD = ($y1 - $y0) * 2.0;
        $zD = $z1 - $z0;
        if ($xD === 0.0) {
            $progress = $z - $zt;
        } elseif ($zD === 0.0) {
            $progress = $x - $xt;
        } else {
            $progress = (($x - $x0) * $xD + ($z - $z0) * $zD) * 2.0;
        }
        $y = $y0 + $yD * $progress;
        if ($yD < 0.0) {
            ++$y;
        } elseif ($yD > 0.0) {
            $y += 0.5;
        }
        return [$x0 + $xD * $progress, $y, $z0 + $zD * $progress];
    }

    /** AbstractMinecart.comeOffTrack: capped speed, halved on the ground, and the float 0.95 air drag in the air. */
    private function minecartComeOffTrack(array &$cart, Collision $collision): void
    {
        $max = $this->minecartMaxSpeed($cart);
        $cart['velocity'][0] = max(-$max, min($max, $cart['velocity'][0]));
        $cart['velocity'][2] = max(-$max, min($max, $cart['velocity'][2]));
        if ($cart['ground']) {
            $cart['velocity'] = array_map(static fn (float $v): float => $v * 0.5, $cart['velocity']);
        }
        $this->moveSimpleEntity($cart, $this->minecartBox($cart), $collision, $cart['inWater'] ? 0.005 : 0.04, null, true);
        if (!$cart['ground']) {
            $cart['velocity'] = array_map(static fn (float $v): float => $v * 0.949999988079071, $cart['velocity']);
        }
    }

    /**
     * MinecartItem.useOn: on a rail, a minecart at the block's centre, a sixteenth up (and half a
     * block more on a slope), facing the default way. The stack shrinks unless the player has
     * infinite materials.
     */
    private function placeMinecart(Client $c, int $slot, int $x, int $y, int $z, string $kind): bool
    {
        $state = $this->world->get($x, $y, $z);
        if (!$this->isRail($state)) {
            return false;
        }
        $slope = str_starts_with($this->railShape($state), 'ascending');
        // A minecart made from an item, not loaded from data, has yet to tick.
        $id = $this->addMinecart($x + 0.5, $y + 0.0625 + ($slope ? 0.5 : 0.0), $z + 0.5, [0.0, 0.0, 0.0], 0.0, ['kind' => $kind, 'firstTick' => true]);
        foreach ($this->localPlayers() as $player) {
            $this->showMinecart($player, $id);
        }
        if ($c->gameMode !== 1) {
            \PhpMinecraftJavaServer\World\Inventory::consume($c->inventory, $slot);
            $this->syncInventory($c);
        }
        return true;
    }

    /**
     * Player.attack on a minecart, then VehicleEntity.hurtServer: the hurt direction flips, it
     * shakes for 10 ticks and its damage grows by ten times the hit. A creative player's hit
     * removes it; otherwise, past 40 damage, it breaks and drops a minecart when entity drops
     * are on.
     */
    private function attackMinecart(Client $c, int $id): bool
    {
        if (!isset($this->minecarts[$id])) {
            return false;
        }
        $cart = &$this->minecarts[$id];
        if (($c->x - $cart['x']) ** 2 + ($c->y - $cart['y']) ** 2 + ($c->z - $cart['z']) ** 2 > 4.5 ** 2) {
            return true;
        }
        $tool = $this->version->catalog->itemName($c->inventory[36 + $c->heldSlot]['item'] ?? -1) ?? '';
        $weapon = $this->version->catalog->combat['items'][$tool] ?? $this->version->catalog->combat['default_item'];
        $elapsed = $this->ticks - $c->lastAttackTick;
        if (Combat::strength($weapon['speed'], $elapsed, 1.0) < $weapon['minimum_charge']) {
            return true;
        }
        $damage = Combat::attack($this->effectAttackDamage($c, (float) $weapon['damage']), $weapon['speed'], $elapsed);
        $c->lastAttackTick = $this->ticks;
        if (in_array($c->gameMode, [0, 2], true)) {
            $c->exhaustion += 0.1;
        }
        if ($damage <= 0.0) {
            return true;
        }
        $cart['hurtDir'] = -$cart['hurtDir'];
        $cart['hurtTime'] = 10;
        $cart['syncVelocity'] = true;
        $cart['damage'] = Float32::round($cart['damage'] + Float32::round($damage * 10.0));
        $cart['dataDirty'] += [8 => true, 9 => true, 10 => true];
        if ($c->gameMode !== 1 && $cart['damage'] > 40.0 && $cart['kind'] === 'tnt_minecart' && $cart['velocity'][0] ** 2 + $cart['velocity'][2] ** 2 >= Float32::round(0.01)) {
            // MinecartTNT.destroy: a moving TNT minecart is primed with a short fuse instead.
            if (($cart['fuse'] ?? -1) < 0) {
                $this->primeMinecartTnt($cart);
                $cart['fuse'] = random_int(0, 19) + random_int(0, 19);
            }
            return true;
        }
        if ($c->gameMode === 1 || $cart['damage'] > 40.0) {
            [$x, $y, $z, $kind, $items] = [$cart['x'], $cart['y'], $cart['z'], $cart['kind'], $cart['items'] ?? []];
            unset($cart);
            $this->removeMinecart($id);
            // VehicleEntity.destroy: the drop, unless the hit was a creative player's; then
            // AbstractMinecartContainer.destroy drops what it held.
            if ($c->gameMode !== 1 && $this->gameRule('entity_drops') === 'true') {
                $this->spawnAtLocation($x, $y, $z, ['item' => $this->version->blocks['items'][self::MINECART_DROPS[$kind]], 'count' => 1]);
                foreach ($items as $stack) {
                    $this->dropContainerStack((int) floor($x), (int) floor($y), (int) floor($z), $stack);
                }
            }
        }
        return true;
    }

    /**
     * Minecart.interact: without the secondary action, a player gets into an empty minecart
     * within reach.
     */
    private function interactMinecart(Client $player, int $id, int $hand, bool $secondary): void
    {
        $cart = $this->minecarts[$id];
        if ($player->dead || $player->gameMode === 3 || $hand < 0 || $hand > 1) {
            return;
        }
        if ($cart['kind'] === 'furnace_minecart') {
            if ($this->withinMinecartReach($player, $cart)) {
                $this->fuelFurnaceMinecart($player, $id, $hand);
                $this->useSwing($player, $hand);
            }
            return;
        }
        if (($cart['kind'] === 'chest_minecart' || $cart['kind'] === 'hopper_minecart') && $this->withinMinecartReach($player, $cart)) {
            // AbstractMinecartContainer.interact: its menu opens.
            $this->openWindow($player, $cart['kind'], ['minecart', $id]);
            $this->useSwing($player, $hand);
            return;
        }
        if ($cart['kind'] !== 'minecart' || $secondary || ($cart['passengers'] ?? []) !== [] || !$this->withinMinecartReach($player, $cart)) {
            return;
        }
        if ($this->startRiding(['client' => $player, 'id' => $player->entityId], $id)) {
            $this->useSwing($player, $hand);
        }
    }

    /** Player.isWithinEntityInteractionRange with the server's 3-block allowance, or another. */
    private function withinMinecartReach(Client $player, array $cart, float $allowance = 3.0): bool
    {
        $box = $this->minecartBox($cart);
        $eye = [$player->x, $player->y + ($player->sneaking ? 1.27 : 1.62), $player->z];
        $distance = 0.0;
        foreach ([0, 1, 2] as $axis) {
            $distance += max($box[$axis] - $eye[$axis], 0.0, $eye[$axis] - $box[$axis + 3]) ** 2;
        }
        return $distance < (3.0 + $allowance) ** 2;
    }

    /**
     * MinecartHopper.suckInItems: one item from the container whose block is above its bowl,
     * through that container's bottom; otherwise, unless a full block covers it, a dropped item
     * in the column above the bowl, or one touching the cart's box grown by a quarter sideways.
     */
    private function hopperMinecartSuck(int $id): void
    {
        $cart = $this->minecarts[$id];
        $above = [(int) floor($cart['x']), (int) floor($cart['y'] + 0.5 + 1.0), (int) floor($cart['z'])];
        $source = $this->hopperContainer($above);
        if ($source !== null) {
            $stacks = $this->containerStacks($source);
            foreach ($this->containerFaceSlots($source, 'down') as $slot) {
                $stack = $stacks[$slot] ?? null;
                if ($stack === null || !$this->canTakeFromContainer($source, $slot, $stack, 'down')) {
                    continue;
                }
                $left = $this->addToMinecart($id, array_replace($stack, ['count' => 1]));
                if ($left === null) {
                    $this->takeFromContainer($source, $slot);
                    return;
                }
            }
        }
        $catalog = $this->version->catalog;
        $over = $this->world->get(...$above);
        $covered = $source !== null || $catalog->collision['shapes'][$catalog->collision['states'][$over]] === [[0.0, 0.0, 0.0, 1.0, 1.0, 1.0]];
        $boxes = $covered ? [] : [[$cart['x'] - 0.5, $cart['y'] + 0.6875, $cart['z'] - 0.5, $cart['x'] + 0.5, $cart['y'] + 2.0, $cart['z'] + 0.5]];
        $own = $this->minecartBox($cart);
        $boxes[] = [$own[0] - 0.25, $own[1], $own[2] - 0.25, $own[3] + 0.25, $own[4], $own[5] + 0.25];
        foreach ($boxes as $box) {
            foreach (array_keys($this->itemEntities) as $item) {
                $entity = $this->itemEntities[$item] ?? null;
                if ($entity === null || ($entity['discard'] ?? false) || !self::itemInBox($entity, $box)) {
                    continue;
                }
                $left = $this->addToMinecart($id, $entity['stack']);
                if ($left === null) {
                    $this->removeItem($item);
                    return;
                }
                $this->itemEntities[$item]['stack'] = $left;
                $this->itemEntities[$item]['dirty'] = true;
            }
        }
    }

    /**
     * HopperBlockEntity.addItem into a minecart's slots in order: an empty slot takes the stack,
     * a slot holding the same item takes what fits. Returns what is left, or null.
     */
    private function addToMinecart(int $id, array $stack): ?array
    {
        $items = $this->minecarts[$id]['items'] ?? [];
        $size = $this->minecarts[$id]['kind'] === 'hopper_minecart' ? 5 : 27;
        for ($slot = 0; $slot < $size && $stack['count'] > 0; ++$slot) {
            $current = $items[$slot] ?? null;
            if ($current === null) {
                $items[$slot] = $stack;
                $stack['count'] = 0;
            } elseif (array_diff_key($current, ['count' => 0]) == array_diff_key($stack, ['count' => 0])) {
                $moved = min($stack['count'], ($this->version->catalog->item($stack['item'])['max_stack'] ?? 64) - $current['count']);
                if ($moved > 0) {
                    $items[$slot]['count'] += $moved;
                    $stack['count'] -= $moved;
                }
            }
        }
        $this->minecarts[$id]['items'] = $items;
        $this->syncContainer(['minecart', $id]);
        return $stack['count'] > 0 ? $stack : null;
    }

    /** AbstractContainerMenu.getRedstoneSignalFromContainer for a chest or hopper minecart. */
    private function minecartSignal(array $cart): int
    {
        $share = 0.0;
        foreach ($cart['items'] ?? [] as $stack) {
            $share = Float32::round($share + Float32::round($stack['count'] / min(64, $this->version->catalog->item($stack['item'])['max_stack'] ?? 64)));
        }
        $share = Float32::round($share / ($cart['kind'] === 'hopper_minecart' ? 5 : 27));
        return (int) floor($share * 14) + ($share > 0.0 ? 1 : 0);
    }

    /** AbstractMinecart.getMaxSpeed: 0.4, 0.2 in water; a furnace minecart's is half that, three quarters in water. */
    private function minecartMaxSpeed(array $cart): float
    {
        $max = $cart['inWater'] ? 0.2 : 0.4;
        if ($cart['kind'] === 'furnace_minecart') {
            return $cart['inWater'] ? $max * 0.75 : $max * 0.5;
        }
        return $max;
    }

    /**
     * MinecartFurnace.interact and addFuel: coal or charcoal adds 3600 ticks of fuel up to 32000,
     * and pushes the cart away from the player, by however far apart they stand. The item is
     * used up unless the player has infinite materials.
     */
    private function fuelFurnaceMinecart(Client $player, int $id, int $hand): void
    {
        $slot = $hand === 0 ? 36 + $player->heldSlot : 45;
        $item = $this->version->catalog->itemName($player->inventory[$slot]['item'] ?? -1) ?? '';
        $cart = &$this->minecarts[$id];
        if (!in_array($item, $this->version->registryTags('minecraft:item')['minecraft:furnace_minecart_fuel'] ?? [], true)) {
            return;
        }
        if (($cart['fuel'] ?? 0) + 3600 > 32000) {
            return;
        }
        $cart['fuel'] = ($cart['fuel'] ?? 0) + 3600;
        $cart['push'] = [$cart['x'] - $player->x, 0.0, $cart['z'] - $player->z];
        if ($player->gameMode !== 1) {
            \PhpMinecraftJavaServer\World\Inventory::consume($player->inventory, $slot);
            $this->syncInventory($player);
        }
    }

    /**
     * MinecartFurnace.applyNaturalSlowdown: while pushing, the push turns along the way the cart
     * moves and is added to four fifths of its motion (a tenth of that in water); otherwise the
     * motion keeps 0.98 of itself.
     */
    private function furnaceMinecartSlowdown(array &$cart): void
    {
        $push = $cart['push'] ?? [0.0, 0.0, 0.0];
        $v = $cart['velocity'];
        if ($push[0] ** 2 + $push[1] ** 2 + $push[2] ** 2 <= 1.0E-7) {
            $cart['velocity'] = [$v[0] * 0.98, 0.0, $v[2] * 0.98];
            return;
        }
        if ($push[0] ** 2 + $push[2] ** 2 > 1.0E-4 && $v[0] ** 2 + $v[2] ** 2 > 0.001) {
            // Vec3.projectedOn, normalized and scaled back to the push's length.
            $dot = ($push[0] * $v[0] + $push[1] * $v[1] + $push[2] * $v[2]) / ($v[0] * $v[0] + $v[1] * $v[1] + $v[2] * $v[2]);
            $projected = [$v[0] * $dot, $v[1] * $dot, $v[2] * $dot];
            $length = sqrt($projected[0] * $projected[0] + $projected[1] * $projected[1] + $projected[2] * $projected[2]);
            $pushLength = sqrt($push[0] * $push[0] + $push[1] * $push[1] + $push[2] * $push[2]);
            $push = $length < 9.999999747378752E-6 ? [0.0, 0.0, 0.0]
                : [$projected[0] / $length * $pushLength, $projected[1] / $length * $pushLength, $projected[2] / $length * $pushLength];
            $cart['push'] = $push;
        }
        $next = [$v[0] * 0.8 + $push[0], $push[1], $v[2] * 0.8 + $push[2]];
        $cart['velocity'] = $cart['inWater'] ? [$next[0] * 0.1, $next[1] * 0.1, $next[2] * 0.1] : $next;
    }

    /**
     * ServerPlayer.getLastClientMoveIntent for the minecart's rider: the movement keys held, as
     * Entity.getInputVector turns them by the player's yaw.
     *
     * @return array{float, float}|null
     */
    private function minecartMoveIntent(array $cart): ?array
    {
        $reference = isset($cart['passengers'][0]) ? $this->riderReference($cart['passengers'][0]) : null;
        if (!isset($reference['client'])) {
            return null;
        }
        $input = $reference['client']->movementInput;
        $left = (($input & 4) !== 0) === (($input & 8) !== 0) ? 0.0 : (($input & 4) !== 0 ? 1.0 : -1.0);
        $forward = (($input & 1) !== 0) === (($input & 2) !== 0) ? 0.0 : (($input & 1) !== 0 ? 1.0 : -1.0);
        $length = $left * $left + $forward * $forward;
        if ($length < 1.0E-7) {
            return null;
        }
        if ($length > 1.0) {
            [$left, $forward] = [$left / sqrt($length), $forward / sqrt($length)];
        }
        $radians = Float32::round($reference['client']->yaw * Float32::round(M_PI / 180.0));
        $sin = $this->mthSin($radians);
        $cos = $this->mthCos($radians);
        return [$left * $cos - $forward * $sin, $forward * $cos + $left * $sin];
    }

    /**
     * AbstractMinecart.getDismountLocationForPassenger: beside the cart, across the way it moves
     * (DismountHelper.offsetsForDirection), on the cart's level, a block up or a block down, in
     * the first pose that fits; otherwise on top of the cart.
     */
    private function minecartDismountLocation(array $entity, int $vehicle): array
    {
        $cart = $this->minecarts[$vehicle];
        $top = [$cart['x'], $this->minecartBox($cart)[4], $cart['z']];
        // OldMinecartBehavior.getMotionDirection: clockwise of the way the cart faces (turned round when flipped).
        $steps = [[0, 1], [-1, 0], [0, -1], [1, 0]];
        $facing = ((int) floor((float) $cart['yaw'] / 90.0 + 0.5)) & 3;
        $forward = $steps[($facing + ($cart['flipped'] ? 3 : 1)) & 3];
        $right = [-$forward[1], $forward[0]];
        $left = [-$right[0], -$right[1]];
        $back = [-$forward[0], -$forward[1]];
        $offsets = [$right, $left, [$back[0] + $right[0], $back[1] + $right[1]], [$back[0] + $left[0], $back[1] + $left[1]],
            [$forward[0] + $right[0], $forward[1] + $right[1]], [$forward[0] + $left[0], $forward[1] + $left[1]], $back, $forward];
        $collision = new Collision($this->version->catalog);
        [$baseX, $baseY, $baseZ] = [(int) floor($cart['x']), (int) floor($cart['y']), (int) floor($cart['z'])];
        $heights = [[0, 1, -1], [0, 1, -1], [0, 1]];
        foreach ($this->dismountBounds($entity) as $pose => [$width, $height]) {
            $reach = min($width, 1.0) / 2.0;
            foreach ($heights[$pose] ?? [0] as $dy) {
                foreach ($offsets as [$dx, $dz]) {
                    [$x, $y, $z] = [$baseX + $dx, $baseY + $dy, $baseZ + $dz];
                    $floor = $this->blockFloorHeight($collision, $x, $y, $z);
                    if (is_infinite($floor) || $floor >= 1.0) {
                        continue;
                    }
                    $location = [$x + 0.5, $y + $floor, $z + 0.5];
                    if ($this->canDismountTo($collision, [$location[0] - $reach, $location[1], $location[2] - $reach, $location[0] + $reach, $location[1] + $height, $location[2] + $reach])) {
                        return $location;
                    }
                }
            }
        }
        return $top;
    }

    /**
     * DetectorRailBlock.checkPressed: a rail that can stay is powered while a minecart's box
     * meets the middle of its block (0.2 in from each side, and 0.2 down from the top). A change
     * tells the rails it connects to, its neighbours and the block below; a pressed rail looks
     * again 20 ticks later; comparators read it again either way.
     */
    private function checkDetectorRail(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        if ($catalog->blockName($state) !== 'detector_rail') {
            return;
        }
        $p = $catalog->properties($state);
        if ($this->railUnsupported($x, $y, $z, $p['shape'])) {
            return;
        }
        $search = [$x + 0.2, $y, $z + 0.2, $x + 0.8, $y + 0.8, $z + 0.8];
        $pressed = false;
        foreach ($this->minecarts as $cart) {
            $box = $this->minecartBox($cart);
            if ($box[0] < $search[3] && $box[3] > $search[0] && $box[1] < $search[4] && $box[4] > $search[1] && $box[2] < $search[5] && $box[5] > $search[2]) {
                $pressed = true;
                break;
            }
        }
        if ($pressed !== ($p['powered'] === 'true')) {
            $this->setBlock($x, $y, $z, $catalog->state('detector_rail', ['powered' => $pressed ? 'true' : 'false'] + $p));
            // updatePowerToConnected: the rails it joins hear of the change.
            foreach ($this->railConnections([$x, $y, $z], $p['shape']) as $pos) {
                $this->poweredRailsChanged(...$pos);
            }
            $this->changedSimulation($x, $y - 1, $z);
        }
        if ($pressed) {
            $this->scheduleBlock('detector_rail', $x, $y, $z, 20);
        }
        $this->containerContentsChanged([$x, $y, $z]);
    }

    /** DetectorRailBlock.tick: a powered rail looks again. */
    private function tickDetectorRail(int $x, int $y, int $z): void
    {
        $state = $this->world->get($x, $y, $z);
        if ($this->version->catalog->blockName($state) === 'detector_rail' && ($this->version->catalog->properties($state)['powered'] ?? 'false') === 'true') {
            $this->checkDetectorRail($x, $y, $z);
        }
    }

    /**
     * MinecartDispenseItemBehavior: a minecart set 1.125 out from the dispenser's centre, on the
     * rail in front of it (0.1 up, or 0.6 on a slope) or on one a block lower (0.9 down, or 0.4
     * on a slope unless dispensing downward). Anything else in front, or no rail, dispenses the
     * item as any other, and the dispense sound and smoke then come a second time.
     */
    private function dispenseMinecart(int $x, int $y, int $z, string $facing, array $container, int $slot, string $kind): void
    {
        [$dx, $dy, $dz] = self::DISPENSE_FACING[$facing];
        $catalog = $this->version->catalog;
        $front = $this->world->get($x + $dx, $y + $dy, $z + $dz);
        $below = $this->world->get($x + $dx, $y + $dy - 1, $z + $dz);
        if ($this->isRail($front)) {
            $offset = str_starts_with($this->railShape($front), 'ascending') ? 0.6 : 0.1;
        } elseif ($catalog->air($front) && $this->isRail($below)) {
            $offset = $facing !== 'down' && str_starts_with($this->railShape($below), 'ascending') ? -0.4 : -0.9;
        } else {
            $this->dispenseItem($x, $y, $z, $facing, $this->containerStacks($container)[$slot]);
            $this->takeFromContainer($container, $slot);
            $this->levelEvent(1000, [$x, $y, $z], 0);
            $this->levelEvent(2000, [$x, $y, $z], self::DISPENSE_DATA[$facing]);
            return;
        }
        $id = $this->addMinecart($x + 0.5 + $dx * 1.125, $y + $dy + $offset, $z + 0.5 + $dz * 1.125, [0.0, 0.0, 0.0], 0.0, ['kind' => $kind, 'firstTick' => true]);
        foreach ($this->localPlayers() as $player) {
            $this->showMinecart($player, $id);
        }
        $this->takeFromContainer($container, $slot);
        $this->levelEvent(1000, [$x, $y, $z], 0);
        $this->levelEvent(2000, [$x, $y, $z], self::DISPENSE_DATA[$facing]);
    }

    /**
     * MinecartTNT.primeFuse: an 80-tick fuse (unless TNT may not explode), the client told with
     * entity event 70, and the primed sound.
     */
    private function primeMinecartTnt(array &$cart): void
    {
        if ($this->gameRule('tnt_explodes') !== 'true') {
            return;
        }
        $cart['fuse'] = 80;
        foreach ($this->localPlayers() as $player) {
            if (isset($player->entities[$cart['id']])) {
                $player->send($this->version, 'entity_event', pack('N', $cart['id']) . "\x46");
            }
        }
        $payload = Packets::sound($this->version->blocks['sounds']['entity.tnt.primed'], 4, $cart['x'], $cart['y'], $cart['z'], 1.0, 1.0, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $cart['x']) ** 2 + ($player->y - $cart['y']) ** 2 + ($player->z - $cart['z']) ** 2 < 256.0) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    /**
     * MinecartTNT.explode: with TNT allowed to explode, a TNT explosion of 4 and up to 1.5 more
     * for each unit of speed (up to 5) at the cart, which a primed cart's rails survive; the cart
     * is then gone. A primed cart that may not explode just goes.
     */
    private function explodeMinecartTnt(int $id, float $speedSqr): void
    {
        $cart = $this->minecarts[$id];
        if ($this->gameRule('tnt_explodes') === 'true') {
            $speed = min(sqrt($speedSqr), 5.0);
            $power = Float32::round(4.0 + 1.0 * self::randomUnit() * 1.5 * $speed);
            $this->explode($cart['x'], $cart['y'], $cart['z'], $power, $id, null, false, null, false, ($cart['fuse'] ?? -1) > -1);
            $this->removeMinecart($id);
        } elseif (($cart['fuse'] ?? -1) > -1) {
            $this->removeMinecart($id);
        }
    }

    /** Minecart.activateMinecart on a powered activator rail: it shakes as if hit. */
    private function activateMinecart(array &$cart): void
    {
        if ($cart['hurtTime'] !== 0) {
            return;
        }
        $cart['hurtDir'] = -$cart['hurtDir'];
        $cart['hurtTime'] = 10;
        $cart['damage'] = 50.0;
        $cart['dataDirty'] += [8 => true, 9 => true, 10 => true];
        // markHurt.
        $cart['syncVelocity'] = true;
    }

    private function minecartState(array $cart): array
    {
        return ['position' => [$cart['x'], $cart['y'], $cart['z']], 'velocity' => $cart['velocity'], 'yaw' => $cart['yaw'], 'pitch' => 0.0, 'head' => 0.0,
            'ground' => $cart['ground'], 'needs_sync' => false, 'sync_velocity' => $cart['syncVelocity'] ?? false, 'sync_position' => $cart['syncPosition'] ?? false,
            'force_update' => $cart['dataDirty'] !== []];
    }

    /** The changed VehicleEntity data: hurt time (8), hurt direction (9) and damage (10). */
    private function minecartData(array $cart, array $dirty): string
    {
        $data = '';
        ksort($dirty);
        foreach (array_keys($dirty) as $index) {
            $data .= match ($index) {
                8 => "\x08\x01" . Binary::writeVarInt($cart['hurtTime']),
                9 => "\x09\x01" . Binary::writeVarInt($cart['hurtDir']),
                10 => "\x0a\x03" . pack('G', $cart['damage']),
                13 => "\x0d\x08" . (($cart['lit'] ?? false) ? "\1" : "\0"),
            };
        }
        return $data;
    }

    /** ServerEntity.sendChanges for a minecart: every 3 ticks, or at once when its data changed. */
    private function synchronizeMinecart(int $id): void
    {
        $cart = $this->minecarts[$id];
        foreach ($this->localPlayers() as $player) {
            if (!isset($player->entities[$id])) {
                $this->showMinecart($player, $id);
            } elseif (!$this->tracksPosition($player, $cart['x'], $cart['z'], 'minecart')) {
                $this->forgetEntity($player, $id);
            }
        }
        $this->synchronizePassengers($cart);
        $state = $this->minecartState($cart);
        $tracker = $this->minecartTrackers[$id] ??= new EntityMovementTracker($id, 3, true, $state, false);
        $packets = $tracker->update($state);
        $this->minecarts[$id]['syncPosition'] = false;
        $this->minecarts[$id]['syncVelocity'] = false;
        if ($cart['dataDirty'] !== []) {
            $packets[] = ['name' => 'set_entity_data', 'payload' => Binary::writeVarInt($id) . $this->minecartData($cart, $cart['dataDirty']) . "\xff"];
            $this->minecarts[$id]['dataDirty'] = [];
        }
        foreach ($packets as $packet) {
            foreach ($this->localPlayers() as $player) {
                if (isset($player->entities[$id])) {
                    $player->send($this->version, $packet['name'], $packet['payload']);
                }
            }
        }
    }

    /** ServerEntity.sendPairingData: the spawn, with any data that differs from the defaults. */
    private function showMinecart(Client $player, int $id): bool
    {
        $cart = $this->minecarts[$id];
        if (isset($player->entities[$id]) || !$this->tracksPosition($player, $cart['x'], $cart['z'], 'minecart')) {
            return isset($player->entities[$id]);
        }
        $tracker = $this->minecartTrackers[$id] ??= new EntityMovementTracker($id, 3, true, $this->minecartState($cart), false);
        $changed = array_filter([8 => $cart['hurtTime'] !== 0, 9 => $cart['hurtDir'] !== 1, 10 => $cart['damage'] !== 0.0, 13 => $cart['lit'] ?? false]);
        $player->send($this->version, 'bundle_delimiter', '');
        $player->send($this->version, 'add_entity', $tracker->spawnPacket($cart['uuid'], $this->version->blocks['entity_types'][$cart['kind']], 0));
        if ($changed !== []) {
            $player->send($this->version, 'set_entity_data', Binary::writeVarInt($id) . $this->minecartData($cart, $changed) . "\xff");
        }
        $this->pairPassengers($player, $cart);
        $player->send($this->version, 'bundle_delimiter', '');
        $player->entities[$id] = true;
        return true;
    }
}
