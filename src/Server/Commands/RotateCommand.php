<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait RotateCommand
{
    /**
     * RotateCommand: a rotation (where "~" is relative to the source's rotation and is sent as a
     * change from the target's), or facing a location or entity as /tp's facing does.
     */
    private function executeRotate(Client $sender, array $arguments): void
    {
        $target = $this->selectEntities($arguments[0] ?? '', $sender, false, true)[0];
        if (($arguments[1] ?? '') === 'facing') {
            if (($arguments[2] ?? '') === 'entity') {
                $entity = $this->selectEntities($arguments[3], $sender, false, true)[0];
                $anchor = $arguments[4] ?? 'feet';
                $facing = [$entity['x'], $entity['y'] + ($anchor === 'eyes' ? $this->eyeHeight($entity) : 0.0), $entity['z'], $entity, $anchor];
            } else {
                $facing = [...$this->positionArguments(array_slice($arguments, 2, 3), $sender), null, null];
            }
            $this->faceEntity($target, $facing);
        } else {
            $f32 = static fn (float $value): float => unpack('G', pack('G', $value))[1];
            // "~" is relative to the source's rotation; the packet carries the change from the target's.
            $angle = static fn (string $text, float $source, float $current): array => str_starts_with($text, '~') ? [$f32($f32($source + (float) substr($text, 1)) - $current), true] : [$f32((float) $text), false];
            [$yaw, $relativeYaw] = $angle($arguments[1], $this->sourceRotation($sender)[0], $target['yaw']);
            [$pitch, $relativePitch] = $angle($arguments[2], $this->sourceRotation($sender)[1], $target['pitch']);
            if (isset($target['client'])) {
                // ClientboundPlayerRotationPacket; the client applies relative values to its own rotation.
                $target['client']->send($this->version, 'player_rotation', pack('G', $yaw) . chr((int) $relativeYaw) . pack('G', $pitch) . chr((int) $relativePitch));
            }
            $this->rotateEntity($target, $relativeYaw ? $target['yaw'] + $yaw : $yaw, $relativePitch ? $target['pitch'] + $pitch : $pitch);
        }
        $this->sendSuccess($sender, ['translate' => 'commands.rotate.success', 'with' => [$this->entityName($target)]], true);
    }
}
