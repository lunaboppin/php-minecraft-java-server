<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Trees;

/**
 * Entity.startRiding and stopRiding for mobs and players riding mobs: the vehicle keeps its
 * passengers in order, each tick sets their positions from the attachment points, and trackers
 * learn of changes through set_passengers.
 */
trait Riding
{
    /** @var array<int, list<int>> ServerEntity.lastPassengers per vehicle. */
    private array $sentPassengers = [];

    /** Relative.ROTATION, and Relative.union(DELTA, ROTATION) as ServerPlayer.teleportTo sends it. */
    private const RELATIVE_ROTATION = 0x18;

    private const RELATIVE_DELTA_ROTATION = 0x1f8;

    /** A selected entity's network ID; players and mobs can ride, and only mobs carry riders. */
    private function riderId(array $entity): int
    {
        return isset($entity['client']) ? $entity['client']->entityId : $entity['id'];
    }

    private function vehicleOf(array $entity): ?int
    {
        return isset($entity['client']) ? $entity['client']->vehicle : (isset($entity['mob']) ? ($this->animals[$entity['mob']]['vehicle'] ?? null) : null);
    }

    /** The selector-shaped reference a command names an entity by. */
    private function mobReference(int $id): array
    {
        $animal = $this->animals[$id];
        return ['mob' => $id, 'id' => $id, 'type' => 'minecraft:' . $animal['kind'], 'uuid' => hex2bin($animal['uuid']), 'x' => $animal['x'], 'y' => $animal['y'], 'z' => $animal['z']];
    }

    private function riderReference(int $id): ?array
    {
        if (isset($this->animals[$id])) {
            return $this->mobReference($id);
        }
        foreach ($this->players() as $player) {
            if ($player->entityId === $id) {
                return ['client' => $player, 'id' => $id];
            }
        }
        return null;
    }

    /** Entity.getSelfAndPassengers: the entity and everything riding it, at any depth. */
    private function selfAndPassengers(int $id): array
    {
        $result = [$id];
        foreach ($this->animals[$id]['passengers'] ?? [] as $passenger) {
            $result = [...$result, ...$this->selfAndPassengers($passenger)];
        }
        return $result;
    }

    /** Entity.startRiding(vehicle, force: true) followed by ServerPlayer's teleport into the seat. */
    private function startRiding(array $entity, int $vehicle): bool
    {
        if ($this->vehicleOf($entity) === $vehicle || !isset($this->animals[$vehicle])) {
            return false;
        }
        $id = $this->riderId($entity);
        for ($above = $vehicle; isset($this->animals[$above]['vehicle']); $above = $this->animals[$above]['vehicle']) {
            if ($this->animals[$above]['vehicle'] === $id) {
                return false;
            }
        }
        if ($this->vehicleOf($entity) !== null) {
            $this->stopRiding($entity);
        }
        $passengers = $this->animals[$vehicle]['passengers'] ?? [];
        // Entity.addPassenger: a player goes first unless a player already leads.
        $first = $passengers[0] ?? null;
        if (isset($entity['client']) && $passengers !== [] && ($first === null || isset($this->animals[$first]))) {
            array_unshift($passengers, $id);
        } else {
            $passengers[] = $id;
        }
        $this->animals[$vehicle]['passengers'] = $passengers;
        if (isset($entity['client'])) {
            $player = $entity['client'];
            $player->vehicle = $vehicle;
            $player->ground = false;
            $this->positionRider($vehicle, $id);
            $this->sendPosition($player, [$player->x, $player->y, $player->z], self::RELATIVE_ROTATION);
            $player->send($this->version, 'set_passengers', $this->passengersPacket($vehicle));
        } else {
            $this->animals[$entity['mob']]['vehicle'] = $vehicle;
            $this->animals[$entity['mob']]['ground'] = false;
        }
        return true;
    }

    /** LivingEntity.stopRiding: leave the vehicle and step down where it lets the rider off. */
    private function stopRiding(array $entity): void
    {
        $vehicle = $this->vehicleOf($entity);
        if ($vehicle === null) {
            return;
        }
        $id = $this->riderId($entity);
        if (isset($this->animals[$vehicle])) {
            $this->animals[$vehicle]['passengers'] = array_values(array_diff($this->animals[$vehicle]['passengers'] ?? [], [$id]));
            if ($this->animals[$vehicle]['passengers'] === []) {
                unset($this->animals[$vehicle]['passengers']);
            }
        }
        if (isset($entity['client'])) {
            $player = $entity['client'];
            $player->vehicle = null;
            // ServerPlayer.removeVehicle tells the rider at once.
            if (isset($this->animals[$vehicle])) {
                $player->send($this->version, 'set_passengers', $this->passengersPacket($vehicle));
            }
            $player->sneaking = false;
        } else {
            unset($this->animals[$entity['mob']]['vehicle']);
        }
        if (isset($this->animals[$vehicle])) {
            $target = $this->dismountLocation($entity, $vehicle);
            if (isset($entity['client'])) {
                $this->sendPosition($entity['client'], $target, self::RELATIVE_DELTA_ROTATION);
                $this->broadcastPlayerPosition($entity['client']);
            } else {
                $mob = &$this->animals[$entity['mob']];
                [$mob['x'], $mob['y'], $mob['z']] = $target;
                [$mob['targetX'], $mob['targetY'], $mob['targetZ']] = $target;
                unset($mob);
            }
        }
    }

    /** A player who dies or leaves is taken off the vehicle where it is, without dismounting. */
    private function leaveVehicle(Client $player): void
    {
        $vehicle = $player->vehicle;
        if ($vehicle === null) {
            return;
        }
        $player->vehicle = null;
        if (isset($this->animals[$vehicle])) {
            $this->animals[$vehicle]['passengers'] = array_values(array_diff($this->animals[$vehicle]['passengers'] ?? [], [$player->entityId]));
            if ($this->animals[$vehicle]['passengers'] === []) {
                unset($this->animals[$vehicle]['passengers']);
            }
        }
    }

    /** Entity.ejectPassengers when a vehicle is removed: its riders stay where they are. */
    private function ejectPassengers(int $vehicle): void
    {
        foreach (array_reverse($this->animals[$vehicle]['passengers'] ?? []) as $passenger) {
            $reference = $this->riderReference($passenger);
            if ($reference === null) {
                continue;
            }
            if (isset($reference['client'])) {
                $reference['client']->vehicle = null;
                $reference['client']->send($this->version, 'set_passengers', Binary::writeVarInt($vehicle) . "\0");
            } else {
                unset($this->animals[$passenger]['vehicle']);
            }
        }
        unset($this->animals[$vehicle]['passengers'], $this->sentPassengers[$vehicle]);
    }

    private function passengersPacket(int $vehicle): string
    {
        $passengers = $this->animals[$vehicle]['passengers'] ?? [];
        return Binary::writeVarInt($vehicle) . Binary::writeVarInt(count($passengers)) . implode('', array_map(Binary::writeVarInt(...), $passengers));
    }

    /** ServerEntity.sendChanges: trackers hear of a changed passenger list before any movement. */
    private function synchronizePassengers(array $mob): void
    {
        $passengers = $mob['passengers'] ?? [];
        if ($passengers === ($this->sentPassengers[$mob['id']] ?? [])) {
            return;
        }
        $this->sentPassengers[$mob['id']] = $passengers;
        if ($passengers === []) {
            unset($this->sentPassengers[$mob['id']]);
        }
        foreach ($this->localPlayers() as $viewer) {
            if (isset($viewer->entities[$mob['id']])) {
                $viewer->send($this->version, 'set_passengers', $this->passengersPacket($mob['id']));
            }
        }
    }

    /** ServerEntity.sendPairingData: a newly tracked vehicle or rider comes with its passengers. */
    private function pairPassengers(Client $viewer, array $mob): void
    {
        if (($mob['passengers'] ?? []) !== []) {
            $viewer->send($this->version, 'set_passengers', $this->passengersPacket($mob['id']));
        }
        if (isset($mob['vehicle'], $this->animals[$mob['vehicle']])) {
            $viewer->send($this->version, 'set_passengers', $this->passengersPacket($mob['vehicle']));
        }
    }

    private function attachmentScale(array $mob): float
    {
        return ($mob['baby'] ?? false) ? 0.5 : 1.0;
    }

    /** EntityAttachments.transformPoint: the point turned with the entity's body. */
    private static function attachmentPoint(array $point, float $scale, float $yaw): array
    {
        $angle = Float32::round(-$yaw * Float32::round(M_PI / 180.0));
        $cos = Trees::mthCos($angle);
        $sin = Trees::mthSin($angle);
        [$x, $y, $z] = [$point[0] * $scale, $point[1] * $scale, $point[2] * $scale];
        return [$x * $cos + $z * $sin, $y, $z * $cos - $x * $sin];
    }

    /** Entity.positionRider: the vehicle's passenger point less the rider's own vehicle point. */
    private function positionRider(int $vehicle, int $passenger): void
    {
        $carrier = $this->animals[$vehicle];
        $points = $this->version->catalog->mobs['entities'][$carrier['kind']]['passenger_attachments'];
        $index = array_search($passenger, $carrier['passengers'] ?? [], true);
        $seat = self::attachmentPoint($points[max(0, min((int) $index, count($points) - 1))], $this->attachmentScale($carrier), (float) $carrier['yaw']);
        if (isset($this->animals[$passenger])) {
            $rider = $this->animals[$passenger];
            $offset = self::attachmentPoint($this->version->catalog->mobs['entities'][$rider['kind']]['vehicle_attachment'], $this->attachmentScale($rider), (float) $rider['yaw']);
        } else {
            $reference = $this->riderReference($passenger);
            if ($reference === null) {
                return;
            }
            $offset = self::attachmentPoint($this->version->catalog->mobs['entities']['player']['vehicle_attachment'], 1.0, $reference['client']->yaw);
        }
        $position = [$carrier['x'] + $seat[0] - $offset[0], $carrier['y'] + $seat[1] - $offset[1], $carrier['z'] + $seat[2] - $offset[2]];
        if (isset($this->animals[$passenger])) {
            $mob = &$this->animals[$passenger];
            [$mob['x'], $mob['y'], $mob['z']] = $position;
            [$mob['targetX'], $mob['targetY'], $mob['targetZ']] = $position;
            unset($mob);
            return;
        }
        $player = $reference['client'];
        if ([$player->x, $player->y, $player->z] !== $position) {
            [$player->x, $player->y, $player->z] = $position;
            $player->tickPosition = $position;
            $player->fallDistance = 0;
            $this->broadcastPlayerPosition($player);
        }
    }

    /** ServerLevel.tickPassenger after each vehicle: riders follow their seats, and a sneaking player gets off. */
    private function tickRiders(): void
    {
        foreach ($this->animals as $id => $animal) {
            if (!isset($animal['vehicle'])) {
                foreach ($this->ridersBelow($id) as [$vehicle, $passenger]) {
                    $reference = $this->riderReference($passenger);
                    if (isset($reference['client']) && $reference['client']->sneaking) {
                        // Player.rideTick: wantsToStopRiding.
                        $this->stopRiding($reference);
                        continue;
                    }
                    $this->positionRider($vehicle, $passenger);
                }
            }
        }
    }

    /** @return list<array{int, int}> vehicle and passenger pairs, each vehicle before its riders. */
    private function ridersBelow(int $vehicle): array
    {
        $pairs = [];
        foreach ($this->animals[$vehicle]['passengers'] ?? [] as $passenger) {
            $pairs[] = [$vehicle, $passenger];
            if (isset($this->animals[$passenger])) {
                $pairs = [...$pairs, ...$this->ridersBelow($passenger)];
            }
        }
        return $pairs;
    }

    /** The rider's bounding boxes for each pose LivingEntity.getDismountPoses tries. */
    private function dismountBounds(array $entity): array
    {
        if (isset($entity['client'])) {
            // Player.getDismountPoses: standing, crouching, then swimming.
            return [[0.6, 1.8], [0.6, 1.5], [0.6, 0.6]];
        }
        $mob = $this->animals[$entity['mob']];
        $size = $this->version->catalog->mobs['entities'][$mob['kind']];
        $scale = $this->attachmentScale($mob);
        return [[$size['width'] * $scale, $size['height'] * $scale]];
    }

    /** The vehicle's getDismountLocationForPassenger. */
    private function dismountLocation(array $entity, int $vehicle): array
    {
        $carrier = $this->animals[$vehicle];
        $size = $this->version->catalog->mobs['entities'][$carrier['kind']];
        $scale = $this->attachmentScale($carrier);
        $top = [$carrier['x'], $carrier['y'] + Float32::round($size['height'] * $scale), $carrier['z']];
        if (!str_ends_with($this->version->catalog->movement['profiles'][$carrier['kind']]['dismount_owner'] ?? '', '.Animal')) {
            return $top;
        }
        // Animal.getDismountLocationForPassenger: DismountHelper.offsetsForDirection beside the
        // vehicle, facing the way Direction.fromYRot points.
        $steps = [[0, 1], [-1, 0], [0, -1], [1, 0]];
        $facing = ((int) floor((float) $carrier['yaw'] / 90.0 + 0.5)) & 3;
        [$forward, $right, $back, $left] = [$steps[$facing], $steps[($facing + 1) & 3], $steps[($facing + 2) & 3], $steps[($facing + 3) & 3]];
        $offsets = [$right, $left, [$back[0] + $right[0], $back[1] + $right[1]], [$back[0] + $left[0], $back[1] + $left[1]],
            [$forward[0] + $right[0], $forward[1] + $right[1]], [$forward[0] + $left[0], $forward[1] + $left[1]], $back, $forward];
        $collision = new Collision($this->version->catalog);
        [$baseX, $baseY, $baseZ] = [(int) floor($carrier['x']), (int) floor($carrier['y']), (int) floor($carrier['z'])];
        foreach ($this->dismountBounds($entity) as [$width, $height]) {
            foreach ($offsets as [$dx, $dz]) {
                [$x, $z] = [$baseX + $dx, $baseZ + $dz];
                $floor = $this->blockFloorHeight($collision, $x, $baseY, $z);
                if (is_infinite($floor) || $floor >= 1.0) {
                    continue;
                }
                $location = [$x + 0.5, $baseY + $floor, $z + 0.5];
                if ($this->canDismountTo($collision, Collision::bounds($location[0], $location[1], $location[2], $width, $height))) {
                    return $location;
                }
            }
        }
        return $top;
    }

    /** BlockGetter.getBlockFloorHeight: the block's collision top, or the one below reaching up into it. */
    private function blockFloorHeight(Collision $collision, int $x, int $y, int $z): float
    {
        $shape = $collision->shape($this->world->get($x, $y, $z));
        if ($shape !== []) {
            return max(array_column($shape, 4));
        }
        $below = $collision->shape($this->world->get($x, $y - 1, $z));
        $top = $below === [] ? -INF : max(array_column($below, 4));
        return $top >= 1.0 ? $top - 1.0 : -INF;
    }

    /** DismountHelper.canDismountTo: no block collides with the box, and it is inside the border. */
    private function canDismountTo(Collision $collision, array $box): bool
    {
        foreach ($collision->boxes($this->world, $box) as $block) {
            if ($block[0] < $box[3] && $block[3] > $box[0] && $block[1] < $box[4] && $block[4] > $box[1] && $block[2] < $box[5] && $block[5] > $box[2]) {
                return false;
            }
        }
        $border = $this->border($this->world->dimension);
        return $border->contains($box[0], $box[2], $this->worldAge) && $border->contains($box[3] - 1.0E-5, $box[5] - 1.0E-5, $this->worldAge);
    }
}
