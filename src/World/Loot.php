<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

/**
 * Vanilla loot tables (reference/26.3/loot.json): pools, rolls, entries, predicates and item
 * modifiers, evaluated as LootTable.getRandomItems does for broken blocks and killed entities,
 * without explosions.
 *
 * A context may hold:
 * - block, state and blockAt (the block, its properties, the block at a Y offset) and tool;
 * - this, attacker and direct_attacker entities, killed_by_player and damage.
 * An entity is ['type' => id, 'flags' => [...], 'components' => [...], 'type_specific' => [kind => [...]],
 * 'equipment' => [slot => stack], 'vehicle' => ?entity]; a stack is ['item' => id, 'enchantments' => [id => level]].
 */
final class Loot
{
    /** @var \Closure(): float uniform in [0, 1) */
    private \Closure $random;

    /**
     * @param array{tables: array<string, array>, predicates: array<string, array>} $data
     * @param \Closure(string, string): list<string> $tag registry and tag => full entry ids
     * @param \Closure(string): ?string $smelt the smelting result of an item
     * @param (\Closure(): float)|null $random
     */
    public function __construct(private readonly array $data, private readonly \Closure $tag, private readonly \Closure $smelt, ?\Closure $random = null)
    {
        $this->random = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * The stacks a table drops, as item ids and counts.
     *
     * @return list<array{string, int}>
     */
    public function drops(string $table, array $context): array
    {
        $table = $this->data['tables'][$table] ?? null;
        if ($table === null) {
            return [];
        }
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

    /**
     * LootPoolEntryContainer.expand: singleton entries, the first child of alternatives that
     * expands, every child of a group, and each item of an expanded tag.
     */
    private function expand(array $entry, array $context): array
    {
        if (!$this->conditions($entry['condition'] ?? null, $context)) {
            return [];
        }
        switch ($entry['type']) {
            case 'minecraft:item':
            case 'minecraft:loot_table':
            case 'minecraft:empty':
                return [$entry];
            case 'minecraft:tag':
                $items = ($this->tag)('minecraft:item', substr($entry['items'] ?? $entry['name'], 1));
                if ($entry['expand'] ?? false) {
                    return array_map(static fn (string $item): array => ['type' => 'minecraft:item', 'name' => $item, 'weight' => $entry['weight'] ?? 1, 'modifier' => $entry['modifier'] ?? null], $items);
                }
                return [['type' => 'minecraft:items', 'names' => $items, 'weight' => $entry['weight'] ?? 1, 'modifier' => $entry['modifier'] ?? null]];
            case 'minecraft:alternatives':
                foreach ($entry['children'] as $child) {
                    $expanded = $this->expand($child, $context);
                    if ($expanded !== []) {
                        return $expanded;
                    }
                }
                return [];
            case 'minecraft:group':
            case 'minecraft:sequence':
                return array_merge(...array_map(fn (array $child): array => $this->expand($child, $context), $entry['children']));
        }
        // Dynamic contents (a decorated pot's sherds) come from block entity data, which is not modelled.
        return [];
    }

    private function create(array $entry, array $context): array
    {
        $stacks = match ($entry['type']) {
            'minecraft:item' => [[$entry['name'], 1]],
            'minecraft:items' => array_map(static fn (string $item): array => [$item, 1], $entry['names']),
            'minecraft:loot_table' => $this->drops($entry['value'], $context),
            default => [],
        };
        return array_map(fn (array $stack): array => $this->modify($stack, $entry['modifier'] ?? null, $context), $stacks);
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
            switch ($modifier['type']) {
                case 'minecraft:set_count':
                    $stack[1] = ($modifier['add'] ?? false ? $stack[1] : 0) + $this->intNumber($modifier['count']);
                    break;
                case 'minecraft:apply_bonus':
                    $stack[1] = $this->bonus($stack[1], $modifier, $this->level($context['tool'] ?? null, $modifier['enchantment']));
                    break;
                case 'minecraft:enchanted_count_increase':
                    // EnchantedCountIncreaseFunction: the attacker's level times the count, rounded, then limited.
                    $level = $this->level($context['attacker']['equipment']['mainhand'] ?? null, $modifier['enchantment']);
                    if ($level > 0) {
                        $stack[1] += (int) round($level * $this->number($modifier['count']));
                        if (($modifier['limit'] ?? 0) > 0) {
                            $stack[1] = min($stack[1], $modifier['limit']);
                        }
                    }
                    break;
                case 'minecraft:limit_count':
                    $stack[1] = $this->limit($stack[1], $modifier['limit']);
                    break;
                case 'minecraft:furnace_smelt':
                    $stack[0] = ($this->smelt)($stack[0]) ?? $stack[0];
                    break;
                    // Without an explosion, decay keeps every item. Copied components, states and potions are not modelled.
            }
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
        switch ($condition['type']) {
            case 'minecraft:survives_explosion':
                // ExplosionCondition: with an explosion radius, one chance in the radius.
                return !isset($context['explosionRadius']) || ($this->random)() <= 1.0 / $context['explosionRadius'];
            case 'minecraft:all_of':
                return $this->conditions($condition['terms'], $context);
            case 'minecraft:any_of':
                return array_filter($condition['terms'], fn (mixed $term): bool => $this->conditions($term, $context)) !== [];
            case 'minecraft:inverted':
                return !$this->conditions($condition['term'], $context);
            case 'minecraft:random_chance':
                return ($this->random)() < $this->number($condition['chance']);
            case 'minecraft:random_chance_with_enchanted_bonus':
                // LevelBasedValue.Linear: base, plus per_level_above_first for each level above the first.
                $level = $this->level($context['attacker']['equipment']['mainhand'] ?? null, $condition['enchantment']);
                $bonus = $condition['enchanted_chance'];
                $chance = $level > 0 ? (is_array($bonus) ? $bonus['base'] + $bonus['per_level_above_first'] * ($level - 1) : $bonus) : $condition['unenchanted_chance'];
                return ($this->random)() < $chance;
            case 'minecraft:table_bonus':
                return ($this->random)() < $condition['chances'][min($this->level($context['tool'] ?? null, $condition['enchantment']), count($condition['chances']) - 1)];
            case 'minecraft:match_block':
                return isset($context['block']) && $this->matchBlock($condition, $context['block'], $context['state']);
            case 'minecraft:match_tool':
                return $this->matchItem($condition['predicate'] ?? [], $context['tool'] ?? null);
            case 'minecraft:location_check':
                $found = isset($context['blockAt']) ? ($context['blockAt'])((int) ($condition['offsetY'] ?? 0)) : null;
                return $found !== null && $this->matchBlock($condition['predicate']['block'] ?? [], $found[0], $found[1]);
            case 'minecraft:killed_by_player':
                return (bool) ($context['killed_by_player'] ?? false);
            case 'minecraft:entity_properties':
                return $this->matchEntity($condition['predicate'] ?? [], $context[$condition['entity']] ?? null);
            case 'minecraft:damage_source_properties':
                return $this->matchDamage($condition['predicate'] ?? [], $context['damage'] ?? null);
        }
        return false;
    }

    /** BlockPredicate / LootItemBlockStatePropertyCondition: the block and its listed properties. */
    private function matchBlock(array $predicate, string $block, array $state): bool
    {
        if (isset($predicate['blocks']) && !in_array($block, $this->ids('minecraft:block', $predicate['blocks']), true)) {
            return false;
        }
        foreach ($predicate['state'] ?? [] as $property => $expected) {
            if (!isset($state[$property]) || !$this->matchValue($state[$property], $expected)) {
                return false;
            }
        }
        return true;
    }

    /** ItemPredicate: items and enchantment levels. */
    private function matchItem(array $predicate, ?array $stack): bool
    {
        if ($stack === null) {
            return false;
        }
        if (isset($predicate['items']) && !in_array($stack['item'], $this->ids('minecraft:item', $predicate['items']), true)) {
            return false;
        }
        foreach ($predicate['predicates']['minecraft:enchantments'] ?? [] as $required) {
            $matched = false;
            foreach ($stack['enchantments'] ?? [] as $name => $level) {
                if (isset($required['enchantments']) && !in_array($name, $this->ids('minecraft:enchantment', $required['enchantments']), true)) {
                    continue;
                }
                if (!isset($required['levels']) || $this->matchValue($level, $required['levels'])) {
                    $matched = true;
                }
            }
            if (!$matched) {
                return false;
            }
        }
        return true;
    }

    /** EntityPredicate: type, flags, equipment, data components, type-specific data and the vehicle. */
    private function matchEntity(array $predicate, ?array $entity): bool
    {
        if ($entity === null) {
            return false;
        }
        foreach ($predicate as $key => $expected) {
            $matched = match (true) {
                $key === 'minecraft:entity_type' => in_array($entity['type'], $this->ids('minecraft:entity_type', $expected), true),
                $key === 'minecraft:flags' => array_filter($expected, static fn (bool $value, string $flag): bool => ($entity['flags'][$flag] ?? false) !== $value, ARRAY_FILTER_USE_BOTH) === [],
                $key === 'minecraft:equipment' => array_filter($expected, fn (array $item, string $slot): bool => !$this->matchItem($item, $entity['equipment'][$slot] ?? null), ARRAY_FILTER_USE_BOTH) === [],
                $key === 'minecraft:components' => array_filter($expected, static fn (mixed $value, string $component): bool => ($entity['components'][$component] ?? null) !== $value, ARRAY_FILTER_USE_BOTH) === [],
                str_starts_with($key, 'minecraft:type_specific/') => $this->matchFields($expected, $entity['type_specific'][substr($key, 24)] ?? null),
                $key === 'minecraft:vehicle' => $this->matchEntity($expected, $entity['vehicle'] ?? null),
                default => false,
            };
            if (!$matched) {
                return false;
            }
        }
        return true;
    }

    /** DamageSourcePredicate: the source and direct entities and damage type tags. */
    private function matchDamage(array $predicate, ?array $damage): bool
    {
        if ($damage === null) {
            return false;
        }
        foreach (['source_entity', 'direct_entity'] as $role) {
            if (isset($predicate[$role]) && !$this->matchEntity($predicate[$role], $damage[$role] ?? null)) {
                return false;
            }
        }
        foreach ($predicate['tags'] ?? [] as $tag) {
            if (in_array($damage['type'], $this->ids('minecraft:damage_type', $tag['id']), true) !== $tag['expected']) {
                return false;
            }
        }
        return true;
    }

    private function matchFields(array $expected, ?array $actual): bool
    {
        if ($actual === null) {
            return false;
        }
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $actual) || !$this->matchValue($actual[$field], $value)) {
                return false;
            }
        }
        return true;
    }

    /** An exact value or a {min, max} range. */
    private function matchValue(mixed $actual, mixed $expected): bool
    {
        if (is_array($expected)) {
            return (!isset($expected['min']) || $actual >= $expected['min']) && (!isset($expected['max']) || $actual <= $expected['max']);
        }
        return is_bool($expected) ? $actual === $expected : (string) $actual === (string) $expected;
    }

    /** An id, a #tag or a list of ids. */
    private function ids(string $registry, string|array $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        return str_starts_with($value, '#') ? ($this->tag)($registry, substr($value, 1)) : [$value];
    }

    private function level(?array $stack, string $enchantment): int
    {
        return (int) ($stack['enchantments'][$enchantment] ?? 0);
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
        switch ($value['type'] ?? 'minecraft:constant') {
            case 'minecraft:uniform':
                // UniformGenerator.getInt: Mth.nextInt over the integer bounds.
                $minimum = $this->intNumber($value['min']);
                return $minimum + $this->nextInt(max(0, $this->intNumber($value['max']) - $minimum) + 1);
            case 'minecraft:binomial':
                $successes = 0;
                for ($trial = 0, $trials = $this->intNumber($value['n']); $trial < $trials; ++$trial) {
                    if (($this->random)() < $this->number($value['p'])) {
                        ++$successes;
                    }
                }
                return $successes;
        }
        return (int) round($value['value']);
    }

    private function nextInt(int $bound): int
    {
        return $bound <= 1 ? 0 : min($bound - 1, (int) floor(($this->random)() * $bound));
    }
}
