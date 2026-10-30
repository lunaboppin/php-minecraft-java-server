<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\Int64;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/**
 * StructureTemplate: a template's palettes in vanilla's placement order (full blocks, then other
 * blocks, then block entities, each by y, x, z), placed with a mirror, a rotation about a pivot,
 * processors and the shape updates StructureTemplate.placeInWorld applies.
 */
final class Template
{
    /** RandomizableContainer block entities, which draw a loot seed when placed from a template. */
    private const RANDOMIZABLE = ['chest', 'trapped_chest', 'barrel', 'dispenser', 'dropper', 'hopper', 'crafter', 'decorated_pot'];

    /** @var array<string, self> */
    private static array $cache = [];

    /**
     * @param list<int> $size
     * @param list<list<array{0: int, 1: int, 2: int, 3: int, 4: ?array}>> $palettes blocks as x, y, z, state and NBT
     */
    private function __construct(public readonly array $size, public readonly array $palettes, public readonly array $entities)
    {
    }

    public static function load(Blocks $blocks, string $directory, string $name): self
    {
        $name = preg_replace('/^minecraft:/', '', $name);
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }
        $json = json_decode((string) gzdecode((string) file_get_contents("$directory/template/$name.json.gz")), true, 512, JSON_THROW_ON_ERROR);
        $palettes = [];
        foreach ($json['palettes'] as $entries) {
            $palette = array_map($blocks->parse(...), $entries);
            // StructureTemplate.addToLists and buildInfoList
            $lists = [[], [], []];
            foreach ($json['blocks'] as $block) {
                $state = $palette[$block[3]];
                $nbt = $block[4] ?? null;
                $list = $nbt !== null ? 2 : ($blocks->flag($state, Blocks::FULL_CUBE) ? 0 : 1);
                $lists[$list][] = [$block[0], $block[1], $block[2], $state, $nbt];
            }
            $ordered = [];
            foreach ($lists as $list) {
                usort($list, static fn (array $a, array $b): int => [$a[1], $a[0], $a[2]] <=> [$b[1], $b[0], $b[2]]);
                array_push($ordered, ...$list);
            }
            $palettes[] = $ordered;
        }
        if (count(self::$cache) > 256) {
            self::$cache = [];
        }
        return self::$cache[$name] = new self($json['size'], $palettes, $json['entities']);
    }

    /**
     * StructureTemplate.transform: mirror, then rotate about the pivot.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function transform(int $x, int $y, int $z, int $mirror, int $rotation, array $pivot): array
    {
        if ($mirror === BlockTransform::LEFT_RIGHT) {
            $z = -$z;
        } elseif ($mirror === BlockTransform::FRONT_BACK) {
            $x = -$x;
        }
        [$px, , $pz] = $pivot;
        return match ($rotation & 3) {
            3 => [$px - $pz + $z, $y, $px + $pz - $x],
            1 => [$px + $pz - $z, $y, $pz - $px + $x],
            2 => [$px + $px - $x, $y, $pz + $pz - $z],
            default => [$x, $y, $z],
        };
    }

    /** StructureTemplate.getBoundingBox */
    public function boundingBox(array $position, int $mirror, int $rotation, array $pivot): array
    {
        [$ax, $ay, $az] = self::transform(0, 0, 0, $mirror, $rotation, $pivot);
        [$bx, $by, $bz] = self::transform($this->size[0] - 1, $this->size[1] - 1, $this->size[2] - 1, $mirror, $rotation, $pivot);
        return [min($ax, $bx) + $position[0], min($ay, $by) + $position[1], min($az, $bz) + $position[2], max($ax, $bx) + $position[0], max($ay, $by) + $position[1], max($az, $bz) + $position[2]];
    }

    /** StructurePlaceSettings.getRandom without a random of its own. */
    public static function positionalRandom(int $x, int $y, int $z): LegacyRandom
    {
        return new LegacyRandom(Int64::coordinateSeed($x, $y, $z));
    }

    /**
     * StructurePlaceSettings.getRandomPalette
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int, 4: ?array}>
     */
    public function palette(array $position): array
    {
        return count($this->palettes) === 1 ? $this->palettes[0] : $this->palettes[self::positionalRandom(...$position)->nextInt(count($this->palettes))];
    }

    /**
     * The blocks of one kind, at their world positions (StructureTemplate.filterBlocks).
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int, 4: ?array}>
     */
    public function filter(Blocks $blocks, array $position, int $mirror, int $rotation, array $pivot, string $block): array
    {
        $out = [];
        foreach ($this->palette($position) as [$x, $y, $z, $state, $nbt]) {
            if ($blocks->name($state) === $block) {
                [$wx, $wy, $wz] = self::transform($x, $y, $z, $mirror, $rotation, $pivot);
                $out[] = [$wx + $position[0], $wy + $position[1], $wz + $position[2], $state, $nbt];
            }
        }
        return $out;
    }

    /**
     * StructureTemplate.placeInWorld within the chunk box, with processors given as a callable
     * that may change or drop each block ((world x, y, z, state, nbt, template x, y, z) => ?block).
     *
     * @param ?callable(array, array): ?array $process
     * @return bool whether the template has blocks to place
     */
    public function place(StructureContext $context, Region $region, array $position, int $mirror, int $rotation, array $pivot, array $chunkBox, RandomSource $random, ?callable $process, bool $waterlog = true, bool $processWholePiece = false, bool $updateShapes = true, ?callable $finalize = null): bool
    {
        $blocks = $context->blocks;
        $list = $this->palette($position);
        if ($list === [] && $this->entities === [] || $this->size[0] < 1 || $this->size[1] < 1 || $this->size[2] < 1) {
            return false;
        }
        // StructureTemplate.processBlockInfos
        $processed = [];
        $originals = [];
        foreach ($list as $block) {
            [$x, $y, $z] = self::transform($block[0], $block[1], $block[2], $mirror, $rotation, $pivot);
            $info = [$x + $position[0], $y + $position[1], $z + $position[2], $block[3], $block[4]];
            if (!$processWholePiece && !Piece::inside($chunkBox, $info[0], $info[1], $info[2])) {
                continue;
            }
            if ($process !== null) {
                $info = $process($info, $block);
            }
            if ($info !== null) {
                $processed[] = $info;
                $originals[] = $block;
            }
        }
        if ($finalize !== null) {
            $processed = $finalize($processed, $originals);
        }
        $placed = [];
        $fill = [];
        $locked = [];
        foreach ($processed as [$x, $y, $z, $state, $nbt]) {
            if (!Piece::inside($chunkBox, $x, $y, $z)) {
                continue;
            }
            $previous = $waterlog ? $region->get($x, $y, $z) : null;
            $state = $context->transform->apply($state, $mirror, $rotation);
            $region->set($x, $y, $z, $state);
            $placed["$x,$y,$z"] = [$x, $y, $z];
            if ($nbt !== null) {
                $name = $blocks->name($state);
                $entity = ['id' => $name] + $nbt;
                if (in_array($name, self::RANDOMIZABLE, true) || str_ends_with($name, 'shulker_box') || str_ends_with($name, 'copper_chest')) {
                    $entity['LootTableSeed'] = $random->nextLong();
                }
                $context->blockEntities["$x,$y,$z"] = $entity;
            }
            if ($previous !== null) {
                // LiquidSettings.APPLY_WATERLOGGING: a placed source locks its water; a waterloggable
                // block takes the water source it replaced, or fills from a neighbouring source later.
                $current = $region->get($x, $y, $z);
                if ($blocks->isWater($current) && $blocks->isSource($current) || $blocks->isLava($current) && $blocks->isSource($current)) {
                    $locked["$x,$y,$z"] = true;
                } elseif ($blocks->has($current, 'waterlogged')) {
                    if ($blocks->isWater($previous) && $blocks->isSource($previous)) {
                        $region->set($x, $y, $z, $blocks->with($current, 'waterlogged', 'true'));
                    } else {
                        $fill["$x,$y,$z"] = [$x, $y, $z];
                    }
                }
            }
        }
        // Waterloggable blocks next to placed water sources fill from them.
        $filled = true;
        while ($filled && $fill !== []) {
            $filled = false;
            foreach ($fill as $key => [$x, $y, $z]) {
                $state = $region->get($x, $y, $z);
                foreach ([[0, 1, 0], [0, 0, -1], [1, 0, 0], [0, 0, 1], [-1, 0, 0]] as [$dx, $dy, $dz]) {
                    $neighbour = $region->get($x + $dx, $y + $dy, $z + $dz);
                    if ($blocks->isWater($neighbour) && $blocks->isSource($neighbour) && !isset($locked[($x + $dx) . ',' . ($y + $dy) . ',' . ($z + $dz)])) {
                        $region->set($x, $y, $z, $blocks->with($state, 'waterlogged', 'true'));
                        $filled = true;
                        unset($fill[$key]);
                        break;
                    }
                }
            }
        }
        // StructurePlaceSettings.setKnownShape(true) skips the shape updates.
        if ($updateShapes) {
            foreach ($placed as [$x, $y, $z]) {
                $context->markForPostProcessing($x, $y, $z);
            }
        }
        return true;
    }
}
