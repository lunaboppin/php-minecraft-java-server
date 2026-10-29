<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Packets;

/**
 * Entity attributes (reference/26.3/attributes.json): AttributeInstance base values and
 * modifiers. Players keep theirs on the Client; mobs keep theirs in their state under
 * 'attributeBases' and 'attributeModifiers'. Modifiers are kept per attribute in insertion order,
 * as [amount, operation, permanent]; status effects add theirs as transient modifiers.
 */
trait Attributes
{
    /** @return array<string, array{id: int, default: float, min?: float, max?: float, synced: bool, description: string}> */
    private function attributeDefinitions(): array
    {
        static $attributes = null;
        return $attributes ??= json_decode((string) file_get_contents($this->version->directory . '/attributes.json'), true, 512, JSON_THROW_ON_ERROR)['attributes'];
    }

    /** Whether an entity type has the attribute (DefaultAttributes' supplier for the type). */
    private function hasAttribute(string $kind, string $name): bool
    {
        return isset($this->version->catalog->mobs['entities'][$kind]['attributes'][$name]);
    }

    private function attributeBase(string $kind, array $bases, string $name): float
    {
        return (float) ($bases[$name] ?? $this->version->catalog->mobs['entities'][$kind]['attributes'][$name]);
    }

    /** AttributeInstance.calculateValue: added values, then multiplied base, then multiplied total, sanitized. */
    private function attributeValue(string $kind, array $bases, array $modifiers, string $name): float
    {
        $base = $this->attributeBase($kind, $bases, $name);
        $list = $modifiers[$name] ?? [];
        foreach ($list as [$amount, $operation]) {
            if ($operation === 0) {
                $base += $amount;
            }
        }
        $value = $base;
        foreach ($list as [$amount, $operation]) {
            if ($operation === 1) {
                $value += $base * $amount;
            }
        }
        foreach ($list as [$amount, $operation]) {
            if ($operation === 2) {
                $value *= 1.0 + $amount;
            }
        }
        $definition = $this->attributeDefinitions()['minecraft:' . $name];
        if (is_nan($value)) {
            return (float) ($definition['min'] ?? $value);
        }
        return isset($definition['min']) ? max($definition['min'], min($definition['max'], $value)) : $value;
    }

    private function playerAttribute(Client $c, string $name): float
    {
        return $this->attributeValue('player', $c->attributeBases, $c->attributeModifiers, $name);
    }

    /** One attribute as ClientboundUpdateAttributesPacket lists it: name, base and modifiers. */
    private function attributeEntry(string $kind, array $bases, array $modifiers, string $name): array
    {
        $list = [];
        foreach ($modifiers[$name] ?? [] as $id => [$amount, $operation]) {
            $list[] = [(string) $id, $amount, $operation];
        }
        return [$name, $this->attributeBase($kind, $bases, $name), $list];
    }

    /** A player's attribute entry; creative mode's interaction range modifiers come first, added at join. */
    private function playerAttributeEntry(Client $c, string $name): array
    {
        $entry = $this->attributeEntry('player', $c->attributeBases, $c->attributeModifiers, $name);
        foreach ($c->gameMode === 1 ? $this->interactionRanges($c) : [] as [$range, , $modifiers]) {
            if ($range === $name) {
                $entry[2] = [...$modifiers, ...$entry[2]];
            }
        }
        return $entry;
    }

    /** Sends a player's changed synchronized attributes to them and those tracking them. */
    private function syncPlayerAttributes(Client $c, array $names): void
    {
        $entries = [];
        foreach ($names as $name) {
            if ($this->attributeDefinitions()['minecraft:' . $name]['synced']) {
                $entries[] = $this->playerAttributeEntry($c, $name);
            }
        }
        if ($entries === []) {
            return;
        }
        $payload = Packets::attributeModifiers($this->version->catalog, $c->entityId, $entries);
        foreach ($this->players() as $player) {
            if ($player === $c || isset($player->entities[$c->entityId])) {
                $player->send($this->version, 'update_attributes', $payload);
            }
        }
        if (in_array('max_health', $names, true)) {
            $this->clampPlayerHealth($c);
        }
    }

    /** ServerEntity.sendPairingData: a player's synchronized attribute instances, for a new tracker. */
    private function pairingAttributes(Client $c): ?string
    {
        $entries = [];
        foreach (array_keys($c->attributeInstances) as $name) {
            if ($this->attributeDefinitions()['minecraft:' . $name]['synced']) {
                $entries[] = $this->playerAttributeEntry($c, $name);
            }
        }
        return $entries === [] ? null : Packets::attributeModifiers($this->version->catalog, $c->entityId, $entries);
    }

    /** LivingEntity.onAttributeUpdated: health falls to a lowered maximum. */
    private function clampPlayerHealth(Client $c): void
    {
        if ($c->health > $this->maxHealth($c)) {
            $c->health = $this->maxHealth($c);
            $this->health($c);
        }
    }

    /** A mob's synchronized attributes, as sent when it starts being tracked or changes. */
    private function mobAttributeEntries(array $mob, ?array $names = null): array
    {
        $entries = [];
        foreach ($names ?? array_keys($this->version->catalog->mobs['entities'][$mob['kind']]['attributes']) as $name) {
            if ($this->attributeDefinitions()['minecraft:' . $name]['synced']) {
                $entries[] = $this->attributeEntry($mob['kind'], $mob['attributeBases'] ?? [], $mob['attributeModifiers'] ?? [], $name);
            }
        }
        return $entries;
    }

    private function syncMobAttributes(int $id, array $names): void
    {
        $mob = &$this->animals[$id];
        $entries = $this->mobAttributeEntries($mob, $names);
        if ($entries !== []) {
            $payload = Packets::attributeModifiers($this->version->catalog, $id, $entries);
            foreach ($this->players() as $player) {
                if (isset($player->entities[$id])) {
                    $player->send($this->version, 'update_attributes', $payload);
                }
            }
        }
        if (in_array('max_health', $names, true)) {
            $max = (float) unpack('G', pack('G', $this->attributeValue($mob['kind'], $mob['attributeBases'] ?? [], $mob['attributeModifiers'] ?? [], 'max_health')))[1];
            if ($mob['health'] > $max) {
                $mob['health'] = $max;
                foreach ($this->players() as $player) {
                    if (isset($player->entities[$id])) {
                        $player->send($this->version, 'set_entity_data', Packets::mobHealth($this->version->catalog, $id, $max));
                    }
                }
            }
        }
    }

    /** Saved base values and permanent modifiers, then the loaded effects' modifiers. */
    private function loadAttributes(Client $c, mixed $data): void
    {
        [$c->attributeBases, $c->attributeModifiers] = [[], []];
        $c->attributeInstances = Client::PLAYER_ATTRIBUTE_INSTANCES;
        $definitions = $this->attributeDefinitions();
        foreach (is_array($data['instances'] ?? null) ? $data['instances'] : [] as $name) {
            if (is_string($name) && isset($definitions['minecraft:' . $name])) {
                $c->attributeInstances[$name] = true;
            }
        }
        foreach (is_array($data['bases'] ?? null) ? $data['bases'] : [] as $name => $base) {
            if (isset($definitions['minecraft:' . $name]) && is_numeric($base)) {
                $c->attributeBases[$name] = (float) $base;
                $c->attributeInstances[$name] = true;
            }
        }
        foreach (is_array($data['modifiers'] ?? null) ? $data['modifiers'] : [] as $name => $modifiers) {
            foreach (isset($definitions['minecraft:' . $name]) && is_array($modifiers) ? $modifiers : [] as $id => $modifier) {
                if (is_array($modifier) && is_numeric($modifier[0] ?? null) && in_array($modifier[1] ?? null, [0, 1, 2], true)) {
                    $c->attributeModifiers[$name][(string) $id] = [(float) $modifier[0], $modifier[1], true];
                    $c->attributeInstances[$name] = true;
                }
            }
        }
        foreach ($c->effects as $effect => $instance) {
            foreach ($this->effectDefinitions()[$effect]['attributes'] as $attribute => $modifier) {
                $amount = $modifier['amounts'][0] * ($instance['amplifier'] + 1);
                $c->attributeInstances[substr($attribute, 10)] = true;
                $c->attributeModifiers[substr($attribute, 10)][$modifier['id']] = [$amount, ['add_value' => 0, 'add_multiplied_base' => 1, 'add_multiplied_total' => 2][$modifier['operation']], false];
            }
        }
    }

    private function savedAttributes(Client $c): array
    {
        $modifiers = array_filter(array_map(static fn (array $list): array => array_map(static fn (array $modifier): array => [$modifier[0], $modifier[1]], array_filter($list, static fn (array $modifier): bool => $modifier[2])), $c->attributeModifiers));
        return ['bases' => $c->attributeBases, 'modifiers' => $modifiers, 'instances' => array_keys($c->attributeInstances)];
    }
}
