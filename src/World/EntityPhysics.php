<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Generation\RandomSource;

/** Shared movement arithmetic checked against actual Java 26.3 entity methods. */
final class EntityPhysics
{
    public function __construct(private readonly string $sineTable)
    {
        if (strlen($sineTable) !== 65536 * 4) {
            throw new \InvalidArgumentException('Invalid entity sine table');
        }
    }

    public static function modifiedFriction(float $base, float $modifier): float
    {
        $difference = Float32::round(1.0 - Float32::round($base));
        $adjustment = Float32::round($difference * Float32::round($modifier));
        $result = Float32::round(1.0 - $adjustment);

        return is_nan($result) ? $result : max(0.0, min(1.0, $result));
    }

    public static function movementAcceleration(
        float $speed,
        float $friction,
        bool $ground,
        float $airSpeed = 0.02
    ): float {
        if (!$ground) {
            return Float32::round($airSpeed);
        }

        $speed = Float32::round($speed);
        $friction = Float32::round($friction);
        if ($friction <= 0.6) {
            return $speed;
        }

        $cube = Float32::round(Float32::round($friction * $friction) * $friction);
        $factor = Float32::round(Float32::round(0.21600002) / $cube);

        return Float32::round($speed * $factor);
    }

    /** @param array{float, float, float} $input
     *  @return array{float, float, float}
     */
    public function inputVector(array $input, float $speed, float $yaw): array
    {
        [$x, $y, $z] = $input;
        $lengthSquared = $x * $x + $y * $y + $z * $z;
        if ($lengthSquared < 1.0E-7) {
            return [0.0, 0.0, 0.0];
        }

        if ($lengthSquared > 1.0) {
            $length = sqrt($lengthSquared);
            $x /= $length;
            $y /= $length;
            $z /= $length;
        }

        $speed = Float32::round($speed);
        $x *= $speed;
        $y *= $speed;
        $z *= $speed;

        $radians = Float32::round(Float32::round($yaw) * Float32::round(0.017453292));
        $sine = $this->trigonometricValue($radians, false);
        $cosine = $this->trigonometricValue($radians, true);

        return [$x * $cosine - $z * $sine, $y, $z * $cosine + $x * $sine];
    }

    /**
     * @param array{float, float, float} $velocity
     * @return array{velocity: array{float, float, float}, synchronize: bool}
     */
    public static function knockback(
        array $velocity,
        float $strength,
        float $resistance,
        bool $ground,
        float $directionX,
        float $directionZ,
        RandomSource $random
    ): array {
        $strength *= 1.0 - $resistance;
        if ($strength <= 0.0) {
            return ['velocity' => $velocity, 'synchronize' => false];
        }

        while ($directionX * $directionX + $directionZ * $directionZ < 9.999999747378752E-6) {
            $directionX = ($random->nextDouble() - $random->nextDouble()) * 0.01;
            $directionZ = ($random->nextDouble() - $random->nextDouble()) * 0.01;
        }

        $length = sqrt($directionX * $directionX + $directionZ * $directionZ);
        $forceX = $directionX / $length * $strength;
        $forceZ = $directionZ / $length * $strength;
        $vertical = $ground ? min(0.4, $velocity[1] / 2.0 + $strength) : $velocity[1];

        return [
            'velocity' => [$velocity[0] / 2.0 - $forceX, $vertical, $velocity[2] / 2.0 - $forceZ],
            'synchronize' => true,
        ];
    }

    private function trigonometricValue(float $radians, bool $cosine): float
    {
        $position = $radians * 10430.378350470453;
        if ($cosine) {
            $position += 16384.0;
        }

        // Mth uses a saturating Java double-to-long cast before masking. PHP
        // integer casts overflow differently, so handle its boundaries first.
        if (is_nan($position)) {
            $index = 0;
        } elseif ($position >= PHP_INT_MAX) {
            $index = 65535;
        } elseif ($position <= PHP_INT_MIN) {
            $index = 0;
        } else {
            $index = ((int) $position) & 65535;
        }

        return unpack('G', substr($this->sineTable, $index * 4, 4))[1];
    }
}
