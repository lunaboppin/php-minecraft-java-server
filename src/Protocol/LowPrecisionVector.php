<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Protocol;

use PhpMinecraftJavaServer\World\Generation\Int64;

/** Protocol-777 LpVec3 encoding, including its unsigned continuation scale. */
final class LowPrecisionVector
{
    private const MAX_COMPONENT = 17179869183.0;

    private const MIN_COMPONENT = 3.051944088384301E-5;

    private const QUANTIZED_MAX = 32766.0;

    public static function encode(float $x, float $y, float $z): string
    {
        $components = array_map(self::sanitize(...), [$x, $y, $z]);
        $maximum = max(array_map(abs(...), $components));
        if ($maximum < self::MIN_COMPONENT) {
            return "\0";
        }

        $scale = (int) ceil($maximum);
        $continuation = $scale > 3;
        $word = ($scale & 3) | ($continuation ? 4 : 0);
        foreach ($components as $axis => $component) {
            $normalized = ($component / $scale * 0.5 + 0.5) * self::QUANTIZED_MAX;
            // Avoid adding 0.5: that addition can round a value immediately
            // below a half-integer upward before floor. Java Math.round does
            // not make that extra rounding step.
            $integer = (int) floor($normalized);
            $quantized = $integer + ($normalized - $integer >= 0.5 ? 1 : 0);
            $word |= $quantized << (3 + $axis * 15);
        }

        $encoded = chr($word & 255) . chr(($word >> 8) & 255) . pack('N', $word >> 16);
        if ($continuation) {
            $encoded .= Binary::writeVarInt(Int64::signed32($scale >> 2));
        }

        return $encoded;
    }

    /** @return array{float, float, float} */
    public static function decode(Binary $input): array
    {
        $first = $input->byte();
        if ($first === 0) {
            return [0.0, 0.0, 0.0];
        }

        $second = $input->byte();
        $word = (($input->int() & 0xffffffff) << 16) | ($second << 8) | $first;
        $scale = $first & 3;
        if (($first & 4) !== 0) {
            $scale |= ($input->varInt() & 0xffffffff) << 2;
        }

        $components = [];
        for ($axis = 0; $axis < 3; ++$axis) {
            $value = min(($word >> (3 + $axis * 15)) & 32767, self::QUANTIZED_MAX);
            $components[] = ($value * 2.0 / self::QUANTIZED_MAX - 1.0) * $scale;
        }

        return $components;
    }

    private static function sanitize(float $value): float
    {
        if (is_nan($value)) {
            return 0.0;
        }

        return max(-self::MAX_COMPONENT, min(self::MAX_COMPONENT, $value));
    }
}
