<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Noise;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Volume;

/**
 * synth.PerlinNoise. The volume path sums each corner's gradient terms in a different order
 * from a single sample, so the two give different float bits; both follow vanilla exactly.
 */
class Perlin implements Noise
{
    protected const GRADIENTS = [
        [1, 1, 0], [-1, 1, 0], [1, -1, 0], [-1, -1, 0], [1, 0, 1], [-1, 0, 1], [1, 0, -1], [-1, 0, -1],
        [0, 1, 1], [0, -1, 1], [0, 1, -1], [0, -1, -1], [1, 1, 0], [0, -1, 1], [-1, 1, 0], [0, -1, -1],
    ];

    /** @var list<int> */
    protected array $permutations;

    protected float $offsetX;

    protected float $offsetY;

    protected float $offsetZ;

    public function __construct(RandomSource $random)
    {
        $this->offsetX = $random->nextDouble() * 256.0;
        $this->offsetY = $random->nextDouble() * 256.0;
        $this->offsetZ = $random->nextDouble() * 256.0;
        $this->permutations = range(0, 255);
        for ($i = 0; $i < 256; ++$i) {
            $j = $i + $random->nextInt(256 - $i);
            [$this->permutations[$i], $this->permutations[$j]] = [$this->permutations[$j], $this->permutations[$i]];
        }
    }

    public static function wrap(float $value): float
    {
        return $value >= -16777215.999999998 && $value < 16777215.999999998 ? $value : $value - floor($value / 33554432.0 + 0.5) * 33554432.0;
    }

    protected function permute(int $value): int
    {
        return $this->permutations[$value & 255];
    }

    public function get(float $x, float $y, float $z): float
    {
        $x = self::wrap($x) + $this->offsetX;
        $y = self::wrap($y) + $this->offsetY;
        $z = self::wrap($z) + $this->offsetZ;
        $fx = (int) floor($x);
        $fy = (int) floor($y);
        $fz = (int) floor($z);
        $ry = F32::r($y - $fy);
        return $this->sampleAndLerp($fx, $fy, $fz, F32::r($x - $fx), $ry, F32::r($z - $fz), $ry);
    }

    private static function dot(int $hash, float $x, float $y, float $z): float
    {
        $g = self::GRADIENTS[$hash & 15];
        return F32::r(F32::r($g[0] * $x + $g[1] * $y) + $g[2] * $z);
    }

    protected function sampleAndLerp(int $ix, int $iy, int $iz, float $x, float $y, float $z, float $lerpY): float
    {
        $mx = F32::r($x - 1.0);
        $my = F32::r($y - 1.0);
        $mz = F32::r($z - 1.0);
        $a = $this->permute($ix);
        $b = $this->permute($ix + 1);
        $aa = $this->permute($a + $iy);
        $ab = $this->permute($a + $iy + 1);
        $ba = $this->permute($b + $iy);
        $bb = $this->permute($b + $iy + 1);
        $d000 = self::dot($this->permute($aa + $iz), $x, $y, $z);
        $d100 = self::dot($this->permute($ba + $iz), $mx, $y, $z);
        $d010 = self::dot($this->permute($ab + $iz), $x, $my, $z);
        $d110 = self::dot($this->permute($bb + $iz), $mx, $my, $z);
        $d001 = self::dot($this->permute($aa + $iz + 1), $x, $y, $mz);
        $d101 = self::dot($this->permute($ba + $iz + 1), $mx, $y, $mz);
        $d011 = self::dot($this->permute($ab + $iz + 1), $x, $my, $mz);
        $d111 = self::dot($this->permute($bb + $iz + 1), $mx, $my, $mz);
        return self::lerp3(F32::smoothstep($x), F32::smoothstep($lerpY), F32::smoothstep($z), $d000, $d100, $d010, $d110, $d001, $d101, $d011, $d111);
    }

    /** Mth.lerp3 */
    protected static function lerp3(float $ax, float $ay, float $az, float $d000, float $d100, float $d010, float $d110, float $d001, float $d101, float $d011, float $d111): float
    {
        return F32::lerp(
            $az,
            F32::lerp($ay, F32::lerp($ax, $d000, $d100), F32::lerp($ax, $d010, $d110)),
            F32::lerp($ay, F32::lerp($ax, $d001, $d101), F32::lerp($ax, $d011, $d111)),
        );
    }

    public function addToVolume(array &$buffer, Volume $volume, float $xzScale, float $yScale, float $amplitude): void
    {
        $index = 0;
        for ($iz = 0; $iz < $volume->sizeZ; ++$iz) {
            $z = self::wrap($volume->blockZ($iz) * $xzScale) + $this->offsetZ;
            $fz = (int) floor($z);
            $rz = F32::r($z - $fz);
            $rz1 = F32::r($rz - 1.0);
            $az = F32::smoothstep($rz);
            for ($ix = 0; $ix < $volume->sizeX; ++$ix) {
                $x = self::wrap($volume->blockX($ix) * $xzScale) + $this->offsetX;
                $fx = (int) floor($x);
                $rx = F32::r($x - $fx);
                $rx1 = F32::r($rx - 1.0);
                $x0 = $this->permute($fx);
                $x1 = $this->permute($fx + 1);
                $ax = F32::smoothstep($rx);
                $lastY = null;
                $corners = [];
                for ($iy = 0; $iy < $volume->sizeY; ++$iy) {
                    $y = self::wrap($volume->blockY($iy) * $yScale) + $this->offsetY;
                    $fy = (int) floor($y);
                    $ry = $this->relativeY($volume->blockY($iy) * $yScale, $y, $fy);
                    $ay = F32::smoothstep($this->alphaY($y, $fy, $ry));
                    if ($lastY !== $fy) {
                        $corners = $this->corners($x0, $x1, $fy, $fz, $rx, $rx1, $rz, $rz1);
                        $lastY = $fy;
                    }
                    $ry1 = F32::r($ry - 1.0);
                    $value = self::lerp3(
                        $ax,
                        $ay,
                        $az,
                        F32::r($corners[0] + $corners[1] * $ry),
                        F32::r($corners[2] + $corners[3] * $ry),
                        F32::r($corners[4] + $corners[5] * $ry1),
                        F32::r($corners[6] + $corners[7] * $ry1),
                        F32::r($corners[8] + $corners[9] * $ry),
                        F32::r($corners[10] + $corners[11] * $ry),
                        F32::r($corners[12] + $corners[13] * $ry1),
                        F32::r($corners[14] + $corners[15] * $ry1),
                    );
                    $buffer[$index] = F32::r($buffer[$index] + F32::r($amplitude * $value));
                    ++$index;
                }
            }
        }
    }

    /** The relative Y used by the gradient terms; smeared noise lowers it. */
    protected function relativeY(float $originalY, float $y, int $floorY): float
    {
        return F32::r($y - $floorY);
    }

    /** The relative Y used by the smoothstep. */
    protected function alphaY(float $y, int $floorY, float $relativeY): float
    {
        return $relativeY;
    }

    /**
     * Each corner's gradient X/Z dot product and Y gradient, in d000, d100, d010, d110, d001,
     * d101, d011, d111 order.
     *
     * @return list<float>
     */
    private function corners(int $x0, int $x1, int $fy, int $fz, float $rx, float $rx1, float $rz, float $rz1): array
    {
        $xy00 = $this->permute($x0 + $fy);
        $xy01 = $this->permute($x0 + $fy + 1);
        $xy10 = $this->permute($x1 + $fy);
        $xy11 = $this->permute($x1 + $fy + 1);
        $out = [];
        foreach ([[$xy00, 0, $rx, $rz], [$xy10, 0, $rx1, $rz], [$xy01, 0, $rx, $rz], [$xy11, 0, $rx1, $rz], [$xy00, 1, $rx, $rz1], [$xy10, 1, $rx1, $rz1], [$xy01, 1, $rx, $rz1], [$xy11, 1, $rx1, $rz1]] as [$xy, $dz, $px, $pz]) {
            $g = self::GRADIENTS[$this->permute($xy + $fz + $dz) & 15];
            $out[] = F32::r($g[0] * $px + $g[2] * $pz);
            $out[] = (float) $g[1];
        }
        return $out;
    }
}
