<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;

/** ServerPlayer.setCamera: a spectator viewing through another entity follows it each tick. */
trait Cameras
{
    /** @param array|null $entity a selected entity, or null for the player's own view */
    private function setCamera(Client $c, ?array $entity): void
    {
        $previous = $c->camera['id'] ?? $c->entityId;
        $camera = $entity === null || ($entity['client'] ?? null) === $c ? null : $entity;
        if (($camera['id'] ?? $c->entityId) === $previous) {
            return;
        }
        $c->camera = $camera;
        // The player moves to the camera, keeping their own rotation.
        $this->teleport($c, $this->cameraPosition($c) ?? [$c->x, $c->y, $c->z]);
        $c->send($this->version, 'set_camera', Binary::writeVarInt($camera['id'] ?? $c->entityId));
    }

    /** The camera entity's position while it is alive, otherwise null. */
    private function cameraPosition(Client $c): ?array
    {
        $camera = $c->camera;
        if ($camera === null) {
            return null;
        }
        if (isset($camera['client'])) {
            $target = $camera['client'];
            return $target->dead || !in_array($target, $this->players(), true) ? null : [$target->x, $target->y, $target->z];
        }
        if (isset($camera['mob'])) {
            $animal = $this->animals[$camera['mob']] ?? null;
            return $animal === null || isset($animal['deadAt']) ? null : [$animal['x'], $animal['y'], $animal['z']];
        }
        $item = $this->itemEntities[$camera['item']] ?? null;
        return $item === null ? null : [$item['x'], $item['y'], $item['z']];
    }

    /** ServerPlayer.tick: snap to a living camera, and stop viewing when it dies or the player sneaks. */
    private function tickCamera(Client $c): void
    {
        if ($c->camera === null) {
            return;
        }
        $position = $this->cameraPosition($c);
        if ($position !== null) {
            [$c->x, $c->y, $c->z] = $position;
            if (!$c->sneaking) {
                return;
            }
        }
        $this->setCamera($c, null);
    }
}
