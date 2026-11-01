<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * DaylightDetectorBlock: every 20 game ticks, in dimensions with sky light, a detector's power
 * follows the sky brightness above it, weighted by the sun's angle, or its complement when
 * inverted; using one flips it between the two.
 */
trait DaylightDetectors
{
    /** @var array<string, array<string, array{int, int, int}>> detector positions by dimension */
    private array $daylightDetectors = [];

    private ?array $mthSine = null;

    /** Keep the detectors known as blocks change. */
    private function daylightDetectorChanged(int $x, int $y, int $z): void
    {
        $key = $x . ':' . $y . ':' . $z;
        if ($this->version->catalog->blockName($this->world->get($x, $y, $z)) === 'daylight_detector') {
            $this->daylightDetectors[$this->world->dimension][$key] = [$x, $y, $z];
        } else {
            unset($this->daylightDetectors[$this->world->dimension][$key]);
        }
    }

    private function restoreDaylightDetectors(): void
    {
        foreach ($this->world->setting('daylightDetectors') ?? [] as $position) {
            $this->daylightDetectors[$this->world->dimension][implode(':', $position)] = $position;
        }
    }

    private function saveDaylightDetectors(): void
    {
        $this->world->setting('daylightDetectors', array_values($this->daylightDetectors[$this->world->dimension] ?? []));
    }

    /** Mth.cos from the 65536-entry sine table. */
    private function mthCos(float $value): float
    {
        $this->mthSine ??= array_values(unpack('G*', (string) file_get_contents($this->version->directory . '/entity-sine.bin')));
        return $this->mthSine[((int) ($value * 10430.378350470453 + 16384.0)) & 65535];
    }

    /**
     * EnvironmentAttributes.SUN_ANGLE in degrees: the day timeline's track from 0 at tick 6000 to
     * 360 a day later, eased by EasingType.CubicBezier(0.362, 0.241, 0.638, 0.759), all in float.
     */
    private function sunAngle(): float
    {
        $f = static fn (float $v): float => Float32::round($v);
        $time = (($this->dayTime % 24000) + 24000) % 24000;
        $from = $time < 6000 ? -18000 : 6000;
        $alpha = $f(($time - $from) / 24000.0);
        $curve = static fn (float $v1, float $v2): array => [$f($f($f(3.0 * $f($v1)) - $f(3.0 * $f($v2))) + 1.0), $f($f(-6.0 * $f($v1)) + $f(3.0 * $f($v2))), $f(3.0 * $f($v1))];
        $sample = static fn (array $c, float $t): float => $f($f($f($f($f($c[0] * $t) + $c[1]) * $t) + $c[2]) * $t);
        $gradient = static fn (array $c, float $t): float => $f($f($f($f($f(3.0 * $c[0]) * $t) + $f(2.0 * $c[1])) * $t) + $c[2]);
        [$xc, $yc] = [$curve(0.362, 0.638), $curve(0.241, 0.759)];
        // solveT: four Newton-Raphson steps of at most a quarter, then bisection.
        $t = $alpha;
        $solved = false;
        for ($i = 0; $i < 4; ++$i) {
            $error = $f($sample($xc, $t) - $alpha);
            if (abs($error) < 1.0E-5) {
                $solved = true;
                break;
            }
            $slope = $gradient($xc, $t);
            if ($slope < 1.0E-5) {
                break;
            }
            $t = $f($t - max(-0.25, min(0.25, $f($error / $slope))));
        }
        if (!$solved) {
            [$t0, $t1] = [0.0, 1.0];
            while ($t0 < $t1) {
                $error = $f($sample($xc, $t) - $alpha);
                if (abs($error) < 1.0E-5) {
                    break;
                }
                if ($error < 0.0) {
                    $t0 = $t;
                } else {
                    $t1 = $t;
                }
                $t = $f($f($t1 + $t0) / 2.0);
            }
        }
        return $f(360.0 * $sample($yc, $t));
    }

    /** DaylightDetectorBlock.updateSignalStrength. */
    private function updateDaylightDetector(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        if ($catalog->blockName($state) !== 'daylight_detector') {
            return;
        }
        $p = $catalog->properties($state);
        // Level.getEffectiveSkyBrightness: sky light less the sky's darkening.
        $target = $this->world->skyLight($x, $y, $z) - $this->skyDarken();
        $angle = Float32::round($this->sunAngle() * Float32::round(M_PI / 180.0));
        if ($p['inverted'] === 'true') {
            $target = 15 - $target;
        } elseif ($target > 0) {
            $offset = $angle < Float32::round(M_PI) ? 0.0 : Float32::round(M_PI * 2.0);
            $angle = Float32::round($angle + Float32::round(Float32::round($offset - $angle) * Float32::round(0.2)));
            // Math.round of a float.
            $target = (int) floor(Float32::round($target * $this->mthCos($angle)) + 0.5);
        }
        $target = max(0, min(15, $target));
        if ((int) $p['power'] !== $target) {
            $this->setBlock($x, $y, $z, $catalog->state('daylight_detector', ['power' => (string) $target] + $p));
        }
    }

    /** DaylightDetectorBlock.tickEntity: every 20 game ticks where the dimension has sky light. */
    private function tickDaylightDetectors(): void
    {
        if ($this->worldAge % 20 !== 0 || $this->world->dimension !== 'overworld') {
            return;
        }
        foreach ($this->daylightDetectors[$this->world->dimension] ?? [] as [$x, $y, $z]) {
            $this->updateDaylightDetector($x, $y, $z);
        }
    }

    /** DaylightDetectorBlock.useWithoutItem: a player who may build flips it. */
    private function useDaylightDetector(Client $c, int $x, int $y, int $z): bool
    {
        if ($c->gameMode === 2 || $c->gameMode === 3) {
            return false;
        }
        $catalog = $this->version->catalog;
        $p = $catalog->properties($this->world->get($x, $y, $z));
        $this->setBlock($x, $y, $z, $catalog->state('daylight_detector', ['inverted' => $p['inverted'] === 'true' ? 'false' : 'true'] + $p));
        $this->updateDaylightDetector($x, $y, $z);
        return true;
    }
}
