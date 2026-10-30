<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Generation;

/** Explicit rounding at Java float bytecode boundaries; PHP arithmetic otherwise uses binary64. */
final class Float32
{
    /** Round a Java long directly to float, avoiding an intermediate double rounding. */
    public static function fromLong(int $value): float
    {
        if ($value === PHP_INT_MIN) {
            return -9223372036854775808.0;
        }
        $negative = $value < 0;
        $magnitude = abs($value);
        $length = 0;
        for ($remaining = $magnitude; $remaining > 0; $remaining >>= 1) {
            ++$length;
        }
        if ($length <= 24) {
            return (float) $value;
        }
        $shift = $length - 24;
        $significand = $magnitude >> $shift;
        $remainder = $magnitude & ((1 << $shift) - 1);
        $half = 1 << ($shift - 1);
        if ($remainder > $half || ($remainder === $half && ($significand & 1) !== 0)) {
            ++$significand;
        }
        $rounded = $significand * (2.0 ** $shift);
        return $negative ? -$rounded : $rounded;
    }

    public static function round(float $value): float
    {
        return unpack('g', pack('g', $value))[1];
    }

    /** Mth.wrapDegrees(float) */
    public static function wrapDegrees(float $degrees): float
    {
        $wrapped = fmod($degrees, 360.0);
        if ($wrapped >= 180.0) {
            $wrapped = self::round($wrapped - 360.0);
        }
        if ($wrapped < -180.0) {
            $wrapped = self::round($wrapped + 360.0);
        }
        return $wrapped;
    }

    public static function lerp(float $t, float $a, float $b): float
    {
        return self::round($a + self::round($t * self::round($b - $a)));
    }

    public static function smoothstep(float $t): float
    {
        $cube = self::round(self::round($t * $t) * $t);
        $inner = self::round(self::round($t * self::round(self::round($t * 6) - 15)) + 10);
        return self::round($cube * $inner);
    }
}
