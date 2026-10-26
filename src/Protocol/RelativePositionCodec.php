<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Protocol;

use PhpMinecraftJavaServer\World\Generation\Int64;

/** Delta components use separately rounded absolute coordinates, not rounded differences. */
final class RelativePositionCodec
{
    public function __construct(public array $base)
    {
    }

    public static function coordinate(float $value): int
    {
        $scaled = $value * 4096.0;
        if (is_nan($scaled)) {
            return 0;
        }
        if ($scaled >= PHP_INT_MAX) {
            return PHP_INT_MAX;
        }
        if ($scaled <= PHP_INT_MIN) {
            return PHP_INT_MIN;
        }
        $floor = floor($scaled);
        return (int) ($scaled - $floor >= 0.5 ? $floor + 1.0 : $floor);
    }

    /** @return array{count: int, bytes: string}|null */
    public function encode(PositionPath $path): ?array
    {
        $base = $this->base;
        $bytes = '';
        $steps = $path->steps ?? [['position' => $path->end, 'ticks' => 0]];
        foreach ($steps as $step) {
            $delta = [];
            for ($axis = 0; $axis < 3; ++$axis) {
                $delta[$axis] = Int64::add(self::coordinate($step['position'][$axis]), Int64::add(~self::coordinate($base[$axis]), 1));
                if ($delta[$axis] < -32768 || $delta[$axis] > 32767) {
                    return null;
                }
            }
            if ($path->steps !== null) {
                $bytes .= Binary::writeVarInt($step['ticks']);
            }
            $bytes .= pack('nnn', ...$delta);
            $base = $step['position'];
        }
        return ['count' => $path->steps === null ? 0 : count($steps), 'bytes' => $bytes];
    }
}
