<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Server\Tracking\EntityMovementTracker;
use PhpMinecraftJavaServer\World\EntityPhysics;

/** Attack impulses and tracked-client snapshots share authoritative velocities. */
trait MobMotion
{
    /** @var array<int, EntityMovementTracker> */
    private array $mobMovementTrackers = [];

    private function mobMotionState(array $mob): array
    {
        return [
            'position' => [$mob['x'], $mob['y'], $mob['z']],
            'velocity' => $this->version->catalog->movement['profiles'][$mob['kind']]['velocity'] === 'zero'
                ? [0.0, 0.0, 0.0] : ($mob['velocity'] ?? [0.0, 0.0, 0.0]),
            'yaw' => $mob['yaw'],
            'pitch' => $mob['pitch'] ?? 0.0,
            'head' => $mob['headYaw'] ?? $mob['yaw'],
            'ground' => $mob['ground'] ?? false,
            'needs_sync' => $mob['needsSync'] ?? false,
            'sync_velocity' => $mob['syncVelocity'] ?? false,
            'sync_position' => $mob['syncPosition'] ?? false,
            'precise' => $mob['requiresPrecisePosition'] ?? false,
            'predicted' => $mob['predictedMovement'] ?? [0.0, 0.0, 0.0],
        ];
    }

    private function mobMotionTracker(array $mob): EntityMovementTracker
    {
        $profile = $this->version->catalog->movement['profiles'][$mob['kind']];
        return $this->mobMovementTrackers[$mob['id']] ??= new EntityMovementTracker(
            $mob['id'],
            max(1, $profile['update_interval']),
            $profile['track_deltas'],
            $this->mobMotionState($mob),
            $profile['interpolation'] === 'stepped'
        );
    }

    private function trackedMobSpawnPacket(array $mob, int $type): string
    {
        $profile = $this->version->catalog->movement['profiles'][$mob['kind']];
        $data = $profile['spawn_data'] === 'warden_pose' && ($mob['pose'] ?? 'standing') === 'emerging' ? 1 : 0;
        return $this->mobMotionTracker($mob)->spawnPacket(hex2bin($mob['uuid']), $type, $data);
    }

    private function knockbackMob(array &$mob, Client $attacker): void
    {
        if (!$this->ordinaryMobTravel($mob)) {
            return;
        }
        $attributes = $this->version->catalog->mobs['entities'][$mob['kind']]['attributes'];
        $result = EntityPhysics::knockback(
            $mob['velocity'],
            $this->version->catalog->movement['default_knockback_strength'],
            $attributes['knockback_resistance'] ?? 0.0,
            $mob['ground'] ?? false,
            $attacker->x - $mob['x'],
            $attacker->z - $mob['z'],
            $this->mobRandom($mob['id'])
        );
        $mob['velocity'] = $this->version->catalog->movement['profiles'][$mob['kind']]['velocity'] === 'zero'
            ? [0.0, 0.0, 0.0] : $result['velocity'];
        $mob['needsSync'] = $result['synchronize'];
        $mob['syncVelocity'] = true;
    }

    private function synchronizeMobMotion(array &$mob): void
    {
        $state = $this->mobMotionState($mob);
        $packets = $this->mobMotionTracker($mob)->update($state);
        $mob['needsSync'] = $state['needs_sync'];
        $mob['syncVelocity'] = $state['sync_velocity'];
        $mob['syncPosition'] = $state['sync_position'];
        unset($mob['predictedMovement']);
        foreach ($packets as $packet) {
            foreach ($this->localPlayers() as $viewer) {
                if (isset($viewer->entities[$mob['id']])) {
                    $viewer->send($this->version, $packet['name'], $packet['payload']);
                }
            }
        }
    }
}
