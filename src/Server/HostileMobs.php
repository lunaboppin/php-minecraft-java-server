<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * Hostile mobs that hunt players: NearestAttackableTargetGoal picks the nearest player a mob can
 * see within its follow range, MeleeAttackGoal walks it there and hits within reach every 20
 * ticks (Mob.doHurtTarget with its attack damage), and a creeper's SwellGoal swells it within 3
 * blocks until it explodes. Paths are straight lines toward the target until navigation exists.
 */
trait HostileMobs
{
    /** Mobs that hunt players this way: melee attackers, and creepers, which only swell. */
    private const HUNTERS = ['zombie', 'husk', 'drowned', 'zombie_villager', 'spider', 'cave_spider', 'creeper'];

    /** Mob.DEFAULT_ATTACK_REACH: sqrt(2.04F) - 0.6F, added to the attacker's box sideways. */
    private const ATTACK_REACH = 0.8282856857085699;

    /**
     * Mob.serverAiStep's target and attack goals for a hunter with AI. Returns the player it is
     * heading for, or null to wander as before.
     */
    private function huntPlayer(array &$mob): ?Client
    {
        if (!in_array($mob['kind'], self::HUNTERS, true)) {
            return null;
        }
        $target = $this->huntTarget($mob);
        $goalTick = ($mob['tickCount'] + $mob['id']) % 2 === 0 || $mob['tickCount'] <= 1;
        if ($target !== null && $goalTick && !$this->keepsHunting($mob, $target)) {
            $target = null;
        }
        // NearestAttackableTargetGoal: one goal tick in five, the nearest player it can see.
        if ($target === null && $goalTick && $this->mobRandom($mob['id'])->nextInt(5) === 0 && $this->huntsInThisLight($mob)) {
            $target = $this->nearestPrey($mob);
        }
        $this->setHuntTarget($mob, $target);
        if ($target === null) {
            return null;
        }
        if ($mob['kind'] === 'creeper') {
            // SwellGoal holds a creeper still while it swells.
            return ($mob['swellDir'] ?? -1) > 0 || $this->mobDistanceSquared($mob, $target) < 9.0 ? null : $target;
        }
        // MeleeAttackGoal.checkAndPerformAttack: within reach, cooled down and in sight.
        $mob['attackCooldown'] = max(($mob['attackCooldown'] ?? 0) - 1, 0);
        if ($mob['attackCooldown'] <= 0 && $this->withinAttackReach($mob, $target) && $this->mobSees($mob, $target)) {
            $mob['attackCooldown'] = 20;
            $this->swing($mob['id'], 0, 'whack', 6);
            $this->mobHurtsPlayer($mob, $target);
        }
        return $target;
    }

    /** Mob.doHurtTarget: its attack damage, scaled for difficulty by the player's hurt. */
    private function mobHurtsPlayer(array $mob, Client $target): void
    {
        $damage = (float) ($this->mobAttributes($mob)['attack_damage'] ?? 0.0);
        $this->damage($target, Float32::round($damage), 'minecraft:mob_attack', null, null, $mob);
    }

    /**
     * Creeper.tick: the swell grows while swelling (with the primed hiss as it starts) and shrinks
     * otherwise; at 30 it explodes with power 3 and is gone. SwellGoal sets the direction for the
     * next tick: toward a target it sees within 7 blocks, away otherwise. Returns whether it exploded.
     */
    private function tickCreeper(array &$mob, bool $ai): bool
    {
        if ($mob['kind'] !== 'creeper') {
            return false;
        }
        $direction = $mob['swellDir'] ?? -1;
        if ($direction > 0 && ($mob['swell'] ?? 0) === 0) {
            $payload = Packets::sound($this->version->blocks['sounds']['entity.creeper.primed'], 5, $mob['x'], $mob['y'], $mob['z'], 1.0, 0.5, unpack('J', random_bytes(8))[1]);
            foreach ($this->localPlayers() as $player) {
                if (($player->x - $mob['x']) ** 2 + ($player->y - $mob['y']) ** 2 + ($player->z - $mob['z']) ** 2 < 16 ** 2) {
                    $player->send($this->version, 'sound', $payload);
                }
            }
        }
        $mob['swell'] = max(0, ($mob['swell'] ?? 0) + $direction);
        if ($mob['swell'] >= 30) {
            [$x, $y, $z, $id] = [$mob['x'], $mob['y'], $mob['z'], $mob['id']];
            // Creeper.explodeCreeper: the creeper goes, and its explosion does not hurt it.
            $this->removeAnimal($id);
            $this->explode($x, $y, $z, ($mob['powered'] ?? false) ? 6.0 : 3.0, null, null, false, null, true);
            return true;
        }
        if ($ai) {
            $target = $this->huntTarget($mob);
            $swelling = $direction > 0 || ($target !== null && $this->mobDistanceSquared($mob, $target) < 9.0);
            $next = !$swelling ? -1 : ($target === null || $this->mobDistanceSquared($mob, $target) > 49.0 || !$this->mobSees($mob, $target) ? -1 : 1);
            if ($next !== $direction) {
                $mob['swellDir'] = $next;
                $packet = Binary::writeVarInt($mob['id']) . "\x10" . Binary::writeVarInt(1) . Binary::writeVarInt($next) . "\xff";
                foreach ($this->localPlayers() as $player) {
                    if (isset($player->entities[$mob['id']])) {
                        $player->send($this->version, 'set_entity_data', $packet);
                    }
                }
            }
        }
        return false;
    }

    private function huntTarget(array $mob): ?Client
    {
        if (!isset($mob['huntTarget'])) {
            return null;
        }
        foreach ($this->localPlayers() as $player) {
            if ($player->uuid === hex2bin($mob['huntTarget'])) {
                return $player;
            }
        }
        return null;
    }

    /** Mob.setTarget, with MeleeAttackGoal's aggressive flag (DATA_MOB_FLAGS bit 4) for trackers. */
    private function setHuntTarget(array &$mob, ?Client $target): void
    {
        $was = isset($mob['huntTarget']);
        if ($target === null) {
            unset($mob['huntTarget'], $mob['unseenTicks']);
        } else {
            $mob['huntTarget'] = bin2hex($target->uuid);
        }
        if ($was === ($target !== null)) {
            return;
        }
        $flags = (($mob['noAi'] ?? false) ? 1 : 0) | ($target !== null ? 4 : 0);
        $packet = Binary::writeVarInt($mob['id']) . "\x0f" . Binary::writeVarInt(0) . chr($flags) . "\xff";
        foreach ($this->localPlayers() as $player) {
            if (isset($player->entities[$mob['id']])) {
                $player->send($this->version, 'set_entity_data', $packet);
            }
        }
    }

    /** TargetGoal.canContinueToUse: alive, attackable, within follow range, and not out of sight for long. */
    private function keepsHunting(array &$mob, Client $target): bool
    {
        if (!$this->attackable($target)) {
            return false;
        }
        $range = (float) ($this->mobAttributes($mob)['follow_range'] ?? 16.0);
        if ($this->mobDistanceSquared($mob, $target) > $range * $range) {
            return false;
        }
        if ($this->mobSees($mob, $target)) {
            $mob['unseenTicks'] = 0;
        } elseif (($mob['unseenTicks'] = ($mob['unseenTicks'] ?? 0) + 1) > 30) {
            return false;
        }
        // SpiderTargetGoal: in bright light a spider gives up, one time in a hundred.
        return !in_array($mob['kind'], ['spider', 'cave_spider'], true) || $this->mobBrightness($mob) < 0.5 || $this->mobRandom($mob['id'])->nextInt(100) !== 0;
    }

    /** SpiderTargetGoal.canUse: spiders only take a target in the dark. */
    private function huntsInThisLight(array $mob): bool
    {
        return !in_array($mob['kind'], ['spider', 'cave_spider'], true) || $this->mobBrightness($mob) < 0.5;
    }

    /** LevelReader.getLightLevelDependentMagicValue at the mob's feet, with the overworld's ambient light of 0. */
    private function mobBrightness(array $mob): float
    {
        $light = Float32::round($this->world->rawBrightness((int) floor($mob['x']), (int) floor($mob['y']), (int) floor($mob['z']), false, $this->skyDarken()) / 15.0);
        return Float32::round($light / Float32::round(4.0 - 3.0 * $light));
    }

    /**
     * TargetingConditions.forCombat with the follow range: the nearest attackable player within it,
     * scaled by how visible they are, whom the mob can see.
     */
    private function nearestPrey(array $mob): ?Client
    {
        $range = (float) ($this->mobAttributes($mob)['follow_range'] ?? 16.0);
        $best = null;
        $nearest = PHP_FLOAT_MAX;
        foreach ($this->localPlayers() as $player) {
            if (!$this->attackable($player)) {
                continue;
            }
            // LivingEntity.getVisibilityPercent: sneaking hides a fifth, invisibility all but 7%.
            $visibility = ($player->sneaking ? 0.8 : 1.0) * (isset($player->effects['minecraft:invisibility']) ? 0.07 : 1.0);
            $limit = max($range * $visibility, 2.0);
            $distance = $this->mobDistanceSquared($mob, $player);
            if ($distance > $limit * $limit || $distance >= $nearest || !$this->mobSees($mob, $player)) {
                continue;
            }
            [$best, $nearest] = [$player, $distance];
        }
        return $best;
    }

    /** Player.canBeSeenAsEnemy: alive, and neither creative nor spectating. */
    private function attackable(Client $player): bool
    {
        return !$player->dead && !in_array($player->gameMode, [1, 3], true) && $player->dimension === $this->world->dimension;
    }

    private function mobDistanceSquared(array $mob, Client $player): float
    {
        return ($player->x - $mob['x']) ** 2 + ($player->y - $mob['y']) ** 2 + ($player->z - $mob['z']) ** 2;
    }

    /** Sensing.hasLineOfSight: no block's collision shape between the mob's eyes and the player's. */
    private function mobSees(array $mob, Client $player): bool
    {
        $size = $this->version->catalog->mobs['entities'][$mob['kind']];
        $from = [$mob['x'], $mob['y'] + $size['eye_height'] * (($mob['baby'] ?? false) ? 0.5 : 1.0), $mob['z']];
        $to = [$player->x, $player->y + ($player->sneaking && !$player->flying ? 1.27 : 1.62), $player->z];
        if (($to[0] - $from[0]) ** 2 + ($to[1] - $from[1]) ** 2 + ($to[2] - $from[2]) ** 2 > 128 ** 2) {
            return false;
        }
        return (new Collision($this->version->catalog))->clipBlocks($this->world, $from, $to) === null;
    }

    /** Mob.isWithinMeleeAttackRange: its box widened by the attack reach meets the player's. */
    private function withinAttackReach(array $mob, Client $player): bool
    {
        $size = $this->version->catalog->mobs['entities'][$mob['kind']];
        $scale = ($mob['baby'] ?? false) ? 0.5 : 1.0;
        $box = Collision::bounds($mob['x'], $mob['y'], $mob['z'], $size['width'] * $scale, $size['height'] * $scale);
        $box = [$box[0] - self::ATTACK_REACH, $box[1], $box[2] - self::ATTACK_REACH, $box[3] + self::ATTACK_REACH, $box[4], $box[5] + self::ATTACK_REACH];
        $other = Collision::bounds($player->x, $player->y, $player->z, 0.6, $player->sneaking && !$player->flying ? 1.5 : 1.8);
        return $box[0] < $other[3] && $box[3] > $other[0] && $box[1] < $other[4] && $box[4] > $other[1] && $box[2] < $other[5] && $box[5] > $other[2];
    }
}
