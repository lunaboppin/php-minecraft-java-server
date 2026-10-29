<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

/** Java float rounding for single values and whole buffers. */
final class F32
{
    /**
     * Round to the nearest float, ties to even. Normal values use a Veltkamp split, which
     * rounds to nearest; a second split to 25 bits finds exact ties, which go through pack.
     */
    public static function r(float $value): float
    {
        $magnitude = $value < 0.0 ? -$value : $value;
        if ($magnitude >= 1.1754943508222875e-38 && $magnitude < 3.4028234663852886e38) {
            $split = $value * 536870913.0;
            $rounded = $split - ($split - $value);
            if ($rounded === $value) {
                return $value;
            }
            $split = $value * 268435457.0;
            if ($split - ($split - $value) !== $value) {
                return $rounded;
            }
        } elseif ($value === 0.0) {
            return $value;
        }
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
