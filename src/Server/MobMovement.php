<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\AirTravel;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\EntityPhysics;
use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Noise;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;

/** Ordinary travel runs once per server tick; navigation goals remain in #35. */
trait MobMovement
{
    use LegacyMobMovement;

    use MobMotion;

    use MobPushing;

    private ?AirTravel $mobAirTravel = null;

    private ?Collision $strollCollision = null;

    private function ordinaryMobTravel(array $mob): bool
    {
        $kind = $mob['kind'];
        $profile = $this->version->catalog->movement['profiles'][$kind];
        if ($profile['travel_owner'] !== 'net.minecraft.world.entity.LivingEntity'
            || $profile['omnidirectional_owner'] !== 'net.minecraft.world.entity.Entity'
            || in_array($kind, self::FLYING_MOBS, true)
            || in_array($kind, self::SWIMMING_MOBS, true)) {
            return false;
        }
        return $profile['velocity'] === 'zero' || !$this->version->catalog->fluid($this->world->get((int) floor($mob['x']), (int) floor($mob['y']), (int) floor($mob['z'])));
    }

    private function moveMob(array &$mob, Noise $noise): void
    {
        if (!$this->ordinaryMobTravel($mob)) {
            // In water (or flying and swimming) a fall ends without damage.
            $mob['fallDistance'] = 0.0;
            unset($this->mobMovementTrackers[$mob['id']]);
            if ($this->ticks % 4 === 0) {
                $this->moveLegacyMob($mob, $noise);
            }
            $this->pushAfterMobTravel($mob);
            return;
        }
        if ($this->version->catalog->movement['profiles'][$mob['kind']]['velocity'] === 'zero') {
            // Shulkers' actual velocity getter always returns zero and their
            // setter ignores impulses; ordinary travel cannot move them.
            $mob['velocity'] = [0.0, 0.0, 0.0];
            $mob['ground'] = false;
            $this->pushAfterMobTravel($mob);
            return;
        }
        $definition = ['attributes' => $this->mobAttributes($mob)] + $this->version->catalog->mobs['entities'][$mob['kind']];
        $this->mobAirTravel ??= new AirTravel(
            $this->version->catalog,
            new EntityPhysics(file_get_contents($this->version->directory . '/entity-sine.bin')),
            $this->version->catalog->movement
        );
        // Mob.serverAiStep counts ticks without a player near; RandomStrollGoal starts no stroll
        // once that reaches 100.
        $mob['noActionTime'] = ($mob['noActionTime'] ?? 0) + 1;
        // Preserve the existing target chooser until native goal/navigation
        // stacks are implemented; feed its intent into actual travel physics.
        // MeleeAttackGoal outranks wandering: a hunter walks straight for its prey, and a
        // swelling creeper stands still. BreedGoal does too: an animal in love heads for its partner.
        $prey = $this->huntPlayer($mob);
        $partner = $prey === null ? $this->breedingPartner($mob) : null;
        if ($prey !== null) {
            [$mob['targetX'], $mob['targetZ'], $mob['nextWander']] = [$prey->x, $prey->z, $this->ticks + 20];
        } elseif (($mob['swellDir'] ?? -1) > 0) {
            [$mob['targetX'], $mob['targetZ'], $mob['nextWander']] = [$mob['x'], $mob['z'], $this->ticks + 20];
        } elseif ($partner !== null) {
            [$mob['targetX'], $mob['targetZ'], $mob['nextWander']] = [$partner['x'], $partner['z'], $this->ticks + 20];
        } elseif ($this->ticks >= $mob['nextWander']) {
            // A stroll that has not reached its target by now is stuck, as PathNavigation finds.
            [$mob['targetX'], $mob['targetZ']] = [$mob['x'], $mob['z']];
            $mob['nextWander'] = PHP_INT_MAX;
        }
        $dx = $mob['targetX'] - $mob['x'];
        $dz = $mob['targetZ'] - $mob['z'];
        // RandomStrollGoal.canUse, once the last stroll is done: one chance in 120 each tick.
        if ($prey === null && $partner === null && ($mob['swellDir'] ?? -1) <= 0 && $dx * $dx + $dz * $dz <= 0.04
            && $mob['noActionTime'] < 100 && $noise->hash($mob['id'], $this->ticks, 0, 121) % 120 === 0) {
            $target = $this->strollTarget($mob, $noise);
            if ($target !== null) {
                [$mob['targetX'], $mob['targetZ']] = $target;
                $mob['nextWander'] = $this->ticks + 200;
            }
        }
        $dx = $mob['targetX'] - $mob['x'];
        $dz = $mob['targetZ'] - $mob['z'];
        if ($prey !== null && isset($mob['strafe'])) {
            // MoveControl.strafe: a quarter of the movement speed, forward and sideways while
            // facing the target (Mob.lookAt).
            [$forward, $right] = $mob['strafe'];
            $speed = Float32::round(0.25 * ($definition['attributes']['movement_speed'] ?? 0.25));
            $mob['yaw'] = Float32::round(rad2deg(atan2(-$dx, $dz)));
            $before = $mob['y'];
            $this->mobAirTravel->step($this->world, $mob, $definition, [$right, 0.0, $forward], $speed);
            $this->mobFall($mob, $mob['y'] - $before);
            $this->pushAfterMobTravel($mob);
            return;
        }
        $moving = $mob['kind'] !== 'giant' && $dx * $dx + $dz * $dz > 0.04;
        // MeleeAttackGoal's speed modifier of 1: the full movement speed while hunting.
        $speed = $moving ? Float32::round($prey !== null ? ($definition['attributes']['movement_speed'] ?? 0.2) : min(0.5, ($definition['attributes']['movement_speed'] ?? 0.2) * 0.6)) : 0.0;
        if ($moving) {
            $mob['yaw'] = Float32::round(rad2deg(atan2(-$dx, $dz)));
        }
        $before = $mob['y'];
        $this->mobAirTravel->step($this->world, $mob, $definition, [0.0, 0.0, $speed], $speed);
        $this->mobFall($mob, $mob['y'] - $before);
        $this->pushAfterMobTravel($mob);
    }

    /**
     * WaterAvoidingRandomStrollGoal's LandRandomPos.getPos(mob, 10, 7): ten offsets of up to 10
     * blocks across and 7 up or down, each kept only if it stands on a solid-render block (the
     * ground navigation's stable destination), then raised out of solid blocks and left neither
     * in water nor in a block the mob cannot walk into. The one the mob values most is the
     * target: an animal favours grass and light, a monster darkness.
     *
     * @return array{float, float}|null the bottom centre of the chosen block
     */
    private function strollTarget(array $mob, Noise $noise): ?array
    {
        $blocks = $this->fireBlocks();
        $catalog = $this->version->catalog;
        $this->strollCollision ??= new Collision($catalog);
        $category = $catalog->mobs['entities'][$mob['kind']]['category'] ?? '';
        $maxY = $this->world->minY + $this->world->height - 1;
        $best = null;
        $bestWeight = -INF;
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $hash = $noise->hash($mob['id'], $this->ticks, $attempt, 122);
            $x = (int) floor($mob['x'] + $hash % 21 - 10);
            $y = (int) floor($mob['y'] + intdiv($hash, 21) % 15 - 7);
            $z = (int) floor($mob['z'] + intdiv($hash, 315) % 21 - 10);
            if ($y < $this->world->minY || $y > $maxY || !$blocks->flag($this->world->get($x, $y - 1, $z), Blocks::SOLID_RENDER)) {
                continue;
            }
            while ($y <= $maxY && $blocks->flag($this->world->get($x, $y, $z), Blocks::SOLID)) {
                ++$y;
            }
            $state = $this->world->get($x, $y, $z);
            if ($blocks->flag($state, Blocks::WATER | Blocks::LAVA) || $this->strollCollision->shape($state) !== [] || in_array($catalog->blockName($state), ['fire', 'soul_fire', 'powder_snow', 'cactus', 'sweet_berry_bush', 'magma_block'], true)) {
                continue;
            }
            $light = $this->world->rawBrightness($x, $y, $z, false, $this->skyDarken()) / 15.0;
            // LevelReader.getPathfindingCostFromLightLevels: the overworld's brightness curve, less a half.
            $cost = $light / (4.0 - 3.0 * $light) - 0.5;
            $weight = match ($category) {
                'creature' => $catalog->blockName($this->world->get($x, $y - 1, $z)) === 'grass_block' ? 10.0 : $cost,
                'monster' => -$cost,
                default => 0.0,
            };
            if ($weight > $bestWeight) {
                [$best, $bestWeight] = [[$x + 0.5, $z + 0.5], $weight];
            }
        }
        return $best;
    }
}
