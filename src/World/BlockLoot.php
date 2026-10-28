<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

/**
 * Vanilla block loot tables (reference/26.3/block-loot.json): pools, rolls, entries, the
 * predicates and item modifiers block tables use, evaluated as LootTable.getRandomItems does for
 * a block broken by an entity with a tool, without an explosion.
 */
final class BlockLoot
{
    /** @var \Closure(): float uniform in [0, 1) */
    private \Closure $random;

    /**
     * @param array{tables: array<string, array>, predicates: array<string, array>} $data
     * @param \Closure(string): list<string> $itemTag item tag => item ids
     * @param \Closure(string): list<string> $blockTag block tag => block ids
     * @param (\Closure(): float)|null $random
     */
    public function __construct(private readonly array $data, private readonly \Closure $itemTag, private readonly \Closure $blockTag, ?\Closure $random = null)
    {
        $this->random = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * The stacks dropped for a block, as item ids and counts.
     *
     * @param array<string, string> $state the broken block's properties
     * @param array{item: string, enchantments?: array<string, int>}|null $tool the breaking tool
     * @param \Closure(int): ?array{string, array<string, string>} $blockAt the block and properties at a Y offset
     * @param bool $entity whether an entity broke the block
     * @return list<array{string, int}>
     */
    public function drops(string $block, array $state, ?array $tool, \Closure $blockAt, bool $entity = true): array
    {
        $table = $this->data['tables'][$block] ?? null;
        if ($table === null) {
            return [];
        }
        $context = ['block' => $block, 'state' => $state, 'tool' => $tool, 'blockAt' => $blockAt, 'entity' => $entity];
        $stacks = [];
        foreach ($table['pools'] ?? [] as $pool) {
            if (!$this->conditions($pool['condition'] ?? null, $context)) {
                continue;
            }
            $rolls = $this->intNumber($pool['rolls'] ?? 1);
            for ($roll = 0; $roll < $rolls; ++$roll) {
                foreach ($this->roll($pool['entries'], $context) as $stack) {
                    $stack = $this->modify($stack, $pool['modifier'] ?? null, $context);
                    $stack = $this->modify($stack, $table['modifier'] ?? null, $context);
                    if ($stack[1] > 0) {
                        $stacks[] = $stack;
                    }
                }
            }
        }
        return $stacks;
    }

    /** LootPool.addRandomItem: one entry, chosen by weight among those that expand. */
    private function roll(array $entries, array $context): array
    {
        $candidates = [];
        foreach ($entries as $entry) {
            foreach ($this->expand($entry, $context) as $candidate) {
                $candidates[] = $candidate;
            }
        }
        if ($candidates === []) {
            return [];
        }
        if (count($candidates) === 1) {
            return $this->create($candidates[0], $context);
        }
        $total = array_sum(array_map(static fn (array $candidate): int => (int) ($candidate['weight'] ?? 1), $candidates));
        $pick = (int) floor(($this->random)() * $total);
        foreach ($candidates as $candidate) {
            $pick -= (int) ($candidate['weight'] ?? 1);
            if ($pick < 0) {
                return $this->create($candidate, $context);
            }
        }
        return [];
    }

    /** LootPoolEntryContainer.expand: singleton entries, or the first child of alternatives that expands. */
    private function expand(array $entry, array $context): array
    {
        if (!$this->conditions($entry['condition'] ?? null, $context)) {
            return [];
        }
        return match ($entry['type']) {
            'minecraft:item' => [$entry],
            'minecraft:alternatives' => (function () use ($entry, $context): array {
                foreach ($entry['children'] as $child) {
                    $expanded = $this->expand($child, $context);
                    if ($expanded !== []) {
                        return $expanded;
                    }
                }
                return [];
            })(),
            'minecraft:group', 'minecraft:sequence' => array_merge(...array_map(fn (array $child): array => $this->expand($child, $context), $entry['children'])),
            // Dynamic contents (a decorated pot's sherds) come from block entity data, which is not modelled.
            default => [],
        };
    }

    private function create(array $entry, array $context): array
    {
        if ($entry['type'] !== 'minecraft:item') {
            return [];
        }
        return [$this->modify([$entry['name'], 1], $entry['modifier'] ?? null, $context)];
    }

    /** Item modifiers (loot functions), in order. */
    private function modify(array $stack, mixed $modifiers, array $context): array
    {
        if ($modifiers === null) {
            return $stack;
        }
        foreach (array_is_list($modifiers) ? $modifiers : [$modifiers] as $modifier) {
            if (!$this->conditions($modifier['condition'] ?? null, $context)) {
                continue;
            }
            $stack[1] = match ($modifier['type']) {
                'minecraft:set_count' => ($modifier['add'] ?? false ? $stack[1] : 0) + $this->intNumber($modifier['count']),
                'minecraft:apply_bonus' => $this->bonus($stack[1], $modifier, $this->enchantment($context, $modifier['enchantment'])),
                'minecraft:limit_count' => $this->limit($stack[1], $modifier['limit']),
                // Without an explosion, decay keeps every item. Copied components and states are not modelled.
                default => $stack[1],
            };
        }
        return $stack;
    }

    /** ApplyBonusCount formulas. */
    private function bonus(int $count, array $modifier, int $level): int
    {
        $parameters = $modifier['parameters'] ?? [];
        switch ($modifier['formula']) {
            case 'minecraft:ore_drops':
                if ($level <= 0) {
                    return $count;
                }
                return $count * (max(0, $this->nextInt($level + 2) - 1) + 1);
            case 'minecraft:uniform_bonus_count':
                return $count + $this->nextInt((int) $parameters['bonusMultiplier'] * $level + 1);
            case 'minecraft:binomial_with_bonus_count':
                for ($trial = 0; $trial < $level + (int) $parameters['extra']; ++$trial) {
                    if (($this->random)() < $parameters['probability']) {
                        ++$count;
                    }
                }
                return $count;
        }
        return $count;
    }

    private function limit(int $count, array $limit): int
    {
        if (isset($limit['min'])) {
            $count = max($count, $this->intNumber($limit['min']));
        }
        if (isset($limit['max'])) {
            $count = min($count, $this->intNumber($limit['max']));
        }
        return $count;
    }

    /** A condition, a list of conditions (all must pass), or a named predicate. */
    private function conditions(mixed $condition, array $context): bool
    {
        if ($condition === null) {
            return true;
        }
        if (is_string($condition)) {
            return $this->conditions($this->data['predicates'][$condition] ?? throw new \RuntimeException('Unknown predicate ' . $condition), $context);
        }
        if (array_is_list($condition)) {
            foreach ($condition as $term) {
                if (!$this->conditions($term, $context)) {
                    return false;
                }
            }
            return true;
        }
        return match ($condition['type']) {
            'minecraft:survives_explosion' => true,
            'minecraft:all_of' => $this->conditions($condition['terms'], $context),
            'minecraft:any_of' => array_filter($condition['terms'], fn (mixed $term): bool => $this->conditions($term, $context)) !== [],
            'minecraft:inverted' => !$this->conditions($condition['term'], $context),
            'minecraft:random_chance' => ($this->random)() < $this->number($condition['chance']),
            'minecraft:table_bonus' => ($this->random)() < $condition['chances'][min($this->enchantment($context, $condition['enchantment']), count($condition['chances']) - 1)],
            'minecraft:match_block' => $this->matchBlock($condition, $context['block'], $context['state']),
            'minecraft:match_tool' => $this->matchTool($condition['predicate'] ?? [], $context['tool']),
            'minecraft:location_check' => ($found = ($context['blockAt'])((int) ($condition['offsetY'] ?? 0))) !== null && $this->matchBlock($condition['predicate']['block'] ?? [], $found[0], $found[1]),
            // The breaking entity exists; an empty predicate accepts any entity.
            'minecraft:entity_properties' => $context['entity'] && ($condition['predicate'] ?? []) === [],
            default => false,
        };
    }

    /** BlockPredicate / LootItemBlockStatePropertyCondition: the block and its listed properties. */
    private function matchBlock(array $predicate, string $block, array $state): bool
    {
        if (isset($predicate['blocks']) && !in_array($block, $this->ids($predicate['blocks'], $this->blockTag), true)) {
            return false;
        }
        foreach ($predicate['state'] ?? [] as $property => $expected) {
            $actual = $state[$property] ?? null;
            if ($actual === null) {
                return false;
            }
            if (is_array($expected)) {
                if ((isset($expected['min']) && (int) $actual < (int) $expected['min']) || (isset($expected['max']) && (int) $actual > (int) $expected['max'])) {
                    return false;
                }
            } elseif ((string) $actual !== (string) $expected) {
                return false;
            }
        }
        return true;
    }

    /** ItemPredicate: items and enchantment levels. */
    private function matchTool(array $predicate, ?array $tool): bool
    {
        if ($tool === null) {
            return false;
        }
        if (isset($predicate['items']) && !in_array($tool['item'], $this->ids($predicate['items'], $this->itemTag), true)) {
            return false;
        }
        foreach ($predicate['predicates']['minecraft:enchantments'] ?? [] as $required) {
            $matched = false;
            foreach ($tool['enchantments'] ?? [] as $name => $level) {
                if (isset($required['enchantments']) && !in_array($name, $this->ids($required['enchantments'], static fn (string $tag): array => []), true)) {
                    continue;
                }
                $levels = $required['levels'] ?? [];
                if (is_int($levels) ? $level === $levels : ((!isset($levels['min']) || $level >= $levels['min']) && (!isset($levels['max']) || $level <= $levels['max']))) {
                    $matched = true;
                }
            }
            if (!$matched) {
                return false;
            }
        }
        return true;
    }

    /** An id, a #tag or a list of ids. */
    private function ids(string|array $value, \Closure $tag): array
    {
        if (is_array($value)) {
            return $value;
        }
        return str_starts_with($value, '#') ? ($tag)(substr($value, 1)) : [$value];
    }

    private function enchantment(array $context, string $name): int
    {
        return (int) ($context['tool']['enchantments'][$name] ?? 0);
    }

    /** NumberProvider: a constant, uniform or binomial. */
    private function number(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        return match ($value['type'] ?? 'minecraft:constant') {
            'minecraft:uniform' => $this->number($value['min']) + ($this->random)() * ($this->number($value['max']) - $this->number($value['min'])),
            'minecraft:binomial' => (float) $this->intNumber($value),
            default => (float) $value['value'],
        };
    }

    private function intNumber(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            return (int) round($value);
        }
        return match ($value['type'] ?? 'minecraft:constant') {
            // UniformGenerator.getInt: Mth.nextInt over the rounded bounds.
            'minecraft:uniform' => ($minimum = (int) round($this->number($value['min']))) + $this->nextInt(max(0, (int) round($this->number($value['max'])) - $minimum) + 1),
            'minecraft:binomial' => (function () use ($value): int {
                $successes = 0;
                for ($trial = 0, $trials = $this->intNumber($value['n']); $trial < $trials; ++$trial) {
                    if (($this->random)() < $this->number($value['p'])) {
                        ++$successes;
                    }
                }
                return $successes;
            })(),
            default => (int) round($value['value']),
        };
    }

    private function nextInt(int $bound): int
    {
        return $bound <= 1 ? 0 : min($bound - 1, (int) floor(($this->random)() * $bound));
    }
}
