<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\F32;
use PhpMinecraftJavaServer\World\Vanilla\Noise\NoiseStack;
use PhpMinecraftJavaServer\World\Vanilla\Noise\NormalNoise;

/**
 * Value providers (util.valueproviders, heightproviders) and block state providers
 * (feature.stateproviders) from their JSON forms.
 */
final class Providers
{
    /** @var array<string, array> */
    private array $stateProviders = [];

    /** @var array<string, NoiseStack> */
    private array $noises = [];

    public function __construct(private readonly Blocks $blocks, private readonly string $directory, private readonly Predicates $predicates)
    {
    }

    /** VerticalAnchor.resolveY */
    public static function anchor(array $anchor, Region $region): int
    {
        return match (true) {
            isset($anchor['absolute']) => $anchor['absolute'],
            isset($anchor['above_bottom']) => $region->minY + $anchor['above_bottom'],
            isset($anchor['below_top']) => $region->minY + $region->height - 1 - $anchor['below_top'],
            isset($anchor['relative_to_sea_level']) => $region->seaLevel + $anchor['relative_to_sea_level'],
        };
    }

    private static function between(RandomSource $random, int $min, int $max): int
    {
        return $random->nextInt($max - $min + 1) + $min;
    }

    /** Mth.nextInt */
    private static function nextInt(RandomSource $random, int $min, int $max): int
    {
        return $min >= $max ? $min : $random->nextInt($max - $min + 1) + $min;
    }

    /** WeightedList selection by cumulative weight. */
    public static function weighted(array $entries, RandomSource $random): mixed
    {
        $total = 0;
        foreach ($entries as $entry) {
            $total += $entry['weight'];
        }
        if ($total === 0) {
            return null;
        }
        $selection = $random->nextInt($total);
        foreach ($entries as $entry) {
            $selection -= $entry['weight'];
            if ($selection < 0) {
                return $entry['data'];
            }
        }
        return null;
    }

    /**
     * IntProvider.minInclusive and maxInclusive.
     *
     * @return array{0: int, 1: int}
     */
    public static function intBounds(mixed $json): array
    {
        if (is_int($json)) {
            return [$json, $json];
        }
        $type = preg_replace('/^minecraft:/', '', $json['type'] ?? 'uniform');
        if ($type === 'constant') {
            return [$json['value'], $json['value']];
        }
        if ($type === 'clamped') {
            [$min, $max] = self::intBounds($json['source']);
            return [max($json['min_inclusive'], $min), min($json['max_inclusive'], $max)];
        }
        return [$json['min_inclusive'] ?? $json['min'] ?? 0, $json['max_inclusive'] ?? $json['max'] ?? 0];
    }

    public static function int(mixed $json, RandomSource $random): int
    {
        if (is_int($json)) {
            return $json;
        }
        // A bare {min_inclusive, max_inclusive} object is a UniformInt.
        $type = preg_replace('/^minecraft:/', '', $json['type'] ?? 'uniform');
        $min = $json['min_inclusive'] ?? 0;
        $max = $json['max_inclusive'] ?? 0;
        switch ($type) {
            case 'constant':
                return $json['value'];
            case 'uniform':
                return self::between($random, $min, $max);
            case 'biased_to_bottom':
                return $min + $random->nextInt($random->nextInt($max - $min + 1) + 1);
            case 'very_biased_to_bottom':
                return $min + $random->nextInt($random->nextInt($random->nextInt($max - $min + 1) + 1) + 1);
            case 'clamped':
                return min(max(self::int($json['source'], $random), $min), $max);
            case 'clamped_normal':
                $value = F32::r(F32::r((float) $json['mean']) + F32::r(F32::r($random->nextGaussian()) * F32::r((float) $json['deviation'])));
                return (int) min(max($value, F32::r((float) $json['min_inclusive'])), F32::r((float) $json['max_inclusive']));
            case 'weighted_list':
                return self::int(self::weighted($json['distribution'], $random), $random);
            case 'trapezoid':
                $tMin = $json['min'] ?? $min;
                $tMax = $json['max'] ?? $max;
                $plateau = $json['plateau'] ?? 0;
                if ($plateau === 0 && $tMax === -$tMin) {
                    return $random->nextInt($tMax + 1) - $random->nextInt($tMax + 1);
                }
                $range = $tMax - $tMin;
                if ($plateau === $range) {
                    return self::between($random, $tMin, $tMax);
                }
                $start = intdiv($range - $plateau, 2);
                return $tMin + self::between($random, 0, $range - $start) + self::between($random, 0, $start);
        }
        throw new \LogicException('Unsupported int provider ' . $type);
    }

    public static function float(mixed $json, RandomSource $random): float
    {
        if (is_int($json) || is_float($json)) {
            return F32::r((float) $json);
        }
        switch (preg_replace('/^minecraft:/', '', $json['type'])) {
            case 'constant':
                return F32::r((float) $json['value']);
            case 'uniform':
                $min = F32::r((float) $json['min_inclusive']);
                return F32::r(F32::r($random->nextFloat() * F32::r(F32::r((float) $json['max_exclusive']) - $min)) + $min);
            case 'clamped_normal':
                $value = F32::r(F32::r((float) $json['mean']) + F32::r(F32::r($random->nextGaussian()) * F32::r((float) $json['deviation'])));
                $min = F32::r((float) $json['min']);
                return $value < $min ? $min : min($value, F32::r((float) $json['max']));
            case 'trapezoid':
                $min = F32::r((float) $json['min']);
                $range = F32::r(F32::r((float) $json['max']) - $min);
                $start = F32::r(F32::r($range - F32::r((float) $json['plateau'])) / 2.0);
                $end = F32::r($range - $start);
                return F32::r(F32::r($min + F32::r($random->nextFloat() * $end)) + F32::r($random->nextFloat() * $start));
        }
        throw new \LogicException('Unsupported float provider ' . $json['type']);
    }

    public static function height(array $json, RandomSource $random, Region $region): int
    {
        $type = preg_replace('/^minecraft:/', '', $json['type'] ?? 'constant');
        if ($type === 'constant') {
            return self::anchor($json['value'] ?? $json, $region);
        }
        $min = self::anchor($json['min_inclusive'], $region);
        $max = self::anchor($json['max_inclusive'], $region);
        $inner = $json['inner'] ?? 1;
        switch ($type) {
            case 'uniform':
                return $min > $max ? $min : self::between($random, $min, $max);
            case 'trapezoid':
                if ($min > $max) {
                    return $min;
                }
                $range = $max - $min;
                $plateau = $json['plateau'] ?? 0;
                if ($plateau >= $range) {
                    return self::between($random, $min, $max);
                }
                $start = intdiv($range - $plateau, 2);
                return $min + self::between($random, 0, $range - $start) + self::between($random, 0, $start);
            case 'biased_to_bottom':
                if ($max - $min - $inner + 1 <= 0) {
                    return $min;
                }
                $limit = $random->nextInt($max - $min - $inner + 1);
                return $random->nextInt($limit + $inner) + $min;
            case 'very_biased_to_bottom':
                if ($max - $min - $inner + 1 <= 0) {
                    return $min;
                }
                $upper = self::nextInt($random, $min + $inner, $max);
                $biased = self::nextInt($random, $min, $upper - 1);
                return self::nextInt($random, $min, $biased - 1 + $inner);
        }
        throw new \LogicException('Unsupported height provider ' . $type);
    }

    private function noise(array $parameters, int $seed): NoiseStack
    {
        $key = json_encode($parameters) . '#' . $seed;
        return $this->noises[$key] ??= NormalNoise::create(new LegacyRandom($seed), $parameters);
    }

    private function resolve(mixed $json): mixed
    {
        if (is_string($json) && is_file($this->directory . '/block_state_provider/' . preg_replace('/^minecraft:/', '', $json) . '.json')) {
            return $this->stateProviders[$json] ??= json_decode((string) file_get_contents($this->directory . '/block_state_provider/' . preg_replace('/^minecraft:/', '', $json) . '.json'), true, 512, JSON_THROW_ON_ERROR);
        }
        return $json;
    }

    /** BlockStateProvider.getOptionalState; null where the provider has no state. */
    public function optionalState(mixed $json, Region $region, RandomSource $random, int $x, int $y, int $z): ?int
    {
        $json = $this->resolve($json);
        if (is_string($json) || !isset($json['type'])) {
            return $this->blocks->parse($json);
        }
        switch (preg_replace('/^minecraft:/', '', $json['type'])) {
            case 'simple_state_provider':
                return $this->blocks->parse($json['state']);
            case 'weighted':
                return $this->blocks->parse(self::weighted($json['entries'], $random));
            case 'rule_based':
                foreach ($json['rules'] as $rule) {
                    if ($this->predicates->test($rule['if_true'], $region, $x, $y, $z)) {
                        $state = $this->optionalState($rule['then'], $region, $random, $x, $y, $z);
                        if ($state !== null) {
                            return $state;
                        }
                    }
                }
                return isset($json['fallback']) ? $this->optionalState($json['fallback'], $region, $random, $x, $y, $z) : null;
            case 'random_block':
                $members = str_starts_with($json['blocks'], '#') ? $this->blocks->tagMembers($json['blocks']) : (array) $json['blocks'];
                if ($members === []) {
                    return null;
                }
                return $this->blocks->state($members[$random->nextInt(count($members))]);
        }
        return $this->state($json, $region, $random, $x, $y, $z);
    }

    /** BlockStateProvider.getState */
    public function state(mixed $json, Region $region, RandomSource $random, int $x, int $y, int $z): int
    {
        $json = $this->resolve($json);
        if (is_string($json) || !isset($json['type'])) {
            return $this->blocks->parse($json);
        }
        $type = preg_replace('/^minecraft:/', '', $json['type']);
        switch ($type) {
            case 'simple_state_provider':
            case 'weighted':
            case 'random_block':
            case 'rule_based':
                return $this->optionalState($json, $region, $random, $x, $y, $z) ?? $region->get($x, $y, $z);
            case 'noise_threshold':
                $scale = F32::r((float) $json['scale']);
                $value = $this->noise($json['noise'], $json['seed'])->get($x * $scale, $y * $scale, $z * $scale);
                if ($value < F32::r((float) $json['threshold'])) {
                    return $this->blocks->parse($json['low_states'][$random->nextInt(count($json['low_states']))]);
                }
                if ($random->nextFloat() < F32::r((float) $json['high_chance'])) {
                    return $this->blocks->parse($json['high_states'][$random->nextInt(count($json['high_states']))]);
                }
                return $this->blocks->parse($json['default_state']);
            case 'noise':
                $scale = F32::r((float) $json['scale']);
                return $this->blocks->parse($this->noiseState($json['states'], $this->noise($json['noise'], $json['seed'])->get($x * $scale, $y * $scale, $z * $scale)));
            case 'dual_noise':
                $slowScale = F32::r((float) $json['slow_scale']);
                $slow = $this->noise($json['slow_noise'], $json['seed']);
                $variety = $json['variety'];
                [$varietyMin, $varietyMax] = is_array($variety) && array_is_list($variety) ? $variety : [$variety['min_inclusive'], $variety['max_inclusive']];
                // DualNoiseProvider scales block coordinates by a float.
                $t = ($slow->get(F32::r($x * $slowScale), F32::r($y * $slowScale), F32::r($z * $slowScale)) - -1.0) / (1.0 - -1.0);
                $local = (int) ($t < 0.0 ? $varietyMin : ($t > 1.0 ? $varietyMax + 1 : $varietyMin + $t * ($varietyMax + 1 - $varietyMin)));
                $possible = [];
                for ($i = 0; $i < $local; ++$i) {
                    $possible[] = $this->noiseState($json['states'], $slow->get(F32::r(($x + $i * 54545) * $slowScale), F32::r($y * $slowScale), F32::r(($z + $i * 34234) * $slowScale)));
                }
                $scale = F32::r((float) $json['scale']);
                return $this->blocks->parse($this->noiseState($possible, $this->noise($json['noise'], $json['seed'])->get($x * $scale, $y * $scale, $z * $scale)));
            case 'randomized_int':
                $state = $this->state($json['source'], $region, $random, $x, $y, $z);
                if (!$this->blocks->has($state, $json['property'])) {
                    return $state;
                }
                return $this->blocks->with($state, $json['property'], (string) self::int($json['values'], $random));
            case 'rotated':
                $state = $this->blocks->parse($json['state']);
                $direction = isset($json['direction']) ? array_search($json['direction'], ['down', 'up', 'north', 'south', 'west', 'east'], true) : $random->nextInt(6);
                $axis = $direction <= 1 ? 'y' : ($direction <= 3 ? 'z' : 'x');
                $name = ['down', 'up', 'north', 'south', 'west', 'east'][$direction];
                // trySetValue: AXIS, then FACING (also HORIZONTAL_FACING's name) when the value is allowed.
                return $this->blocks->with($this->blocks->with($state, 'axis', $axis), 'facing', $name);
        }
        throw new \LogicException('Unsupported block state provider ' . $type);
    }

    /** NoiseProvider.getRandomState */
    private function noiseState(array $states, float $noise): mixed
    {
        $value = F32::r(F32::r(1.0 + $noise) / 2.0);
        $value = $value < 0.0 ? 0.0 : min($value, F32::r(0.9999));
        return $states[(int) F32::r($value * count($states))];
    }
}
