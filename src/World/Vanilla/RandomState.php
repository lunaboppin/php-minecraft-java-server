<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

use PhpMinecraftJavaServer\World\Generation\EndIslands;
use PhpMinecraftJavaServer\World\Generation\Int64;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\PositionalRandom;
use PhpMinecraftJavaServer\World\Generation\XoroshiroRandom;
use PhpMinecraftJavaServer\World\Vanilla\Density\Binary;
use PhpMinecraftJavaServer\World\Vanilla\Density\Caching;
use PhpMinecraftJavaServer\World\Vanilla\Density\Constant;
use PhpMinecraftJavaServer\World\Vanilla\Density\FindTopSurface;
use PhpMinecraftJavaServer\World\Vanilla\Density\Gradient;
use PhpMinecraftJavaServer\World\Vanilla\Density\Interpolated;
use PhpMinecraftJavaServer\World\Vanilla\Density\IntervalSelect;
use PhpMinecraftJavaServer\World\Vanilla\Density\Lerp;
use PhpMinecraftJavaServer\World\Vanilla\Density\NoiseSampler;
use PhpMinecraftJavaServer\World\Vanilla\Density\PointSampler;
use PhpMinecraftJavaServer\World\Vanilla\Density\RangeChoice;
use PhpMinecraftJavaServer\World\Vanilla\Density\ShiftB;
use PhpMinecraftJavaServer\World\Vanilla\Density\Slice;
use PhpMinecraftJavaServer\World\Vanilla\Density\Spline;
use PhpMinecraftJavaServer\World\Vanilla\Density\Unary;
use PhpMinecraftJavaServer\World\Vanilla\Noise\NoiseStack;
use PhpMinecraftJavaServer\World\Vanilla\Noise\NormalNoise;
use PhpMinecraftJavaServer\World\Vanilla\Noise\SmearedPerlin;

/**
 * RandomState and its DensityFunctionCompiler: seeded noises and positional randoms, and
 * density functions compiled the way vanilla compiles them. References are inlined, caches
 * with equal inputs share one cache, and parts that do not vary along an axis are sliced at
 * coordinate 0 of that axis (DfRewriteRule.SLICE_UNIFORM_AXES). The slices and caches decide
 * which values a sampling context reuses, so they are part of the result.
 */
final class RandomState
{
    private const X = 1;
    private const Y = 2;
    private const Z = 4;

    public readonly PositionalRandom $random;

    /** @var array<string, array> */
    private array $definitions = [];

    /** @var array<string, NoiseStack> */
    private array $noises = [];

    /** @var array<string, PositionalRandom> */
    private array $factories = [];

    /** @var array<string, Sampler> */
    private array $samplers = [];

    /** @var array<string, array> */
    private array $prepared = [];

    private int $nextCache = 0;

    public function __construct(public readonly int $seed, private readonly string $directory, private readonly bool $legacy = false)
    {
        $this->random = ($legacy ? new LegacyRandom($seed) : new XoroshiroRandom($seed))->forkPositional();
    }

    /** RandomState.getOrCreateRandomFactory */
    public function factory(string $name): PositionalRandom
    {
        return $this->factories[$name] ??= $this->random->fromHashOf($name)->forkPositional();
    }

    public function noise(string $name): NoiseStack
    {
        $name = self::name($name);
        if (!isset($this->noises[$name])) {
            $parameters = json_decode((string) file_get_contents($this->directory . '/noise/' . substr($name, 10) . '.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->noises[$name] = NormalNoise::create($this->random->fromHashOf($name), $parameters);
        }
        return $this->noises[$name];
    }

    /** RandomState.getSampler for a density function in its JSON form. */
    public function sampler(mixed $json): Sampler
    {
        $node = $this->parse($json);
        $key = self::key($node);
        if (!isset($this->samplers[$key])) {
            $this->samplers[$key] = $this->compile($this->optimize($node));
        }
        return $this->samplers[$key];
    }

    private static function name(string $name): string
    {
        return str_contains($name, ':') ? $name : 'minecraft:' . $name;
    }

    private static function key(array $node): string
    {
        return $node['type'] === 'prepared' ? 'prepared#' . $node['id'] : json_encode($node, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function definition(string $name): array
    {
        $name = self::name($name);
        if (!array_key_exists($name, $this->definitions)) {
            $path = $this->directory . '/density_function/' . substr($name, 10) . '.json';
            if (!is_file($path)) {
                throw new \InvalidArgumentException('Unknown density function ' . $name);
            }
            $this->definitions[$name] = $this->parse(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
        }
        return $this->definitions[$name];
    }

    /** Decode a density function as DensityFunctions.DIRECT_CODEC does, keeping references. */
    private function parse(mixed $json): array
    {
        if (is_int($json) || is_float($json)) {
            return ['type' => 'constant', 'value' => F32::r((float) $json)];
        }
        if (is_string($json)) {
            return ['type' => 'ref', 'name' => self::name($json)];
        }
        if (!is_array($json) || !is_string($json['type'] ?? null)) {
            throw new \InvalidArgumentException('Invalid density function');
        }
        $type = preg_replace('/^minecraft:/', '', $json['type']);
        $node = ['type' => $type];
        switch ($type) {
            case 'noise':
                $node += [
                    'noise' => self::name($json['noise']),
                    'xz_scale' => (float) $json['xz_scale'],
                    'y_scale' => (float) $json['y_scale'],
                    'shift_x' => $this->parse($json['shift_x'] ?? 0.0),
                    'shift_y' => $this->parse($json['shift_y'] ?? 0.0),
                    'shift_z' => $this->parse($json['shift_z'] ?? 0.0),
                ];
                break;
            case 'shift':
            case 'shift_a':
            case 'shift_b':
                $node['noise'] = self::name($json['noise']);
                break;
            case 'spline':
                $node['spline'] = $this->parseSpline($json['spline']);
                break;
            case 'interval_select':
                $node['input'] = $this->parse($json['input']);
                $node['thresholds'] = array_map(static fn ($v): float => F32::r((float) $v), $json['thresholds']);
                $node['functions'] = array_map($this->parse(...), $json['functions']);
                break;
            default:
                foreach ($json as $field => $value) {
                    if ($field === 'type') {
                        continue;
                    }
                    $node[$field] = in_array($field, ['input', 'left', 'right', 'alpha', 'first', 'second', 'when_in_range', 'when_out_of_range', 'density', 'upper_bound', 'argument', 'base', 'exponent', 'multiple'], true)
                        ? $this->parse($value)
                        : $value;
                }
        }
        return $node;
    }

    private function parseSpline(mixed $json): mixed
    {
        if (is_int($json) || is_float($json)) {
            return F32::r((float) $json);
        }
        return [
            'coordinate' => $this->parse($json['coordinate']),
            'points' => array_map(fn (array $p): array => [F32::r((float) $p['location']), F32::r((float) $p['derivative']), $this->parseSpline($p['value'])], $json['points']),
        ];
    }

    /** @return list<string> the fields of a node that are density functions */
    private static function children(array $node): array
    {
        return match ($node['type']) {
            'noise' => ['shift_x', 'shift_y', 'shift_z'],
            'add', 'sub', 'mul', 'div', 'min', 'max' => ['left', 'right'],
            'lerp' => ['alpha', 'first', 'second'],
            'range_choice' => ['input', 'when_in_range', 'when_out_of_range'],
            'find_top_surface' => ['density', 'upper_bound'],
            'pow' => ['base', 'exponent'],
            'constant', 'ref', 'prepared', 'gradient', 'shift', 'shift_a', 'shift_b', 'blend_alpha', 'blend_offset', 'beardifier',
            'old_blended_noise', 'end_outer_islands', 'distance_to_point', 'spline', 'interval_select' => [],
            default => ['input'],
        };
    }

    private function rewriteChildren(array $node, \Closure $rule): array
    {
        if ($node['type'] === 'prepared') {
            return $node;
        }
        foreach (self::children($node) as $field) {
            $node[$field] = $rule($node[$field]);
        }
        if ($node['type'] === 'interval_select') {
            $node['input'] = $rule($node['input']);
            $node['functions'] = array_map($rule, $node['functions']);
        }
        if ($node['type'] === 'spline') {
            $node['spline'] = $this->mapSpline($node['spline'], $rule);
        }
        return $node;
    }

    private function mapSpline(mixed $spline, \Closure $rule): mixed
    {
        if (!is_array($spline)) {
            return $spline;
        }
        $spline['coordinate'] = $rule($spline['coordinate']);
        foreach ($spline['points'] as $i => $point) {
            $spline['points'][$i][2] = $this->mapSpline($point[2], $rule);
        }
        return $spline;
    }

    /** DensityFunctionCompiler.optimizerRule: inline and prepare caches, then slice uniform axes. */
    private function optimize(array $node): array
    {
        return $this->slice($this->inline($node), 7);
    }

    private function inline(array $node): array
    {
        while ($node['type'] === 'ref') {
            $node = $this->definition($node['name']);
        }
        if ($node['type'] === 'cache') {
            $key = self::key($node['input']);
            if (!isset($this->prepared[$key])) {
                $id = $this->nextCache++;
                $input = $this->optimize($node['input']);
                $this->prepared[$key] = ['type' => 'prepared', 'id' => $id, 'axes' => $this->axes($input), 'sampler' => new Caching($id, $this->compile($input))];
            }
            return $this->prepared[$key];
        }
        return $this->rewriteChildren($node, $this->inline(...));
    }

    private function slice(array $node, int $parent): array
    {
        if ($node['type'] === 'constant' || $node['type'] === 'gradient') {
            return $node;
        }
        $axes = $this->axes($node);
        $node = $this->rewriteChildren($node, fn (array $child): array => $this->slice($child, $axes));
        $removed = $parent & ~$axes;
        if ($removed === 0) {
            return $node;
        }
        $existing = 0;
        for ($inner = $node; $inner['type'] === 'slice'; $inner = $inner['input']) {
            $existing |= self::axisBit($inner['axis']);
        }
        $removed &= ~$existing;
        foreach ([self::X => 'x', self::Z => 'z', self::Y => 'y'] as $bit => $axis) {
            if (($removed & $bit) !== 0) {
                $node = ['type' => 'slice', 'axis' => $axis, 'coordinate' => 0, 'input' => $node];
            }
        }
        return $node;
    }

    private static function axisBit(string $axis): int
    {
        return match ($axis) {
            'x' => self::X,
            'y' => self::Y,
            'z' => self::Z,
        };
    }

    /** DensityFunction.domainAxes */
    private function axes(array $node): int
    {
        switch ($node['type']) {
            case 'constant':
                return 0;
            case 'ref':
                return $this->axes($this->definition($node['name']));
            case 'prepared':
                return $node['axes'];
            case 'gradient':
                return self::axisBit($node['axis']);
            case 'noise':
                $axes = 7;
                if ($node['y_scale'] == 0.0) {
                    $axes &= ~self::Y;
                }
                if ($node['xz_scale'] == 0.0) {
                    $axes &= ~(self::X | self::Z);
                }
                return $axes | $this->axes($node['shift_x']) | $this->axes($node['shift_y']) | $this->axes($node['shift_z']);
            case 'shift_a':
            case 'shift_b':
            case 'blend_alpha':
            case 'blend_offset':
                return self::X | self::Z;
            case 'shift':
            case 'beardifier':
            case 'old_blended_noise':
            case 'distance_to_point':
                return 7;
            case 'end_outer_islands':
                return self::X | self::Z;
            case 'find_top_surface':
                return ($this->axes($node['density']) | $this->axes($node['upper_bound'])) & ~self::Y;
            case 'slice':
                return $this->axes($node['input']) & ~self::axisBit($node['axis']);
            case 'spline':
                $axes = 0;
                $this->mapSpline($node['spline'], function (array $coordinate) use (&$axes): array {
                    $axes |= $this->axes($coordinate);
                    return $coordinate;
                });
                return $axes;
            case 'interval_select':
                $axes = $this->axes($node['input']);
                foreach ($node['functions'] as $function) {
                    $axes |= $this->axes($function);
                }
                return $axes;
        }
        $axes = 0;
        foreach (self::children($node) as $field) {
            $axes |= $this->axes($node[$field]);
        }
        return $axes;
    }

    private static function isZero(array $node): bool
    {
        return $node['type'] === 'constant' && $node['value'] == 0.0;
    }

    private function compile(array $node): Sampler
    {
        $type = $node['type'];
        switch ($type) {
            case 'constant':
                return new Constant($node['value']);
            case 'prepared':
                return $node['sampler'];
            case 'ref':
                return $this->compile($this->definition($node['name']));
            case 'blend_alpha':
                return new Constant(1.0);
            case 'blend_offset':
            case 'beardifier':
                return new Constant(0.0);
            case 'blend_density':
                return $this->compile($node['input']);
            case 'noise':
                $noise = $this->noise($node['noise']);
                if (self::isZero($node['shift_x']) && self::isZero($node['shift_y']) && self::isZero($node['shift_z'])) {
                    return new NoiseSampler($noise, $node['xz_scale'], $node['y_scale']);
                }
                return new NoiseSampler(
                    $noise,
                    $node['xz_scale'],
                    $node['y_scale'],
                    $this->compile($node['shift_x']),
                    self::isZero($node['shift_y']) ? null : $this->compile($node['shift_y']),
                    $this->compile($node['shift_z']),
                );
            case 'shift':
                return new Binary('const_mul', new NoiseSampler($this->noise($node['noise']), 0.25, 0.25), null, 4.0);
            case 'shift_a':
                return new Binary('const_mul', new NoiseSampler($this->noise($node['noise']), 0.25, 0.0), null, 4.0);
            case 'shift_b':
                return new ShiftB($this->noise($node['noise']));
            case 'add':
            case 'sub':
            case 'mul':
            case 'div':
            case 'min':
            case 'max':
                return Binary::compile($type, $this->compile($node['left']), $this->compile($node['right']));
            case 'clamp':
                return new Unary('clamp', $this->compile($node['input']), F32::r((float) $node['min']), F32::r((float) $node['max']));
            case 'abs':
            case 'square':
            case 'cube':
            case 'sqrt':
            case 'half_negative':
            case 'quarter_negative':
            case 'reciprocal':
            case 'negate':
            case 'squeeze':
            case 'log':
            case 'sign':
                return new Unary($type, $this->compile($node['input']));
            case 'lerp':
                return new Lerp($this->compile($node['alpha']), $this->compile($node['first']), $this->compile($node['second']));
            case 'range_choice':
                return new RangeChoice(
                    $this->compile($node['input']),
                    F32::r((float) $node['min_inclusive']),
                    F32::r((float) $node['max_exclusive']),
                    $this->compile($node['when_in_range']),
                    $this->compile($node['when_out_of_range']),
                );
            case 'interval_select':
                $functions = array_map($this->compile(...), $node['functions']);
                return new IntervalSelect($this->compile($node['input']), $node['thresholds'], $functions);
            case 'slice':
                $fixed = ['x' => null, 'y' => null, 'z' => null];
                $fixed[$node['axis']] = (int) $node['coordinate'];
                $input = $node['input'];
                // SliceFunction.compileSampler merges an X slice of a Z slice (or the reverse).
                if ($input['type'] === 'slice' && $node['axis'] !== 'y' && $input['axis'] !== 'y' && $input['axis'] !== $node['axis']) {
                    $fixed[$input['axis']] = (int) $input['coordinate'];
                    $input = $input['input'];
                }
                return new Slice($this->compile($input), $fixed['x'], $fixed['y'], $fixed['z']);
            case 'interpolated':
                return new Interpolated($this->compile($node['input']), (int) $node['cell_size_xz'], (int) $node['cell_size_y']);
            case 'find_top_surface':
                return new Slice(new FindTopSurface($this->compile($node['density']), $this->compile($node['upper_bound']), (int) $node['lower_bound'], (int) $node['cell_height']), null, 0, null);
            case 'gradient':
                return new Gradient($node['axis'], $node['tiling'] ?? 'clamp_to_edge', (int) $node['from_coordinate'], (int) $node['to_coordinate'], F32::r((float) $node['from_value']), F32::r((float) $node['to_value']));
            case 'spline':
                $coordinates = [];
                $indexes = [];
                $spline = $this->mapSplineCoordinates($node['spline'], $coordinates, $indexes);
                return new Spline($spline, $coordinates);
            case 'old_blended_noise':
                return $this->blendedNoise($node);
            case 'end_outer_islands':
                $islands = new EndIslands($this->seed);
                return new PointSampler($islands->get(...));
            case 'distance_to_point':
                return $this->distanceToPoint($node);
        }
        throw new \LogicException('Unsupported density function ' . $type);
    }

    /** Replace each spline coordinate with the index of its deduplicated sampler. */
    private function mapSplineCoordinates(mixed $spline, array &$coordinates, array &$indexes): mixed
    {
        if (!is_array($spline)) {
            return $spline;
        }
        $key = self::key($spline['coordinate']);
        if (!isset($indexes[$key])) {
            $indexes[$key] = count($coordinates);
            $coordinates[] = $this->compile($spline['coordinate']);
        }
        $points = [];
        foreach ($spline['points'] as [$location, $derivative, $value]) {
            $points[] = [$location, $derivative, $this->mapSplineCoordinates($value, $coordinates, $indexes)];
        }
        return ['coordinate' => $indexes[$key], 'points' => $points];
    }

    /** BlendedNoise.compileSampler: legacy terrain stacks seeded from "minecraft:terrain". */
    private function blendedNoise(array $node): Sampler
    {
        $random = $this->legacy ? new LegacyRandom($this->seed) : $this->random->fromHashOf('minecraft:terrain');
        $xz = 684.412 * $node['xz_scale'];
        $y = 684.412 * $node['y_scale'];
        $limitSmear = $y * $node['smear_scale_multiplier'];
        $fbm = static function (int $firstOctave, float $smear, float $valueFactor) use ($random): NoiseStack {
            $octaves = -$firstOctave + 1;
            $factor = 1.0;
            $valueFactor /= 2.0 ** $octaves - 1.0;
            $layers = [];
            for ($i = $octaves - 1; $i >= 0; --$i) {
                $layers[] = [new SmearedPerlin($random, $smear * $factor), $factor, F32::r($valueFactor)];
                $factor /= 2.0;
                $valueFactor *= 2.0;
            }
            return new NoiseStack($layers);
        };
        // 0.99998474F widened to double.
        $min = $fbm(-15, $limitSmear, 0.9999847412109375);
        $max = $fbm(-15, $limitSmear, 0.9999847412109375);
        $main = $fbm(-7, $limitSmear / $node['y_factor'], 12.75);
        $choice = new Unary('clamp', new Binary('const_add', new NoiseSampler($main, $xz / $node['xz_factor'], $y / $node['y_factor']), null, 0.5), 0.0, 1.0);
        return new Lerp($choice, new NoiseSampler($min, $xz, $y), new NoiseSampler($max, $xz, $y));
    }

    private function distanceToPoint(array $node): Sampler
    {
        [$px, $py, $pz] = $node['point'];
        $metric = $node['metric'];
        return new PointSampler(static function (int $x, int $y, int $z) use ($px, $py, $pz, $metric): float {
            $a = F32::r((float) Int64::signed32($px - $x));
            $b = F32::r((float) Int64::signed32($py - $y));
            $c = F32::r((float) Int64::signed32($pz - $z));
            $square = F32::r(F32::r(F32::r($a * $a) + F32::r($b * $b)) + F32::r($c * $c));
            return match ($metric) {
                'euclidean' => F32::r(sqrt($square)),
                'euclidean_squared' => $square,
                'manhattan' => F32::r(F32::r(abs($a) + abs($b)) + abs($c)),
                'chebyshev' => max(abs($a), abs($b), abs($c)),
            };
        });
    }
}
