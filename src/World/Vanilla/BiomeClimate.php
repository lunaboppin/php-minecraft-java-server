<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

use PhpMinecraftJavaServer\World\Generation\Int64;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\SimplexNoise2D;

/**
 * Biome climate settings and the position-dependent temperature of Biome.getTemperature,
 * with BiomeManager's zoomed biome lookup.
 */
final class BiomeClimate
{
    /** @var array<string, array{0: float, 1: string}> */
    private array $climates = [];

    private readonly SimplexNoise2D $temperatureNoise;

    /** @var list<array{0: SimplexNoise2D, 1: float, 2: float}> */
    private readonly array $frozenNoise;

    private readonly SimplexNoise2D $infoNoise;

    public function __construct(private readonly string $directory)
    {
        $this->temperatureNoise = new SimplexNoise2D(new LegacyRandom(1234));
        $random = new LegacyRandom(3456);
        $this->frozenNoise = [
            [new SimplexNoise2D($random), 1.0, F32::r(0.14285715)],
            [new SimplexNoise2D($random), 0.5, F32::r(0.2857143)],
            [new SimplexNoise2D($random), 0.25, F32::r(0.5714286)],
        ];
        $this->infoNoise = new SimplexNoise2D(new LegacyRandom(2345));
    }

    /** @return array{0: float, 1: string} the base temperature and temperature modifier */
    public function climate(string $biome): array
    {
        if (!isset($this->climates[$biome])) {
            $json = json_decode((string) file_get_contents($this->directory . '/biome/' . $biome . '.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->climates[$biome] = [F32::r((float) $json['temperature']), $json['temperature_modifier'] ?? 'none'];
        }
        return $this->climates[$biome];
    }

    /** Biome.getHeightAdjustedTemperature */
    public function temperature(string $biome, int $x, int $y, int $z, int $seaLevel): float
    {
        [$base, $modifier] = $this->climate($biome);
        $temperature = $modifier === 'frozen' ? $this->frozenTemperature($x, $z, $base) : $base;
        $snowLevel = $seaLevel + 17;
        if ($y <= $snowLevel) {
            return $temperature;
        }
        $v = F32::r($this->temperatureNoise->get(F32::r($x / 8.0), F32::r($z / 8.0)) * 8.0);
        return F32::r($temperature - F32::r(F32::r(F32::r(F32::r($v + $y) - $snowLevel) * F32::r(0.05)) / 40.0));
    }

    private function frozenTemperature(int $x, int $z, float $base): float
    {
        $large = 0.0;
        foreach ($this->frozenNoise as [$noise, $frequency, $amplitude]) {
            $large = F32::r($large + F32::r($amplitude * $noise->get($x * 0.05 * $frequency, $z * 0.05 * $frequency)));
        }
        $patches = F32::r($large * 7.0) + $this->infoNoise->get($x * 0.2, $z * 0.2);
        if ($patches < 0.3 && $this->infoNoise->get($x * 0.09, $z * 0.09) < 0.8) {
            return F32::r(0.2);
        }
        return $base;
    }

    public function coldEnoughToSnow(string $biome, int $x, int $y, int $z, int $seaLevel): bool
    {
        return !($this->temperature($biome, $x, $y, $z, $seaLevel) >= F32::r(0.15));
    }

    /** BiomeManager.obfuscateSeed */
    public static function zoomSeed(int $seed): int
    {
        return unpack('P', substr(hash('sha256', pack('P', $seed), true), 0, 8))[1];
    }

    private static function lcg(int $value, int $add): int
    {
        // LinearCongruentialGenerator.next
        return Int64::add(Int64::multiply($value, Int64::add(Int64::multiply($value, 6364136223846793005), 1442695040888963407)), $add);
    }

    private static function fiddle(int $value): float
    {
        return ((((($value >> 24) % 1024) + 1024) % 1024) / 1024.0 - 0.5) * 0.9;
    }

    /**
     * BiomeManager.getBiome: the quart whose jittered corner is nearest.
     *
     * @return array{0: int, 1: int, 2: int} quart coordinates
     */
    public static function zoom(int $seed, int $x, int $y, int $z): array
    {
        $ax = $x - 2;
        $ay = $y - 2;
        $az = $z - 2;
        $px = $ax >> 2;
        $py = $ay >> 2;
        $pz = $az >> 2;
        $fx = ($ax & 3) / 4.0;
        $fy = ($ay & 3) / 4.0;
        $fz = ($az & 3) / 4.0;
        $best = 0;
        $bestDistance = INF;
        for ($i = 0; $i < 8; ++$i) {
            $cx = ($i & 4) === 0 ? $px : $px + 1;
            $cy = ($i & 2) === 0 ? $py : $py + 1;
            $cz = ($i & 1) === 0 ? $pz : $pz + 1;
            $dx = ($i & 4) === 0 ? $fx : $fx - 1.0;
            $dy = ($i & 2) === 0 ? $fy : $fy - 1.0;
            $dz = ($i & 1) === 0 ? $fz : $fz - 1.0;
            $r = self::lcg($seed, $cx);
            $r = self::lcg($r, $cy);
            $r = self::lcg($r, $cz);
            $r = self::lcg($r, $cx);
            $r = self::lcg($r, $cy);
            $r = self::lcg($r, $cz);
            $fiddleX = self::fiddle($r);
            $r = self::lcg($r, $seed);
            $fiddleY = self::fiddle($r);
            $r = self::lcg($r, $seed);
            $fiddleZ = self::fiddle($r);
            $distance = ($dz + $fiddleZ) ** 2 + ($dy + $fiddleY) ** 2 + ($dx + $fiddleX) ** 2;
            if ($bestDistance > $distance) {
                $best = $i;
                $bestDistance = $distance;
            }
        }
        return [($best & 4) === 0 ? $px : $px + 1, ($best & 2) === 0 ? $py : $py + 1, ($best & 1) === 0 ? $pz : $pz + 1];
    }
}
