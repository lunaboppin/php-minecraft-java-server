<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\Int64;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\PositionalRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/**
 * PoolElementStructurePiece: a template pool element placed with its processors (rule, block_rot,
 * protected_blocks, capped, gravity for terrain matching, jigsaw replacement) and known shapes.
 */
final class JigsawPiece extends Piece
{
    /** @var list<array{0: int, 1: int, 2: int, 3: int, 4: string}> JigsawJunctions: x, ground y, z, delta y, projection */
    public array $junctions = [];

    public function __construct(public readonly array $element, public array $position, public readonly int $groundLevelDelta, public readonly int $rotation, array $box, public readonly string $liquid, int $genDepth = 0)
    {
        parent::__construct($box, 'north', $genDepth);
    }

    public function type(): string
    {
        return 'jigsaw';
    }

    public function data(): array
    {
        return ['element' => $this->element, 'position' => $this->position, 'ground' => $this->groundLevelDelta, 'rotation' => $this->rotation, 'liquid' => $this->liquid, 'depth' => $this->genDepth, 'junctions' => $this->junctions];
    }

    public static function restoreWith(array $piece): self
    {
        $restored = new self($piece['element'], $piece['position'], $piece['ground'], $piece['rotation'], $piece['box'], $piece['liquid'], $piece['depth']);
        $restored->junctions = $piece['junctions'];
        return $restored;
    }

    public function move(int $dy): void
    {
        $this->box[1] += $dy;
        $this->box[4] += $dy;
        $this->position[1] += $dy;
    }

    public function postProcess(StructureContext $context, Region $region, RandomSource $random, array $chunkBox): void
    {
        $this->placeElement($context, $region, $random, $chunkBox, $this->element);
    }

    private function placeElement(StructureContext $context, Region $region, RandomSource $random, array $chunkBox, array $element): bool
    {
        $type = preg_replace('/^minecraft:/', '', $element['element_type']);
        if ($type === 'list_pool_element') {
            foreach ($element['elements'] as $child) {
                if (!$this->placeElement($context, $region, $random, $chunkBox, $child + ['projection' => $element['projection'] ?? 'rigid'])) {
                    return false;
                }
            }
            return true;
        }
        if ($type === 'feature_pool_element') {
            return ($context->placeFeature)($element['feature'], $region, $random, $this->position);
        }
        if ($type !== 'single_pool_element' && $type !== 'legacy_single_pool_element') {
            return true;
        }
        $template = Template::load($context->blocks, $context->directory, $element['location']);
        $processors = $this->processorList($context, $element['processors'] ?? []);
        $legacy = $type === 'legacy_single_pool_element';
        $whole = false;
        foreach ($processors as $processor) {
            $whole = $whole || preg_replace('/^minecraft:/', '', $processor['processor_type']) === 'capped';
        }
        $reference = $context->referencePos;
        $process = function (array $info, array $original) use ($context, $region, $processors, $legacy, $element, $reference): ?array {
            $name = $context->blocks->name($info[3]);
            // BlockIgnoreProcessor.STRUCTURE_BLOCK (single), JigsawReplacementProcessor
            if (!$legacy && $name === 'structure_block') {
                return null;
            }
            if ($name === 'jigsaw') {
                $final = $context->blocks->parse($info[4]['final_state'] ?? 'minecraft:air');
                if ($context->blocks->name($final) === 'structure_void') {
                    return null;
                }
                $info = [$info[0], $info[1], $info[2], $final, null];
            }
            foreach ($processors as $processor) {
                $info = $this->process($context, $region, $processor, $info, $original, $reference);
                if ($info === null) {
                    return null;
                }
            }
            if (($element['projection'] ?? 'rigid') === 'terrain_matching') {
                // GravityProcessor(WORLD_SURFACE_WG, -1)
                $info[1] = $region->height(Region::WORLD_SURFACE_WG, $info[0], $info[2]) - 1 + $original[1];
            }
            // BlockIgnoreProcessor.STRUCTURE_AND_AIR (legacy elements, last)
            if ($legacy && in_array($context->blocks->name($info[3]), ['structure_block', 'air'], true)) {
                return null;
            }
            return $info;
        };
        $finalize = $whole ? fn (array $processed, array $originals): array => $this->finalize($context, $region, $processors, $processed, $originals, $reference) : null;
        $liquid = $element['override_liquid_settings'] ?? $this->liquid;
        return $template->place($context, $region, $this->position, BlockTransform::NONE, $this->rotation, [0, 0, 0], $chunkBox, $random, $process, $liquid === 'apply_waterlogging', $whole, false, $finalize, true, true);
    }

    /** @return list<array> */
    private function processorList(StructureContext $context, mixed $list): array
    {
        if (is_string($list)) {
            $list = json_decode((string) file_get_contents($context->directory . '/processor_list/' . preg_replace('/^minecraft:/', '', $list) . '.json'), true, 512, JSON_THROW_ON_ERROR);
        }
        return $list['processors'] ?? $list;
    }

    private static function inSet(StructureContext $context, mixed $set, int $state): bool
    {
        foreach ((array) $set as $block) {
            if ($context->blocks->is($state, $block)) {
                return true;
            }
        }
        return false;
    }

    /** StructureProcessor.processBlock for one processor. */
    private function process(StructureContext $context, Region $region, array $processor, array $info, array $original, array $reference): ?array
    {
        switch (preg_replace('/^minecraft:/', '', $processor['processor_type'])) {
            case 'rule':
                $random = new LegacyRandom(Int64::coordinateSeed($info[0], $info[1], $info[2]));
                $existing = $region->get($info[0], $info[1], $info[2]);
                foreach ($processor['rules'] as $rule) {
                    if ($context->predicates->rule($rule['input_predicate'], $info[3], $info[1], $random)
                        && $context->predicates->rule($rule['location_predicate'], $existing, $info[1], $random)
                        && $this->position($rule['position_predicate'] ?? null, $info, $reference, $random)) {
                        return [$info[0], $info[1], $info[2], $context->blocks->parse($rule['output_state']), $info[4]];
                    }
                }
                return $info;
            case 'block_rot':
                $random = Template::positionalRandom($info[0], $info[1], $info[2]);
                $rottable = $processor['rottable_blocks'] ?? null;
                return ($rottable === null || self::inSet($context, $rottable, $info[3])) && !($random->nextFloat() <= F32::r((float) $processor['integrity'])) ? null : $info;
            case 'protected_blocks':
                return self::inSet($context, $processor['value'], $region->get($info[0], $info[1], $info[2])) ? null : $info;
            case 'capped':
                return $info;
        }
        return $info;
    }

    /** PosRuleTest: always_true or axis_aligned_linear_pos. */
    private function position(?array $predicate, array $info, array $reference, RandomSource $random): bool
    {
        if ($predicate === null || preg_replace('/^minecraft:/', '', $predicate['predicate_type']) === 'always_true') {
            return true;
        }
        $axis = ['x' => 0, 'y' => 1, 'z' => 2][$predicate['axis'] ?? 'y'];
        $distance = abs($info[$axis] - $reference[$axis]);
        $chance = $random->nextFloat();
        $min = (float) ($predicate['min_dist'] ?? 0);
        $max = (float) ($predicate['max_dist'] ?? 10);
        $factor = F32::r(($distance - $min) / ($max - $min));
        $minChance = F32::r((float) ($predicate['min_chance'] ?? 0.0));
        $maxChance = F32::r((float) ($predicate['max_chance'] ?? 0.0));
        $threshold = $factor < 0.0 ? $minChance : ($factor > 1.0 ? $maxChance : F32::r($minChance + F32::r($factor * F32::r($maxChance - $minChance))));
        return $chance <= $threshold;
    }

    /** CappedProcessor.finalizeProcessing: the delegate applied to at most its limit of shuffled blocks. */
    private function finalize(StructureContext $context, Region $region, array $processors, array $processed, array $originals, array $reference): array
    {
        foreach ($processors as $processor) {
            if (preg_replace('/^minecraft:/', '', $processor['processor_type']) !== 'capped' || $processed === []) {
                continue;
            }
            $random = (new PositionalRandom(false, (new LegacyRandom($context->seed))->nextLong()))->at(...$this->position);
            $limit = $processor['limit'];
            $max = is_int($limit) ? $limit : $limit['max_inclusive'] ?? $limit['value'] ?? 0;
            if ($max === 0) {
                continue;
            }
            $count = min(is_int($limit) ? $limit : \PhpMinecraftJavaServer\World\Vanilla\Decoration\Providers::int($limit, $random), count($processed));
            if ($count < 1) {
                continue;
            }
            $indexes = range(0, count($processed) - 1);
            for ($i = count($indexes); $i > 1; --$i) {
                $j = $random->nextInt($i);
                [$indexes[$i - 1], $indexes[$j]] = [$indexes[$j], $indexes[$i - 1]];
            }
            $replaced = 0;
            foreach ($indexes as $index) {
                if ($replaced >= $count) {
                    break;
                }
                $altered = $this->process($context, $region, $processor['delegate'], $processed[$index], $originals[$index], $reference);
                if ($altered !== null && $altered !== $processed[$index]) {
                    ++$replaced;
                    $processed[$index] = $altered;
                }
            }
        }
        return $processed;
    }
}
