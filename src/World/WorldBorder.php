<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

use PhpMinecraftJavaServer\Protocol\Binary;

/**
 * A dimension's WorldBorder: centre, a size that may be moving between two sizes over game
 * ticks, damage outside the safe zone and the client warning settings.
 */
final class WorldBorder
{
    public const MAX_SIZE = 59999968.0;
    public const ABSOLUTE_MAX = 29999984;

    public float $centerX = 0.0;

    public float $centerZ = 0.0;

    public float $fromSize = self::MAX_SIZE;

    public float $toSize = self::MAX_SIZE;

    public int $lerpStart = 0;

    public int $lerpDuration = 0;

    public float $damagePerBlock = 0.2;

    public float $safeZone = 5.0;

    public int $warningBlocks = 5;

    public int $warningTime = 300;

    public static function fromArray(?array $data): self
    {
        $border = new self();
        foreach ($data ?? [] as $field => $value) {
            if (property_exists($border, $field)) {
                $border->$field = is_int($border->$field) ? (int) $value : (float) $value;
            }
        }
        return $border;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** MovingBorderExtent.getSize: linear between the sizes, then static at the target. */
    public function size(int $gameTime): float
    {
        $remaining = $this->remaining($gameTime);
        if ($remaining <= 0) {
            return $this->toSize;
        }
        $progress = ($gameTime - $this->lerpStart) / $this->lerpDuration;
        return $this->fromSize + ($this->toSize - $this->fromSize) * $progress;
    }

    /** Ticks left of a moving border. */
    public function remaining(int $gameTime): int
    {
        return $this->lerpDuration <= 0 ? 0 : max(0, $this->lerpStart + $this->lerpDuration - $gameTime);
    }

    public function setSize(float $size): void
    {
        [$this->fromSize, $this->toSize, $this->lerpDuration] = [$size, $size, 0];
    }

    public function lerpSizeBetween(float $from, float $to, int $ticks, int $gameTime): void
    {
        [$this->fromSize, $this->toSize, $this->lerpStart, $this->lerpDuration] = [$from, $to, $gameTime, $ticks];
    }

    /** WorldBorder.getDistanceToBorder: positive inside, the distance to the nearest edge. */
    public function distanceToBorder(float $x, float $z, int $gameTime): float
    {
        $half = $this->size($gameTime) / 2.0;
        $minX = max(-self::ABSOLUTE_MAX, $this->centerX - $half);
        $maxX = min(self::ABSOLUTE_MAX, $this->centerX + $half);
        $minZ = max(-self::ABSOLUTE_MAX, $this->centerZ - $half);
        $maxZ = min(self::ABSOLUTE_MAX, $this->centerZ + $half);
        return min($x - $minX, $maxX - $x, $z - $minZ, $maxZ - $z);
    }

    /** WorldBorder.isWithinBounds: from the minimum edge inclusive to the maximum exclusive. */
    public function contains(float $x, float $z, int $gameTime): bool
    {
        $half = $this->size($gameTime) / 2.0;
        return $x >= max(-self::ABSOLUTE_MAX, $this->centerX - $half) && $x < min(self::ABSOLUTE_MAX, $this->centerX + $half)
            && $z >= max(-self::ABSOLUTE_MAX, $this->centerZ - $half) && $z < min(self::ABSOLUTE_MAX, $this->centerZ + $half);
    }

    /** ClientboundInitializeBorderPacket */
    public function initializePacket(int $gameTime): string
    {
        $moving = $this->remaining($gameTime) > 0;
        return pack('EEEE', $this->centerX, $this->centerZ, $moving ? $this->size($gameTime) : $this->toSize, $this->toSize)
            . Binary::writeVarLong($this->remaining($gameTime)) . Binary::writeVarInt(self::ABSOLUTE_MAX)
            . Binary::writeVarInt($this->warningBlocks) . Binary::writeVarInt($this->warningTime);
    }
}
