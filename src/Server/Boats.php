<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Server\Tracking\EntityMovementTracker;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Combat;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * AbstractBoat without a controlling player, which the server moves itself: it floats on
 * water, sinks slowly under it, slides on land by the friction of the blocks under it and
 * falls through the air. Each wood's boat, chest boat and the bamboo rafts share this.
 */
trait Boats
{
    /** @var array<int, array> boats by entity ID */
    private array $boats = [];

    /** @var array<int, EntityMovementTracker> */
    private array $boatTrackers = [];

    /** @return bool whether the entity type (and the item of the same name) is a boat or raft */
    private function isBoatType(string $kind): bool
    {
        return str_ends_with($kind, '_boat') || str_ends_with($kind, '_raft');
    }

    private function addBoat(string $kind, float $x, float $y, float $z, array $velocity, float $yaw, array $saved = []): int
    {
        $id = $this->nextEntity++;
        $this->boats[$id] = $saved + ['kind' => $kind, 'uuid' => random_bytes(16), 'x' => $x, 'y' => $y, 'z' => $z, 'velocity' => $velocity, 'yaw' => $yaw,
            'hurtTime' => 0, 'hurtDir' => 1, 'damage' => 0.0, 'ground' => false, 'status' => null, 'waterLevel' => 0.0, 'landFriction' => 0.0,
            'lastYd' => 0.0, 'fallDistance' => 0.0, 'dataDirty' => []];
        $this->boats[$id]['id'] = $id;
        return $id;
    }

    /** Boats saved with the level float on from where they were. */
    private function restoreBoats(): void
    {
        foreach ($this->world->setting('boats') ?? [] as $saved) {
            if (!isset($saved['uuid'], $saved['kind'], $saved['x'], $saved['y'], $saved['z']) || !$this->isBoatType((string) $saved['kind'])) {
                continue;
            }
            $saved['uuid'] = hex2bin($saved['uuid']);
            $saved['velocity'] = array_map('floatval', $saved['velocity'] ?? [0.0, 0.0, 0.0]);
            $this->addBoat($saved['kind'], (float) $saved['x'], (float) $saved['y'], (float) $saved['z'], $saved['velocity'], (float) ($saved['yaw'] ?? 0.0), $saved);
        }
    }

    private function savedBoats(): array
    {
        return array_values(array_map(static fn (array $boat): array => ['uuid' => bin2hex($boat['uuid'])]
            + array_intersect_key($boat, array_flip(['kind', 'x', 'y', 'z', 'velocity', 'yaw'])), $this->boats));
    }

    /** The selector-shaped reference a command names a boat by. */
    private function boatReference(int $id): array
    {
        $boat = $this->boats[$id];
        return ['boat' => $id, 'x' => $boat['x'], 'y' => $boat['y'], 'z' => $boat['z'], 'yaw' => (float) $boat['yaw'], 'pitch' => 0.0, 'type' => 'minecraft:' . $boat['kind'],
            'width' => 1.375, 'height' => 0.5625, 'id' => $id, 'uuid' => $boat['uuid']];
    }

    private function removeBoat(int $id): void
    {
        unset($this->boats[$id], $this->boatTrackers[$id]);
        foreach ($this->localPlayers() as $player) {
            $this->forgetEntity($player, $id);
        }
    }

    /** EntityDimensions.makeBoundingBox for a boat: 1.375 wide and 0.5625 high. */
    private function boatBox(array $boat): array
    {
        return [$boat['x'] - 0.6875, $boat['y'], $boat['z'] - 0.6875, $boat['x'] + 0.6875, $boat['y'] + 0.5625, $boat['z'] + 0.6875];
    }

    /** ChunkMap.tick sends what changed before the entities tick; then AbstractBoat.tick for each. */
    private function tickBoats(): void
    {
        foreach (array_keys($this->boats) as $id) {
            $this->synchronizeBoat($id);
        }
        $collision = new Collision($this->version->catalog);
        foreach (array_keys($this->boats) as $id) {
            if (!isset($this->boats[$id])) {
                continue;
            }
            $boat = &$this->boats[$id];
            $old = $boat['status'];
            $boat['status'] = $this->boatStatus($boat);
            if ($boat['hurtTime'] > 0) {
                --$boat['hurtTime'];
                $boat['dataDirty'][8] = true;
            }
            if ($boat['damage'] > 0.0) {
                $boat['damage'] = Float32::round($boat['damage'] - 1.0);
                $boat['dataDirty'][10] = true;
            }
            // Entity.checkBelowWorld.
            if ($boat['y'] < $this->world->minY - 64) {
                unset($boat);
                $this->removeBoat($id);
                continue;
            }
            // Entity.baseTick: flowing water pushes the boat.
            $this->entityFluidCurrent($this->boatBox($boat), $boat['velocity']);
            $this->floatBoat($boat, $old, $collision);
            $before = $boat['y'];
            $this->moveSimpleEntity($boat, $this->boatBox($boat), $collision, 0.04, touchingShapes: true);
            // AbstractBoat.checkFallDamage: the motion left after the move, and the fall so far.
            $boat['lastYd'] = $boat['velocity'][1];
            if ($boat['ground']) {
                $boat['fallDistance'] = 0.0;
            } elseif ($boat['y'] < $before && ($this->fluids()->fluid($this->world->get((int) floor($boat['x']), (int) floor($boat['y']) - 1, (int) floor($boat['z'])))[0] ?? null) !== 'water') {
                $boat['fallDistance'] += $before - $boat['y'];
            }
            unset($boat);
        }
    }

    /**
     * AbstractBoat.getStatus: under water when water covers its top (flowing water apart), in
     * water when its bottom is below the water's surface, on land when a block under it has
     * friction, and otherwise in the air.
     */
    private function boatStatus(array &$boat): string
    {
        $box = $this->boatBox($boat);
        $fluids = $this->fluids();
        $block = $this->world->get(...);
        $top = $box[4] + 0.001;
        $under = null;
        for ($x = (int) floor($box[0]); $x < (int) ceil($box[3]); ++$x) {
            for ($y = (int) floor($box[4]); $y < (int) ceil($top); ++$y) {
                for ($z = (int) floor($box[2]); $z < (int) ceil($box[5]); ++$z) {
                    $fluid = $fluids->fluid($block($x, $y, $z));
                    if (($fluid[0] ?? null) === 'water' && $top < $y + $fluids->entityHeight($block, $fluid, $x, $y, $z)) {
                        if (!$fluid[3]) {
                            $boat['waterLevel'] = $box[4];
                            return 'under_flowing_water';
                        }
                        $under = 'under_water';
                    }
                }
            }
        }
        if ($under !== null) {
            $boat['waterLevel'] = $box[4];
            return $under;
        }
        $inWater = false;
        $boat['waterLevel'] = -PHP_FLOAT_MAX;
        for ($x = (int) floor($box[0]); $x < (int) ceil($box[3]); ++$x) {
            for ($y = (int) floor($box[1]); $y < (int) ceil($box[1] + 0.001); ++$y) {
                for ($z = (int) floor($box[2]); $z < (int) ceil($box[5]); ++$z) {
                    $fluid = $fluids->fluid($block($x, $y, $z));
                    if (($fluid[0] ?? null) === 'water') {
                        $height = Float32::round($y + $fluids->entityHeight($block, $fluid, $x, $y, $z));
                        $boat['waterLevel'] = max($height, $boat['waterLevel']);
                        $inWater = $inWater || $box[1] < $height;
                    }
                }
            }
        }
        if ($inWater) {
            return 'in_water';
        }
        $friction = $this->boatGroundFriction($box);
        if ($friction > 0.0) {
            $boat['landFriction'] = $friction;
            return 'on_land';
        }
        return 'in_air';
    }

    /**
     * AbstractBoat.getGroundFriction: the mean friction of the blocks (lily pads apart) whose
     * collision meets the thin slab just under the boat, its corners left out.
     */
    private function boatGroundFriction(array $box): float
    {
        $slab = [$box[0], $box[1] - 0.001, $box[2], $box[3], $box[1], $box[5]];
        $catalog = $this->version->catalog;
        $collision = new Collision($catalog);
        $movement = $catalog->movement;
        [$x0, $x1] = [(int) floor($slab[0]) - 1, (int) ceil($slab[3]) + 1];
        [$y0, $y1] = [(int) floor($slab[1]) - 1, (int) ceil($slab[4]) + 1];
        [$z0, $z1] = [(int) floor($slab[2]) - 1, (int) ceil($slab[5]) + 1];
        $friction = 0.0;
        $count = 0;
        for ($x = $x0; $x < $x1; ++$x) {
            for ($z = $z0; $z < $z1; ++$z) {
                $edges = ($x === $x0 || $x === $x1 - 1 ? 1 : 0) + ($z === $z0 || $z === $z1 - 1 ? 1 : 0);
                if ($edges === 2) {
                    continue;
                }
                for ($y = $y0; $y < $y1; ++$y) {
                    if ($edges > 0 && ($y === $y0 || $y === $y1 - 1)) {
                        continue;
                    }
                    $state = $this->world->get($x, $y, $z);
                    $name = $catalog->blockName($state);
                    if ($name === 'lily_pad') {
                        continue;
                    }
                    foreach ($collision->shape($state) as $shape) {
                        if ($shape[0] + $x < $slab[3] && $shape[3] + $x > $slab[0] && $shape[1] + $y < $slab[4] && $shape[4] + $y > $slab[1] && $shape[2] + $z < $slab[5] && $shape[5] + $z > $slab[2]) {
                            $friction = Float32::round($friction + (float) ($movement['block_friction'][$name] ?? $movement['default_block_friction']));
                            ++$count;
                            break;
                        }
                    }
                }
            }
        }
        return $count === 0 ? 0.0 : Float32::round($friction / $count);
    }

    /**
     * AbstractBoat.floatBoat: a boat that drops out of the air into water is set on the surface;
     * otherwise gravity and the status's friction act, and water lifts a boat below its level.
     */
    private function floatBoat(array &$boat, ?string $old, Collision $collision): void
    {
        $vspeed = -0.04;
        $buoyancy = 0.0;
        $invFriction = Float32::round(0.05);
        if ($old === 'in_air' && $boat['status'] !== 'in_air' && $boat['status'] !== 'on_land') {
            $boat['waterLevel'] = $boat['y'] + 0.5625;
            $target = $this->boatWaterLevelAbove($boat) - 0.5625 + 0.101;
            $moved = $this->boatBox($boat);
            $moved[1] += $target - $boat['y'];
            $moved[4] += $target - $boat['y'];
            if ($collision->boxes($this->world, $moved) === [] || !$this->boxesMeet($collision->boxes($this->world, $moved), $moved)) {
                $boat['y'] = $target;
                $boat['velocity'][1] = 0.0;
                $boat['lastYd'] = 0.0;
            }
            $boat['status'] = 'in_water';
            return;
        }
        if ($boat['status'] === 'in_water') {
            $buoyancy = ($boat['waterLevel'] - $boat['y']) / 0.5625;
            $invFriction = Float32::round(0.9);
        } elseif ($boat['status'] === 'under_flowing_water') {
            $vspeed = -7.0E-4;
            $invFriction = Float32::round(0.9);
        } elseif ($boat['status'] === 'under_water') {
            $buoyancy = Float32::round(0.01);
            $invFriction = Float32::round(0.45);
        } elseif ($boat['status'] === 'in_air') {
            $invFriction = Float32::round(0.9);
        } elseif ($boat['status'] === 'on_land') {
            $invFriction = $boat['landFriction'];
        }
        $boat['velocity'] = [$boat['velocity'][0] * $invFriction, $boat['velocity'][1] + $vspeed, $boat['velocity'][2] * $invFriction];
        if ($buoyancy > 0.0) {
            $boat['velocity'][1] = ($boat['velocity'][1] + $buoyancy * (0.04 / 0.65)) * 0.75;
        }
    }

    /** Whether any of the boxes meets the box. */
    private function boxesMeet(array $boxes, array $box): bool
    {
        foreach ($boxes as $other) {
            if ($other[0] < $box[3] && $other[3] > $box[0] && $other[1] < $box[4] && $other[4] > $box[1] && $other[2] < $box[5] && $other[5] > $box[2]) {
                return true;
            }
        }
        return false;
    }

    /** AbstractBoat.getWaterLevelAbove: the first level above the boat's top not filled with water. */
    private function boatWaterLevelAbove(array $boat): float
    {
        $box = $this->boatBox($boat);
        $fluids = $this->fluids();
        $block = $this->world->get(...);
        $maxY = (int) ceil($box[4] - $boat['lastYd']);
        for ($y = (int) floor($box[4]); $y < $maxY; ++$y) {
            $height = 0.0;
            for ($x = (int) floor($box[0]); $x < (int) ceil($box[3]); ++$x) {
                for ($z = (int) floor($box[2]); $z < (int) ceil($box[5]); ++$z) {
                    $fluid = $fluids->fluid($block($x, $y, $z));
                    if (($fluid[0] ?? null) === 'water') {
                        $height = max($height, $fluids->entityHeight($block, $fluid, $x, $y, $z));
                    }
                    if ($height >= 1.0) {
                        continue 3;
                    }
                }
            }
            if ($height < 1.0) {
                return $y + $height;
            }
        }
        return $maxY + 1;
    }

    /**
     * Player.attack on a boat, then VehicleEntity.hurtServer: it shakes, its damage grows by ten
     * times the hit, and past 40 (or at once for a creative player) it is gone, leaving its item
     * unless the hit was a creative player's.
     */
    private function attackBoat(Client $c, int $id): bool
    {
        if (!isset($this->boats[$id])) {
            return false;
        }
        $boat = &$this->boats[$id];
        if (($c->x - $boat['x']) ** 2 + ($c->y - $boat['y']) ** 2 + ($c->z - $boat['z']) ** 2 > 4.5 ** 2) {
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
        $boat['hurtDir'] = -$boat['hurtDir'];
        $boat['hurtTime'] = 10;
        $boat['syncVelocity'] = true;
        $boat['damage'] = Float32::round($boat['damage'] + Float32::round($damage * 10.0));
        $boat['dataDirty'] += [8 => true, 9 => true, 10 => true];
        if ($c->gameMode === 1 || $boat['damage'] > 40.0) {
            [$x, $y, $z, $kind] = [$boat['x'], $boat['y'], $boat['z'], $boat['kind']];
            unset($boat);
            $this->removeBoat($id);
            if ($c->gameMode !== 1 && $this->gameRule('entity_drops') === 'true') {
                $this->spawnAtLocation($x, $y, $z, ['item' => $this->version->blocks['items'][$kind], 'count' => 1]);
            }
        }
        return true;
    }

    /**
     * BoatItem.use: along the player's look within reach, the first block or water surface hit
     * (ClipContext.Fluid.ANY) gets a boat facing the way the player does, unless it would stand
     * in a block or the look passes through an entity first. The item is used up unless the
     * player has infinite materials.
     */
    private function useBoatItem(Client $c, int $slot, string $kind): bool
    {
        if (!$this->isBoatType($kind)) {
            return false;
        }
        // Entity.calculateViewVector.
        $radians = Float32::round(M_PI / 180.0);
        $yaw = Float32::round(-Float32::round($c->yaw) * $radians);
        $pitch = Float32::round(Float32::round($c->pitch) * $radians);
        $cosPitch = $this->mthCos($pitch);
        $view = [Float32::round($this->mthSin($yaw) * $cosPitch), -$this->mthSin($pitch), Float32::round($this->mthCos($yaw) * $cosPitch)];
        // Entity.getEyeY: the eye height is a float.
        $eye = [$c->x, $c->y + Float32::round($c->sneaking && !$c->flying ? 1.27 : 1.62), $c->z];
        $reach = $c->gameMode === 1 ? 5.0 : 4.5;
        $hit = $this->clipBlocksAndFluids($eye, [$eye[0] + $view[0] * $reach, $eye[1] + $view[1] * $reach, $eye[2] + $view[2] * $reach]);
        if ($hit === null) {
            return false;
        }
        $id = $this->addBoat($kind, $hit[0], $hit[1], $hit[2], [0.0, 0.0, 0.0], Float32::round($c->yaw));
        $collision = new Collision($this->version->catalog);
        $box = $this->boatBox($this->boats[$id]);
        if ($this->boxesMeet($collision->boxes($this->world, $box), $box)) {
            unset($this->boats[$id]);
            return true;
        }
        foreach ($this->localPlayers() as $player) {
            $this->showBoat($player, $id);
        }
        if ($c->gameMode !== 1) {
            \PhpMinecraftJavaServer\World\Inventory::consume($c->inventory, $slot);
            $this->syncInventory($c);
        }
        $this->awardStat($c, 'used', $kind);
        return true;
    }

    /**
     * BlockGetter.clip with ClipContext.Fluid.ANY: walking the blocks along the segment, the
     * nearest point where it meets a block's collision or a fluid's surface box.
     *
     * @return array{float, float, float}|null
     */
    private function clipBlocksAndFluids(array $from, array $to): ?array
    {
        $collision = new Collision($this->version->catalog);
        $fluids = $this->fluids();
        $block = $this->world->get(...);
        $d = [$to[0] - $from[0], $to[1] - $from[1], $to[2] - $from[2]];
        $cell = [(int) floor($from[0]), (int) floor($from[1]), (int) floor($from[2])];
        $end = [(int) floor($to[0]), (int) floor($to[1]), (int) floor($to[2])];
        $step = array_map(static fn (float $v): int => $v > 0 ? 1 : ($v < 0 ? -1 : 0), $d);
        $next = [];
        $delta = [];
        foreach ([0, 1, 2] as $axis) {
            $delta[$axis] = $step[$axis] === 0 ? INF : abs(1.0 / $d[$axis]);
            $next[$axis] = $step[$axis] === 0 ? INF : ($step[$axis] > 0 ? $cell[$axis] + 1 - $from[$axis] : $from[$axis] - $cell[$axis]) * $delta[$axis];
        }
        for ($guard = 0; $guard < 64; ++$guard) {
            [$x, $y, $z] = $cell;
            $state = $block($x, $y, $z);
            $boxes = array_map(static fn (array $b): array => [$b[0] + $x, $b[1] + $y, $b[2] + $z, $b[3] + $x, $b[4] + $y, $b[5] + $z], $collision->shape($state));
            $fluid = $fluids->fluid($state);
            if ($fluid !== null) {
                $boxes[] = [$x, $y, $z, $x + 1, $y + $fluids->entityHeight($block, $fluid, $x, $y, $z), $z + 1];
            }
            $best = null;
            foreach ($boxes as $box) {
                $t = self::segmentEntry($from, $d, $box);
                if ($t !== null && ($best === null || $t < $best)) {
                    $best = $t;
                }
            }
            if ($best !== null) {
                return [$from[0] + $d[0] * $best, $from[1] + $d[1] * $best, $from[2] + $d[2] * $best];
            }
            if ($cell === $end) {
                return null;
            }
            $axis = $next[0] < $next[1] ? ($next[0] < $next[2] ? 0 : 2) : ($next[1] < $next[2] ? 1 : 2);
            if ($next[$axis] > 1.0) {
                return null;
            }
            $cell[$axis] += $step[$axis];
            $next[$axis] += $delta[$axis];
        }
        return null;
    }

    /** AABB.clip: where along the segment (0 to 1) it enters the box, or null. */
    private static function segmentEntry(array $from, array $d, array $box): ?float
    {
        $enter = 0.0;
        $leave = 1.0;
        foreach ([0, 1, 2] as $axis) {
            if (abs($d[$axis]) < 1.0E-12) {
                if ($from[$axis] < $box[$axis] || $from[$axis] > $box[$axis + 3]) {
                    return null;
                }
                continue;
            }
            $t1 = ($box[$axis] - $from[$axis]) / $d[$axis];
            $t2 = ($box[$axis + 3] - $from[$axis]) / $d[$axis];
            $enter = max($enter, min($t1, $t2));
            $leave = min($leave, max($t1, $t2));
            if ($enter > $leave) {
                return null;
            }
        }
        return $enter;
    }

    private function boatState(array $boat): array
    {
        return ['position' => [$boat['x'], $boat['y'], $boat['z']], 'velocity' => $boat['velocity'], 'yaw' => $boat['yaw'], 'pitch' => 0.0, 'head' => 0.0,
            'ground' => $boat['ground'], 'needs_sync' => false, 'sync_velocity' => $boat['syncVelocity'] ?? false, 'sync_position' => false,
            'force_update' => $boat['dataDirty'] !== []];
    }

    /** The changed VehicleEntity data: hurt time (8), hurt direction (9) and damage (10). */
    private function boatData(array $boat, array $dirty): string
    {
        $data = '';
        ksort($dirty);
        foreach (array_keys($dirty) as $index) {
            $data .= match ($index) {
                8 => "\x08\x01" . Binary::writeVarInt($boat['hurtTime']),
                9 => "\x09\x01" . Binary::writeVarInt($boat['hurtDir']),
                10 => "\x0a\x03" . pack('G', $boat['damage']),
            };
        }
        return $data;
    }

    /** ServerEntity.sendChanges for a boat: every 3 ticks, or at once when its data changed. */
    private function synchronizeBoat(int $id): void
    {
        $boat = $this->boats[$id];
        foreach ($this->localPlayers() as $player) {
            if (!isset($player->entities[$id])) {
                $this->showBoat($player, $id);
            } elseif (!$this->tracksPosition($player, $boat['x'], $boat['z'], $boat['kind'])) {
                $this->forgetEntity($player, $id);
            }
        }
        $state = $this->boatState($boat);
        $tracker = $this->boatTrackers[$id] ??= new EntityMovementTracker($id, 3, true, $state, false);
        $packets = $tracker->update($state);
        $this->boats[$id]['syncVelocity'] = false;
        if ($boat['dataDirty'] !== []) {
            $packets[] = ['name' => 'set_entity_data', 'payload' => Binary::writeVarInt($id) . $this->boatData($boat, $boat['dataDirty']) . "\xff"];
            $this->boats[$id]['dataDirty'] = [];
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
    private function showBoat(Client $player, int $id): bool
    {
        $boat = $this->boats[$id];
        if (isset($player->entities[$id]) || !$this->tracksPosition($player, $boat['x'], $boat['z'], $boat['kind'])) {
            return isset($player->entities[$id]);
        }
        $tracker = $this->boatTrackers[$id] ??= new EntityMovementTracker($id, 3, true, $this->boatState($boat), false);
        $changed = array_filter([8 => $boat['hurtTime'] !== 0, 9 => $boat['hurtDir'] !== 1, 10 => $boat['damage'] !== 0.0]);
        $player->send($this->version, 'bundle_delimiter', '');
        $player->send($this->version, 'add_entity', $tracker->spawnPacket($boat['uuid'], $this->version->blocks['entity_types'][$boat['kind']], 0));
        if ($changed !== []) {
            $player->send($this->version, 'set_entity_data', Binary::writeVarInt($id) . $this->boatData($boat, $changed) . "\xff");
        }
        $player->send($this->version, 'bundle_delimiter', '');
        $player->entities[$id] = true;
        return true;
    }
}
