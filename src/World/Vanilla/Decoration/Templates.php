<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

use PhpMinecraftJavaServer\World\Generation\Int64;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/**
 * StructureTemplate placement for template features: templates exported as JSON by
 * tools/update_reference.py, rotation without mirroring, processor lists and the fossil feature.
 * Block entity data is not placed yet.
 */
final class Templates
{
    /** Rotation: none, clockwise 90, clockwise 180, counterclockwise 90. */
    public const NONE = 0;

    private const HORIZONTAL_FACINGS = ['north', 'east', 'south', 'west'];

    private readonly Blocks $blocks;

    private readonly Predicates $predicates;

    /** @var array<string, array{size: list<int>, blocks: list<array{0: int, 1: int, 2: int, 3: int}>}> */
    private array $templates = [];

    public function __construct(private readonly Decorator $decorator, private readonly string $directory)
    {
        $this->blocks = $decorator->blocks;
        $this->predicates = $decorator->predicates;
    }

    /**
     * A template with its blocks in StructureTemplate.buildInfoList order: full blocks, then other
     * blocks, then blocks with block entity data, each sorted by y, x and z.
     *
     * @return array{size: list<int>, blocks: list<array{0: int, 1: int, 2: int, 3: int}>}
     */
    public function template(string $name): array
    {
        $name = preg_replace('/^minecraft:/', '', $name);
        if (isset($this->templates[$name])) {
            return $this->templates[$name];
        }
        $json = json_decode((string) gzdecode((string) file_get_contents($this->directory . '/template/' . $name . '.json.gz')), true, 512, JSON_THROW_ON_ERROR);
        $palette = array_map($this->blocks->parse(...), $json['palettes'][0]);
        $lists = [[], [], []];
        foreach ($json['blocks'] as $block) {
            $state = $palette[$block[3]];
            $list = isset($block[4]) ? 2 : ($this->blocks->flag($state, Blocks::FULL_CUBE) ? 0 : 1);
            $lists[$list][] = [$block[0], $block[1], $block[2], $state];
        }
        $blocks = [];
        foreach ($lists as $list) {
            usort($list, static fn (array $a, array $b): int => [$a[1], $a[0], $a[2]] <=> [$b[1], $b[0], $b[2]]);
            array_push($blocks, ...$list);
        }
        return $this->templates[$name] = ['size' => $json['size'], 'blocks' => $blocks];
    }

    /**
     * StructureTemplate.transform around the origin, without mirroring.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function transform(int $x, int $y, int $z, int $rotation): array
    {
        return match ($rotation) {
            1 => [-$z, $y, $x],
            2 => [-$x, $y, -$z],
            3 => [$z, $y, -$x],
            default => [$x, $y, $z],
        };
    }

    /** BlockState.rotate for axis and facing properties. */
    public function rotateState(int $state, int $rotation): int
    {
        if ($rotation === self::NONE) {
            return $state;
        }
        $properties = $this->blocks->properties($state);
        if (isset($properties['axis']) && $rotation !== 2 && $properties['axis'] !== 'y') {
            $state = $this->blocks->with($state, 'axis', $properties['axis'] === 'x' ? 'z' : 'x');
        }
        if (isset($properties['facing']) && ($index = array_search($properties['facing'], self::HORIZONTAL_FACINGS, true)) !== false) {
            $state = $this->blocks->with($state, 'facing', self::HORIZONTAL_FACINGS[($index + $rotation) % 4]);
        }
        return $state;
    }

    private function processors(mixed $list): array
    {
        if (is_string($list)) {
            $list = $this->decorator->load('processor_list', $list);
        }
        return $list['processors'] ?? $list;
    }

    /**
     * StructureTemplate.placeInWorld with a shared random, blocks clipped to the bounding box.
     *
     * @param array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int} $box min x, y, z, max x, y, z
     */
    public function place(array $template, Region $region, int $x, int $y, int $z, int $rotation, array $box, mixed $processors, RandomSource $random): void
    {
        $processors = $this->processors($processors);
        $placed = [];
        foreach ($template['blocks'] as [$bx, $by, $bz, $state]) {
            [$tx, $ty, $tz] = self::transform($bx, $by, $bz, $rotation);
            [$px, $py, $pz] = [$x + $tx, $y + $ty, $z + $tz];
            if ($px < $box[0] || $py < $box[1] || $pz < $box[2] || $px > $box[3] || $py > $box[4] || $pz > $box[5]) {
                continue;
            }
            foreach ($processors as $processor) {
                $state = $this->process($processor, $region, $state, $px, $py, $pz, $random);
                if ($state === null) {
                    continue 2;
                }
            }
            $placed[] = [$px, $py, $pz, $state];
        }
        foreach ($placed as [$px, $py, $pz, $state]) {
            $region->set($px, $py, $pz, $this->rotateState($state, $rotation));
        }
    }

    private function process(array $processor, Region $region, int $state, int $x, int $y, int $z, RandomSource $random): ?int
    {
        switch (preg_replace('/^minecraft:/', '', $processor['processor_type'])) {
            case 'block_rot':
                $rottable = $processor['rottable_blocks'] ?? null;
                if (($rottable === null || $this->matches($rottable, $state)) && !($random->nextFloat() <= F32::r((float) $processor['integrity']))) {
                    return null;
                }
                return $state;
            case 'protected_blocks':
                return $this->blocks->is($region->get($x, $y, $z), $processor['value']) ? null : $state;
            case 'rule':
                $ruleRandom = new LegacyRandom(Int64::coordinateSeed($x, $y, $z));
                $existing = $region->get($x, $y, $z);
                foreach ($processor['rules'] as $rule) {
                    if ($this->predicates->rule($rule['input_predicate'], $state, $y, $ruleRandom)
                        && $this->predicates->rule($rule['location_predicate'], $existing, $y, $ruleRandom)
                        && preg_replace('/^minecraft:/', '', $rule['position_predicate']['predicate_type'] ?? 'always_true') === 'always_true') {
                        return $this->blocks->parse($rule['output_state']);
                    }
                }
                return $state;
        }
        $this->decorator->skipped['processor ' . $processor['processor_type']] = ($this->decorator->skipped['processor ' . $processor['processor_type']] ?? 0) + 1;
        return $state;
    }

    private function matches(mixed $blocks, int $state): bool
    {
        foreach ((array) $blocks as $block) {
            if ($this->blocks->is($state, $block)) {
                return true;
            }
        }
        return false;
    }

    /** FossilFeature: a buried skeleton with its ore overlay. */
    public function fossil(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $rotation = $random->nextInt(4);
        $index = $random->nextInt(count($feature['fossil_structures']));
        $base = $this->template($feature['fossil_structures'][$index]);
        $overlay = $this->template($feature['overlay_structures'][$index]);
        $chunkX = $x >> 4 << 4;
        $chunkZ = $z >> 4 << 4;
        $box = [$chunkX - 16, $region->minY, $chunkZ - 16, $chunkX + 31, $region->maxY(), $chunkZ + 31];
        [$sizeX, $sizeY, $sizeZ] = $base['size'];
        [$rotatedX, $rotatedZ] = $rotation % 2 === 1 ? [$sizeZ, $sizeX] : [$sizeX, $sizeZ];
        $lowX = $x - intdiv($rotatedX, 2);
        $lowZ = $z - intdiv($rotatedZ, 2);
        $lowest = $y;
        for ($dx = 0; $dx < $rotatedX; ++$dx) {
            for ($dz = 0; $dz < $rotatedZ; ++$dz) {
                $lowest = min($lowest, $region->height(Region::OCEAN_FLOOR_WG, $lowX + $dx, $lowZ + $dz));
            }
        }
        $targetY = max($lowest - 15 - $random->nextInt(10), $region->minY + 10);
        // StructureTemplate.getZeroPositionWithTransform without mirroring.
        [$offsetX, $offsetZ] = match ($rotation) {
            1 => [$sizeZ - 1, 0],
            2 => [$sizeX - 1, $sizeZ - 1],
            3 => [0, $sizeX - 1],
            default => [0, 0],
        };
        [$tx, $tz] = [$lowX + $offsetX, $lowZ + $offsetZ];
        [$cx, , $cz] = self::transform($sizeX - 1, 0, $sizeZ - 1, $rotation);
        $empty = 0;
        foreach ([$tx, $tx + $cx] as $px) {
            foreach ([$targetY, $targetY + $sizeY - 1] as $py) {
                foreach ([$tz, $tz + $cz] as $pz) {
                    $state = $region->get($px, $py, $pz);
                    if ($this->blocks->isAir($state) || in_array($this->blocks->name($state), ['lava', 'water'], true)) {
                        ++$empty;
                    }
                }
            }
        }
        if ($empty > $feature['max_empty_corners_allowed']) {
            return false;
        }
        $this->place($base, $region, $tx, $targetY, $tz, $rotation, $box, $feature['fossil_processors'], $random);
        $this->place($overlay, $region, $tx, $targetY, $tz, $rotation, $box, $feature['overlay_processors'], $random);
        return true;
    }
}
