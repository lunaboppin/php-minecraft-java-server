<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

use PhpMinecraftJavaServer\World\Generation\Float32;

/** The native local difficulty calculation; callers supply actual inhabited time. */
final readonly class RegionalDifficulty
{
    public float $effective;

    public float $special;

    public function __construct(public int $difficulty, int $dayTime, int $inhabitedTime, float $moon)
    {
        if ($difficulty < 0 || $difficulty > 3) {
            throw new \InvalidArgumentException('Difficulty must be between peaceful and hard');
        }
        if ($difficulty === 0) {
            $this->effective = $this->special = 0.0;
            return;
        }

        $global = Float32::round(Float32::round(Float32::fromLong($dayTime) - 72000.0) / 1440000.0);
        $global = Float32::round(self::clamp($global, 0.0, 1.0) * 0.25);
        $base = Float32::round(0.75 + $global);
        $local = Float32::round(Float32::fromLong($inhabitedTime) / 3600000.0);
        $local = Float32::round(self::clamp($local, 0.0, 1.0) * ($difficulty === 3 ? 1.0 : 0.75));
        $moonBonus = self::clamp(Float32::round(Float32::round($moon) * 0.25), 0.0, $global);
        $local = Float32::round($local + $moonBonus);
        if ($difficulty === 1) {
            $local = Float32::round($local * 0.5);
        }
        $this->effective = Float32::round($difficulty * Float32::round($base + $local));
        $this->special = $this->effective < 2.0 ? 0.0
            : ($this->effective > 4.0 ? 1.0 : Float32::round(Float32::round($this->effective - 2.0) / 2.0));
    }

    /** Mth's float clamp preserves NaN through Math.min. */
    private static function clamp(float $value, float $minimum, float $maximum): float
    {
        return is_nan($value) ? $value : ($value < $minimum ? $minimum : min($value, $maximum));
    }
}
