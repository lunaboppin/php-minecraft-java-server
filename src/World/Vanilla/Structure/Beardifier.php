<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Vanilla\F32;

/**
 * Beardifier: the density structures with terrain adaptation add to the final density, raising
 * ground under their rigid pieces and around jigsaw junctions (beard), or carving room around
 * them (bury, encapsulate).
 */
final class Beardifier
{
    /** @var list<float> BEARD_KERNEL by zi * 576 + xi * 24 + yi */
    private static array $kernel = [];

    /**
     * @param list<array{0: array, 1: string, 2: int}> $rigids box, terrain adjustment, ground level delta
     * @param list<array{0: int, 1: int, 2: int}> $junctions source x, ground y, z
     */
    public function __construct(private readonly array $rigids, private readonly array $junctions, private readonly array $affected)
    {
        if (self::$kernel === []) {
            for ($zi = 0; $zi < 24; ++$zi) {
                for ($xi = 0; $xi < 24; ++$xi) {
                    for ($yi = 0; $yi < 24; ++$yi) {
                        $dy = $yi - 12 + 0.5;
                        $distance = ($xi - 12) ** 2 + $dy * $dy + ($zi - 12) ** 2;
                        self::$kernel[$zi * 576 + $xi * 24 + $yi] = F32::r(pow(M_E, -$distance / 16.0));
                    }
                }
            }
        }
    }

    public function affects(int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ): bool
    {
        return Piece::intersects($this->affected, [$minX, $minY, $minZ, $maxX, $maxY, $maxZ]);
    }

    public function inside(int $x, int $y, int $z): bool
    {
        return Piece::inside($this->affected, $x, $y, $z);
    }

    /** Mth.fastInvSqrt */
    private static function fastInvSqrt(float $x): float
    {
        $half = 0.5 * $x;
        $bits = unpack('q', pack('e', $x))[1];
        $bits = 6910469410427058090 - ($bits >> 1);
        $x = unpack('e', pack('q', $bits))[1];
        return $x * (1.5 - $half * $x * $x);
    }

    private static function lengthSquared(float $x, float $y, float $z): float
    {
        return F32::r(F32::r(F32::r($x * $x) + F32::r($y * $y)) + F32::r($z * $z));
    }

    private static function bury(float $dx, float $dy, float $dz): float
    {
        $distance = self::lengthSquared($dx, $dy, $dz);
        return $distance >= 36.0 ? 0.0 : F32::r(1.0 - F32::r(F32::r(sqrt($distance)) / 6.0));
    }

    private static function beard(int $dx, int $dy, int $dz, int $toGround): float
    {
        $xi = $dx + 12;
        $yi = $dy + 12;
        $zi = $dz + 12;
        if ($xi < 0 || $xi >= 24 || $yi < 0 || $yi >= 24 || $zi < 0 || $zi >= 24) {
            return 0.0;
        }
        $offset = F32::r($toGround + 0.5);
        $distance = self::lengthSquared((float) $dx, $offset, (float) $dz);
        $value = F32::r(F32::r(-$offset * F32::r(self::fastInvSqrt(F32::r($distance / 2.0)))) / 2.0);
        return F32::r($value * self::$kernel[$zi * 576 + $xi * 24 + $yi]);
    }

    /** Beardifier.sampleValueUnchecked */
    public function sample(int $x, int $y, int $z): float
    {
        $value = 0.0;
        foreach ($this->rigids as [$box, $adjustment, $ground]) {
            $dx = max(0, $box[0] - $x, $x - $box[3]);
            $dz = max(0, $box[2] - $z, $z - $box[5]);
            $groundY = $box[1] + $ground;
            $toGround = $y - $groundY;
            $dy = match ($adjustment) {
                'bury', 'beard_thin' => $toGround,
                'beard_box' => max(0, $groundY - $y, $y - $box[4]),
                'encapsulate' => max(0, $box[1] - $y, $y - $box[4]),
                default => 0,
            };
            $value = F32::r($value + match ($adjustment) {
                'bury' => self::bury($dx, F32::r($dy / 2.0), $dz),
                'beard_thin', 'beard_box' => F32::r(self::beard($dx, $dy, $dz, $toGround) * F32::r(0.8)),
                'encapsulate' => F32::r(self::bury(F32::r($dx / 2.0), F32::r($dy / 2.0), F32::r($dz / 2.0)) * F32::r(0.8)),
                default => 0.0,
            });
        }
        foreach ($this->junctions as [$jx, $jy, $jz]) {
            $dy = $y - $jy;
            $value = F32::r($value + F32::r(self::beard($x - $jx, $dy, $z - $jz, $dy) * F32::r(0.4)));
        }
        return $value;
    }
}
