<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

/** Mutable spawn attributes retain bases and modifiers instead of flattening them. */
final class SpawnAttributes
{
    private array $bases;

    private array $modifiers = [];

    private array $operations = [];

    public function __construct(private readonly array $definitions, array $bases)
    {
        $this->bases = $bases;
    }

    public function setBase(string $attribute, float $value): void
    {
        $this->requireAttribute($attribute);
        $this->bases[$attribute] = $value;
    }

    public function hasModifier(string $attribute, string $identifier): bool
    {
        return isset($this->modifiers[$attribute][$identifier]);
    }

    public function add(string $attribute, string $identifier, float $amount, int $operation, bool $permanent = true): void
    {
        $this->requireAttribute($attribute);
        if ($operation < 0 || $operation > 2) {
            throw new \InvalidArgumentException('Unknown attribute modifier operation');
        }
        if (!preg_match('/^[a-z0-9_.-]+:[a-z0-9_\/.\-]+$/D', $identifier)) {
            throw new \InvalidArgumentException('Expected a canonical modifier identifier');
        }
        if ($this->hasModifier($attribute, $identifier)) {
            throw new \InvalidArgumentException('Modifier is already applied on this attribute');
        }
        $modifier = ['id' => $identifier, 'amount' => $amount, 'operation' => $operation, 'permanent' => $permanent];
        $this->modifiers[$attribute][$identifier] = $modifier;
        $this->operations[$attribute][$operation] ??= new ModifierMap();
        $this->operations[$attribute][$operation]->put($identifier, $modifier);
    }

    public function remove(string $attribute, string $identifier): void
    {
        $modifier = $this->modifiers[$attribute][$identifier] ?? null;
        if ($modifier !== null) {
            $this->operations[$attribute][$modifier['operation']]->remove($identifier);
            unset($this->modifiers[$attribute][$identifier]);
        }
    }

    public function replace(string $attribute, string $identifier, float $amount, int $operation, bool $permanent = true): void
    {
        $this->remove($attribute, $identifier);
        $this->add($attribute, $identifier, $amount, $operation, $permanent);
    }

    public function value(string $attribute): float
    {
        $this->requireAttribute($attribute);
        $base = (float) $this->bases[$attribute];
        foreach (($this->operations[$attribute][0] ?? null)?->values() ?? [] as $modifier) {
            $base += $modifier['amount'];
        }
        $value = $base;
        foreach (($this->operations[$attribute][1] ?? null)?->values() ?? [] as $modifier) {
            $value += $base * $modifier['amount'];
        }
        foreach (($this->operations[$attribute][2] ?? null)?->values() ?? [] as $modifier) {
            $value *= 1.0 + $modifier['amount'];
        }
        $definition = $this->definitions[$attribute];
        if (is_nan($value)) {
            return (float) $definition['min'];
        }
        return $value < $definition['min'] ? (float) $definition['min'] : min($value, (float) $definition['max']);
    }

    public function snapshot(): array
    {
        $state = [];
        foreach ($this->bases as $name => $base) {
            $state[$name] = ['base' => (float) $base, 'value' => $this->value($name), 'modifiers' => array_values($this->modifiers[$name] ?? [])];
        }
        return $state;
    }

    private function requireAttribute(string $attribute): void
    {
        if (!array_key_exists($attribute, $this->bases) || !isset($this->definitions[$attribute])) {
            throw new \InvalidArgumentException('Unknown spawn attribute: ' . $attribute);
        }
    }
}
