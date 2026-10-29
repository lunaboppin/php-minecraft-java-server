<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Tracking\EntityMovementTracker;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Generation\Float32;

trait Entities
{
    private array $itemEntities = [];

    /** @var array<int, EntityMovementTracker> */
    private array $itemTrackers = [];

    private static function randomUnit(): float
    {
        return mt_rand() / (mt_getrandmax() + 1.0);
    }

    /**
     * An item entity, by default as the ItemEntity constructor makes it: a random sideways push
     * and a 0.2 hop. $age 5999 is makeFakeItem's, gone on its next tick.
     */
    private function dropStack(float $x, float $y, float $z, array $stack, int $age = 0, ?array $velocity = null, int $pickupDelay = 0, ?string $target = null): ?int
    {
        if ($stack['count'] <= 0) {
            return null;
        }
        // The entity ID is taken even when too many items already lie around to keep this one.
        $id = $this->nextEntity++;
        if (count($this->itemEntities) >= 256) {
            return null;
        }
        $velocity ??= [self::randomUnit() * 0.2 - 0.1, 0.2, self::randomUnit() * 0.2 - 0.1];
        $this->itemEntities[$id] = ['id' => $id, 'uuid' => random_bytes(16), 'x' => $x, 'y' => $y, 'z' => $z, 'yaw' => self::randomUnit() * 360.0,
            'velocity' => $velocity, 'stack' => $stack, 'age' => $age, 'pickupDelay' => $pickupDelay, 'target' => $target, 'ticks' => 0, 'ground' => false,
            'created' => $this->ticks, 'dirty' => true];
        foreach ($this->localPlayers() as $player) {
            $this->showItem($player, $this->itemEntities[$id]);
        }
        return $id;
    }

    /** Block.popResource: a block's drop from a random point near its centre, picked up after 10 ticks. */
    private function popResource(int $x, int $y, int $z, array $stack): void
    {
        $offset = static fn (): float => -0.25 + self::randomUnit() * 0.5;
        $this->dropStack($x + 0.5 + $offset(), $y + 0.5 + $offset() - 0.125, $z + 0.5 + $offset(), $stack, 0, null, 10);
    }

    /** Entity.spawnAtLocation: a mob's drop at its feet, picked up after 10 ticks. */
    private function spawnAtLocation(float $x, float $y, float $z, array $stack): void
    {
        $this->dropStack($x, $y, $z, $stack, 0, null, 10);
    }

    /**
     * LivingEntity.createItemStackToDrop for a player: from the hand, thrown along the look or
     * scattered at random, picked up after 40 ticks.
     */
    private function playerDrop(Client $c, array $stack, bool $randomly, int $age = 0): ?int
    {
        $eye = $c->y + ($c->sneaking && !$c->flying ? 1.27 : 1.62);
        if ($randomly) {
            $power = Float32::round(self::randomUnit() * 0.5);
            $direction = Float32::round(self::randomUnit() * (float) Float32::round(M_PI * 2));
            $velocity = [-sin($direction) * $power, 0.2, cos($direction) * $power];
        } else {
            $pitch = deg2rad($c->pitch);
            $yaw = deg2rad($c->yaw);
            $direction = self::randomUnit() * M_PI * 2;
            $power = 0.02 * self::randomUnit();
            $velocity = [-sin($yaw) * cos($pitch) * 0.3 + cos($direction) * $power, -sin($pitch) * 0.3 + 0.1 + (self::randomUnit() - self::randomUnit()) * 0.1, cos($yaw) * cos($pitch) * 0.3 + sin($direction) * $power];
        }
        $velocity = array_map(static fn (float $v): float => Float32::round($v), $velocity);
        // getEyeY() - 0.3F subtracts the float 0.3.
        return $this->dropStack($c->x, $eye - Float32::round(0.3), $c->z, $stack, $age, $velocity, 40);
    }

    /** Containers.dropItemStack: a broken container's stacks in random parts of 10 to 30, free to take at once. */
    private function dropContainerStack(int $x, int $y, int $z, array $stack): void
    {
        while ($stack['count'] > 0) {
            $count = min($stack['count'], random_int(10, 30));
            $stack['count'] -= $count;
            $triangle = static fn (float $mean, float $spread): float => $mean + $spread * (self::randomUnit() - self::randomUnit());
            $this->dropStack(
                $x + self::randomUnit() * 0.75 + 0.125,
                $y + self::randomUnit() * 0.75,
                $z + self::randomUnit() * 0.75 + 0.125,
                array_replace($stack, ['count' => $count]),
                0,
                [$triangle(0.0, 0.11485), $triangle(0.2, 0.11485), $triangle(0.0, 0.11485)]
            );
        }
    }

    private function itemMetadata(array $entity): string
    {
        return Binary::writeVarInt($entity['id']) . chr($this->version->catalog->itemMetadataIndex) . Binary::writeVarInt($this->version->catalog->itemMetadataSerializer) . Packets::stack($entity['stack'], $this->version->blocks['component_damage'], $this->version->catalog->itemStacks) . "\xff";
    }

    private function itemReference(): array
    {
        static $reference = null;
        return $reference ??= json_decode((string) file_get_contents($this->version->directory . '/item-entities.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function itemState(array $entity): array
    {
        return ['position' => [$entity['x'], $entity['y'], $entity['z']], 'velocity' => $entity['velocity'], 'yaw' => $entity['yaw'], 'pitch' => 0.0, 'head' => 0.0,
            'ground' => $entity['ground'], 'needs_sync' => $entity['needsSync'] ?? false, 'sync_velocity' => false, 'sync_position' => false];
    }

    private function itemTracker(array $entity): EntityMovementTracker
    {
        $profile = $this->itemReference()['item'];
        return $this->itemTrackers[$entity['id']] ??= new EntityMovementTracker($entity['id'], $profile['update_interval'], $profile['track_deltas'], $this->itemState($entity));
    }

    /** ServerEntity.sendPairingData for an item: the spawn with its movement, then its stack. */
    private function showItem(Client $c, array $entity): void
    {
        $id = $entity['id'];
        if (isset($c->entities[$id]) || !$this->tracksPosition($c, $entity['x'], $entity['z'], 'item')) {
            return;
        }
        $c->send($this->version, 'add_entity', $this->itemTracker($entity)->spawnPacket($entity['uuid'], $this->version->blocks['entity_types']['item'], 0));
        $c->send($this->version, 'set_entity_data', $this->itemMetadata($entity));
        $c->entities[$id] = true;
    }

    /**
     * ItemEntity.hurtServer: items resisting the damage type (netherite against fire), or fall
     * damage for a fall-immune entity type, take none; otherwise their health of 5 falls by the
     * damage, truncated, and the item is removed at 0 or below.
     */
    private function hurtItem(int $id, float $amount, string $type): bool
    {
        $item = $this->itemEntities[$id] ?? null;
        if ($item === null) {
            return false;
        }
        $reference = $this->itemReference();
        $name = $this->version->catalog->itemName($item['stack']['item']);
        $fall = in_array($type, $this->version->catalog->damageTypes['tags']['minecraft:is_fall'] ?? [], true);
        if (in_array($type, $reference['damage_resistant'][$name] ?? [], true)
            || ($fall && in_array('item', $this->version->registryTags('minecraft:entity_type')['minecraft:fall_damage_immune'] ?? [], true))) {
            return false;
        }
        $health = (int) Float32::round(($item['health'] ?? 5) - $amount);
        $this->itemEntities[$id]['health'] = $health;
        if ($health <= 0) {
            $this->removeItem($id);
        }
        return true;
    }

    private function removeItem(int $id): void
    {
        if (isset($this->itemEntities[$id])) {
            $this->entityRemoved($this->itemEntities[$id]['uuid']);
        }
        unset($this->itemEntities[$id], $this->itemTrackers[$id]);
        foreach ($this->localPlayers() as $player) {
            if (isset($player->entities[$id])) {
                $player->send($this->version, 'remove_entities', "\1" . Binary::writeVarInt($id));
                unset($player->entities[$id]);
            }
        }
    }

    /** ItemEntity.tick for every item, then players touching items pick them up. */
    private function tickItems(): void
    {
        $collision = new Collision($this->version->catalog);
        foreach (array_keys($this->itemEntities) as $id) {
            if (!isset($this->itemEntities[$id])) {
                continue;
            }
            $item = &$this->itemEntities[$id];
            ++$item['ticks'];
            if ($item['pickupDelay'] > 0 && $item['pickupDelay'] !== 32767) {
                --$item['pickupDelay'];
            }
            [$oldX, $oldY, $oldZ] = [$item['x'], $item['y'], $item['z']];
            $oldVelocity = $item['velocity'];
            $fluid = $this->itemFluid($item);
            if ($fluid === 'water') {
                // setUnderwaterMovement.
                $item['velocity'] = [$item['velocity'][0] * 0.99, $item['velocity'][1] + ($item['velocity'][1] < 0.06 ? 5.0E-4 : 0.0), $item['velocity'][2] * 0.99];
            } elseif ($fluid === 'lava') {
                $item['velocity'] = [$item['velocity'][0] * 0.95, $item['velocity'][1] + ($item['velocity'][1] < 0.06 ? 5.0E-4 : 0.0), $item['velocity'][2] * 0.95];
            } else {
                $item['velocity'][1] -= 0.04;
            }
            $horizontal = $item['velocity'][0] ** 2 + $item['velocity'][2] ** 2;
            // Resting items move only every fourth tick, staggered by their ID.
            if (!($item['ground'] && $horizontal <= 1.0E-5 && ($item['ticks'] + $id) % 4 !== 0)) {
                $box = [$item['x'] - 0.125, $item['y'], $item['z'] - 0.125, $item['x'] + 0.125, $item['y'] + 0.25, $item['z'] + 0.125];
                $moved = $collision->move($this->world, $box, $item['velocity']);
                $below = $moved[1] !== $item['velocity'][1] && $item['velocity'][1] < 0.0;
                [$item['x'], $item['y'], $item['z']] = [$item['x'] + $moved[0], $item['y'] + $moved[1], $item['z'] + $moved[2]];
                foreach ([0, 1, 2] as $axis) {
                    if ($moved[$axis] !== $item['velocity'][$axis]) {
                        $item['velocity'][$axis] = 0.0;
                    }
                }
                $item['ground'] = $below;
                $ground = 0.98;
                if ($item['ground']) {
                    $ground *= $this->blockFriction($this->world->get((int) floor($item['x']), (int) floor($item['y'] - 0.999999), (int) floor($item['z'])));
                }
                $item['velocity'] = [$item['velocity'][0] * $ground, $item['velocity'][1] * 0.98, $item['velocity'][2] * $ground];
                if ($item['ground'] && $item['velocity'][1] < 0.0) {
                    $item['velocity'][1] *= -0.5;
                }
            }
            $movedBlock = floor($oldX) !== floor($item['x']) || floor($oldY) !== floor($item['y']) || floor($oldZ) !== floor($item['z']);
            if ($item['ticks'] % ($movedBlock ? 2 : 40) === 0) {
                unset($item);
                $this->mergeNearbyItems($id);
                if (!isset($this->itemEntities[$id])) {
                    continue;
                }
                $item = &$this->itemEntities[$id];
            }
            ++$item['age'];
            $change = ($item['velocity'][0] - $oldVelocity[0]) ** 2 + ($item['velocity'][1] - $oldVelocity[1]) ** 2 + ($item['velocity'][2] - $oldVelocity[2]) ** 2;
            $item['needsSync'] = ($item['needsSync'] ?? false) || $change > 0.01;
            if ($item['age'] >= 6000 || $item['y'] < $this->world->minY - 64) {
                unset($item);
                $this->removeItem($id);
                continue;
            }
            unset($item);
            $this->itemTouches($id);
            if (isset($this->itemEntities[$id])) {
                $this->synchronizeItem($id);
            }
        }
    }

    /** Entity.isInWater/isInLava with a fluid height above 0.1 in the item's block. */
    private function itemFluid(array $item): ?string
    {
        $state = $this->world->get((int) floor($item['x']), (int) floor($item['y']), (int) floor($item['z']));
        $catalog = $this->version->catalog;
        $name = $catalog->blockName($state);
        if ($name === 'lava') {
            return 'lava';
        }
        return $name === 'water' || ($catalog->properties($state)['waterlogged'] ?? 'false') === 'true' ? 'water' : null;
    }

    private function blockFriction(int $state): float
    {
        $movement = $this->version->catalog->movement;
        return (float) ($movement['block_friction'][$this->version->catalog->blockName($state)] ?? $movement['default_block_friction']);
    }

    /** ItemEntity.isMergable. */
    private function itemMergable(array $item): bool
    {
        return $item['pickupDelay'] !== 32767 && $item['age'] < 6000 && $item['stack']['count'] < ($this->version->catalog->item($item['stack']['item'])['max_stack'] ?? 64);
    }

    /** ItemEntity.mergeWithNeighbours: the smaller of two matching stacks joins the larger. */
    private function mergeNearbyItems(int $id): void
    {
        foreach (array_keys($this->itemEntities) as $otherId) {
            $item = $this->itemEntities[$id] ?? null;
            $other = $this->itemEntities[$otherId] ?? null;
            if ($item === null || !$this->itemMergable($item)) {
                return;
            }
            if ($other === null || $otherId === $id || !$this->itemMergable($other)
                || abs($other['x'] - $item['x']) >= 0.75 || abs($other['y'] - $item['y']) >= 0.25 || abs($other['z'] - $item['z']) >= 0.75) {
                continue;
            }
            $sameStack = static fn (array $a, array $b): bool => array_diff_key($a, ['count' => 0]) == array_diff_key($b, ['count' => 0]);
            $max = $this->version->catalog->item($item['stack']['item'])['max_stack'] ?? 64;
            if ($item['target'] !== $other['target'] || !$sameStack($item['stack'], $other['stack']) || $item['stack']['count'] + $other['stack']['count'] > $max) {
                continue;
            }
            [$to, $from] = $other['stack']['count'] < $item['stack']['count'] ? [$id, $otherId] : [$otherId, $id];
            $moved = min(min($max, 64) - $this->itemEntities[$to]['stack']['count'], $this->itemEntities[$from]['stack']['count']);
            $this->itemEntities[$to]['stack']['count'] += $moved;
            $this->itemEntities[$from]['stack']['count'] -= $moved;
            $this->itemEntities[$to]['pickupDelay'] = max($this->itemEntities[$to]['pickupDelay'], $this->itemEntities[$from]['pickupDelay']);
            $this->itemEntities[$to]['age'] = min($this->itemEntities[$to]['age'], $this->itemEntities[$from]['age']);
            $this->itemEntities[$to]['dirty'] = true;
            if ($this->itemEntities[$from]['stack']['count'] <= 0) {
                $this->removeItem($from);
            }
        }
    }

    /**
     * Player.touch with ItemEntity.playerTouch: after the pickup delay, a player whose box (grown
     * by 1, 0.5, 1) meets the item takes what fits; creative players take the rest as well.
     */
    private function itemTouches(int $id): void
    {
        foreach ($this->localPlayers() as $player) {
            $item = $this->itemEntities[$id] ?? null;
            if ($item === null) {
                return;
            }
            if ($item['pickupDelay'] !== 0 || ($item['target'] !== null && $item['target'] !== $player->uuid) || $player->dead || $player->gameMode === 3) {
                continue;
            }
            if (abs($player->x - $item['x']) >= 1.425 || abs($player->z - $item['z']) >= 1.425 || $item['y'] + 0.25 <= $player->y - 0.5 || $item['y'] >= $player->y + 2.3) {
                continue;
            }
            $count = $item['stack']['count'];
            $left = $this->giveStack($player, $item['stack']);
            if ($left === $count && $player->gameMode !== 1) {
                continue;
            }
            if ($player->gameMode === 1) {
                $left = 0;
            }
            foreach ($this->localPlayers() as $viewer) {
                if ($viewer === $player || isset($viewer->entities[$id])) {
                    $viewer->send($this->version, 'take_item_entity', Binary::writeVarInt($id) . Binary::writeVarInt($player->entityId) . Binary::writeVarInt($count));
                }
            }
            $this->syncInventory($player);
            if ($left === 0) {
                $this->removeItem($id);
                return;
            }
            $this->itemEntities[$id]['stack']['count'] = $left;
            $this->itemEntities[$id]['dirty'] = true;
        }
    }

    private function synchronizeItem(int $id): void
    {
        $item = $this->itemEntities[$id];
        foreach ($this->localPlayers() as $player) {
            if (!isset($player->entities[$id])) {
                $this->showItem($player, $item);
            } elseif (!$this->tracksPosition($player, $item['x'], $item['z'], 'item')) {
                $this->forgetEntity($player, $id);
            }
        }
        // A new or changed stack reaches viewers with the next ServerEntity.sendChanges.
        if ($item['dirty']) {
            $this->itemEntities[$id]['dirty'] = false;
            foreach ($this->localPlayers() as $player) {
                if (isset($player->entities[$id])) {
                    $player->send($this->version, 'set_entity_data', $this->itemMetadata($item));
                }
            }
        }
        $state = $this->itemState($item);
        $packets = $this->itemTracker($item)->update($state);
        if ($packets !== []) {
            $this->itemEntities[$id]['needsSync'] = false;
        }
        foreach ($packets as $packet) {
            foreach ($this->localPlayers() as $player) {
                if (isset($player->entities[$id])) {
                    $player->send($this->version, $packet['name'], $packet['payload']);
                }
            }
        }
    }
}
