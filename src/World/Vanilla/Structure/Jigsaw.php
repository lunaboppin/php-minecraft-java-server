<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;

/**
 * JigsawPlacement and the template pools it draws from: a start piece, then children attached
 * jigsaw to jigsaw while they fit in the free space their parent leaves, breadth first by
 * placement priority, up to the structure's depth.
 */
final class Jigsaw
{
    /** Direction steps, for jigsaw orientations. */
    private const STEPS = ['down' => [0, -1, 0], 'up' => [0, 1, 0], 'north' => [0, 0, -1], 'south' => [0, 0, 1], 'west' => [-1, 0, 0], 'east' => [1, 0, 0]];

    private const OPPOSITE = ['down' => 'up', 'up' => 'down', 'north' => 'south', 'south' => 'north', 'west' => 'east', 'east' => 'west'];

    /** @var array<string, array{elements: list<array>, fallback: string}> */
    private array $pools = [];

    /** @var array<string, int> */
    private array $maxSizes = [];

    /** @var array<string, string> PoolAliasLookup of the start being generated */
    private array $aliases = [];

    /**
     * @param callable(int, int): int $freeHeight ChunkGenerator.getFirstFreeHeight on WORLD_SURFACE_WG
     */
    public function __construct(private readonly Blocks $blocks, private readonly string $directory, private $freeHeight)
    {
    }

    /** @return array{elements: list<array>, fallback: string} templates repeated by weight */
    public function pool(string $name): array
    {
        $name = preg_replace('/^minecraft:/', '', $name);
        if (isset($this->pools[$name])) {
            return $this->pools[$name];
        }
        $path = $this->directory . "/template_pool/$name.json";
        if (!is_file($path)) {
            return $this->pools[$name] = ['elements' => [], 'fallback' => 'empty'];
        }
        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $elements = [];
        foreach ($json['elements'] as $entry) {
            for ($i = 0; $i < $entry['weight']; ++$i) {
                $elements[] = $entry['element'];
            }
        }
        return $this->pools[$name] = ['elements' => $elements, 'fallback' => preg_replace('/^minecraft:/', '', $json['fallback'])];
    }

    /** @return list<array> Util.shuffledCopy */
    private static function shuffled(array $list, RandomSource $random): array
    {
        for ($i = count($list); $i > 1; --$i) {
            $j = $random->nextInt($i);
            [$list[$i - 1], $list[$j]] = [$list[$j], $list[$i - 1]];
        }
        return $list;
    }

    private static function type(array $element): string
    {
        return preg_replace('/^minecraft:/', '', $element['element_type']);
    }

    private function template(array $element): Template
    {
        return Template::load($this->blocks, $this->directory, $element['location']);
    }

    /** StructurePoolElement.getBoundingBox */
    public function boundingBox(array $element, array $position, int $rotation): array
    {
        switch (self::type($element)) {
            case 'single_pool_element':
            case 'legacy_single_pool_element':
                return $this->template($element)->boundingBox($position, BlockTransform::NONE, $rotation, [0, 0, 0]);
            case 'list_pool_element':
                $box = null;
                foreach ($element['elements'] as $child) {
                    if (self::type($child) === 'empty_pool_element') {
                        continue;
                    }
                    $part = $this->boundingBox($child, $position, $rotation);
                    $box = $box === null ? $part : [min($box[0], $part[0]), min($box[1], $part[1]), min($box[2], $part[2]), max($box[3], $part[3]), max($box[4], $part[4]), max($box[5], $part[5])];
                }
                return $box;
            case 'feature_pool_element':
                return [$position[0], $position[1], $position[2], $position[0], $position[1], $position[2]];
        }
        return [$position[0], $position[1], $position[2], $position[0], $position[1], $position[2]];
    }

    /** StructureTemplatePool.getMaxSize: the tallest template in a pool. */
    private function maxSize(string $pool): int
    {
        if (isset($this->maxSizes[$pool])) {
            return $this->maxSizes[$pool];
        }
        $max = 0;
        foreach ($this->pool($pool)['elements'] as $element) {
            if (self::type($element) !== 'empty_pool_element') {
                $box = $this->boundingBox($element, [0, 0, 0], 0);
                $max = max($max, $box[4] - $box[1] + 1);
            }
        }
        return $this->maxSizes[$pool] = $max;
    }

    /** JigsawBlock orientation (FrontAndTop) after a rotation. */
    private static function rotateOrientation(string $orientation, int $rotation): string
    {
        [$front, $top] = explode('_', $orientation);
        $turn = static function (string $direction) use ($rotation): string {
            $horizontal = ['north', 'east', 'south', 'west'];
            $index = array_search($direction, $horizontal, true);
            return $index === false ? $direction : $horizontal[($index + $rotation) % 4];
        };
        return $turn($front) . '_' . $turn($top);
    }

    /**
     * StructurePoolElement.getShuffledJigsawBlocks: [x, y, z, orientation, nbt] in world space,
     * shuffled then ordered by selection priority, highest first.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: string, 4: array}>
     */
    public function jigsaws(array $element, array $position, int $rotation, RandomSource $random): array
    {
        switch (self::type($element)) {
            case 'single_pool_element':
            case 'legacy_single_pool_element':
                $jigsaws = [];
                foreach ($this->template($element)->filter($this->blocks, $position, BlockTransform::NONE, $rotation, [0, 0, 0], 'jigsaw') as [$x, $y, $z, $state, $nbt]) {
                    $jigsaws[] = [$x, $y, $z, self::rotateOrientation($this->blocks->properties($state)['orientation'], $rotation), $nbt ?? []];
                }
                $jigsaws = self::shuffled($jigsaws, $random);
                // Stable sort by selection priority, highest first.
                $order = array_keys($jigsaws);
                usort($order, static fn (int $a, int $b): int => [-($jigsaws[$a][4]['selection_priority'] ?? 0), $a] <=> [-($jigsaws[$b][4]['selection_priority'] ?? 0), $b]);
                return array_map(static fn (int $i): array => $jigsaws[$i], $order);
            case 'list_pool_element':
                return $this->jigsaws($element['elements'][0], $position, $rotation, $random);
            case 'feature_pool_element':
                return [[$position[0], $position[1], $position[2], 'down_south', ['pool' => 'minecraft:empty', 'target' => 'minecraft:empty', 'joint' => 'rollable', 'final_state' => 'minecraft:air']]];
        }
        return [];
    }

    private static function front(array $jigsaw): string
    {
        return explode('_', $jigsaw[3])[0];
    }

    /** JigsawBlock.canAttach */
    private static function canAttach(array $source, array $target): bool
    {
        [$sourceFront, $sourceTop] = explode('_', $source[3]);
        [$targetFront, $targetTop] = explode('_', $target[3]);
        $joint = $source[4]['joint'] ?? (in_array($sourceFront, ['up', 'down'], true) ? 'rollable' : 'aligned');
        $name = $target[4]['name'] ?? null;
        return $sourceFront === self::OPPOSITE[$targetFront] && ($joint === 'rollable' || $sourceTop === $targetTop)
            && ($name === null || ($source[4]['target'] ?? 'minecraft:empty') === $name);
    }

    private static function projection(array $element): string
    {
        return $element['projection'] ?? 'rigid';
    }

    /** StructurePoolElement.getGroundLevelDelta */
    private static function groundLevelDelta(array $element): int
    {
        return self::type($element) === 'empty_pool_element' ? 0 : 1;
    }

    /**
     * JigsawPlacement.addPieces for a jigsaw structure starting at a chunk.
     *
     * @return array{0: list<JigsawPiece>, 1: array{0: int, 1: int, 2: int}}|null the pieces and the position checked for the biome
     */
    /** WeightedList.getRandomOrThrow over [weight, data] entries. */
    private static function weighted(array $entries, RandomSource $random): mixed
    {
        $total = array_sum(array_column($entries, 'weight'));
        $choice = $random->nextInt($total);
        foreach ($entries as $entry) {
            $choice -= $entry['weight'];
            if ($choice < 0) {
                return $entry['data'];
            }
        }
        return end($entries)['data'];
    }

    /** PoolAliasBinding.forEachResolved */
    private function bindAliases(array $binding, RandomSource $random): void
    {
        switch (preg_replace('/^minecraft:/', '', $binding['type'])) {
            case 'direct':
                $this->aliases[preg_replace('/^minecraft:/', '', $binding['alias'])] ??= preg_replace('/^minecraft:/', '', $binding['target']);
                break;
            case 'random':
                $this->aliases[preg_replace('/^minecraft:/', '', $binding['alias'])] ??= preg_replace('/^minecraft:/', '', self::weighted($binding['targets'], $random));
                break;
            case 'random_group':
                foreach (self::weighted($binding['groups'], $random) as $child) {
                    $this->bindAliases($child, $random);
                }
                break;
        }
    }

    private function alias(string $pool): string
    {
        $pool = preg_replace('/^minecraft:/', '', $pool);
        return $this->aliases[$pool] ?? $pool;
    }

    public function generate(array $structure, RandomSource $random, array $position, int $minY, int $maxY, callable $validBiome, int $seed = 0): ?array
    {
        // PoolAliasLookup.create from a positional random at the start position.
        $this->aliases = [];
        if (($structure['pool_aliases'] ?? []) !== []) {
            $aliasRandom = (new \PhpMinecraftJavaServer\World\Generation\PositionalRandom(false, (new \PhpMinecraftJavaServer\World\Generation\LegacyRandom($seed))->nextLong()))->at(...$position);
            foreach ($structure['pool_aliases'] as $binding) {
                $this->bindAliases($binding, $aliasRandom);
            }
        }
        $rotation = $random->nextInt(4);
        $pool = $this->pool($this->alias($structure['start_pool']));
        if ($pool['elements'] === []) {
            return null;
        }
        $element = $pool['elements'][$random->nextInt(count($pool['elements']))];
        if (self::type($element) === 'empty_pool_element') {
            return null;
        }
        $anchor = $position;
        if (isset($structure['start_jigsaw_name'])) {
            $found = null;
            foreach ($this->jigsaws($element, $position, $rotation, $random) as $jigsaw) {
                if (($jigsaw[4]['name'] ?? null) === $structure['start_jigsaw_name']) {
                    $found = $jigsaw;
                    break;
                }
            }
            if ($found === null) {
                return null;
            }
            $anchor = [$found[0], $found[1], $found[2]];
        }
        $local = [$anchor[0] - $position[0], $anchor[1] - $position[1], $anchor[2] - $position[2]];
        $adjusted = [$position[0] - $local[0], $position[1] - $local[1], $position[2] - $local[2]];
        $center = new JigsawPiece($element, $adjusted, self::groundLevelDelta($element), $rotation, $this->boundingBox($element, $adjusted, $rotation), $structure['liquid_settings'] ?? 'apply_waterlogging');
        $box = $center->box;
        $centerX = intdiv($box[3] + $box[0], 2);
        $centerZ = intdiv($box[5] + $box[2], 2);
        $bottomY = isset($structure['project_start_to_heightmap'])
            ? $position[1] + ($this->freeHeight)($centerX, $centerZ)
            : $adjusted[1];
        $center->move($bottomY - ($box[1] + $center->groundLevelDelta));
        $padding = $structure['dimension_padding'] ?? 0;
        [$padBottom, $padTop] = is_array($padding) ? [$padding['bottom'] ?? 0, $padding['top'] ?? 0] : [$padding, $padding];
        if (($padBottom !== 0 || $padTop !== 0) && ($center->box[1] < $minY + $padBottom || $center->box[4] > $maxY - $padTop)) {
            return null;
        }
        $centerY = $bottomY + $local[1];
        // Structure.findValidGenerationPoint: the biome check comes before the children are placed.
        if (!$validBiome($centerX, $centerY, $centerZ)) {
            return null;
        }
        $pieces = [$center];
        $depth = $structure['size'];
        if ($depth > 0) {
            $distance = $structure['max_distance_from_center'] ?? 80;
            [$horizontal, $vertical] = is_array($distance) ? [$distance['horizontal'], $distance['vertical'] ?? $distance['horizontal']] : [$distance, $distance];
            $free = new FreeSpace([
                $centerX - $horizontal,
                max($centerY - $vertical, $minY + $padBottom),
                $centerZ - $horizontal,
                $centerX + $horizontal,
                min($centerY + $vertical, $maxY - $padTop),
                $centerZ + $horizontal,
            ]);
            $free->subtract($center->box);
            $this->place($pieces, $center, $free, $depth, (bool) ($structure['use_expansion_hack'] ?? false), $random);
        }
        return [$pieces, [$centerX, $centerY, $centerZ]];
    }

    /** JigsawPlacement.Placer: breadth first by placement priority (SequencedPriorityIterator). */
    private function place(array &$pieces, JigsawPiece $center, FreeSpace $free, int $maxDepth, bool $expansionHack, RandomSource $random): void
    {
        $queues = [];
        $this->tryPlacingChildren($pieces, $queues, $center, $free, 0, $maxDepth, $expansionHack, $random);
        while ($queues !== []) {
            $priority = max(array_keys($queues));
            [$piece, $space, $depth] = array_shift($queues[$priority]);
            if ($queues[$priority] === []) {
                unset($queues[$priority]);
            }
            $this->tryPlacingChildren($pieces, $queues, $piece, $space, $depth, $maxDepth, $expansionHack, $random);
        }
    }

    private function tryPlacingChildren(array &$pieces, array &$queues, JigsawPiece $source, FreeSpace $contextFree, int $depth, int $maxDepth, bool $expansionHack, RandomSource $random): void
    {
        $sourceRigid = self::projection($source->element) === 'rigid';
        $sourceFree = null;
        $sourceBox = $source->box;
        $sourceBoxY = $sourceBox[1];
        foreach ($this->jigsaws($source->element, $source->position, $source->rotation, $random) as $sourceJigsaw) {
            $front = self::front($sourceJigsaw);
            [$dx, $dy, $dz] = self::STEPS[$front];
            $targetPos = [$sourceJigsaw[0] + $dx, $sourceJigsaw[1] + $dy, $sourceJigsaw[2] + $dz];
            $sourceLocalY = $sourceJigsaw[1] - $sourceBoxY;
            $sourceBaseHeight = null;
            $poolName = $this->alias($sourceJigsaw[4]['pool'] ?? 'minecraft:empty');
            $pool = $this->pool($poolName);
            if ($pool['elements'] === [] && $poolName !== 'empty') {
                continue;
            }
            $fallback = $this->pool($pool['fallback']);
            if ($fallback['elements'] === [] && $pool['fallback'] !== 'empty') {
                continue;
            }
            if (Piece::inside($sourceBox, ...$targetPos)) {
                $sourceFree ??= new FreeSpace($sourceBox);
                $childrenFree = $sourceFree;
            } else {
                $childrenFree = $contextFree;
            }
            $targets = $depth !== $maxDepth ? self::shuffled($pool['elements'], $random) : [];
            array_push($targets, ...self::shuffled($fallback['elements'], $random));
            $placementPriority = $sourceJigsaw[4]['placement_priority'] ?? 0;
            foreach ($targets as $target) {
                if (self::type($target) === 'empty_pool_element') {
                    break;
                }
                foreach (self::shuffled([0, 1, 2, 3], $random) as $rotation) {
                    $targetJigsaws = $this->jigsaws($target, [0, 0, 0], $rotation, $random);
                    $hackBox = $this->boundingBox($target, [0, 0, 0], $rotation);
                    $expandTo = 0;
                    if ($expansionHack && $hackBox[4] - $hackBox[1] + 1 <= 16) {
                        foreach ($targetJigsaws as $jigsaw) {
                            [$jx, $jy, $jz] = self::STEPS[self::front($jigsaw)];
                            if (!Piece::inside($hackBox, $jigsaw[0] + $jx, $jigsaw[1] + $jy, $jigsaw[2] + $jz)) {
                                continue;
                            }
                            $childPool = $this->alias($jigsaw[4]['pool'] ?? 'minecraft:empty');
                            $expandTo = max($expandTo, $this->maxSize($childPool), $this->maxSize($this->pool($childPool)['fallback']));
                        }
                    }
                    foreach ($targetJigsaws as $targetJigsaw) {
                        if (!self::canAttach($sourceJigsaw, $targetJigsaw)) {
                            continue;
                        }
                        $rawPos = [$targetPos[0] - $targetJigsaw[0], $targetPos[1] - $targetJigsaw[1], $targetPos[2] - $targetJigsaw[2]];
                        $rawBox = $this->boundingBox($target, $rawPos, $rotation);
                        $targetRigid = self::projection($target) === 'rigid';
                        $targetLocalY = $targetJigsaw[1];
                        $deltaY = $sourceLocalY - $targetLocalY + $dy;
                        if ($sourceRigid && $targetRigid) {
                            $targetBoxY = $sourceBoxY + $deltaY;
                        } else {
                            $sourceBaseHeight ??= ($this->freeHeight)($sourceJigsaw[0], $sourceJigsaw[2]);
                            $targetBoxY = $sourceBaseHeight - $targetLocalY;
                        }
                        $yOffset = $targetBoxY - $rawBox[1];
                        $targetBox = [$rawBox[0], $rawBox[1] + $yOffset, $rawBox[2], $rawBox[3], $rawBox[4] + $yOffset, $rawBox[5]];
                        $boxPosition = [$rawPos[0], $rawPos[1] + $yOffset, $rawPos[2]];
                        if ($expandTo > 0) {
                            $newSize = max($expandTo + 1, $targetBox[4] - $targetBox[1]);
                            $targetBox[4] = max($targetBox[4], $targetBox[1] + $newSize);
                        }
                        if (!$childrenFree->fits($targetBox)) {
                            continue;
                        }
                        $childrenFree->subtract($targetBox);
                        $sourceGround = $source->groundLevelDelta;
                        $targetGround = $targetRigid ? $sourceGround - $deltaY : self::groundLevelDelta($target);
                        $piece = new JigsawPiece($target, $boxPosition, $targetGround, $rotation, $targetBox, $source->liquid, $source->genDepth + 1);
                        if ($sourceRigid) {
                            $junctionY = $sourceBoxY + $sourceLocalY;
                        } elseif ($targetRigid) {
                            $junctionY = $targetBoxY + $targetLocalY;
                        } else {
                            $sourceBaseHeight ??= ($this->freeHeight)($sourceJigsaw[0], $sourceJigsaw[2]);
                            $junctionY = $sourceBaseHeight + intdiv($deltaY, 2);
                        }
                        $source->junctions[] = [$targetPos[0], $junctionY - $sourceLocalY + $sourceGround, $targetPos[2], $deltaY, self::projection($target)];
                        $piece->junctions[] = [$sourceJigsaw[0], $junctionY - $targetLocalY + $targetGround, $sourceJigsaw[2], -$deltaY, self::projection($source->element)];
                        $pieces[] = $piece;
                        if ($depth + 1 <= $maxDepth) {
                            $queues[$placementPriority][] = [$piece, $childrenFree, $depth + 1];
                        }
                        continue 4;
                    }
                }
            }
        }
    }
}
