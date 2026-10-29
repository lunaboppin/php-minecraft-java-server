<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Server\Tracking\EntityMovementTracker;
use PhpMinecraftJavaServer\World\Collision;

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

    private function spawnOrb(float $x, float $y, float $z, int $value): void
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
        foreach ($this->localPlayers() as $player) {
            $this->showOrb($player, $this->experienceOrbs[$id]);
        }
    }

    private function orbState(array $orb): array
    {
        return ['position' => [$orb['x'], $orb['y'], $orb['z']], 'velocity' => $orb['velocity'], 'yaw' => $orb['yaw'], 'pitch' => 0.0, 'head' => 0.0,
            'ground' => $orb['ground'], 'needs_sync' => $orb['needsSync'] ?? false, 'sync_velocity' => false, 'sync_position' => false];
    }

    private function orbTracker(array $orb): EntityMovementTracker
    {
        $profile = $this->experienceReference()['orb'];
        return $this->orbTrackers[$orb['id']] ??= new EntityMovementTracker($orb['id'], $profile['update_interval'], $profile['track_deltas'], $this->orbState($orb));
    }

    /** ServerEntity.sendPairingData for an orb: the spawn and its value. */
    private function showOrb(Client $c, array $orb): void
    {
        if (isset($c->entities[$orb['id']]) || !$this->tracksPosition($c, $orb['x'], $orb['z'], 'experience_orb')) {
            return;
        }
        $reference = $this->experienceReference()['orb'];
        $c->send($this->version, 'add_entity', $this->orbTracker($orb)->spawnPacket($orb['uuid'], $reference['type'], 0));
        $c->send($this->version, 'set_entity_data', $this->orbData($orb));
        $c->entities[$orb['id']] = true;
    }

    /** ExperienceOrb.hurtServer: health of 5, lowered by the damage truncated, removed at 0. */
    private function hurtOrb(int $id, float $amount): bool
    {
        if (!isset($this->experienceOrbs[$id])) {
            return false;
        }
        $health = (int) \PhpMinecraftJavaServer\World\Generation\Float32::round($this->experienceOrbs[$id]['health'] - $amount);
        $this->experienceOrbs[$id]['health'] = $health;
        if ($health <= 0) {
            $this->removeOrb($id);
        }
        return true;
    }

    private function orbData(array $orb): string
    {
        return Binary::writeVarInt($orb['id']) . chr($this->experienceReference()['orb']['value_index']) . Binary::writeVarInt($this->version->catalog->entityMetadata['int_serializer']) . Binary::writeVarInt($orb['value']) . "\xff";
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
        $collision = new Collision($this->version->catalog);
        foreach (array_keys($this->experienceOrbs) as $id) {
            if (!isset($this->experienceOrbs[$id])) {
                continue;
            }
            $orb = &$this->experienceOrbs[$id];
            ++$orb['ticks'];
            $box = static fn (array $o): array => [$o['x'] - 0.25, $o['y'], $o['z'] - 0.25, $o['x'] + 0.25, $o['y'] + 0.5, $o['z'] + 0.25];
            $colliding = $this->boxCollides($collision, $box($orb));
            if ($this->waterHeightAt($orb['x'], $orb['y'] + 0.425, $orb['z'])) {
                // setUnderwaterMovement.
                $orb['velocity'] = [$orb['velocity'][0] * 0.99, min($orb['velocity'][1] + 5.0E-4, 0.06), $orb['velocity'][2] * 0.99];
            } elseif (!$colliding) {
                $orb['velocity'][1] -= 0.03;
            }
            if ($this->version->catalog->blockName($this->world->get((int) floor($orb['x']), (int) floor($orb['y']), (int) floor($orb['z']))) === 'lava') {
                $random = static fn (): float => mt_rand() / mt_getrandmax();
                $orb['velocity'] = [($random() - $random()) * 0.2, 0.2, ($random() - $random()) * 0.2];
            }
            if ($orb['ticks'] % 20 === 1) {
                $this->scanForOrbMerges($orb);
            }
            $this->followNearestPlayer($orb);
            $fall = $orb['velocity'][1];
            $moved = $collision->move($this->world, $box($orb), $orb['velocity']);
            $below = $moved[1] !== $orb['velocity'][1] && $orb['velocity'][1] < 0.0;
            [$orb['x'], $orb['y'], $orb['z']] = [$orb['x'] + $moved[0], $orb['y'] + $moved[1], $orb['z'] + $moved[2]];
            foreach ([0, 2] as $axis) {
                if ($moved[$axis] !== $orb['velocity'][$axis]) {
                    $orb['velocity'][$axis] = 0.0;
                }
            }
            if ($moved[1] !== $orb['velocity'][1]) {
                $orb['velocity'][1] = 0.0;
            }
            $orb['ground'] = $below;
            $friction = 0.98;
            if ($orb['ground']) {
                $friction *= $this->blockFriction($this->world->get((int) floor($orb['x']), (int) floor($orb['y'] - 0.999999), (int) floor($orb['z'])));
            }
            $orb['velocity'] = array_map(static fn (float $v): float => $v * $friction, $orb['velocity']);
            if ($below && $fall < -0.03) {
                $orb['velocity'][1] = -$fall * 0.4;
            }
            if (++$orb['age'] >= 6000 || $orb['y'] < $this->world->minY - 64) {
                unset($orb);
                $this->removeOrb($id);
                continue;
            }
            unset($orb);
            $this->orbTouches($id);
            if (isset($this->experienceOrbs[$id])) {
                $this->synchronizeOrb($id);
            }
        }
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

    private function waterHeightAt(float $x, float $y, float $z): bool
    {
        $state = $this->world->get((int) floor($x), (int) floor($y), (int) floor($z));
        return $this->version->catalog->blockName($state) === 'water' || ($this->version->catalog->properties($state)['waterlogged'] ?? 'false') === 'true';
    }

    private function blockFriction(int $state): float
    {
        $movement = $this->version->catalog->movement;
        return (float) ($movement['block_friction'][$this->version->catalog->blockName($state)] ?? $movement['default_block_friction']);
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
        if ($orb['dirty']) {
            $this->experienceOrbs[$id]['dirty'] = false;
            foreach ($this->localPlayers() as $player) {
                if (isset($player->entities[$id])) {
                    $player->send($this->version, 'set_entity_data', $this->orbData($orb));
                }
            }
        }
        foreach ($this->orbTracker($orb)->update($this->orbState($orb)) as $packet) {
            foreach ($this->localPlayers() as $player) {
                if (isset($player->entities[$id])) {
                    $player->send($this->version, $packet['name'], $packet['payload']);
                }
            }
        }
    }
}
