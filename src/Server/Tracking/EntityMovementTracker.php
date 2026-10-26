<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Tracking;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\LowPrecisionVector;
use PhpMinecraftJavaServer\Protocol\RelativePositionCodec;
use PhpMinecraftJavaServer\World\Generation\Float32;

/** Ordinary unmounted movement selection; metadata/equipment have their own handlers. */
final class EntityMovementTracker
{
    private readonly RelativePositionCodec $positions;

    private readonly SteppedPositionTracker $steps;

    private array $lastVelocity;

    private int $lastYaw;

    private int $lastPitch;

    private int $lastHead;

    private bool $lastGround;

    private int $ticks = 0;

    private int $teleportDelay = 0;

    public function __construct(
        private readonly int $id,
        private readonly int $interval,
        private readonly bool $trackDelta,
        array $initial
    ) {
        if ($interval < 1) {
            throw new \InvalidArgumentException('Movement interval must be positive');
        }
        $this->positions = new RelativePositionCodec($initial['position']);
        $this->steps = new SteppedPositionTracker();
        $this->lastVelocity = $initial['velocity'];
        $this->lastYaw = self::angle($initial['yaw']);
        $this->lastPitch = self::angle($initial['pitch']);
        $this->lastHead = self::angle($initial['head']);
        $this->lastGround = $initial['ground'];
    }

    private static function angle(float $degrees): int
    {
        $scaled = Float32::round(Float32::round(Float32::round($degrees) * 256.0) / 360.0);
        $integer = is_nan($scaled) ? 0 : (int) max(-2147483648.0, min(2147483647.0, floor($scaled)));
        $byte = $integer & 255;
        return $byte >= 128 ? $byte - 256 : $byte;
    }

    private static function distance(array $first, array $second): float
    {
        $x = $first[0] - $second[0];
        $y = $first[1] - $second[1];
        $z = $first[2] - $second[2];
        return $x * $x + $y * $y + $z * $z;
    }

    private function packet(string $name, string $payload, string $delivery = 'tracking'): array
    {
        return ['name' => $name, 'payload' => Binary::writeVarInt($this->id) . $payload, 'delivery' => $delivery];
    }

    /** Pair from the same cached base and encoded rotations as the next delta. */
    public function spawnPacket(string $uuid, int $type): string
    {
        return Binary::writeVarInt($this->id) . $uuid . Binary::writeVarInt($type)
            . pack('EEE', ...$this->positions->base) . LowPrecisionVector::encode(...$this->lastVelocity)
            . chr($this->lastPitch & 255) . chr($this->lastYaw & 255) . chr($this->lastHead & 255) . "\0";
    }

    /** @return list<array{name: string, payload: string, delivery: string}> */
    public function update(array &$state): array
    {
        $packets = [];
        $this->steps->update($state['position'], $state['predicted'] ?? [0.0, 0.0, 0.0], $state['sync_position']);
        $state['sync_position'] = false;
        if ($state['needs_sync'] || $this->ticks % $this->interval === 0 || ($state['force_update'] ?? false)) {
            $yaw = self::angle($state['yaw']);
            $pitch = self::angle($state['pitch']);
            $rotated = abs($yaw - $this->lastYaw) >= 1 || abs($pitch - $this->lastPitch) >= 1;
            ++$this->teleportDelay;
            $path = $this->steps->path($state['position']);
            $this->steps->clear();
            $moved = self::distance($state['position'], $this->positions->base) >= 7.62939453125E-6 || $this->ticks % 60 === 0;
            $movement = null;
            $hasPosition = false;
            $hasRotation = false;
            $fullPosition = ($state['precise'] ?? false) || $this->teleportDelay > 400 || $this->lastGround !== $state['ground'];
            if ($fullPosition) {
                $this->lastGround = $state['ground'];
                $this->teleportDelay = 0;
            }
            $relative = !$fullPosition && $moved ? $this->positions->encode($path) : null;
            if ($fullPosition || ($moved && $relative === null)) {
                $movement = $this->packet('entity_position_sync', $path->encode() . pack('GG', $state['yaw'], $state['pitch']) . chr((int) $state['ground']));
                $hasPosition = $hasRotation = true;
            } elseif ($moved) {
                $flags = ($relative['count'] << 1) | (int) $state['ground'];
                $payload = Binary::writeVarInt($flags) . $relative['bytes'];
                if ($rotated) {
                    $payload .= chr($yaw & 255) . chr($pitch & 255);
                }
                $movement = $rotated ? $this->packet('move_entity_pos_rot', $payload) : $this->packet('move_entity_pos', $payload);
                $hasPosition = true;
                $hasRotation = $rotated;
            } elseif ($rotated) {
                $movement = $this->packet('move_entity_rot', chr((int) $state['ground']) . chr($yaw & 255) . chr($pitch & 255));
                $hasRotation = true;
            }
            if ($state['needs_sync'] || $this->trackDelta || ($state['fall_flying'] ?? false)) {
                $difference = self::distance($state['velocity'], $this->lastVelocity);
                $length = self::distance($state['velocity'], [0.0, 0.0, 0.0]);
                if ($difference > 1.0E-7 || ($difference > 0.0 && $length === 0.0)) {
                    $this->lastVelocity = $state['velocity'];
                    $packets[] = $this->packet('set_entity_motion', LowPrecisionVector::encode(...$state['velocity']));
                }
            }
            if ($movement !== null) {
                $packets[] = $movement;
                if ($hasPosition) {
                    $this->positions->base = $state['position'];
                }
                if ($hasRotation) {
                    $this->lastYaw = $yaw;
                    $this->lastPitch = $pitch;
                }
            }
            $head = self::angle($state['head']);
            if (abs($head - $this->lastHead) >= 1) {
                $packets[] = $this->packet('rotate_head', chr($head & 255));
                $this->lastHead = $head;
            }
            $state['needs_sync'] = false;
        }
        ++$this->ticks;
        if ($state['sync_velocity']) {
            $packets[] = $this->packet('set_entity_motion', LowPrecisionVector::encode(...$state['velocity']), 'tracking_and_self');
            $state['sync_velocity'] = false;
        }
        return $packets;
    }
}
