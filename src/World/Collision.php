<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

use PhpMinecraftJavaServer\Protocol\Catalog;
use PhpMinecraftJavaServer\World\Generation\Float32;

/** Static block-state voxel shapes exported from the pinned Java server. */
final class Collision
{
    private const EPS = 1.0e-7;

    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function shape(int $state): array
    {
        return $this->catalog->collision['shapes'][$this->catalog->collision['states'][$state]];
    }

    public static function bounds(float $x, float $y, float $z, float $width, float $height): array
    {
        return [$x - $width / 2, $y, $z - $width / 2, $x + $width / 2, $y + $height, $z + $width / 2];
    }

    public function boxes(World $world, array $bounds): array
    {
        $result = [];
        for ($x = (int) floor($bounds[0]) - 1; $x <= (int) floor($bounds[3]) + 1; ++$x) {
            for ($z = (int) floor($bounds[2]) - 1; $z <= (int) floor($bounds[5]) + 1; ++$z) {
                for ($y = max($world->minY, (int) floor($bounds[1]) - 1); $y <= min($world->minY + $world->height - 1, (int) floor($bounds[4]) + 1); ++$y) {
                    foreach ($this->shape($world->get($x, $y, $z)) as $box) {
                        $result[] = [$box[0] + $x, $box[1] + $y, $box[2] + $z, $box[3] + $x, $box[4] + $y, $box[5] + $z];
                    }
                }
            }
        }
        return $result;
    }

    public static function clip(array $box, array $obstacles, int $axis, float $delta): float
    {
        if (abs($delta) < self::EPS) {
            return 0.0;
        }
        foreach ($obstacles as $other) {
            for ($j = 0; $j < 3; ++$j) {
                if ($j !== $axis && ($box[$j + 3] <= $other[$j] + self::EPS || $box[$j] >= $other[$j + 3] - self::EPS)) {
                    continue 2;
                }
            }
            if ($delta > 0 && $box[$axis + 3] <= $other[$axis] + self::EPS) {
                $delta = min($delta, max(0.0, $other[$axis] - $box[$axis + 3]));
            } elseif ($delta < 0 && $box[$axis] >= $other[$axis + 3] - self::EPS) {
                $delta = max($delta, min(0.0, $other[$axis + 3] - $box[$axis]));
            }
        }
        return $delta;
    }

    private static function expand(array $box, array $delta): array
    {
        for ($axis = 0; $axis < 3; ++$axis) {
            $box[$axis] += min(0.0, $delta[$axis]);
            $box[$axis + 3] += max(0.0, $delta[$axis]);
        }
        return $box;
    }

    private static function moveWithBoxes(array $box, array $delta, array $obstacles): array
    {
        $result = [0.0, 0.0, 0.0];
        $axes = abs($delta[0]) < abs($delta[2]) ? [1, 2, 0] : [1, 0, 2];
        foreach ($axes as $axis) {
            $result[$axis] = self::clip($box, $obstacles, $axis, $delta[$axis]);
            $box[$axis] += $result[$axis];
            $box[$axis + 3] += $result[$axis];
        }
        return $result;
    }

    public function move(World $world, array $box, array $delta): array
    {
        return self::moveWithBoxes($box, $delta, $this->boxes($world, self::expand($box, $delta)));
    }

    /** Native candidate-height stepping for static block collisions. */
    public function moveWithStep(World $world, array $box, array $delta, float $stepHeight, bool $onGround): array
    {
        $motion = $this->move($world, $box, $delta);
        $landed = $delta[1] < 0.0 && $motion[1] !== $delta[1];
        if ($stepHeight <= 0.0 || (!$landed && !$onGround)
            || ($motion[0] === $delta[0] && $motion[2] === $delta[2])) {
            return $motion;
        }

        $start = $box;
        if ($landed) {
            $start[1] += $motion[1];
            $start[4] += $motion[1];
        }
        $swept = self::expand($start, [$delta[0], $stepHeight, $delta[2]]);
        if (!$landed) {
            $swept = self::expand($swept, [0.0, -9.999999747378752E-6, 0.0]);
        }
        $obstacles = array_values(array_filter(
            $this->boxes($world, $swept),
            static function (array $obstacle) use ($swept): bool {
                for ($axis = 0; $axis < 3; ++$axis) {
                    if ($obstacle[$axis + 3] <= $swept[$axis] || $obstacle[$axis] >= $swept[$axis + 3]) {
                        return false;
                    }
                }
                return true;
            }
        ));
        $heights = [];
        $oldHeight = Float32::round($motion[1]);
        foreach ($obstacles as $obstacle) {
            foreach ([$obstacle[1], $obstacle[4]] as $edge) {
                $height = Float32::round($edge - $start[1]);
                if ($height >= 0.0 && $height <= $stepHeight && $height !== $oldHeight) {
                    $heights[bin2hex(pack('G', $height))] = $height;
                }
            }
        }
        sort($heights, SORT_NUMERIC);
        $distance = $motion[0] * $motion[0] + $motion[2] * $motion[2];
        foreach ($heights as $height) {
            $candidate = self::moveWithBoxes($start, [$delta[0], $height, $delta[2]], $obstacles);
            if ($candidate[0] * $candidate[0] + $candidate[2] * $candidate[2] > $distance) {
                $candidate[1] -= $box[1] - $start[1];
                return $candidate;
            }
        }
        return $motion;
    }

    public function eggHeight(World $world, int $x, int $y, int $z, float $width, float $height, bool $below): float
    {
        $box = self::bounds($x + 0.5, $y + 1.0, $z + 0.5, $width, $height);
        $obstacles = $this->boxes($world, [$x, $y - ($below ? 1 : 0), $z, $x + 1, $y + 1, $z + 1]);
        return $y + 1.0 + self::clip($box, $obstacles, 1, $below ? -2.0 : -1.0);
    }

    private static function rayBox(array $start, array $direction, array $box, float $reach): ?float
    {
        $near = 0.0;
        $far = $reach;
        for ($axis = 0; $axis < 3; ++$axis) {
            if (abs($direction[$axis]) < 1e-12) {
                if ($start[$axis] < $box[$axis] || $start[$axis] > $box[$axis + 3]) {
                    return null;
                }continue;
            }
            $a = ($box[$axis] - $start[$axis]) / $direction[$axis];
            $b = ($box[$axis + 3] - $start[$axis]) / $direction[$axis];
            $near = max($near, min($a, $b));
            $far = min($far, max($a, $b));
            if ($near > $far) {
                return null;
            }
        }
        return $near;
    }

    /** The spawn egg's POV ray uses outline shapes and source fluids, unlike movement collision. */
    public function sourceRay(World $world, array $start, array $direction, float $reach): ?array
    {
        $end = [];
        for ($i = 0; $i < 3; ++$i) {
            $end[$i] = $start[$i] + $direction[$i] * $reach;
        }$best = null;
        for ($x = (int) floor(min($start[0], $end[0])) - 1; $x <= (int) floor(max($start[0], $end[0])) + 1; ++$x) {
            for ($z = (int) floor(min($start[2], $end[2])) - 1; $z <= (int) floor(max($start[2], $end[2])) + 1; ++$z) {
                for ($y = max($world->minY, (int) floor(min($start[1], $end[1])) - 1); $y <= min($world->minY + $world->height - 1, (int) floor(max($start[1], $end[1])) + 1); ++$y) {
                    $state = $world->get($x, $y, $z);
                    $name = $this->catalog->blockName($state);
                    $properties = $this->catalog->properties($state);
                    $candidates = [];
                    foreach ($this->catalog->collision['shapes'][$this->catalog->collision['outline_states'][$state]] as $box) {
                        $candidates[] = [$box, false];
                    }
                    $liquid = in_array($name, ['water', 'lava'], true);
                    $waterlogged = ($properties['waterlogged'] ?? 'false') === 'true';
                    if (($liquid && ($properties['level'] ?? '0') === '0') || $waterlogged) {
                        $aboveState = $world->get($x, $y + 1, $z);
                        $above = $this->catalog->blockName($aboveState);
                        $same = $above === ($waterlogged ? 'water' : $name);
                        if (($waterlogged || $name === 'water') && ($this->catalog->properties($aboveState)['waterlogged'] ?? 'false') === 'true') {
                            $same = true;
                        }
                        $candidates[] = [[0, 0, 0, 1, $same ? 1 : 8 / 9, 1], true];
                    }
                    foreach ($candidates as [$box,$fluid]) {
                        $translated = [$box[0] + $x, $box[1] + $y, $box[2] + $z, $box[3] + $x, $box[4] + $y, $box[5] + $z];
                        $distance = self::rayBox($start, $direction, $translated, $reach);
                        if ($distance !== null && ($best === null || $distance < $best['distance'] - 1e-9 || (abs($distance - $best['distance']) < 1e-9 && !$fluid))) {
                            $best = ['position' => [$x, $y, $z], 'distance' => $distance, 'fluid' => $fluid];
                        }
                    }
                }
            }
        }
        return $best;
    }
}
