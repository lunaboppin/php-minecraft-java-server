<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Noise;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/** synth.NormalNoise: builds the paired octave stack for a noise definition. */
final class NormalNoise
{
    /** @param array{first_octave?: int, firstOctave?: int, amplitudes?: list<float>} $parameters */
    public static function create(RandomSource $random, array $parameters): NoiseStack
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
}
