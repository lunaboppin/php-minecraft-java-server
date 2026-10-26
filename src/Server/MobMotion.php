<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\EntityPhysics;

/** Attack impulses and tracked-client snapshots share authoritative velocities. */
trait MobMotion
{
    private array $mobLastSent = [];

    private array $mobTrackingTicks = [];

    private array $mobLastSentVelocity = [];

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
        $mob['velocity'] = $result['velocity'];
        $mob['needsSync'] = $result['synchronize'];
        $mob['syncVelocity'] = true;
    }

    private function broadcastMobVelocity(array $mob): void
    {
        foreach ($this->localPlayers() as $viewer) {
            if (isset($viewer->entities[$mob['id']])) {
                $viewer->send($this->version, 'set_entity_motion', Packets::entityMotion($mob['id'], ...$mob['velocity']));
            }
        }
    }

    private function synchronizeMobMotion(array &$mob): void
    {
        $id = $mob['id'];
        $interval = max(1, $this->version->catalog->movement['profiles'][$mob['kind']]['update_interval']);
        $tick = $this->mobTrackingTicks[$id] ?? 0;
        $this->mobTrackingTicks[$id] = $tick + 1;
        $lastVelocity = $this->mobLastSentVelocity[$id] ??= $mob['velocity'];
        if ($tick % $interval === 0 || ($mob['needsSync'] ?? false) || ($mob['syncPosition'] ?? false)) {
            $difference = 0.0;
            $length = 0.0;
            foreach ($mob['velocity'] as $axis => $component) {
                $difference += ($component - $lastVelocity[$axis]) ** 2;
                $length += $component * $component;
            }
            $trackDeltas = $this->version->catalog->movement['profiles'][$mob['kind']]['track_deltas'];
            if (($trackDeltas || ($mob['needsSync'] ?? false))
                && ($difference > 1.0E-7 || ($difference > 0.0 && $length === 0.0))) {
                $this->mobLastSentVelocity[$id] = $mob['velocity'];
                $this->broadcastMobVelocity($mob);
            }
            $state = [$mob['x'], $mob['y'], $mob['z'], $mob['yaw'], $mob['ground'] ?? false, $mob['velocity']];
            if (($this->mobLastSent[$id] ?? null) !== $state || ($mob['syncPosition'] ?? false)) {
                $this->mobLastSent[$id] = $state;
                foreach ($this->localPlayers() as $viewer) {
                    if (!isset($viewer->entities[$id])) {
                        continue;
                    }
                    $viewer->send($this->version, 'teleport_entity', Packets::teleportEntity(
                        $id,
                        $mob['x'],
                        $mob['y'],
                        $mob['z'],
                        $mob['yaw'],
                        0.0,
                        $mob['ground'] ?? false,
                        $mob['velocity']
                    ));
                    $viewer->send($this->version, 'rotate_head', Packets::headRotation($id, $mob['yaw']));
                }
            }
            $mob['needsSync'] = false;
            $mob['syncPosition'] = false;
        }
        if ($mob['syncVelocity'] ?? false) {
            $this->broadcastMobVelocity($mob);
            $mob['syncVelocity'] = false;
        }
    }
}
