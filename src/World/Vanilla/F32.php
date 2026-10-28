<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

/** Java float rounding for single values and whole buffers. */
final class F32
{
    public static function r(float $value): float
    {
        return unpack('g', pack('g', $value))[1];
    }

    /**
     * @param list<float> $values
     * @return list<float>
     */
    public static function all(array $values): array
    {
        return $values === [] ? [] : array_values(unpack('g*', pack('g*', ...$values)));
    }

    public static function lerp(float $t, float $a, float $b): float
    {
        return self::r($a + self::r($t * self::r($b - $a)));
    }

    public static function smoothstep(float $t): float
    {
        return self::r(self::r(self::r($t * $t) * $t) * self::r(self::r($t * self::r(self::r($t * 6.0) - 15.0)) + 10.0));
    }
}
