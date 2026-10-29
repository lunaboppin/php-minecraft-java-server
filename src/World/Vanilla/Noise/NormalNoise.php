<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Noise;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/**
 * synth.NormalNoise: builds the paired octave stack for a noise definition. The Nether's
 * temperature and vegetation noises use the deprecated legacy initialisation instead.
 */
final class NormalNoise
{
    /** @param array{first_octave?: int, firstOctave?: int, amplitudes?: list<float>} $parameters */
    public static function create(RandomSource $random, array $parameters, bool $legacyNetherBiome = false): NoiseStack
    {
        $octave = $parameters['base_octave'] ?? 0;
        $count = $parameters['octave_count'] ?? 1;
        $base = (float) ($parameters['base_amplitude'] ?? 1.0);
        $mode = $parameters['normalize'] ?? true;
        $modifiers = $parameters['amplitude_modifiers'] ?? [];
        $amplitude = $base;
        if ($mode !== false) {
            $amplitude = $base * (0.5 ** -($count - 1) / (0.5 ** -$count - 1.0));
        }
        $frequency = 2.0 ** $octave;
        $octaves = [];
        $sum = 0.0;
        $compensation = 0.0;
        $variance = 0.0;
        $first = PHP_INT_MAX;
        $last = PHP_INT_MIN;
        for ($i = 0; $i < $count; ++$i) {
            $modifier = (float) ($modifiers[$i] ?? 1.0);
            if ($modifier != 0.0) {
                $a = $amplitude * $modifier;
                $octaves[] = ['octave_' . ($octave + $i), $frequency, $a];
                // DoubleStream.sum uses Kahan summation.
                $corrected = abs($a) - $compensation;
                $next = $sum + $corrected;
                $compensation = ($next - $sum) - $corrected;
                $sum = $next;
                $deviation = 0.2702247831245211 * abs($a);
                $variance += $deviation * $deviation;
                $first = min($first, $i);
                $last = max($last, $i);
            }
            $frequency *= 2.0;
            $amplitude *= 0.5;
        }
        $deviation = sqrt($variance);
        $normalization = $deviation == 0.0 ? 0.0 : (($sum - $compensation) * 0.3333333333333333) / ($deviation * sqrt(2.0));
        if ($mode === 'legacy' && $normalization != 0.0) {
            $normalization = $base * 0.5 * 0.3333333333333333 / (0.1 * (1.0 + 1.0 / ($last - $first + 1)));
        }
        if ($legacyNetherBiome) {
            return self::legacyNetherBiome($random, $octave, $count, $modifiers, F32::r($normalization * $base));
        }
        $firstRandom = $random->forkPositional();
        $secondRandom = $random->forkPositional();
        $layers = [];
        foreach ($octaves as [$seed, $octaveFrequency, $octaveAmplitude]) {
            $weight = F32::r($normalization * $octaveAmplitude);
            $layers[] = [new Perlin($firstRandom->fromHashOf($seed)), $octaveFrequency, $weight];
            $layers[] = [new Perlin($secondRandom->fromHashOf($seed)), $octaveFrequency * 1.0181268882175227, $weight];
        }
        return new NoiseStack($layers);
    }

    /**
     * NormalNoise.createForLegacyNetherBiome: two LegacyFbmInitializer stacks drawn from one
     * legacy random, flattened by NoiseStack.Builder.addStack.
     *
     * @param list<float> $modifiers
     */
    private static function legacyNetherBiome(RandomSource $random, int $firstOctave, int $count, array $modifiers, float $valueFactor): NoiseStack
    {
        $amplitudes = $modifiers === [] ? array_fill(0, $count, 1.0) : array_map(floatval(...), $modifiers);
        $layers = [];
        foreach ([1.0, 1.0181268882175227] as $frequency) {
            foreach (self::legacyFbm($random, $firstOctave, $amplitudes) as [$noise, $octaveFrequency, $amplitude]) {
                $layers[] = [$noise, $octaveFrequency * $frequency, F32::r($amplitude * $valueFactor)];
            }
        }
        return new NoiseStack($layers);
    }

    /**
     * LegacyFbmInitializer.createForLegacyNetherBiome
     *
     * @param list<float> $amplitudes
     * @return list<array{0: Perlin, 1: float, 2: float}>
     */
    private static function legacyFbm(RandomSource $random, int $firstOctave, array $amplitudes): array
    {
        $octaves = count($amplitudes);
        $zeroOctaveIndex = -$firstOctave;
        $levels = array_fill(0, $octaves, null);
        $zeroOctave = new Perlin($random);
        if ($zeroOctaveIndex >= 0 && $zeroOctaveIndex < $octaves && $amplitudes[$zeroOctaveIndex] != 0.0) {
            $levels[$zeroOctaveIndex] = $zeroOctave;
        }
        for ($i = $zeroOctaveIndex - 1; $i >= 0; --$i) {
            if ($i < $octaves && $amplitudes[$i] != 0.0) {
                $levels[$i] = new Perlin($random);
            } else {
                $random->consumeCount(262);
            }
        }
        if ($zeroOctaveIndex < $octaves - 1) {
            throw new \InvalidArgumentException('Positive octaves are temporarily disabled');
        }
        $factor = 2.0 ** -$zeroOctaveIndex;
        $valueFactor = 2.0 ** ($octaves - 1) / (2.0 ** $octaves - 1.0);
        $layers = [];
        foreach ($levels as $index => $noise) {
            if ($noise !== null) {
                $layers[] = [$noise, $factor, F32::r($valueFactor * $amplitudes[$index])];
            }
            $factor *= 2.0;
            $valueFactor /= 2.0;
        }
        return $layers;
    }
}
