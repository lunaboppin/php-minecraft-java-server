<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
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
            $this->addMinecart((float) $saved['x'], (float) $saved['y'], (float) $saved['z'], $saved['velocity'], (float) ($saved['yaw'] ?? 0.0), $saved);
        }
    }

    /** AbstractMinecart.addAdditionalSaveData: FlippedRotation, with the position, motion and rotation every entity saves. */
    private function savedMinecarts(): array
    {
        return array_values(array_map(static fn (array $cart): array => ['uuid' => bin2hex($cart['uuid'])]
            + array_intersect_key($cart, array_flip(['x', 'y', 'z', 'velocity', 'yaw', 'flipped'])), $this->minecarts));
    }

    /** The selector-shaped reference a command names a minecart by. */
    private function minecartReference(int $id): array
    {
        $cart = $this->minecarts[$id];
        return ['minecart' => $id, 'x' => $cart['x'], 'y' => $cart['y'], 'z' => $cart['z'], 'yaw' => (float) $cart['yaw'], 'pitch' => 0.0, 'type' => 'minecraft:minecart',
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
            $cart = &$this->minecarts[$id];
            [$xo, , $zo] = [$cart['x'], $cart['y'], $cart['z']];
            $yawO = $cart['yaw'];
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
                if ($this->version->catalog->blockName($state) === 'activator_rail' && ($properties['powered'] ?? 'false') === 'true') {
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
            $passengers = $cart['passengers'] ?? [];
            unset($cart);
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
        $max = $cart['inWater'] ? 0.2 : 0.4;
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
        // AbstractMinecart.applyNaturalSlowdown: 0.96, or 0.997 with a passenger, and the float 0.95 in water.
        $slowdown = ($cart['passengers'] ?? []) === [] ? 0.96 : 0.997;
        $cart['velocity'] = [$cart['velocity'][0] * $slowdown, 0.0, $cart['velocity'][2] * $slowdown];
        if ($cart['inWater']) {
            $cart['velocity'] = array_map(static fn (float $v): float => $v * 0.949999988079071, $cart['velocity']);
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
        $max = $cart['inWater'] ? 0.2 : 0.4;
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
    private function placeMinecart(Client $c, int $slot, int $x, int $y, int $z): bool
    {
        $state = $this->world->get($x, $y, $z);
        if (!$this->isRail($state)) {
            return false;
        }
        $slope = str_starts_with($this->railShape($state), 'ascending');
        $id = $this->addMinecart($x + 0.5, $y + 0.0625 + ($slope ? 0.5 : 0.0), $z + 0.5, [0.0, 0.0, 0.0]);
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
        if ($c->gameMode === 1 || $cart['damage'] > 40.0) {
            [$x, $y, $z] = [$cart['x'], $cart['y'], $cart['z']];
            unset($cart);
            $this->removeMinecart($id);
            // VehicleEntity.destroy: the drop, unless the hit was a creative player's.
            if ($c->gameMode !== 1 && $this->gameRule('entity_drops') === 'true') {
                $this->spawnAtLocation($x, $y, $z, ['item' => $this->version->blocks['items']['minecart'], 'count' => 1]);
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
        if ($player->dead || $player->gameMode === 3 || $hand < 0 || $hand > 1 || $secondary || ($cart['passengers'] ?? []) !== []) {
            return;
        }
        $box = $this->minecartBox($cart);
        $eye = [$player->x, $player->y + ($player->sneaking ? 1.27 : 1.62), $player->z];
        $distance = 0.0;
        foreach ([0, 1, 2] as $axis) {
            $distance += max($box[$axis] - $eye[$axis], 0.0, $eye[$axis] - $box[$axis + 3]) ** 2;
        }
        // Player.isWithinEntityInteractionRange with the server's 3-block allowance.
        if ($distance >= (3.0 + 3.0) ** 2) {
            return;
        }
        if ($this->startRiding(['client' => $player, 'id' => $player->entityId], $id)) {
            $this->useSwing($player, $hand);
        }
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
        $changed = array_filter([8 => $cart['hurtTime'] !== 0, 9 => $cart['hurtDir'] !== 1, 10 => $cart['damage'] !== 0.0]);
        $player->send($this->version, 'bundle_delimiter', '');
        $player->send($this->version, 'add_entity', $tracker->spawnPacket($cart['uuid'], $this->version->blocks['entity_types']['minecart'], 0));
        if ($changed !== []) {
            $player->send($this->version, 'set_entity_data', Binary::writeVarInt($id) . $this->minecartData($cart, $changed) . "\xff");
        }
        $this->pairPassengers($player, $cart);
        $player->send($this->version, 'bundle_delimiter', '');
        $player->entities[$id] = true;
        return true;
    }
}
