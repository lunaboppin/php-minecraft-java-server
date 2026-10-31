<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\ProtocolException;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * Steering a saddled pig with a carrot on a stick: the controlling player's client moves the pig
 * and reports it with move_vehicle, which ServerGamePacketListenerImpl.handleMoveVehicle checks;
 * the server turns the pig with its rider (Pig.tickRidden) and runs its boost.
 */
trait VehicleSteering
{
    /** Pig.getControllingPassenger: the first passenger of a saddled pig, holding a carrot on a stick. */
    private function controllingPlayer(int $vehicle): ?Client
    {
        $mob = $this->animals[$vehicle] ?? null;
        $first = $mob['passengers'][0] ?? null;
        if ($mob === null || $mob['kind'] !== 'pig' || !isset($mob['equipment'][7]) || $first === null || isset($this->animals[$first])) {
            return null;
        }
        $player = $this->riderReference($first)['client'] ?? null;
        if ($player === null) {
            return null;
        }
        // Player.isHolding: either hand.
        foreach ([36 + $player->heldSlot, 45] as $slot) {
            if (isset($player->inventory[$slot]) && $this->version->catalog->itemName($player->inventory[$slot]['item']) === 'carrot_on_a_stick') {
                return $player;
            }
        }
        return null;
    }

    /** Entity.getRootVehicle for a player, by entity ID; null when the player rides nothing. */
    private function rootVehicle(Client $player): ?int
    {
        $vehicle = $player->vehicle;
        while ($vehicle !== null && isset($this->animals[$vehicle]['vehicle'])) {
            $vehicle = $this->animals[$vehicle]['vehicle'];
        }
        return $vehicle !== null && isset($this->animals[$vehicle]) ? $vehicle : null;
    }

    /**
     * LivingEntity.travelRidden on the server for a client-controlled pig: Pig.tickRidden turns it
     * with the rider and runs ItemBasedSteering.tickBoost; the client simulates the movement.
     */
    private function tickRiddenPig(array &$mob, Client $controller): void
    {
        $mob['yaw'] = $controller->yaw;
        $mob['pitch'] = Float32::round($controller->pitch * Float32::round(0.5));
        $mob['headYaw'] = $mob['yaw'];
        $mob['velocity'] = [0.0, 0.0, 0.0];
        if (($mob['boosting'] ?? false) && $mob['boostTime']++ > $mob['boostTimeTotal']) {
            $mob['boosting'] = false;
        }
    }

    /**
     * FoodOnAStickItem.use: while the player controls a pig that is not boosting, ItemBasedSteering.boost
     * starts a boost of 140 to 980 ticks and the stick takes 7 damage, becoming a fishing rod when it
     * breaks. Returns whether the stick was used, which swings the hand.
     */
    private function boostVehicle(Client $player, int $slot): bool
    {
        $vehicle = $player->vehicle;
        if ($vehicle === null || $this->rootVehicle($player) !== $vehicle || $this->controllingPlayer($vehicle) !== $player || ($this->animals[$vehicle]['boosting'] ?? false)) {
            return false;
        }
        $mob = &$this->animals[$vehicle];
        $mob['boosting'] = true;
        $mob['boostTime'] = 0;
        $mob['boostTimeTotal'] = random_int(0, 840) + 140;
        $mob['boostDirty'] = true;
        unset($mob);
        // ItemStack.hurtAndConvertOnBreak: players with infinite materials take no damage.
        if ($player->gameMode !== 1) {
            $stack = $player->inventory[$slot];
            $stack['damage'] = ($stack['damage'] ?? 0) + 7;
            if ($stack['damage'] >= $this->version->catalog->item($stack['item'])['durability']) {
                $stack = ['item' => $this->version->blocks['items']['fishing_rod'], 'count' => 1];
            }
            $player->inventory[$slot] = $stack;
            $this->syncInventory($player);
        }
        return true;
    }

    /** ServerEntity.sendDirtyEntityData for the pig's DATA_BOOST_TIME, which precedes its sound variant and variant. */
    private function synchronizeBoost(array &$mob): void
    {
        if (!isset($mob['boostDirty'])) {
            return;
        }
        unset($mob['boostDirty']);
        $index = $this->version->catalog->mobSounds['mobs']['pig']['variant_index'] - 2;
        $payload = Binary::writeVarInt($mob['id']) . chr($index) . Binary::writeVarInt(1) . Binary::writeVarInt($mob['boostTimeTotal']) . "\xff";
        foreach ($this->localPlayers() as $viewer) {
            if (isset($viewer->entities[$mob['id']])) {
                $viewer->send($this->version, 'set_entity_data', $payload);
            }
        }
    }

    /** ServerGamePacketListenerImpl.tickPlayer: the controlled vehicle's position is the next tick's starting point. */
    private function tickControlledVehicle(Client $player): void
    {
        $vehicle = $this->rootVehicle($player);
        if ($vehicle !== null && $this->controllingPlayer($vehicle) === $player) {
            $mob = $this->animals[$vehicle];
            $player->lastVehicle = $vehicle;
            $player->vehicleFirstGood = $player->vehicleLastGood = [$mob['x'], $mob['y'], $mob['z']];
        } else {
            $player->lastVehicle = null;
        }
    }

    /** ClientboundMoveVehiclePacket.fromEntity. */
    private function sendVehiclePosition(Client $player, array $mob): void
    {
        $player->send($this->version, 'move_vehicle', pack('EEE', $mob['x'], $mob['y'], $mob['z']) . pack('G', $mob['yaw']) . pack('G', $mob['pitch'] ?? 0.0));
    }

    /** ServerGamePacketListenerImpl.handleMoveVehicle. */
    private function moveVehicle(Client $player, Binary $r): void
    {
        [$x, $y, $z] = [$r->double(), $r->double(), $r->double()];
        [$yaw, $pitch] = [$r->float(), $r->float()];
        $ground = $r->byte();
        $r->end();
        if ($ground > 1) {
            throw new ProtocolException('Invalid vehicle movement');
        }
        if (is_nan($x) || is_nan($y) || is_nan($z) || !is_finite($yaw) || !is_finite($pitch)) {
            $this->disconnect($player, ['translate' => 'multiplayer.disconnect.invalid_vehicle_movement']);
            return;
        }
        // updateAwaitingTeleport: an unconfirmed teleport is sent again after twenty ticks.
        if ($player->teleport !== null) {
            if ($this->ticks - $player->teleportTick > 20) {
                $this->sendPosition($player, [$player->x, $player->y, $player->z]);
            }
            return;
        }
        if (!$player->playerLoaded) {
            return;
        }
        $vehicle = $this->rootVehicle($player);
        if ($vehicle === null) {
            return;
        }
        if ($this->controllingPlayer($vehicle) !== $player || $vehicle !== $player->lastVehicle) {
            // The client moved a vehicle it does not control: it is told where the vehicle is, at
            // most once a second unless it strayed more than a block.
            $mob = $this->animals[$vehicle];
            if ($this->controllingPlayer($vehicle) !== $player
                && ($this->ticks - $player->vehiclePositionLastResetAt > 20 || ($mob['x'] - $x) ** 2 + ($mob['y'] - $y) ** 2 + ($mob['z'] - $z) ** 2 > 1.0)) {
                $this->log($player->name . ' was expected to be controlling vehicle Pig but was not. Resetting vehicle position.');
                $this->sendVehiclePosition($player, $mob);
                $player->vehiclePositionLastResetAt = $this->ticks;
            }
            return;
        }
        $mob = &$this->animals[$vehicle];
        [$oldX, $oldY, $oldZ] = [$mob['x'], $mob['y'], $mob['z']];
        $x = max(-3.0E7, min(3.0E7, $x));
        $y = max(-2.0E7, min(2.0E7, $y));
        $z = max(-3.0E7, min(3.0E7, $z));
        $yaw = self::wrapDegrees($yaw);
        $pitch = self::wrapDegrees($pitch);
        [$firstX, $firstY, $firstZ] = $player->vehicleFirstGood;
        // The vehicle's own velocity is zero while the client simulates it.
        if (($x - $firstX) ** 2 + ($y - $firstY) ** 2 + ($z - $firstZ) ** 2 > 100.0) {
            $this->log('Pig (vehicle of ' . $player->name . ') moved too quickly! ' . ($x - $firstX) . ',' . ($y - $firstY) . ',' . ($z - $firstZ));
            $this->sendVehiclePosition($player, $mob);
            unset($mob);
            return;
        }
        $size = $this->version->catalog->mobs['entities']['pig'];
        $scale = $this->attachmentScale($mob);
        [$width, $height] = [$size['width'] * $scale, $size['height'] * $scale];
        $oldBox = Collision::bounds($oldX, $oldY, $oldZ, $width, $height);
        [$lastX, $lastY, $lastZ] = $player->vehicleLastGood;
        // Entity.move(MoverType.PLAYER) from where the server has the pig, stepping up as it can.
        $collision = new Collision($this->version->catalog);
        $moved = $collision->moveWithStep($this->world, $oldBox, [$x - $lastX, $y - $lastY, $z - $lastZ], (float) ($this->mobAttributes($mob)['step_height'] ?? 0.6), $mob['ground'] ?? false);
        // The vertical difference is always forgiven (yDist > -0.5 || yDist < 0.5).
        $wrong = ($x - $oldX - $moved[0]) ** 2 + ($z - $oldZ - $moved[2]) ** 2 > 0.0625;
        if ($wrong) {
            $this->log('Pig (vehicle of ' . $player->name . ') moved wrongly! ' . sqrt(($x - $oldX - $moved[0]) ** 2 + ($z - $oldZ - $moved[2]) ** 2));
        }
        $newBox = Collision::bounds($x, $y, $z, $width, $height);
        if (($wrong && !self::collidesWithBlocks($collision, $oldBox, $this->world)) || $this->collidesWithAnythingNew($collision, $oldBox, $newBox)) {
            $mob['yaw'] = $yaw;
            $mob['pitch'] = $pitch;
            $this->sendVehiclePosition($player, $mob);
            unset($mob);
            return;
        }
        [$mob['x'], $mob['y'], $mob['z']] = [$x, $y, $z];
        [$mob['targetX'], $mob['targetY'], $mob['targetZ']] = [$x, $y, $z];
        $mob['yaw'] = $yaw;
        $mob['pitch'] = $pitch;
        $mob['ground'] = $ground === 1;
        unset($mob);
        // handlePlayerKnownMovement: real movement shows the player is not idle.
        if (($x - $oldX) ** 2 + ($y - $oldY) ** 2 + ($z - $oldZ) ** 2 > 1.0E-5) {
            $player->lastActionTime = self::now();
        }
        $player->vehicleLastGood = [$x, $y, $z];
    }

    /** Level.noCollision's block part, inverted: a block shape overlaps the box. */
    private static function collidesWithBlocks(Collision $collision, array $box, $world): bool
    {
        foreach ($collision->boxes($world, $box) as $block) {
            if (self::boxesOverlap($block, $box)) {
                return true;
            }
        }
        return false;
    }

    /** isEntityCollidingWithAnythingNew: a shape at the new position that the old box did not touch. */
    private function collidesWithAnythingNew(Collision $collision, array $oldBox, array $newBox): bool
    {
        $deflate = static fn (array $box): array => [$box[0] + 1.0E-5, $box[1] + 1.0E-5, $box[2] + 1.0E-5, $box[3] - 1.0E-5, $box[4] - 1.0E-5, $box[5] - 1.0E-5];
        [$oldBox, $newBox] = [$deflate($oldBox), $deflate($newBox)];
        foreach ($collision->boxes($this->world, $newBox) as $block) {
            if (self::boxesOverlap($block, $newBox) && !self::boxesOverlap($block, $oldBox)) {
                return true;
            }
        }
        return false;
    }

    private static function boxesOverlap(array $a, array $b): bool
    {
        return $a[0] < $b[3] && $a[3] > $b[0] && $a[1] < $b[4] && $a[4] > $b[1] && $a[2] < $b[5] && $a[5] > $b[2];
    }
}
