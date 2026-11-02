<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Tracking\EntityMovementTracker;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * Thrown snowballs, eggs and ender pearls (ThrowableItemProjectile): thrown from the eyes along the
 * look with a little spread, falling and slowing each tick, and hitting the first block or entity
 * on their way. Snowballs and eggs hurt for nothing (a blaze takes 3 from a snowball) but knock
 * back, eggs sometimes hatch chicks, and an ender pearl takes its thrower where it lands.
 */
trait Projectiles
{
    /** The items thrown, by the entity type each throws. */
    private const THROWN = ['snowball' => 'snowball', 'egg' => 'egg', 'blue_egg' => 'egg', 'brown_egg' => 'egg', 'ender_pearl' => 'ender_pearl'];

    /** @var array<int, array> */
    private array $projectiles = [];

    /** @var array<int, EntityMovementTracker> */
    private array $projectileTrackers = [];

    /** SnowballItem, EggItem and EnderpearlItem.use: the sound, the throw, then one fewer in the hand. */
    private function throwItem(Client $c, int $slot, string $item): bool
    {
        $type = self::THROWN[$item] ?? null;
        if ($type === null) {
            return false;
        }
        // ServerPlayerGameMode.useItem: nothing happens while the item cools down.
        if (($c->cooldowns['minecraft:' . $item] ?? 0) > $this->ticks) {
            $this->syncInventory($c);
            return true;
        }
        $sound = ['snowball' => 'entity.snowball.throw', 'egg' => 'entity.egg.throw', 'ender_pearl' => 'entity.ender_pearl.throw'][$type];
        $pitch = Float32::round(0.4 / Float32::round(Float32::round(self::randomUnit() * 0.4) + 0.8));
        $payload = Packets::sound($this->version->blocks['sounds'][$sound], 6, $c->x, $c->y, $c->z, 0.5, $pitch, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $c->x) ** 2 + ($player->y - $c->y) ** 2 + ($player->z - $c->z) ** 2 < 16 ** 2) {
                $player->send($this->version, 'sound', $payload);
            }
        }
        $stack = $c->inventory[$slot];
        $this->spawnProjectile($c, $type, ['count' => 1] + $stack);
        if ($c->gameMode !== 1) {
            if (--$c->inventory[$slot]['count'] <= 0) {
                unset($c->inventory[$slot]);
            }
            $this->syncInventory($c);
        }
        // UseCooldown: an ender pearl's second.
        if ($type === 'ender_pearl') {
            $c->cooldowns['minecraft:ender_pearl'] = $this->ticks + 20;
            $c->send($this->version, 'cooldown', Binary::writeString('minecraft:ender_pearl') . Binary::writeVarInt(20));
        }
        return true;
    }

    /**
     * Projectile.spawnProjectileFromRotation: the projectile at the thrower's eyes less 0.1, shot
     * along its look at 1.5 with a spread of 1, plus the thrower's own movement.
     */
    private function spawnProjectile(Client $owner, string $type, array $stack): int
    {
        $id = $this->nextEntity++;
        $eye = $owner->sneaking && !$owner->flying ? 1.27 : 1.62;
        $projectile = ['id' => $id, 'uuid' => random_bytes(16), 'type' => $type, 'stack' => $stack, 'owner' => $owner->uuid,
            'x' => $owner->x, 'y' => $owner->y + Float32::round($eye) - Float32::round(0.1), 'z' => $owner->z, 'velocity' => [0.0, 0.0, 0.0],
            'yaw' => 0.0, 'pitch' => 0.0, 'ticks' => 0, 'leftOwner' => false, 'water' => false, 'needsSync' => false,
            // ThrowableItemProjectile.setItem leaves the stack dirty for the first ServerEntity.sendChanges.
            'dirty' => true];
        // Projectile.shootFromRotation and shoot.
        $radians = Float32::round(M_PI / 180.0);
        $yaw = Float32::round(Float32::round($owner->yaw) * $radians);
        $pitch = Float32::round(Float32::round($owner->pitch) * $radians);
        $direction = [
            Float32::round(-$this->mthSin($yaw) * $this->mthCos($pitch)),
            Float32::round(-$this->mthSin($pitch)),
            Float32::round($this->mthCos($yaw) * $this->mthCos($pitch)),
        ];
        $length = sqrt($direction[0] ** 2 + $direction[1] ** 2 + $direction[2] ** 2);
        $spread = 0.0172275 * 1.0;
        $velocity = [];
        foreach ($direction as $axis => $value) {
            $velocity[$axis] = (($length < 1.0E-5 ? 0.0 : $value / $length) + $spread * (self::randomUnit() - self::randomUnit())) * 1.5;
        }
        $projectile['yaw'] = Float32::round(self::mthAtan2($velocity[0], $velocity[2]) * 180.0 / Float32::round(M_PI));
        $projectile['pitch'] = Float32::round(self::mthAtan2($velocity[1], sqrt($velocity[0] ** 2 + $velocity[2] ** 2)) * 180.0 / Float32::round(M_PI));
        $projectile['needsSync'] = true;
        $known = $owner->knownMovement;
        $projectile['velocity'] = [$velocity[0] + $known[0], $velocity[1] + ($owner->ground ? 0.0 : $known[1]), $velocity[2] + $known[2]];
        $this->projectiles[$id] = $projectile;
        foreach ($this->localPlayers() as $player) {
            $this->showProjectile($player, $id);
        }
        return $id;
    }

    /**
     * ThrowableProjectile.tick: gravity, then drag, then the first block or entity along the move,
     * which stops it there; it turns toward its flight and is hit after its other ticking.
     */
    private function tickProjectiles(): void
    {
        foreach (array_keys($this->projectiles) as $id) {
            $this->synchronizeProjectile($id);
        }
        $collision = new Collision($this->version->catalog);
        foreach (array_keys($this->projectiles) as $id) {
            if (!isset($this->projectiles[$id])) {
                continue;
            }
            $projectile = &$this->projectiles[$id];
            ++$projectile['ticks'];
            $owner = $this->projectileOwner($projectile);
            // ThrownEnderpearl.tick: a pearl whose thrower died vanishes, as the game rule has it.
            if ($projectile['type'] === 'ender_pearl' && $owner !== null && $owner->dead && $this->gameRule('ender_pearls_vanish_on_death') === 'true') {
                unset($projectile);
                $this->removeProjectile($id);
                continue;
            }
            $start = [$projectile['x'], $projectile['y'], $projectile['z']];
            $projectile['velocity'][1] -= 0.03;
            $drag = $projectile['water'] ? Float32::round(0.8) : Float32::round(0.99);
            $projectile['velocity'] = [$projectile['velocity'][0] * $drag, $projectile['velocity'][1] * $drag, $projectile['velocity'][2] * $drag];
            $hit = $this->projectileHit($collision, $projectile, $owner);
            $end = $hit['location'] ?? [$start[0] + $projectile['velocity'][0], $start[1] + $projectile['velocity'][1], $start[2] + $projectile['velocity'][2]];
            [$projectile['x'], $projectile['y'], $projectile['z']] = $end;
            // Projectile.updateRotation: a fifth of the way toward the direction of flight.
            $velocity = $projectile['velocity'];
            $projectile['pitch'] = self::lerpRotation($projectile['pitch'], Float32::round(self::mthAtan2($velocity[1], sqrt($velocity[0] ** 2 + $velocity[2] ** 2)) * 180.0 / Float32::round(M_PI)));
            $projectile['yaw'] = self::lerpRotation($projectile['yaw'], Float32::round(self::mthAtan2($velocity[0], $velocity[2]) * 180.0 / Float32::round(M_PI)));
            // Projectile.checkLeftOwner, once clear of the thrower's box.
            if (!$projectile['leftOwner']) {
                $box = $this->projectileBox($projectile);
                $search = self::inflate(self::expandTowards($box, $velocity), 1.0);
                $projectile['leftOwner'] = $owner === null || $owner->dead || $owner->dimension !== $this->world->dimension || !self::intersects($search, $this->playerBox($owner));
            }
            // Entity.baseTick: whether it is in water for the next tick's drag.
            $projectile['water'] = $this->version->catalog->blockName($this->world->get((int) floor($projectile['x']), (int) floor($projectile['y']), (int) floor($projectile['z']))) === 'water';
            if ($projectile['y'] < $this->world->minY - 64) {
                unset($projectile);
                $this->removeProjectile($id);
                continue;
            }
            unset($projectile);
            if ($hit !== null) {
                $this->projectileImpact($id, $hit, $start);
            }
        }
    }

    /**
     * ProjectileUtil.getHitResultOnMoveVector: the block the move reaches first, then the nearest
     * entity it passes through before that, their boxes grown by up to 0.3 as the projectile ages.
     */
    private function projectileHit(Collision $collision, array $projectile, ?Client $owner): ?array
    {
        $from = [$projectile['x'], $projectile['y'], $projectile['z']];
        $delta = $projectile['velocity'];
        $to = [$from[0] + $delta[0], $from[1] + $delta[1], $from[2] + $delta[2]];
        $hit = $collision->clipBlocks($this->world, $from, $to);
        if ($hit !== null) {
            $to = $hit['location'];
        }
        $margin = max(0.0, min(0.3, Float32::round(($projectile['ticks'] - 2) / 20.0)));
        $search = self::inflate(self::expandTowards($this->projectileBox($projectile), $delta), 1.0);
        $nearest = PHP_FLOAT_MAX;
        $entity = null;
        foreach ($this->projectileTargets($projectile, $owner) as $target) {
            if (!self::intersects($search, $target['box'])) {
                continue;
            }
            $location = Collision::clipBox(self::inflate($target['box'], $margin), $from, $to);
            if ($location === null) {
                continue;
            }
            $distance = ($location[0] - $from[0]) ** 2 + ($location[1] - $from[1]) ** 2 + ($location[2] - $from[2]) ** 2;
            if ($distance < $nearest) {
                $nearest = $distance;
                $entity = ['entity' => $target, 'location' => $location];
            }
        }
        return $entity ?? ($hit === null ? null : ['block' => $hit, 'location' => $hit['location']]);
    }

    /** Entity.canBeHitByProjectile: living players and mobs, primed TNT and falling blocks, but not the thrower before it is clear. */
    private function projectileTargets(array $projectile, ?Client $owner): array
    {
        $targets = [];
        foreach ($this->localPlayers() as $player) {
            if (!$player->dead && $player->gameMode !== 3 && ($player !== $owner || $projectile['leftOwner'])) {
                $targets[] = ['player' => $player, 'box' => $this->playerBox($player)];
            }
        }
        foreach ($this->animals as $id => $mob) {
            if (isset($mob['deadAt']) || $mob['health'] <= 0) {
                continue;
            }
            $size = $this->version->catalog->mobs['entities'][$mob['kind']];
            $scale = ($mob['baby'] ?? false) ? 0.5 : 1.0;
            $targets[] = ['mob' => $id, 'box' => Collision::bounds($mob['x'], $mob['y'], $mob['z'], $size['width'] * $scale, $size['height'] * $scale)];
        }
        foreach ($this->tntEntities as $tnt) {
            $targets[] = ['other' => true, 'box' => Collision::bounds($tnt['x'], $tnt['y'], $tnt['z'], 0.98, 0.98)];
        }
        foreach ($this->fallingBlocks ?? [] as $block) {
            $targets[] = ['other' => true, 'box' => Collision::bounds($block['x'], $block['y'], $block['z'], 0.98, 0.98)];
        }
        return $targets;
    }

    /**
     * Projectile.onHit for each kind: an entity is hurt for nothing by the thrower (a blaze takes
     * 3 from a snowball), a block reacts as BlockBehaviour.onProjectileHit does, then a snowball or
     * egg bursts (an egg sometimes hatching chicks) and a pearl brings its thrower.
     */
    private function projectileImpact(int $id, array $hit, array $start): void
    {
        $projectile = $this->projectiles[$id];
        $owner = $this->projectileOwner($projectile);
        if (isset($hit['entity'])) {
            $amount = $projectile['type'] === 'snowball' && ($hit['entity']['mob'] ?? null) !== null && $this->animals[$hit['entity']['mob']]['kind'] === 'blaze' ? 3.0 : 0.0;
            $source = ['id' => $id, 'velocity' => $projectile['velocity']];
            if (isset($hit['entity']['player'])) {
                $this->damage($hit['entity']['player'], $amount, 'minecraft:thrown', $owner, $source);
            } elseif (isset($hit['entity']['mob'])) {
                $this->hurtMob($hit['entity']['mob'], $amount, 'minecraft:thrown', $owner, $source);
            }
        } elseif ($this->version->catalog->blockName($this->world->get(...$hit['block']['position'])) === 'bell') {
            [$x, $y, $z] = $hit['block']['position'];
            $this->useBell($x, $y, $z, $hit['block']['face'], $hit['location'][1] - $y);
        }
        if ($projectile['type'] === 'egg' && random_int(0, 7) === 0) {
            $count = random_int(0, 31) === 0 ? 4 : 1;
            $variant = ['blue_egg' => 'cold', 'brown_egg' => 'warm'][$this->version->catalog->itemName($projectile['stack']['item'])] ?? null;
            for ($i = 0; $i < $count; ++$i) {
                $this->spawnAnimal('chicken', $projectile['x'], $projectile['y'], $projectile['z'], ['baby' => true, 'yaw' => $projectile['yaw']] + ($variant === null ? [] : ['variant' => $variant]));
            }
        }
        if ($projectile['type'] === 'ender_pearl') {
            $this->teleportPearlOwner($owner, $start);
            $this->removeProjectile($id);
            return;
        }
        // The burst of snowball or item particles, then the projectile is gone.
        foreach ($this->localPlayers() as $player) {
            if (isset($player->entities[$id])) {
                $player->send($this->version, 'entity_event', pack('N', $id) . "\3");
            }
        }
        $this->removeProjectile($id);
    }

    /**
     * ThrownEnderpearl.onHit: the thrower, still in this dimension and alive, moves to where the
     * pearl was at the start of its tick, keeping its look, stops falling and takes 5 damage.
     */
    private function teleportPearlOwner(?Client $owner, array $position): void
    {
        if ($owner === null || $owner->dead || $owner->dimension !== $this->world->dimension || $owner->sleepingPos !== null) {
            return;
        }
        $this->leaveVehicle($owner);
        // Relative.ROTATION: the look is kept, as zero changes of yaw and pitch.
        $this->teleport($owner, $position, (1 << 3) | (1 << 4));
        $this->damage($owner, 5.0, 'minecraft:ender_pearl');
        $payload = Packets::sound($this->version->blocks['sounds']['entity.player.teleport'], 7, $position[0], $position[1], $position[2], 1.0, 1.0, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $position[0]) ** 2 + ($player->y - $position[1]) ** 2 + ($player->z - $position[2]) ** 2 < 16 ** 2) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    private function projectileOwner(array $projectile): ?Client
    {
        foreach ($this->players() as $player) {
            if ($player->uuid === $projectile['owner']) {
                return $player;
            }
        }
        return null;
    }

    private function removeProjectile(int $id): void
    {
        unset($this->projectiles[$id], $this->projectileTrackers[$id]);
        foreach ($this->localPlayers() as $player) {
            $this->forgetEntity($player, $id);
        }
    }

    private function projectileState(array $projectile): array
    {
        return ['position' => [$projectile['x'], $projectile['y'], $projectile['z']], 'velocity' => $projectile['velocity'], 'yaw' => $projectile['yaw'], 'pitch' => $projectile['pitch'],
            'head' => 0.0, 'ground' => false, 'needs_sync' => $projectile['needsSync'], 'sync_velocity' => false, 'sync_position' => false];
    }

    /** ServerEntity.sendChanges for a projectile: its movement every ten ticks, its motion when it changes. */
    private function synchronizeProjectile(int $id): void
    {
        $projectile = $this->projectiles[$id];
        foreach ($this->localPlayers() as $player) {
            if (!isset($player->entities[$id])) {
                $this->showProjectile($player, $id);
            } elseif (!$this->tracksPosition($player, $projectile['x'], $projectile['z'], $projectile['type'])) {
                $this->forgetEntity($player, $id);
            }
        }
        $state = $this->projectileState($projectile);
        $tracker = $this->projectileTrackers[$id] ??= new EntityMovementTracker($id, 10, true, $state, false);
        $packets = $tracker->update($state);
        $this->projectiles[$id]['needsSync'] = $state['needs_sync'];
        if ($projectile['dirty']) {
            $this->projectiles[$id]['dirty'] = false;
            $packets[] = ['name' => 'set_entity_data', 'payload' => $this->itemMetadata($projectile)];
        }
        foreach ($packets as $packet) {
            foreach ($this->localPlayers() as $player) {
                if (isset($player->entities[$id])) {
                    $player->send($this->version, $packet['name'], $packet['payload']);
                }
            }
        }
    }

    /**
     * ServerEntity.sendPairingData: the spawn with the thrower's ID, then the stack, which never
     * counts as the default since setItem stores a copy.
     */
    private function showProjectile(Client $player, int $id): bool
    {
        $projectile = $this->projectiles[$id];
        if (isset($player->entities[$id]) || !$this->tracksPosition($player, $projectile['x'], $projectile['z'], $projectile['type'])) {
            return isset($player->entities[$id]);
        }
        $tracker = $this->projectileTrackers[$id] ??= new EntityMovementTracker($id, 10, true, $this->projectileState($projectile), false);
        $owner = $this->projectileOwner($projectile);
        $player->send($this->version, 'bundle_delimiter', '');
        $player->send($this->version, 'add_entity', $tracker->spawnPacket($projectile['uuid'], $this->version->blocks['entity_types'][$projectile['type']], $owner?->entityId ?? 0));
        $player->send($this->version, 'set_entity_data', $this->itemMetadata($projectile));
        $player->send($this->version, 'bundle_delimiter', '');
        $player->entities[$id] = true;
        return true;
    }

    private function projectileBox(array $projectile): array
    {
        return Collision::bounds($projectile['x'], $projectile['y'], $projectile['z'], 0.25, 0.25);
    }

    /** Player.getDefaultDimensions: 0.6 wide, 1.8 tall standing and 1.5 sneaking. */
    private function playerBox(Client $player): array
    {
        return Collision::bounds($player->x, $player->y, $player->z, 0.6, $player->sneaking && !$player->flying ? 1.5 : 1.8);
    }

    private static function expandTowards(array $box, array $delta): array
    {
        for ($axis = 0; $axis < 3; ++$axis) {
            if ($delta[$axis] < 0.0) {
                $box[$axis] += $delta[$axis];
            } else {
                $box[$axis + 3] += $delta[$axis];
            }
        }
        return $box;
    }

    private static function inflate(array $box, float $amount): array
    {
        return [$box[0] - $amount, $box[1] - $amount, $box[2] - $amount, $box[3] + $amount, $box[4] + $amount, $box[5] + $amount];
    }

    /** AABB.intersects: overlapping, not merely touching. */
    private static function intersects(array $a, array $b): bool
    {
        return $a[0] < $b[3] && $a[3] > $b[0] && $a[1] < $b[4] && $a[4] > $b[1] && $a[2] < $b[5] && $a[5] > $b[2];
    }

    /** Projectile.lerpRotation: the old angle unwound toward the new, then a fifth of the way. */
    private static function lerpRotation(float $old, float $new): float
    {
        while (Float32::round($new - $old) < -180.0) {
            $old = Float32::round($old - 360.0);
        }
        while (Float32::round($new - $old) >= 180.0) {
            $old = Float32::round($old + 360.0);
        }
        return Float32::round($old + Float32::round(Float32::round(0.2) * Float32::round($new - $old)));
    }

    /** Mth.sin from the 65536-entry sine table, beside DaylightDetectors' Mth.cos. */
    private function mthSin(float $value): float
    {
        $this->mthSine ??= array_values(unpack('G*', (string) file_get_contents($this->version->directory . '/entity-sine.bin')));
        return $this->mthSine[((int) ($value * 10430.378350470453)) & 65535];
    }
}
