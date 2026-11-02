<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Server\Tracking\EntityMovementTracker;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * ExperienceOrb: experience dropped in the world as orbs that fall, drift towards the nearest
 * player within 8 blocks, merge with orbs of the same value and group, and are taken one at a
 * time by touching players.
 */
trait ExperienceOrbs
{
    /** @var array<int, array> */
    private array $experienceOrbs = [];

    /** @var array<int, EntityMovementTracker> */
    private array $orbTrackers = [];

    private function experienceReference(): array
    {
        static $reference = null;
        return $reference ??= json_decode((string) file_get_contents($this->version->directory . '/experience.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Orbs saved with the world come back with new network IDs. */
    private function restoreExperienceOrbs(): void
    {
        foreach ($this->world->setting('experienceOrbs') ?? [] as $saved) {
            if (!isset($saved['uuid'], $saved['value']) || ($saved['count'] ?? 1) <= 0) {
                continue;
            }
            $id = $this->nextEntity++;
            $this->experienceOrbs[$id] = ['id' => $id, 'uuid' => hex2bin($saved['uuid']), 'x' => (float) $saved['x'], 'y' => (float) $saved['y'], 'z' => (float) $saved['z'],
                'yaw' => (float) ($saved['yaw'] ?? 0.0), 'velocity' => array_map('floatval', $saved['velocity'] ?? [0.0, 0.0, 0.0]), 'value' => (int) $saved['value'],
                'count' => (int) ($saved['count'] ?? 1), 'age' => (int) ($saved['age'] ?? 0), 'health' => (int) ($saved['health'] ?? 5), 'ticks' => 0, 'ground' => false,
                'following' => null, 'dirty' => true];
        }
    }

    /** ExperienceOrb.getExperienceValue: the largest orb size the amount allows. */
    private static function orbValue(int $amount): int
    {
        foreach ([2477, 1237, 617, 307, 149, 73, 37, 17, 7, 3] as $size) {
            if ($amount >= $size) {
                return $size;
            }
        }
        return 1;
    }

    /** ExperienceOrb.award: split into orbs, each first offered to a matching orb nearby. */
    private function awardExperience(float $x, float $y, float $z, int $amount): void
    {
        while ($amount > 0) {
            $value = self::orbValue($amount);
            $amount -= $value;
            if (!$this->mergeIntoNearbyOrb($x, $y, $z, $value)) {
                $this->spawnOrb($x, $y, $z, $value);
            }
        }
    }

    /** ExperienceOrb.tryMergeToExisting: an orb of the same value and group within half a block. */
    private function mergeIntoNearbyOrb(float $x, float $y, float $z, int $value): bool
    {
        $group = random_int(0, 39);
        foreach ($this->experienceOrbs as &$orb) {
            if (($orb['id'] - $group) % 40 === 0 && $orb['value'] === $value
                && abs($orb['x'] - $x) < 0.75 && $orb['y'] < $y + 0.5 && $orb['y'] + 0.5 > $y - 0.5 && abs($orb['z'] - $z) < 0.75) {
                ++$orb['count'];
                $orb['age'] = 0;
                return true;
            }
        }
        return false;
    }

    private function spawnOrb(float $x, float $y, float $z, int $value, ?array $loaded = null): int
    {
        $id = $this->nextEntity++;
        $random = static fn (): float => mt_rand() / mt_getrandmax();
        $this->experienceOrbs[$id] = [
            'id' => $id, 'uuid' => random_bytes(16), 'x' => $x, 'y' => $y, 'z' => $z, 'yaw' => $random() * 360.0,
            'velocity' => [($random() * 0.2 - 0.1) * 2.0, $random() * 0.2 * 2.0, ($random() * 0.2 - 0.1) * 2.0],
            'value' => $value, 'count' => 1, 'age' => 0, 'health' => 5, 'ticks' => 0, 'ground' => false, 'following' => null,
            // The value set while constructing stays dirty until the first ServerEntity.sendChanges.
            'dirty' => true,
        ];
        if ($loaded !== null) {
            // ExperienceOrb's bare constructor, then its tag: no push, facing yaw 0.
            $this->experienceOrbs[$id] = $loaded + ['yaw' => 0.0, 'velocity' => [0.0, 0.0, 0.0]] + $this->experienceOrbs[$id];
        }
        foreach ($this->localPlayers() as $player) {
            $this->showOrb($player, $this->experienceOrbs[$id]);
        }
        return $id;
    }

    private function orbState(array $orb): array
    {
        return ['position' => [$orb['x'], $orb['y'], $orb['z']], 'velocity' => $orb['velocity'], 'yaw' => $orb['yaw'], 'pitch' => 0.0, 'head' => 0.0,
            'ground' => $orb['ground'], 'needs_sync' => $orb['needsSync'] ?? false, 'sync_velocity' => $orb['syncVelocity'] ?? false, 'sync_position' => false];
    }

    private function orbTracker(array $orb): EntityMovementTracker
    {
        $profile = $this->experienceReference()['orb'];
        return $this->orbTrackers[$orb['id']] ??= new EntityMovementTracker($orb['id'], $profile['update_interval'], $profile['track_deltas'], $this->orbState($orb), false);
    }

    /** ServerEntity.sendPairingData for an orb: the spawn and its value. */
    private function showOrb(Client $c, array $orb): void
    {
        if (isset($c->entities[$orb['id']]) || !$this->tracksPosition($c, $orb['x'], $orb['z'], 'experience_orb')) {
            return;
        }
        $reference = $this->experienceReference()['orb'];
        $c->send($this->version, 'bundle_delimiter', '');
        $c->send($this->version, 'add_entity', $this->orbTracker($orb)->spawnPacket($orb['uuid'], $reference['type'], 0));
        $c->send($this->version, 'set_entity_data', $this->orbData($orb));
        $c->send($this->version, 'bundle_delimiter', '');
        $c->entities[$orb['id']] = true;
    }

    /** ExperienceOrb.hurtServer: health of 5, lowered by the damage truncated, removed at 0. */
    private function hurtOrb(int $id, float $amount): bool
    {
        if (!isset($this->experienceOrbs[$id])) {
            return false;
        }
        $health = (int) Float32::round($this->experienceOrbs[$id]['health'] - $amount);
        $this->experienceOrbs[$id]['health'] = $health;
        // Entity.markHurt: its motion is sent with the next changes.
        $this->experienceOrbs[$id]['syncVelocity'] = true;
        if ($health <= 0) {
            $this->removeOrb($id);
        }
        return true;
    }

    /**
     * An orb's entity data: the shared flags (on fire) and ticks frozen when not their defaults,
     * then its value; or, for SynchedEntityData.packDirty, only the entries in $dirty.
     */
    private function orbData(array $orb, ?array $dirty = null): string
    {
        $index = $this->experienceReference()['orb']['value_index'];
        $entries = [
            0 => [$orb['onFire'] ?? false, "\x00\x00" . chr(($orb['onFire'] ?? false) ? 1 : 0)],
            7 => [($orb['frozen'] ?? 0) > 0, "\x07\x01" . Binary::writeVarInt($orb['frozen'] ?? 0)],
            $index => [true, chr($index) . Binary::writeVarInt($this->version->catalog->entityMetadata['int_serializer']) . Binary::writeVarInt($orb['value'])],
        ];
        $payload = Binary::writeVarInt($orb['id']);
        foreach ($entries as $entry => [$changed, $bytes]) {
            if ($dirty === null ? $changed : isset($dirty[$entry])) {
                $payload .= $bytes;
            }
        }
        return $payload . "\xff";
    }

    private function removeOrb(int $id): void
    {
        if (isset($this->experienceOrbs[$id])) {
            $this->entityRemoved($this->experienceOrbs[$id]['uuid']);
        }
        unset($this->experienceOrbs[$id], $this->orbTrackers[$id]);
        foreach ($this->localPlayers() as $player) {
            $this->forgetEntity($player, $id);
        }
    }

    /** ExperienceOrb.tick for every orb, then players touching orbs take them. */
    private function tickExperienceOrbs(): void
    {
        // ChunkMap.tick sends what changed before the entities tick.
        foreach (array_keys($this->experienceOrbs) as $id) {
            $this->synchronizeOrb($id);
        }
        $collision = new Collision($this->version->catalog);
        foreach (array_keys($this->experienceOrbs) as $id) {
            if (!isset($this->experienceOrbs[$id])) {
                continue;
            }
            $orb = &$this->experienceOrbs[$id];
            ++$orb['ticks'];
            $box = static fn (array $o): array => [$o['x'] - 0.25, $o['y'], $o['z'] - 0.25, $o['x'] + 0.25, $o['y'] + 0.5, $o['z'] + 0.25];
            // Entity.baseTick: the fluids it is in and their current, with its eye 0.425 up.
            $fluid = $this->entityFluidCurrent($box($orb), $orb['velocity'], false, [$orb['x'], $orb['y'] + 0.425, $orb['z']]);
            // Entity.isInLava is false on an entity's first tick.
            $inLava = $orb['ticks'] > 1 && $fluid['lava'] > 0.0;
            if (($orb['fire'] ?? 0) > 0) {
                if ($orb['fire'] % 20 === 0 && !$inLava && $this->hurtOrb($id, 1.0) && !isset($this->experienceOrbs[$id])) {
                    unset($orb);
                    continue;
                }
                --$orb['fire'];
            }
            // The shared flag shows whether it is still burning.
            if ((($orb['fire'] ?? 0) > 0) !== ($orb['onFire'] ?? false)) {
                $orb['onFire'] = ($orb['fire'] ?? 0) > 0;
                $orb['dataDirty'][0] = true;
            }
            $colliding = $this->boxCollides($collision, $box($orb));
            if ($fluid['eye_water']) {
                // setUnderwaterMovement.
                $orb['velocity'] = [$orb['velocity'][0] * 0.9900000095367432, min($orb['velocity'][1] + 5.000000237487257E-4, 0.05999999865889549), $orb['velocity'][2] * 0.9900000095367432];
            } elseif (!$colliding) {
                $orb['velocity'][1] -= 0.03;
            }
            $catalog = $this->version->catalog;
            if (($this->fluids()->fluid($this->world->get((int) floor($orb['x']), (int) floor($orb['y']), (int) floor($orb['z'])))[0] ?? null) === 'lava') {
                $orb['velocity'] = [Float32::round(Float32::round(self::randomUnit() - self::randomUnit()) * 0.2), 0.20000000298023224, Float32::round(Float32::round(self::randomUnit() - self::randomUnit()) * 0.2)];
            }
            if ($orb['ticks'] % 20 === 1) {
                $this->scanForOrbMerges($orb);
            }
            $this->followNearestPlayer($orb);
            if ($orb['following'] === null && $colliding) {
                $moved = $box($orb);
                $next = [$moved[0] + $orb['velocity'][0], $moved[1] + $orb['velocity'][1], $moved[2] + $orb['velocity'][2], $moved[3] + $orb['velocity'][0], $moved[4] + $orb['velocity'][1], $moved[5] + $orb['velocity'][2]];
                if ($this->boxCollides($collision, $next)) {
                    $this->moveTowardsClosestSpace($orb);
                    $orb['needsSync'] = true;
                }
            }
            $from = $box($orb);
            $fall = $orb['velocity'][1];
            $this->moveSimpleEntity($orb, $from, $collision, 0.03);
            $alive = fn (): bool => isset($this->experienceOrbs[$id]);
            if (!$this->entityInsideBlocks($orb, $from, $box($orb), 'experience_orb', false, fn (float $amount): bool => $this->hurtOrb($id, $amount), $alive, static fn (): bool => true)) {
                unset($orb);
                continue;
            }
            // getAirDrag, times the friction of the block below on the ground, as floats.
            $friction = 0.9800000190734863;
            if ($orb['ground']) {
                $friction = Float32::round($friction * Float32::round($this->blockFriction($this->world->get((int) floor($orb['x']), (int) floor($orb['y'] - 0.5000010132789612), (int) floor($orb['z'])))));
            }
            $orb['velocity'] = array_map(static fn (float $v): float => $v * $friction, $orb['velocity']);
            if ($orb['ground'] && $fall < -0.03) {
                $orb['velocity'][1] = -$fall * 0.4;
            }
            if (++$orb['age'] >= 6000 || $orb['y'] < $this->world->minY - 64) {
                unset($orb);
                $this->removeOrb($id);
                continue;
            }
            unset($orb);
            $this->orbTouches($id);
        }
    }

    /** Entity.moveTowardsClosestSpace: an orb stuck in a block is nudged out its nearest open side. */
    private function moveTowardsClosestSpace(array &$orb): void
    {
        $collision = new Collision($this->version->catalog);
        $point = [$orb['x'], $orb['y'] + 0.25, $orb['z']];
        $cell = array_map(static fn (float $v): int => (int) floor($v), $point);
        $closest = [1, 1, PHP_FLOAT_MAX];
        // North, south, west, east, then up; up when every side is a full block.
        foreach ([[2, -1], [2, 1], [0, -1], [0, 1], [1, 1]] as [$axis, $step]) {
            $neighbour = $cell;
            $neighbour[$axis] += $step;
            if ($collision->shape($this->world->get(...$neighbour)) !== [[0.0, 0.0, 0.0, 1.0, 1.0, 1.0]]) {
                $offset = $point[$axis] - $cell[$axis];
                $distance = $step > 0 ? 1.0 - $offset : $offset;
                if ($distance < $closest[2]) {
                    $closest = [$axis, $step, $distance];
                }
            }
        }
        $speed = Float32::round(Float32::round(self::randomUnit() * 0.2) + 0.1);
        $velocity = array_map(static fn (float $v): float => $v * 0.75, $orb['velocity']);
        $velocity[$closest[0]] = $closest[1] * $speed;
        $orb['velocity'] = $velocity;
    }

    private function boxCollides(Collision $collision, array $box): bool
    {
        foreach ($collision->boxes($this->world, $box) as $solid) {
            if ($solid[0] < $box[3] && $solid[3] > $box[0] && $solid[1] < $box[4] && $solid[4] > $box[1] && $solid[2] < $box[5] && $solid[5] > $box[2]) {
                return true;
            }
        }
        return false;
    }

    /** ExperienceOrb.scanForMerges: orbs of the same value and group within half a block join this one. */
    private function scanForOrbMerges(array &$orb): void
    {
        foreach ($this->experienceOrbs as $otherId => $other) {
            if ($otherId === $orb['id'] || ($other['id'] - $orb['id']) % 40 !== 0 || $other['value'] !== $orb['value']) {
                continue;
            }
            // This orb's box inflated by half a block against the other's.
            if (abs($other['x'] - $orb['x']) < 1.0 && abs($other['y'] - $orb['y']) < 1.0 && abs($other['z'] - $orb['z']) < 1.0) {
                $orb['count'] += $other['count'];
                $orb['age'] = min($orb['age'], $other['age']);
                $this->removeOrb($otherId);
            }
        }
    }

    /** ExperienceOrb.followNearbyPlayer: pulled towards the nearest non-spectator within 8 blocks. */
    private function followNearestPlayer(array &$orb): void
    {
        $following = $orb['following'] === null ? null : ($this->clients[$orb['following']] ?? null);
        if ($following === null || $following->gameMode === 3 || ($following->x - $orb['x']) ** 2 + ($following->y - $orb['y']) ** 2 + ($following->z - $orb['z']) ** 2 > 64) {
            $following = null;
            $nearest = 64.0;
            foreach ($this->localPlayers() as $player) {
                $distance = ($player->x - $orb['x']) ** 2 + ($player->y - $orb['y']) ** 2 + ($player->z - $orb['z']) ** 2;
                if ($player->gameMode !== 3 && $distance < $nearest) {
                    [$following, $nearest] = [$player, $distance];
                }
            }
            if ($following !== null && ($following->dead || $following->health <= 0)) {
                $following = null;
            }
            $orb['following'] = $following === null ? null : array_search($following, $this->clients, true);
        }
        if ($following === null) {
            return;
        }
        $delta = [$following->x - $orb['x'], $following->y + 0.81 - $orb['y'], $following->z - $orb['z']];
        $length = sqrt($delta[0] ** 2 + $delta[1] ** 2 + $delta[2] ** 2);
        if ($length < 1.0E-4) {
            return;
        }
        $power = 1.0 - $length / 8.0;
        foreach ($delta as $axis => $value) {
            $orb['velocity'][$axis] += $value / $length * $power * $power * 0.1;
        }
    }

    /** Player.touch with ExperienceOrb.playerTouch: one orb of the stack every two ticks. */
    private function orbTouches(int $id): void
    {
        $orb = $this->experienceOrbs[$id];
        foreach ($this->localPlayers() as $player) {
            if ($player->dead || $player->gameMode === 3 || $player->takeXpDelay > 0) {
                continue;
            }
            // The player's box inflated by 1, 0.5, 1 against the orb's half-block box.
            if (abs($player->x - $orb['x']) >= 1.55 || abs($player->z - $orb['z']) >= 1.55 || $orb['y'] + 0.5 <= $player->y - 0.5 || $orb['y'] >= $player->y + 2.3) {
                continue;
            }
            $player->takeXpDelay = 2;
            foreach ($this->localPlayers() as $viewer) {
                if (isset($viewer->entities[$id]) || $viewer === $player) {
                    $viewer->send($this->version, 'take_item_entity', Binary::writeVarInt($id) . Binary::writeVarInt($player->entityId) . Binary::writeVarInt(1));
                }
            }
            $remaining = $this->repairWithExperience($player, $orb['value']);
            if ($remaining > 0) {
                $this->giveExperiencePoints($player, $remaining);
            }
            if (--$this->experienceOrbs[$id]['count'] === 0) {
                $this->removeOrb($id);
            }
            return;
        }
    }

    /**
     * ExperienceOrb.repairPlayerItems: a random damaged item with mending is repaired by two
     * durability per experience point, and what is left over goes to the player.
     */
    private function repairWithExperience(Client $player, int $amount): int
    {
        $candidates = [];
        foreach ([36 + $player->heldSlot, 45, 5, 6, 7, 8] as $slot) {
            $stack = $player->inventory[$slot] ?? null;
            if ($stack !== null && isset($stack['enchantments']['minecraft:mending']) && ($stack['damage'] ?? 0) > 0) {
                $candidates[] = $slot;
            }
        }
        if ($candidates === []) {
            return $amount;
        }
        $slot = $candidates[array_rand($candidates)];
        $damage = $player->inventory[$slot]['damage'];
        $repairable = $amount * 2;
        $repair = min($repairable, $damage);
        $player->inventory[$slot]['damage'] = $damage - $repair;
        $this->syncInventory($player);
        $remaining = $amount - intdiv($repair * $amount, $repairable);
        return $repair > 0 && $remaining > 0 ? $this->repairWithExperience($player, $remaining) : 0;
    }

    private function synchronizeOrb(int $id): void
    {
        $orb = $this->experienceOrbs[$id];
        foreach ($this->localPlayers() as $player) {
            if (!isset($player->entities[$id])) {
                $this->showOrb($player, $orb);
            } elseif (!$this->tracksPosition($player, $orb['x'], $orb['z'], 'experience_orb')) {
                $this->forgetEntity($player, $id);
            }
        }
        // ServerEntity.sendChanges: changed data (its value, burning, freezing) forces the update and is
        // sent after the movement but before the motion of a hurt orb; needsSync clears once acted on.
        $dirty = $orb['dataDirty'] ?? [];
        if ($orb['dirty']) {
            $dirty[$this->experienceReference()['orb']['value_index']] = true;
        }
        $state = $this->orbState($orb) + ['force_update' => $dirty !== []];
        $hurt = $state['sync_velocity'];
        $packets = $this->orbTracker($orb)->update($state);
        if ($dirty !== []) {
            $this->experienceOrbs[$id]['dirty'] = false;
            $this->experienceOrbs[$id]['dataDirty'] = [];
            $motion = $hurt ? array_pop($packets) : null;
            $packets[] = ['name' => 'set_entity_data', 'payload' => $this->orbData($orb, $dirty)];
            if ($motion !== null) {
                $packets[] = $motion;
            }
        }
        $this->experienceOrbs[$id]['needsSync'] = $state['needs_sync'];
        $this->experienceOrbs[$id]['syncVelocity'] = $state['sync_velocity'];
        foreach ($packets as $packet) {
            foreach ($this->localPlayers() as $player) {
                if (isset($player->entities[$id])) {
                    $player->send($this->version, $packet['name'], $packet['payload']);
                }
            }
        }
    }
}
