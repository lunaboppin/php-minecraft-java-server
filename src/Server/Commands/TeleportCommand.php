<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Server\Client;

trait TeleportCommand
{
    private function executeTeleport(Client $sender, array $arguments): void
    {
        // teleport <location> | <destination> | <targets> <location> [<rotation> | facing ...] | <targets> <destination>
        $count = count($arguments);
        [$targets, $destination, $rest] = match (true) {
            $count === 1 => [$this->selectEntities('@s', $sender), $arguments, []],
            $count === 2 => [$this->selectEntities($arguments[0], $sender), [$arguments[1]], []],
            $count === 3 => [$this->selectEntities('@s', $sender), $arguments, []],
            $count >= 4 => [$this->selectEntities($arguments[0], $sender), array_slice($arguments, 1, 3), array_slice($arguments, 4)],
            default => throw new \InvalidArgumentException('Usage: /tp [targets] destination|x y z'),
        };
        if (count($destination) === 1) {
            $to = $this->selectEntities($destination[0], $sender, false, true)[0];
            $dimension = isset($to['client']) ? $to['client']->dimension : $this->sourceDimension($sender);
            foreach ($targets as $target) {
                // performTeleport to an entity: no relatives, and the destination's rotation.
                $from = isset($target['client']) ? [$target['client']->x, $target['client']->y, $target['client']->z, $target['client']->yaw, $target['client']->pitch] : null;
                $this->rotateEntity($target, (float) $to['yaw'], (float) $to['pitch']);
                $this->moveEntity($target, $dimension, [$to['x'], $to['y'], $to['z']], 0, $from);
            }
            $message = count($targets) === 1
                ? ['translate' => 'commands.teleport.success.entity.single', 'with' => [$this->entityName($targets[0]), $this->entityName($to)]]
                : ['translate' => 'commands.teleport.success.entity.multiple', 'with' => [count($targets), $this->entityName($to)]];
            $this->sendSuccess($sender, $message, true);
            return;
        }
        $rotation = null;
        $facing = null;
        if (count($rest) === 2) {
            $rotation = $rest;
        } elseif (($rest[0] ?? null) === 'facing' && ($rest[1] ?? null) === 'entity' && in_array(count($rest), [3, 4], true)) {
            $entity = $this->selectEntities($rest[2], $sender, false, true)[0];
            $anchor = $rest[3] ?? 'feet';
            $facing = [$entity['x'], $entity['y'] + ($anchor === 'eyes' ? $this->eyeHeight($entity) : 0.0), $entity['z'], $entity, $anchor];
        } elseif (($rest[0] ?? null) === 'facing' && count($rest) === 4) {
            $facing = [...$this->positionArguments(array_slice($rest, 1), $sender), null, null];
        } elseif ($rest !== []) {
            throw new \InvalidArgumentException('Usage: /tp [targets] destination|x y z');
        }
        // Relative coordinates are resolved from the command's sender, even for other targets.
        $position = $this->positionArguments($destination, $sender);
        // TeleportCommand.getRelatives: "~" axes and their velocities stay relative (local "^"
        // coordinates count as relative on every axis), and so do angles that are "~" or not given.
        // A change of dimension sends absolute coordinates instead.
        $relative = 0;
        foreach ($destination as $axis => $text) {
            if ($text[0] === '~' || $text[0] === '^') {
                $relative |= (1 << (5 + $axis)) | (1 << $axis);
            }
        }
        $relative |= $rotation === null ? 0x18 : ((str_starts_with($rotation[0], '~') ? 0x08 : 0) | (str_starts_with($rotation[1], '~') ? 0x10 : 0));
        foreach ($targets as $target) {
            $from = isset($target['client']) ? [$target['client']->x, $target['client']->y, $target['client']->z, $target['client']->yaw, $target['client']->pitch] : null;
            if ($rotation !== null) {
                // RotationArgument: yaw then pitch; "~" is relative to the target's own rotation.
                $angle = static fn (string $text, float $current): float => str_starts_with($text, '~') ? $current + (float) substr($text, 1) : (float) $text;
                $yaw = $angle($rotation[0], $target['yaw']);
                $yaw -= 360.0 * floor(($yaw + 180.0) / 360.0);
                $this->rotateEntity($target, $yaw, max(-90.0, min(90.0, $angle($rotation[1], $target['pitch']))));
            }
            $this->moveEntity($target, $this->sourceDimension($sender), $position, $relative, $from);
            if ($facing !== null) {
                $this->faceEntity($target, $facing);
            }
        }
        // String.format("%f"): six decimals, independent of locale.
        $coordinates = array_map(static fn (float $value): array => ['text' => sprintf('%.6F', $value)], $position);
        $message = count($targets) === 1
            ? ['translate' => 'commands.teleport.success.location.single', 'with' => [$this->entityName($targets[0]), ...$coordinates]]
            : ['translate' => 'commands.teleport.success.location.multiple', 'with' => [count($targets), ...$coordinates]];
        $this->sendSuccess($sender, $message, true);
    }

    /** EntityDimensions eye height: a player's standing eyes, or the mob type's. */
    private function eyeHeight(array $entity): float
    {
        // EntityDimensions.eyeHeight is a float: the player's standing 1.62f.
        return isset($entity['client']) ? unpack('G', pack('G', 1.62))[1] : (float) $this->version->catalog->mobs['entities'][substr($entity['type'], 10)]['eye_height'];
    }

    private function rotateEntity(array $target, float $yaw, float $pitch): void
    {
        // Entity.setXRot: the pitch, as a float remainder of 360, within ±90.
        $pitch = max(-90.0, min(90.0, fmod(unpack('G', pack('G', $pitch))[1], 360.0)));
        if (isset($target['orb'])) {
            $this->experienceOrbs[$target['orb']]['yaw'] = $yaw;
            return;
        }
        if (isset($target['item'])) {
            return;
        }
        if (isset($target['client'])) {
            $target['client']->yaw = $yaw;
            $target['client']->pitch = $pitch;
            return;
        }
        $this->animals[$target['mob']]['yaw'] = $yaw;
    }

    /**
     * LookAt after teleporting: a player is told to look at the position (or entity) from their
     * feet; a mob's rotation is set from its feet.
     *
     * @param array{float, float, float, ?array, ?string} $facing
     */
    private function faceEntity(array $target, array $facing): void
    {
        [$x, $y, $z, $entity, $anchor] = $facing;
        if (isset($target['item']) || isset($target['orb'])) {
            return;
        }
        [$fromX, $fromY, $fromZ] = isset($target['client']) ? [$target['client']->x, $target['client']->y, $target['client']->z] : [$this->animals[$target['mob']]['x'], $this->animals[$target['mob']]['y'], $this->animals[$target['mob']]['z']];
        $dx = $x - $fromX;
        $dy = $y - $fromY;
        $dz = $z - $fromZ;
        $pitch = -rad2deg(atan2($dy, sqrt($dx * $dx + $dz * $dz)));
        $yaw = rad2deg(atan2($dz, $dx)) - 90.0;
        $yaw -= 360.0 * floor(($yaw + 180.0) / 360.0);
        $this->rotateEntity($target, $yaw, max(-90.0, min(90.0, $pitch)));
        if (isset($target['client'])) {
            $payload = Binary::writeVarInt(0) . pack('E3', $x, $y, $z) . ($entity === null ? "\0" : "\1" . Binary::writeVarInt($entity['id']) . Binary::writeVarInt($anchor === 'eyes' ? 1 : 0));
            $target['client']->send($this->version, 'player_look_at', $payload);
        }
    }

    /** Move a selected player (across dimensions if needed) or a mob in the sender's dimension. */
    private function moveEntity(array $target, string $dimension, array $position, int $relative = 0, ?array $from = null): void
    {
        if (isset($target['orb'])) {
            // The orb's tracker sends the new position with its next update.
            $orb = &$this->experienceOrbs[$target['orb']];
            [$orb['x'], $orb['y'], $orb['z']] = $position;
            $orb['needsSync'] = true;
            return;
        }
        if (isset($target['item'])) {
            $item = &$this->itemEntities[$target['item']];
            [$item['x'], $item['y'], $item['z']] = $position;
            foreach ($this->localPlayers() as $player) {
                if (isset($player->entities[$target['item']])) {
                    $player->send($this->version, 'teleport_entity', \PhpMinecraftJavaServer\Protocol\Packets::teleportEntity($target['item'], $position[0], $position[1], $position[2], 0, 0, false));
                }
            }
            return;
        }
        if (!isset($target['client'])) {
            $this->teleportAnimal($target['mob'], $position);
            return;
        }
        $player = $target['client'];
        // ServerPlayer.teleportTo with resetCamera: a teleported spectator stops viewing.
        $this->setCamera($player, null);
        if ($player->dimension === $dimension) {
            $this->teleport($player, $position, $relative, $from);
        } else {
            $this->changeDimension($player, $dimension, $position);
        }
    }
}
