<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Tracking\EntityMovementTracker;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Fluids;
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
    private function dropStack(float $x, float $y, float $z, array $stack, int $age = 0, ?array $velocity = null, int $pickupDelay = 0, ?string $target = null, ?float $yaw = null): ?int
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
        $this->itemEntities[$id] = ['id' => $id, 'uuid' => random_bytes(16), 'x' => $x, 'y' => $y, 'z' => $z, 'yaw' => $yaw ?? self::randomUnit() * 360.0,
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
    private function playerDrop(Client $c, array $stack, bool $randomly, int $age = 0, bool $thrown = false): ?int
    {
        // Entity.getEyeY: the eye height is a float.
        $eye = $c->y + Float32::round($c->sneaking && !$c->flying ? 1.27 : 1.62);
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
        $id = $this->dropStack($c->x, $eye - Float32::round(0.3), $c->z, $stack, $age, $velocity, 40);
        // createItemStackToDrop: a stack thrown from the hand remembers its thrower, and
        // ServerPlayer.drop counts it as dropped.
        if ($thrown && $id !== null) {
            $this->itemEntities[$id]['thrower'] = $c->uuid;
            $this->awardStat($c, 'dropped', $this->version->catalog->itemName($stack['item']), $stack['count']);
            $this->awardStat($c, 'custom', 'drop');
        }
        return $id;
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

    /** ItemEntity.addAdditionalSaveData: what survives a restart, with its UUID but not its network ID. */
    private function savedItem(array $item): array
    {
        return ['uuid' => bin2hex($item['uuid']), 'target' => $item['target'] === null ? null : bin2hex($item['target']), 'thrower' => isset($item['thrower']) ? bin2hex($item['thrower']) : null]
            + array_intersect_key($item, array_flip(['x', 'y', 'z', 'yaw', 'velocity', 'stack', 'age', 'pickupDelay', 'health']));
    }

    /** Items saved with the world come back with new network IDs. */
    private function restoreItems(): void
    {
        foreach ($this->world->setting('items') ?? [] as $saved) {
            if (!isset($saved['stack']['item'], $saved['stack']['count'], $saved['uuid']) || $saved['stack']['count'] <= 0) {
                continue;
            }
            $id = $this->nextEntity++;
            $this->itemEntities[$id] = ['id' => $id, 'uuid' => hex2bin($saved['uuid']), 'x' => (float) $saved['x'], 'y' => (float) $saved['y'], 'z' => (float) $saved['z'],
                'yaw' => (float) ($saved['yaw'] ?? 0.0), 'velocity' => array_map('floatval', $saved['velocity'] ?? [0.0, 0.0, 0.0]), 'stack' => $saved['stack'],
                'age' => (int) ($saved['age'] ?? 0), 'pickupDelay' => (int) ($saved['pickupDelay'] ?? 0), 'target' => isset($saved['target']) ? hex2bin($saved['target']) : null,
                'health' => (int) ($saved['health'] ?? 5), 'ticks' => 0, 'ground' => false, 'created' => $this->ticks, 'dirty' => true]
                + (isset($saved['thrower']) ? ['thrower' => hex2bin($saved['thrower'])] : []);
        }
    }

    /**
     * An item's entity data: the shared flags (on fire) and ticks frozen when not their defaults,
     * then its stack; or, for SynchedEntityData.packDirty, only the entries in $dirty.
     */
    private function itemMetadata(array $entity, ?array $dirty = null): string
    {
        $catalog = $this->version->catalog;
        $entries = [
            // Entity.DATA_SHARED_FLAGS_ID (a byte) and DATA_TICKS_FROZEN (an int).
            0 => [$entity['onFire'] ?? false, "\x00\x00" . chr(($entity['onFire'] ?? false) ? 1 : 0)],
            7 => [($entity['frozen'] ?? 0) > 0, "\x07\x01" . Binary::writeVarInt($entity['frozen'] ?? 0)],
            $catalog->itemMetadataIndex => [true, chr($catalog->itemMetadataIndex) . Binary::writeVarInt($catalog->itemMetadataSerializer) . Packets::stack($entity['stack'], $this->version->blocks['component_damage'], $catalog->itemStacks)],
        ];
        $payload = Binary::writeVarInt($entity['id']);
        foreach ($entries as $index => [$changed, $entry]) {
            if ($dirty === null ? $changed : isset($dirty[$index])) {
                $payload .= $entry;
            }
        }
        return $payload . "\xff";
    }

    private function itemReference(): array
    {
        static $reference = null;
        return $reference ??= json_decode((string) file_get_contents($this->version->directory . '/item-entities.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function itemState(array $entity): array
    {
        return ['position' => [$entity['x'], $entity['y'], $entity['z']], 'velocity' => $entity['velocity'], 'yaw' => $entity['yaw'], 'pitch' => 0.0, 'head' => 0.0,
            'ground' => $entity['ground'], 'needs_sync' => $entity['needsSync'] ?? false, 'sync_velocity' => $entity['syncVelocity'] ?? false, 'sync_position' => $entity['syncPosition'] ?? false,
            'vertical_collision' => $entity['verticalCollision'] ?? false, 'horizontal_collision' => $entity['horizontalCollision'] ?? false];
    }

    private function itemTracker(array $entity): EntityMovementTracker
    {
        $profile = $this->itemReference()['item'];
        return $this->itemTrackers[$entity['id']] ??= new EntityMovementTracker($entity['id'], $profile['update_interval'], $profile['track_deltas'], $this->itemState($entity), false);
    }

    /** ServerEntity.sendPairingData for an item: the spawn with its movement, then its stack. */
    private function showItem(Client $c, array $entity): void
    {
        $id = $entity['id'];
        if (isset($c->entities[$id]) || !$this->tracksPosition($c, $entity['x'], $entity['z'], 'item')) {
            return;
        }
        // ServerEntity.sendPairingData: the spawn and the stack, bundled.
        $c->send($this->version, 'bundle_delimiter', '');
        $c->send($this->version, 'add_entity', $this->itemTracker($entity)->spawnPacket($entity['uuid'], $this->version->blocks['entity_types']['item'], 0));
        $c->send($this->version, 'set_entity_data', $this->itemMetadata($entity));
        $c->send($this->version, 'bundle_delimiter', '');
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
        // Entity.markHurt: its motion is sent with the next changes.
        $this->itemEntities[$id]['syncVelocity'] = true;
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

    /**
     * ChunkMap.tick sends what changed before the entities tick; then ItemEntity.tick for every
     * item, and players touching items pick them up.
     */
    private function tickItems(): void
    {
        foreach (array_keys($this->itemEntities) as $id) {
            $this->synchronizeItem($id);
        }
        $collision = new Collision($this->version->catalog);
        foreach (array_keys($this->itemEntities) as $id) {
            if (!isset($this->itemEntities[$id])) {
                continue;
            }
            // ItemEntity.tick: an empty item is discarded.
            if ($this->itemEntities[$id]['discard'] ?? false) {
                $this->removeItem($id);
                continue;
            }
            $item = &$this->itemEntities[$id];
            ++$item['ticks'];
            if ($item['pickupDelay'] > 0 && $item['pickupDelay'] !== 32767) {
                --$item['pickupDelay'];
            }
            [$oldX, $oldY, $oldZ] = [$item['x'], $item['y'], $item['z']];
            $oldVelocity = $item['velocity'];
            // Entity.baseTick: the fluids it is in, and their current.
            $fluid = $this->entityFluidCurrent([$item['x'] - 0.125, $item['y'], $item['z'] - 0.125, $item['x'] + 0.125, $item['y'] + 0.25, $item['z'] + 0.125], $item['velocity']);
            // Entity.isInLava is false on an entity's first tick.
            $inLava = $item['ticks'] > 1 && $fluid['lava'] > 0.0;
            // Entity.baseTick: a burning item takes fire damage each second out of lava, unless immune.
            if (($item['fire'] ?? 0) > 0) {
                if ($this->itemFireImmune($item)) {
                    $item['fire'] = 0;
                } else {
                    if ($item['fire'] % 20 === 0 && !$inLava && $this->hurtItem($id, 1.0, 'minecraft:on_fire') && !isset($this->itemEntities[$id])) {
                        unset($item);
                        continue;
                    }
                    --$item['fire'];
                }
            }
            // Entity.baseTick: the shared flag shows whether it is still burning.
            if ((($item['fire'] ?? 0) > 0) !== ($item['onFire'] ?? false)) {
                $item['onFire'] = ($item['fire'] ?? 0) > 0;
                $item['dataDirty'][0] = true;
            }
            if ($fluid['water'] > 0.10000000149011612) {
                // setUnderwaterMovement: setFluidMovement(0.99F).
                $item['velocity'] = [$item['velocity'][0] * 0.9900000095367432, $item['velocity'][1] + ($item['velocity'][1] < 0.05999999865889549 ? 5.000000237487257E-4 : 0.0), $item['velocity'][2] * 0.9900000095367432];
            } elseif ($inLava && $fluid['lava'] > 0.10000000149011612) {
                // setUnderLavaMovement: setFluidMovement(0.95F).
                $item['velocity'] = [$item['velocity'][0] * 0.949999988079071, $item['velocity'][1] + ($item['velocity'][1] < 0.05999999865889549 ? 5.000000237487257E-4 : 0.0), $item['velocity'][2] * 0.949999988079071];
            } else {
                $item['velocity'][1] -= 0.04;
            }
            $horizontal = $item['velocity'][0] ** 2 + $item['velocity'][2] ** 2;
            // Resting items move only every fourth tick, staggered by their ID.
            if (!($item['ground'] && $horizontal <= 9.999999747378752E-6 && ($item['ticks'] + $id) % 4 !== 0)) {
                $box = [$item['x'] - 0.125, $item['y'], $item['z'] - 0.125, $item['x'] + 0.125, $item['y'] + 0.25, $item['z'] + 0.125];
                $delta = $item['velocity'];
                // Entity.move: a cobweb or powder snow slows this move and stops the item.
                $stuck = $item['stuck'] ?? [0.0, 0.0, 0.0];
                if ($stuck[0] ** 2 + $stuck[1] ** 2 + $stuck[2] ** 2 > 1.0E-7) {
                    $delta = [$delta[0] * $stuck[0], $delta[1] * $stuck[1], $delta[2] * $stuck[2]];
                    $item['stuck'] = [0.0, 0.0, 0.0];
                    $item['velocity'] = [0.0, 0.0, 0.0];
                }
                $fall = $delta[1];
                $moved = $collision->move($this->world, $box, $delta);
                $below = $moved[1] !== $delta[1] && $delta[1] < 0.0;
                $item['horizontalCollision'] = abs($moved[0] - $delta[0]) >= 1.0E-5 || abs($moved[2] - $delta[2]) >= 1.0E-5;
                if ($delta[1] !== 0.0) {
                    $item['verticalCollision'] = $moved[1] !== $delta[1];
                }
                [$item['x'], $item['y'], $item['z']] = [$item['x'] + $moved[0], $item['y'] + $moved[1], $item['z'] + $moved[2]];
                foreach ([0, 1, 2] as $axis) {
                    if ($moved[$axis] !== $delta[$axis]) {
                        $item['velocity'][$axis] = 0.0;
                    }
                }
                $item['ground'] = $below;
                $bounced = $below ? $this->itemBounce($item, $fall, $moved[1]) : null;
                if ($bounced !== null) {
                    $item['velocity'][1] = $bounced;
                    $item['syncPosition'] = true;
                }
                $speed = $this->blockSpeedFactor($item['x'], $item['y'], $item['z']);
                $item['velocity'] = [$item['velocity'][0] * $speed, $item['velocity'][1], $item['velocity'][2] * $speed];
                if (!$this->itemInsideBlocks($id, $item, $box)) {
                    unset($item);
                    continue;
                }
                // getAirDrag and getFriction are floats, multiplied as floats.
                $drag = 0.9800000190734863;
                $ground = $drag;
                if ($item['ground']) {
                    $ground = Float32::round($drag * Float32::round($this->blockFriction($this->world->get((int) floor($item['x']), (int) floor($item['y'] - 0.999999), (int) floor($item['z'])))));
                }
                $item['velocity'] = [$item['velocity'][0] * $ground, $item['velocity'][1] * $drag, $item['velocity'][2] * $ground];
                if ($item['ground'] && $item['velocity'][1] < 0.0) {
                    $item['velocity'][1] *= -0.5;
                }
            } elseif (!$this->itemInsideBlocks($id, $item, [$item['x'] - 0.125, $item['y'], $item['z'] - 0.125, $item['x'] + 0.125, $item['y'] + 0.25, $item['z'] + 0.125])) {
                // applyEffectsFromBlocksForLastMovements: a resting item still meets the blocks it lies in.
                unset($item);
                continue;
            }
            unset($item);
            // ItemEntity.tick's applyEffectsFromBlocks: a hopper the item reaches takes it in.
            $this->itemInsideHoppers($id);
            if (!isset($this->itemEntities[$id])) {
                continue;
            }
            $item = &$this->itemEntities[$id];
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
            // ItemEntity.tick updates its fluids again after moving: their current applies twice,
            // and an item in any fluid is synced.
            $after = $this->entityFluidCurrent([$item['x'] - 0.125, $item['y'], $item['z'] - 0.125, $item['x'] + 0.125, $item['y'] + 0.25, $item['z'] + 0.125], $item['velocity']);
            $change = ($item['velocity'][0] - $oldVelocity[0]) ** 2 + ($item['velocity'][1] - $oldVelocity[1]) ** 2 + ($item['velocity'][2] - $oldVelocity[2]) ** 2;
            $item['needsSync'] = ($item['needsSync'] ?? false) || $after['any'] || $change > 0.01;
            if ($item['age'] >= 6000 || $item['y'] < $this->world->minY - 64) {
                unset($item);
                $this->removeItem($id);
                continue;
            }
            unset($item);
            $this->itemTouches($id);
        }
    }

    /**
     * Entity.restituteMovementAfterCollisions for an item landing on slime, a bed or a shelf
     * mushroom faster than gravity: the bounce, or null when it does not bounce.
     */
    private function itemBounce(array $item, float $fall, float $movedY): ?float
    {
        if (-$fall <= 0.04) {
            return null;
        }
        $name = $this->version->catalog->blockName($this->world->get((int) floor($item['x']), (int) floor($item['y'] - 0.2), (int) floor($item['z'])));
        $restitution = match ($name) {
            'slime_block' => 0.800000011920929,
            'shelf_mushroom' => 0.6000000238418579,
            default => str_ends_with($name, '_bed') ? 0.6000000238418579 : 0.0,
        };
        if ($restitution === 0.0) {
            return null;
        }
        $portion = $movedY / $fall;
        return ($portion * 0.04 - $fall) * (1.0 + $portion * (0.9800000190734863 - 1.0)) * $restitution;
    }

    /** Entity.isInWater/isInLava with a fluid height above 0.1 in the item's block. */
    /**
     * EntityFluidInteraction.update and applyCurrentTo for an entity pushed by fluids: the height
     * of each fluid it is in, and the current of the water and lava it meets added to its
     * velocity, normalized for anything but a player, at 0.014 for water and 0.0023333 for lava
     * (0.007 where lava is fast).
     *
     * @return array{water: float, lava: float, any: bool} any is true in any fluid at all
     */
    private function entityFluidCurrent(array $box, array &$velocity, bool $player = false): array
    {
        $fluids = $this->fluids();
        $block = $this->world->get(...);
        $inner = [$box[0] + 0.001, $box[1] + 0.001, $box[2] + 0.001, $box[3] - 0.001, $box[4] - 0.001, $box[5] - 0.001];
        $heights = [];
        $currents = [];
        for ($x = (int) floor($inner[0]); $x <= (int) ceil($inner[3]) - 1; ++$x) {
            for ($y = (int) floor($inner[1]); $y <= (int) ceil($inner[4]) - 1; ++$y) {
                for ($z = (int) floor($inner[2]); $z <= (int) ceil($inner[5]) - 1; ++$z) {
                    $fluid = $fluids->fluid($block($x, $y, $z));
                    if ($fluid === null) {
                        continue;
                    }
                    $top = $y + $fluids->entityHeight($block, $fluid, $x, $y, $z);
                    if ($top < $inner[1]) {
                        continue;
                    }
                    // Each fluid type (source or flowing) keeps its height; each fluid tag its current.
                    $type = Fluids::tickType($fluid);
                    $heights[$type] = max($top - $box[1], $heights[$type] ?? 0.0);
                    $current = &$currents[$fluid[0]];
                    $current ??= [0.0, [0.0, 0.0, 0.0], 0];
                    $current[0] = max($heights[$type], $current[0]);
                    $flow = $fluids->flow($block, $fluid, $x, $y, $z);
                    if ($current[0] < 0.4) {
                        $flow = [$flow[0] * $current[0], $flow[1] * $current[0], $flow[2] * $current[0]];
                    }
                    $current[1] = [$current[1][0] + $flow[0], $current[1][1] + $flow[1], $current[1][2] + $flow[2]];
                    ++$current[2];
                    unset($current);
                }
            }
        }
        $height = ['water' => max($heights['water'] ?? 0.0, $heights['flowing_water'] ?? 0.0), 'lava' => max($heights['lava'] ?? 0.0, $heights['flowing_lava'] ?? 0.0), 'any' => $heights !== []];
        foreach (['water' => 0.014, 'lava' => $this->fastLava() ? 0.007 : 0.0023333333333333335] as $family => $scale) {
            [, $sum, $count] = $currents[$family] ?? [0.0, [0.0, 0.0, 0.0], 0];
            if ($height[$family] <= 0.0 || $count === 0 || $sum[0] ** 2 + $sum[1] ** 2 + $sum[2] ** 2 < 9.999999747378752E-6) {
                continue;
            }
            $impulse = $player ? [$sum[0] / $count, $sum[1] / $count, $sum[2] / $count] : Fluids::normalize($sum);
            $impulse = [$impulse[0] * $scale, $impulse[1] * $scale, $impulse[2] * $scale];
            if (abs($velocity[0]) < 0.003 && abs($velocity[2]) < 0.003 && sqrt($impulse[0] ** 2 + $impulse[1] ** 2 + $impulse[2] ** 2) < 0.0045000000000000005) {
                $impulse = Fluids::normalize($impulse);
                $impulse = [$impulse[0] * 0.0045000000000000005, $impulse[1] * 0.0045000000000000005, $impulse[2] * 0.0045000000000000005];
            }
            $velocity = [$velocity[0] + $impulse[0], $velocity[1] + $impulse[1], $velocity[2] + $impulse[2]];
        }
        return $height;
    }

    /** ItemEntity.fireImmune: an item resistant to fire damage. */
    private function itemFireImmune(array $item): bool
    {
        return in_array('minecraft:in_fire', $this->itemReference()['damage_resistant'][$this->version->catalog->itemName($item['stack']['item'])] ?? [], true);
    }

    /**
     * Entity.applyEffectsFromBlocks for an item moved from the box $from: bubble columns, cobwebs,
     * powder snow and honey act at once; fire, lava and water are collected and applied in
     * InsideBlockEffectType order, each block's damage after its ignition. False once it is destroyed.
     */
    private function itemInsideBlocks(int $id, array &$item, array $from): bool
    {
        $fluids = $this->fluids();
        $block = $this->world->get(...);
        $to = [$item['x'] - 0.125, $item['y'], $item['z'] - 0.125, $item['x'] + 0.125, $item['y'] + 0.25, $item['z'] + 0.125];
        $swept = [min($from[0], $to[0]) + 1.0E-5, min($from[1], $to[1]) + 1.0E-5, min($from[2], $to[2]) + 1.0E-5, max($from[3], $to[3]) - 1.0E-5, max($from[4], $to[4]) - 1.0E-5, max($from[5], $to[5]) - 1.0E-5];
        $catalog = $this->version->catalog;
        $target = [$to[0] + 1.0E-5, $to[1] + 1.0E-5, $to[2] + 1.0E-5, $to[3] - 1.0E-5, $to[4] - 1.0E-5, $to[5] - 1.0E-5];
        // InsideBlockEffectApplier.StepBasedCollector: each effect once, with the damage each block adds after it.
        $effects = [];
        $fireDamage = [];
        $lava = 0;
        $powderSnow = [];
        for ($x = (int) floor($swept[0]); $x <= (int) floor($swept[3]); ++$x) {
            for ($y = (int) floor($swept[1]); $y <= (int) floor($swept[4]); ++$y) {
                for ($z = (int) floor($swept[2]); $z <= (int) floor($swept[5]); ++$z) {
                    $state = $block($x, $y, $z);
                    $name = $catalog->blockName($state);
                    if ($name === 'bubble_column' && $target[0] < $x + 1 && $target[3] > $x && $target[1] < $y + 1 && $target[4] > $y && $target[2] < $z + 1 && $target[5] > $z) {
                        // BubbleColumnBlock.entityInside: pushed up, or dragged down, harder at the top.
                        $above = $block($x, $y + 1, $z);
                        $down = $catalog->properties($state)['drag'] === 'true';
                        $vy = $item['velocity'][1];
                        $item['velocity'][1] = (new Collision($catalog))->shape($above) === [] && $fluids->fluid($above) === null
                            ? ($down ? max(-0.9, $vy - 0.03) : min(1.8, $vy + 0.1))
                            : ($down ? max(-0.3, $vy - 0.03) : min(0.7, $vy + 0.06));
                    } elseif ($name === 'cobweb') {
                        // WebBlock.entityInside: makeStuckInBlock.
                        $item['stuck'] = [0.25, 0.05000000074505806, 0.25];
                    } elseif ($name === 'powder_snow') {
                        // PowderSnowBlock.entityInside: stuck, frozen (which an item ignores) and put out,
                        // melting the snow first if it was burning and mobs may grief.
                        $item['stuck'] = [0.8999999761581421, 1.5, 0.8999999761581421];
                        $powderSnow[] = [$x, $y, $z];
                        $effects['freeze'] = true;
                        $effects['extinguish'] = true;
                    } elseif ($name === 'honey_block') {
                        $this->itemHoneySlide($item, $x, $y, $z);
                    } elseif ($name === 'fire' || $name === 'soul_fire') {
                        // BaseFireBlock.entityInside: ignited, then hurt by the fire.
                        $effects['fire_ignite'] = true;
                        $fireDamage[] = $name === 'fire' ? 1.0 : 2.0;
                    }
                    $fluid = $fluids->fluid($state);
                    // FluidState.getAABB: the fluid's height in its block.
                    if ($fluid !== null && $swept[1] < $y + $fluids->entityHeight($block, $fluid, $x, $y, $z)) {
                        if ($fluid[0] === 'water') {
                            $effects['extinguish'] = true;
                        } else {
                            $effects['lava_ignite'] = true;
                            ++$lava;
                        }
                    }
                }
            }
        }
        if (isset($effects['freeze']) && !in_array('item', $this->version->registryTags('minecraft:entity_type')['minecraft:freeze_immune_entity_types'] ?? [], true)) {
            // InsideBlockEffectType.FREEZE: frozen a tick longer, up to getTicksRequiredToFreeze.
            $frozen = min(140, ($item['frozen'] ?? 0) + 1);
            if ($frozen !== ($item['frozen'] ?? 0)) {
                $item['frozen'] = $frozen;
                $item['dataDirty'][7] = true;
            }
        }
        $immune = $this->itemFireImmune($item);
        if (isset($effects['fire_ignite']) && !$immune) {
            // BaseFireBlock.fireIgnite: igniteForSeconds(8).
            $item['fire'] = max($item['fire'] ?? 0, 160);
        }
        foreach ($fireDamage as $damage) {
            if ($this->hurtItem($id, $damage, 'minecraft:in_fire') && !isset($this->itemEntities[$id])) {
                return false;
            }
        }
        if (isset($effects['lava_ignite']) && !$immune) {
            // Entity.lavaIgnite, then each lava block's lavaHurt.
            $item['fire'] = max($item['fire'] ?? 0, 300);
            for ($i = 0; $i < $lava; ++$i) {
                if (!$this->itemLavaHurt($id, $item)) {
                    return false;
                }
            }
        }
        if (($item['fire'] ?? 0) > 0 && $this->gameRule('mob_griefing') === 'true') {
            foreach ($powderSnow as [$x, $y, $z]) {
                // Level.destroyBlock without drops.
                $this->levelEvent(2001, [$x, $y, $z], $block($x, $y, $z));
                $this->setBlock($x, $y, $z, $catalog->state('air'));
            }
        }
        if (isset($effects['extinguish'])) {
            $item['fire'] = 0;
        }
        return true;
    }

    /** LavaFluid's lavaHurt for an item: 4 damage, with the burn sound when it dies or every half second. */
    private function itemLavaHurt(int $id, array $item): bool
    {
        if ($this->hurtItem($id, 4.0, 'minecraft:lava')) {
            $health = $this->itemEntities[$id]['health'] ?? 0;
            if ($health <= 0 || $item['ticks'] % 10 === 0) {
                $payload = Packets::sound($this->version->blocks['sounds']['entity.generic.burn'], 8, $item['x'], $item['y'], $item['z'], 0.4, Float32::round(2.0 + Float32::round(self::randomUnit() * 0.4)), unpack('J', random_bytes(8))[1]);
                foreach ($this->localPlayers() as $player) {
                    if (($player->x - $item['x']) ** 2 + ($player->y - $item['y']) ** 2 + ($player->z - $item['z']) ** 2 < 256.0) {
                        $player->send($this->version, 'sound', $payload);
                    }
                }
            }
        }
        return isset($this->itemEntities[$id]);
    }

    /** HoneyBlock.entityInside: an item falling down the side of honey slides slowly. */
    private function itemHoneySlide(array &$item, int $x, int $y, int $z): void
    {
        // getOldDeltaY undoes this tick's drag and gravity.
        $old = $item['velocity'][1] / 0.9800000190734863 + 0.08;
        if ($item['ground'] || $item['y'] > $y + 0.9375 - 1.0E-7 || $old >= -0.08
            || (abs($x + 0.5 - $item['x']) + 1.0E-7 <= 0.5625 && abs($z + 0.5 - $item['z']) + 1.0E-7 <= 0.5625)) {
            return;
        }
        // doSlideMovement: falling at getNewDeltaY(-0.05), slowed sideways when it was falling fast.
        $scale = $old < -0.13 ? -0.05 / $old : 1.0;
        $item['velocity'] = [$item['velocity'][0] * $scale, (-0.05 - 0.08) * 0.9800000190734863, $item['velocity'][2] * $scale];
    }

    /** Entity.getBlockSpeedFactor: soul sand and honey slow what moves over them. */
    private function blockSpeedFactor(float $x, float $y, float $z): float
    {
        $catalog = $this->version->catalog;
        $factor = static fn (string $name): float => $name === 'soul_sand' || $name === 'honey_block' ? 0.4000000059604645 : 1.0;
        $here = $catalog->blockName($this->world->get((int) floor($x), (int) floor($y), (int) floor($z)));
        if ($here === 'water' || $here === 'bubble_column' || $factor($here) !== 1.0) {
            return $factor($here);
        }
        // getBlockPosBelowThatAffectsMyMovement.
        return $factor($catalog->blockName($this->world->get((int) floor($x), (int) floor($y - 0.5000001), (int) floor($z))));
    }

    private function blockFriction(int $state): float
    {
        $movement = $this->version->catalog->movement;
        return (float) ($movement['block_friction'][$this->version->catalog->blockName($state)] ?? $movement['default_block_friction']);
    }

    /** ItemEntity.isMergable. */
    private function itemMergable(array $item): bool
    {
        return !($item['discard'] ?? false) && $item['pickupDelay'] !== 32767 && $item['age'] < 6000 && $item['stack']['count'] < ($this->version->catalog->item($item['stack']['item'])['max_stack'] ?? 64);
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
            if ($item === null || ($item['discard'] ?? false)) {
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
            // ItemEntity.playerTouch: the whole stack counts as picked up, however much fitted.
            $this->awardStat($player, 'picked_up', $this->version->catalog->itemName($item['stack']['item']), $count);
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
        // ServerEntity.sendChanges: changed entity data (a new stack, burning, freezing) forces the
        // update, and is sent after the movement but before the motion of a hurt item.
        $dirty = $item['dataDirty'] ?? [];
        if ($item['dirty']) {
            $dirty[$this->version->catalog->itemMetadataIndex] = true;
        }
        $state = $this->itemState($item) + ['force_update' => $dirty !== []];
        $hurt = $state['sync_velocity'];
        $packets = $this->itemTracker($item)->update($state);
        if ($dirty !== []) {
            $this->itemEntities[$id]['dirty'] = false;
            $this->itemEntities[$id]['dataDirty'] = [];
            $motion = $hurt ? array_pop($packets) : null;
            $packets[] = ['name' => 'set_entity_data', 'payload' => $this->itemMetadata($item, $dirty)];
            if ($motion !== null) {
                $packets[] = $motion;
            }
        }
        // ServerEntity.sendChanges clears needsSync once it has acted on it, whatever it sent.
        $this->itemEntities[$id]['needsSync'] = $state['needs_sync'];
        $this->itemEntities[$id]['syncVelocity'] = $state['sync_velocity'];
        $this->itemEntities[$id]['syncPosition'] = false;
        foreach ($packets as $packet) {
            foreach ($this->localPlayers() as $player) {
                if (isset($player->entities[$id])) {
                    $player->send($this->version, $packet['name'], $packet['payload']);
                }
            }
        }
    }
}
