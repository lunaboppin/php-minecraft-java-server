<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Protocol;

use PhpMinecraftJavaServer\Protocol\Components\EnchantmentComponent;
use PhpMinecraftJavaServer\World\Generation\Int64;

/** Optional stacks with the component additions used by ordinary spawn equipment. */
final class ItemStackCodec
{
    private readonly EnchantmentComponent $enchantments;

    private readonly array $componentNames;

    private readonly array $componentFields;

    public function __construct(
        private readonly array $rules,
        private readonly array $enchantingRules
    ) {
        $ids = array_map(static fn (array $definition): int => $definition['id'], $enchantingRules['enchantments']);
        $this->enchantments = new EnchantmentComponent($ids);
        $this->componentNames = array_flip($rules['component_ids']);
        $this->componentFields = array_flip($rules['fields']);
    }

    public function read(Binary $input): ?array
    {
        $count = $input->varInt();
        if ($count <= 0) {
            return null;
        }
        $item = $input->varInt();
        if (!isset($this->rules['defaults'][$item])) {
            throw new ProtocolException('Unknown item holder');
        }
        $added = $input->varInt();
        $removed = $input->varInt();
        $total = Int64::signed32($added + $removed);
        // Match the native allocation check, including its signed count sum.
        $minimumBytes = max(0, $added) * 2 + max(0, $removed);
        if ($total < 0 || $minimumBytes > $input->remaining()) {
            throw new ProtocolException('Invalid component patch count');
        }
        $stack = ['item' => $item, 'count' => $count];
        for ($index = 0; $index < $added; ++$index) {
            $name = $this->componentName($input->varInt());
            $field = $this->componentFields[$name] ?? throw new ProtocolException('Unsupported component addition: ' . $name);
            $stack[$field] = match ($field) {
                'enchantments', 'storedEnchantments' => $this->enchantments->read($input),
                'damage' => $input->varInt(),
                'enchantability' => $this->readEnchantability($input),
            };
        }
        for ($index = 0; $index < $removed; ++$index) {
            $stack['removedComponents'][] = $this->componentName($input->varInt());
        }
        return $this->canonical($stack);
    }

    public function write(?array $stack): string
    {
        $stack = $this->canonical($stack);
        if ($stack === null) {
            return "\0";
        }
        $additions = [];
        foreach ($this->rules['fields'] as $field => $name) {
            if (!array_key_exists($field, $stack)) {
                continue;
            }
            $value = $stack[$field];
            $payload = match ($field) {
                'enchantments', 'storedEnchantments' => $this->enchantments->write($value),
                'damage', 'enchantability' => Binary::writeVarInt($value),
            };
            $additions[] = Binary::writeVarInt($this->rules['component_ids'][$name]) . $payload;
        }
        $removals = $stack['removedComponents'] ?? [];
        $output = Binary::writeVarInt($stack['count']) . Binary::writeVarInt($stack['item']);
        $output .= Binary::writeVarInt(count($additions)) . Binary::writeVarInt(count($removals));
        $output .= implode('', $additions);
        foreach ($removals as $name) {
            $output .= Binary::writeVarInt($this->rules['component_ids'][$name]);
        }
        return $output;
    }

    /** Normalize a patch against the item's actual default components. */
    public function canonical(?array $stack): ?array
    {
        if ($stack === null || $stack['count'] <= 0 || $stack['item'] === $this->rules['air_item']) {
            return null;
        }
        $defaults = $this->rules['defaults'][$stack['item']] ?? throw new \InvalidArgumentException('Unknown item holder');
        foreach ($stack as $field => $value) {
            if (!in_array($field, ['item', 'count', 'removedComponents'], true) && !isset($this->rules['fields'][$field])) {
                throw new \InvalidArgumentException('Unsupported stack field: ' . $field);
            }
        }
        $removals = [];
        foreach ($stack['removedComponents'] ?? [] as $name) {
            if (!isset($this->rules['component_ids'][$name])) {
                throw new \InvalidArgumentException('Unknown component removal');
            }
            if (isset($this->componentFields[$name])) {
                unset($stack[$this->componentFields[$name]]);
            }
            if (in_array($name, $defaults['present'], true)) {
                $removals[$name] = true;
            }
        }
        unset($stack['removedComponents']);
        foreach ($this->rules['fields'] as $field => $name) {
            if (!array_key_exists($field, $stack)) {
                continue;
            }
            if (in_array($field, ['enchantments', 'storedEnchantments'], true)) {
                $this->enchantments->write($stack[$field]);
                ksort($stack[$field]);
            } elseif (!is_int($stack[$field]) || ($field === 'enchantability' && $stack[$field] <= 0)) {
                throw new \InvalidArgumentException('Invalid ' . $field . ' component');
            }
            if (array_key_exists($field, $defaults['values']) && $stack[$field] === $defaults['values'][$field]) {
                unset($stack[$field]);
            }
        }
        if ($removals !== []) {
            $stack['removedComponents'] = array_keys($removals);
            sort($stack['removedComponents']);
        }
        ksort($stack);
        return $stack;
    }

    /** DataComponentHolder.has: a component set on the stack, or one of its item's defaults it keeps. */
    public function hasComponent(array $stack, string $name): bool
    {
        if (in_array($name, $stack['removedComponents'] ?? [], true)) {
            return false;
        }
        if (isset($this->componentFields[$name]) && array_key_exists($this->componentFields[$name], $stack)) {
            return true;
        }
        return in_array($name, $this->rules['defaults'][$stack['item']]['present'] ?? [], true);
    }

    /** Current merged component metadata for the native mob enchanting phase. */
    public function enchantingMetadata(?array $stack): array
    {
        $stack = $this->canonical($stack);
        $item = $stack['item'] ?? $this->rules['air_item'];
        $metadata = $this->enchantingRules['default_items'][$item];
        if ($stack === null) {
            return $metadata;
        }
        $values = $this->rules['defaults'][$item]['values'];
        foreach ($this->rules['fields'] as $field => $name) {
            if (array_key_exists($field, $stack ?? [])) {
                $values[$field] = $stack[$field];
            }
            if (in_array($name, $stack['removedComponents'] ?? [], true)) {
                unset($values[$field]);
            }
        }
        $metadata['enchantability'] = $values['enchantability'] ?? null;
        $storageField = $metadata['stored_book'] ? 'storedEnchantments' : 'enchantments';
        $metadata['can_store'] = array_key_exists($storageField, $values);
        return $metadata;
    }

    public function same(?array $first, ?array $second): bool
    {
        $first = $this->canonical($first);
        $second = $this->canonical($second);
        if ($first === null || $second === null) {
            return $first === $second;
        }
        unset($first['count'], $second['count']);
        return $first === $second;
    }

    private function componentName(int $id): string
    {
        return $this->componentNames[$id] ?? throw new ProtocolException('Unknown component holder');
    }

    private function readEnchantability(Binary $input): int
    {
        $value = $input->varInt();
        if ($value <= 0) {
            throw new ProtocolException('Enchantability must be positive');
        }
        return $value;
    }
}
