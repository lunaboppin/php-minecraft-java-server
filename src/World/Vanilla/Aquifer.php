<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

use PhpMinecraftJavaServer\World\Generation\PositionalRandom;

/**
 * Aquifer.NoiseBasedAquifer: local water and lava levels on a jittered 16x12x16 grid, with
 * barriers between neighbouring levels. Substances are 'water', 'lava', 'air', or null for the
 * default block.
 */
final class Aquifer
{
    private const WAY_BELOW_MIN_Y = -32512;
    private const SURFACE_OFFSETS = [[0, 0], [-2, -1], [-1, -1], [0, -1], [1, -1], [-3, 0], [-2, 0], [-1, 0], [1, 0], [-2, 1], [-1, 1], [0, 1], [1, 1]];

    private readonly float $flowingSimilarity;

    private readonly int $minGridX;

    private readonly int $minGridY;

    private readonly int $minGridZ;

    private readonly int $gridSizeX;

    private readonly int $gridSizeZ;

    private readonly int $skipSamplingAboveY;

    /** @var array<int, array{0: int, 1: string}> */
    private array $statusCache = [];

    /** @var array<int, array{0: int, 1: int, 2: int}> */
    private array $locationCache = [];

    /** @var array<string, int> */
    private array $surfaceCache = [];

    public bool $shouldScheduleFluidUpdate = false;

    public function __construct(
        private readonly SamplerContext $context,
        private readonly Sampler $barrier,
        private readonly Sampler $floodedness,
        private readonly Sampler $spread,
        private readonly Sampler $lava,
        private readonly Sampler $exclusion,
        private readonly Sampler $surfaceLevel,
        private readonly PositionalRandom $random,
        Volume $volume,
        private readonly int $seaLevel,
    ) {
        $this->flowingSimilarity = self::similarity(100, 144);
        $this->minGridX = (($volume->minX - 5) >> 4);
        $maxGridX = (($volume->maxX() - 5) >> 4) + 1;
        $this->gridSizeX = $maxGridX - $this->minGridX + 1;
        $this->minGridY = self::gridY($volume->minY + 1) - 1;
        $this->minGridZ = (($volume->minZ - 5) >> 4);
        $maxGridZ = (($volume->maxZ() - 5) >> 4) + 1;
        $this->gridSizeZ = $maxGridZ - $this->minGridZ + 1;
        $maxSurface = $this->maxSurfaceLevel($this->minGridX << 4, $this->minGridZ << 4, ($maxGridX << 4) + 9, ($maxGridZ << 4) + 9) + 8;
        $this->skipSamplingAboveY = (self::gridY($maxSurface + 12) + 1) * 12 + 11 - 1;
    }

    private static function gridY(int $y): int
    {
        return intdiv($y - ((($y % 12) + 12) % 12), 12);
    }

    private static function floorDiv(int $a, int $b): int
    {
        return intdiv($a - ((($a % $b) + $b) % $b), $b);
    }

    private static function similarity(int $first, int $second): float
    {
        return 1.0 - ($second - $first) / 25.0;
    }

    /** NoiseBasedChunkGenerator.createFluidPicker for the Overworld. */
    private function globalFluid(int $y): array
    {
        return $y < min(-54, $this->seaLevel) ? [-54, 'lava'] : [$this->seaLevel, 'water'];
    }

    private static function at(array $status, int $y): string
    {
        return $y < $status[0] ? $status[1] : 'air';
    }

    private function surface(int $x, int $z): int
    {
        $qx = $x & ~3;
        $qz = $z & ~3;
        return $this->surfaceCache["$qx,$qz"] ??= (int) floor($this->surfaceLevel->value($this->context, $qx, 0, $qz));
    }

    private function maxSurfaceLevel(int $minX, int $minZ, int $maxX, int $maxZ): int
    {
        $minQx = $minX >> 2;
        $minQz = $minZ >> 2;
        $volume = new Volume(($maxX >> 2) - $minQx + 1, 1, ($maxZ >> 2) - $minQz + 1, $minQx << 2, 0, $minQz << 2, 4, 1, 4);
        $buffer = $this->surfaceLevel->volume($this->context, $volume);
        $max = PHP_INT_MIN;
        for ($z = 0; $z < $volume->sizeZ; ++$z) {
            for ($x = 0; $x < $volume->sizeX; ++$x) {
                $level = (int) floor($buffer[$volume->index($x, 0, $z)]);
                $this->surfaceCache[$volume->blockX($x) . ',' . $volume->blockZ($z)] = $level;
                $max = max($max, $level);
            }
        }
        return $max;
    }

    public function computeSubstance(int $x, int $y, int $z, float $density): ?string
    {
        if ($density > 0.0) {
            $this->shouldScheduleFluidUpdate = false;
            return null;
        }
        $global = $this->globalFluid($y);
        if ($y > $this->skipSamplingAboveY) {
            $this->shouldScheduleFluidUpdate = false;
            return self::at($global, $y);
        }
        if (self::at($global, $y) === 'lava') {
            $this->shouldScheduleFluidUpdate = false;
            return 'lava';
        }
        $ax = ($x - 5) >> 4;
        $ay = self::gridY($y + 1);
        $az = ($z - 5) >> 4;
        $d = [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX];
        $c = [0, 0, 0, 0];
        for ($x1 = 0; $x1 <= 1; ++$x1) {
            for ($y1 = -1; $y1 <= 1; ++$y1) {
                for ($z1 = 0; $z1 <= 1; ++$z1) {
                    $gx = $ax + $x1;
                    $gy = $ay + $y1;
                    $gz = $az + $z1;
                    $index = (($gy - $this->minGridY) * $this->gridSizeZ + ($gz - $this->minGridZ)) * $this->gridSizeX + ($gx - $this->minGridX);
                    if (!isset($this->locationCache[$index])) {
                        $random = $this->random->at($gx, $gy, $gz);
                        $lx = ($gx << 4) + $random->nextInt(10);
                        $ly = $gy * 12 + $random->nextInt(9);
                        $this->locationCache[$index] = [$lx, $ly, ($gz << 4) + $random->nextInt(10)];
                    }
                    [$lx, $ly, $lz] = $this->locationCache[$index];
                    $dx = $lx - $x;
                    $dy = $ly - $y;
                    $dz = $lz - $z;
                    $distance = $dx * $dx + $dy * $dy + $dz * $dz;
                    if ($d[0] >= $distance) {
                        $c = [$index, $c[0], $c[1], $c[2]];
                        $d = [$distance, $d[0], $d[1], $d[2]];
                    } elseif ($d[1] >= $distance) {
                        $c = [$c[0], $index, $c[1], $c[2]];
                        $d = [$d[0], $distance, $d[1], $d[2]];
                    } elseif ($d[2] >= $distance) {
                        $c = [$c[0], $c[1], $index, $c[2]];
                        $d = [$d[0], $d[1], $distance, $d[2]];
                    } elseif ($d[3] >= $distance) {
                        $c[3] = $index;
                        $d[3] = $distance;
                    }
                }
            }
        }
        $status1 = $this->status($c[0]);
        $similarity12 = self::similarity($d[0], $d[1]);
        $fluid = self::at($status1, $y);
        if ($similarity12 <= 0.0) {
            $this->shouldScheduleFluidUpdate = $similarity12 >= $this->flowingSimilarity && $status1 !== $this->status($c[1]);
            return $fluid;
        }
        if ($fluid === 'water' && self::at($this->globalFluid($y - 1), $y - 1) === 'lava') {
            $this->shouldScheduleFluidUpdate = true;
            return $fluid;
        }
        $barrierNoise = NAN;
        $status2 = $this->status($c[1]);
        if ($density + $similarity12 * $this->pressure($x, $y, $z, $barrierNoise, $status1, $status2) > 0.0) {
            $this->shouldScheduleFluidUpdate = false;
            return null;
        }
        $status3 = $this->status($c[2]);
        $similarity13 = self::similarity($d[0], $d[2]);
        if ($similarity13 > 0.0 && $density + $similarity12 * $similarity13 * $this->pressure($x, $y, $z, $barrierNoise, $status1, $status3) > 0.0) {
            $this->shouldScheduleFluidUpdate = false;
            return null;
        }
        $similarity23 = self::similarity($d[1], $d[2]);
        if ($similarity23 > 0.0 && $density + $similarity12 * $similarity23 * $this->pressure($x, $y, $z, $barrierNoise, $status2, $status3) > 0.0) {
            $this->shouldScheduleFluidUpdate = false;
            return null;
        }
        $mayFlow12 = $status1 !== $status2;
        $mayFlow23 = $similarity23 >= $this->flowingSimilarity && $status2 !== $status3;
        $mayFlow13 = $similarity13 >= $this->flowingSimilarity && $status1 !== $status3;
        if (!$mayFlow12 && !$mayFlow23 && !$mayFlow13) {
            $this->shouldScheduleFluidUpdate = $similarity13 >= $this->flowingSimilarity
                && self::similarity($d[0], $d[3]) >= $this->flowingSimilarity
                && $status1 !== $this->status($c[3]);
        } else {
            $this->shouldScheduleFluidUpdate = true;
        }
        return $fluid;
    }

    private function pressure(int $x, int $y, int $z, float &$barrierNoise, array $first, array $second): float
    {
        $type1 = self::at($first, $y);
        $type2 = self::at($second, $y);
        if (($type1 === 'lava' && $type2 === 'water') || ($type1 === 'water' && $type2 === 'lava')) {
            return 2.0;
        }
        $difference = abs($first[0] - $second[0]);
        if ($difference === 0) {
            return 0.0;
        }
        $average = 0.5 * ($first[0] + $second[0]);
        $above = $y + 0.5 - $average;
        $towardsMiddle = $difference / 2.0 - abs($above);
        if ($above > 0.0) {
            $center = 0.0 + $towardsMiddle;
            $gradient = $center > 0.0 ? $center / 1.5 : $center / 2.5;
        } else {
            $center = 3.0 + $towardsMiddle;
            $gradient = $center > 0.0 ? $center / 3.0 : $center / 10.0;
        }
        $noise = 0.0;
        if (!($gradient < -2.0) && !($gradient > 2.0)) {
            if (is_nan($barrierNoise)) {
                $barrierNoise = $this->barrier->value($this->context, $x, $y, $z);
            }
            $noise = $barrierNoise;
        }
        return 2.0 * ($noise + $gradient);
    }

    private function status(int $index): array
    {
        if (!isset($this->statusCache[$index])) {
            [$x, $y, $z] = $this->locationCache[$index];
            $this->statusCache[$index] = $this->computeFluid($x, $y, $z);
        }
        return $this->statusCache[$index];
    }

    private function computeFluid(int $x, int $y, int $z): array
    {
        $global = $this->globalFluid($y);
        $lowest = PHP_INT_MAX;
        $top = $y + 12;
        $bottom = $y - 12;
        $centerUnderFluid = false;
        foreach (self::SURFACE_OFFSETS as [$ox, $oz]) {
            $sx = $x + ($ox << 4);
            $sz = $z + ($oz << 4);
            $surface = $this->surface($sx, $sz);
            $adjusted = $surface + 8;
            $start = $ox === 0 && $oz === 0;
            if ($start && $bottom > $adjusted) {
                return $global;
            }
            $pokesAbove = $top > $adjusted;
            if ($pokesAbove || $start) {
                $atSurface = $this->globalFluid($adjusted);
                if (self::at($atSurface, $adjusted) !== 'air') {
                    if ($start) {
                        $centerUnderFluid = true;
                    }
                    if ($pokesAbove) {
                        return $atSurface;
                    }
                }
            }
            $lowest = min($lowest, $surface);
        }
        $level = $this->surfaceLevelFor($x, $y, $z, $global, $lowest, $centerUnderFluid);
        return [$level, $this->fluidType($x, $y, $z, $global, $level)];
    }

    private function surfaceLevelFor(int $x, int $y, int $z, array $global, int $lowest, bool $centerUnderFluid): int
    {
        if ($this->exclusion->value($this->context, $x, $y, $z) > 0.0) {
            $partially = -1.0;
            $fully = -1.0;
        } else {
            $below = $lowest + 8 - $y;
            $factor = 0.0;
            if ($centerUnderFluid) {
                // Mth.clampedMap(distanceBelowSurface, 0, 64, 1, 0)
                $t = ($below - 0.0) / 64.0;
                $factor = $t < 0.0 ? 1.0 : ($t > 1.0 ? 0.0 : 1.0 + $t * (0.0 - 1.0));
            }
            $noise = min(max($this->floodedness->value($this->context, $x, $y, $z), -1.0), 1.0);
            $t = ($factor - 1.0) / (0.0 - 1.0);
            $fullyThreshold = -0.3 + $t * (0.8 - -0.3);
            $partiallyThreshold = -0.8 + $t * (0.4 - -0.8);
            $partially = $noise - $partiallyThreshold;
            $fully = $noise - $fullyThreshold;
        }
        if ($fully > 0.0) {
            return $global[0];
        }
        if ($partially > 0.0) {
            $cellY = self::floorDiv($y, 40);
            // A float sample times 10.0F, widened to double.
            $spread = F32::r($this->spread->value($this->context, self::floorDiv($x, 16), $cellY, self::floorDiv($z, 16)) * 10.0);
            $quantized = (int) floor($spread / 3) * 3;
            return min($lowest, $cellY * 40 + 20 + $quantized);
        }
        return self::WAY_BELOW_MIN_Y;
    }

    private function fluidType(int $x, int $y, int $z, array $global, int $level): string
    {
        if ($level <= -10 && $level !== self::WAY_BELOW_MIN_Y && $global[1] !== 'lava') {
            $noise = $this->lava->value($this->context, self::floorDiv($x, 64), self::floorDiv($y, 40), self::floorDiv($z, 64));
            if (abs($noise) > 0.3) {
                return 'lava';
            }
        }
        return $global[1];
    }
}
