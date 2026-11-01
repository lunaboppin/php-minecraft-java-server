<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\AirTravel;
use PhpMinecraftJavaServer\World\EntityPhysics;
use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Noise;

/** Ordinary travel runs once per server tick; navigation goals remain in #35. */
trait MobMovement
{
    use LegacyMobMovement;

    use MobMotion;

    use MobPushing;

    private ?AirTravel $mobAirTravel = null;

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
        if ($this->ticks >= $mob['nextWander'] && $mob['noActionTime'] < 100) {
            $hash = $noise->hash($mob['id'], $this->ticks >> 5, 0, 121);
            $mob['targetX'] = $mob['x'] + $hash % 17 - 8;
            $mob['targetZ'] = $mob['z'] + intdiv($hash, 19) % 17 - 8;
            $mob['nextWander'] = $this->ticks + 80 + $hash % 160;
        }
        $dx = $mob['targetX'] - $mob['x'];
        $dz = $mob['targetZ'] - $mob['z'];
        $moving = $mob['kind'] !== 'giant' && $dx * $dx + $dz * $dz > 0.04;
        $speed = $moving ? Float32::round(min(0.5, ($definition['attributes']['movement_speed'] ?? 0.2) * 0.6)) : 0.0;
        if ($moving) {
            $mob['yaw'] = Float32::round(rad2deg(atan2(-$dx, $dz)));
        }
        $before = $mob['y'];
        $this->mobAirTravel->step($this->world, $mob, $definition, [0.0, 0.0, $speed], $speed);
        $this->mobFall($mob, $mob['y'] - $before);
        $this->pushAfterMobTravel($mob);
    }
}
