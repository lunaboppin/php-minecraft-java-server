<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\NbtDouble;
use PhpMinecraftJavaServer\Server\Client;

trait AttributeCommand
{
    /** AttributeCommand: get, base get|set|reset and modifier add|remove|value get, on players and mobs. */
    private function executeAttribute(Client $sender, array $arguments): void
    {
        $entity = $this->selectEntities($arguments[0], $sender, false, true)[0];
        $name = $this->entityName($entity);
        if (isset($entity['item'])) {
            throw new CommandFailure(['translate' => 'commands.attribute.failed.entity', 'with' => [$name]]);
        }
        $attribute = preg_replace('/^minecraft:/', '', $arguments[1]);
        $label = ['translate' => $this->attributeDefinitions()['minecraft:' . $attribute]['description']];
        $kind = substr($entity['type'], 10);
        if (!$this->hasAttribute($kind, $attribute)) {
            throw new CommandFailure(['translate' => 'commands.attribute.failed.no_attribute', 'with' => [$name, $label]]);
        }
        $client = $entity['client'] ?? null;
        $state = $client !== null
            ? ['bases' => $client->attributeBases, 'modifiers' => $client->attributeModifiers]
            : ['bases' => $this->animals[$entity['mob']]['attributeBases'] ?? [], 'modifiers' => $this->animals[$entity['mob']]['attributeModifiers'] ?? []];
        // Changed attributes reach clients with the entity's next tick, after the reply.
        $store = function (array $state, array $message) use ($sender, $client, $entity, $attribute): void {
            if ($client !== null) {
                [$client->attributeBases, $client->attributeModifiers] = [$state['bases'], $state['modifiers']];
            } else {
                $this->animals[$entity['mob']]['attributeBases'] = $state['bases'];
                $this->animals[$entity['mob']]['attributeModifiers'] = $state['modifiers'];
            }
            $this->sendSuccess($sender, $message, true);
            if ($client !== null) {
                $this->syncPlayerAttributes($client, [$attribute]);
                $this->savePlayer($client);
            } else {
                $this->syncMobAttributes($entity['mob'], [$attribute]);
            }
        };
        $modifierId = static fn (string $id): string => str_contains($id, ':') ? $id : 'minecraft:' . $id;
        switch ($arguments[2] === 'get' ? 'get' : $arguments[2] . ' ' . ($arguments[3] ?? '')) {
            case 'get':
                $value = $this->attributeValue($kind, $state['bases'], $state['modifiers'], $attribute);
                $this->sendSuccess($sender, ['translate' => 'commands.attribute.value.get.success', 'with' => [$label, $name, new NbtDouble($value)]], false);
                return;
            case 'base get':
                $value = $this->attributeBase($kind, $state['bases'], $attribute);
                $this->sendSuccess($sender, ['translate' => 'commands.attribute.base_value.get.success', 'with' => [$label, $name, new NbtDouble($value)]], false);
                return;
            case 'base set':
                $value = (float) $arguments[4];
                $state['bases'][$attribute] = $value;
                $store($state, ['translate' => 'commands.attribute.base_value.set.success', 'with' => [$label, $name, new NbtDouble($value)]]);
                return;
            case 'base reset':
                // AttributeInstance.setBaseValue(attribute default for the type).
                unset($state['bases'][$attribute]);
                $value = $this->attributeBase($kind, [], $attribute);
                $store($state, ['translate' => 'commands.attribute.base_value.reset.success', 'with' => [$label, $name, new NbtDouble($value)]]);
                return;
            case 'modifier add':
                $id = $modifierId($arguments[4]);
                if (isset($state['modifiers'][$attribute][$id])) {
                    throw new CommandFailure(['translate' => 'commands.attribute.failed.modifier_already_present', 'with' => [['text' => $id], $label, $name]]);
                }
                $operation = ['add_value' => 0, 'add_multiplied_base' => 1, 'add_multiplied_total' => 2][$arguments[6]];
                $state['modifiers'][$attribute][$id] = [(float) $arguments[5], $operation, true];
                $store($state, ['translate' => 'commands.attribute.modifier.add.success', 'with' => [['text' => $id], $label, $name]]);
                return;
            case 'modifier remove':
                $id = $modifierId($arguments[4]);
                if (!isset($state['modifiers'][$attribute][$id])) {
                    throw new CommandFailure(['translate' => 'commands.attribute.failed.no_modifier', 'with' => [$label, $name, ['text' => $id]]]);
                }
                unset($state['modifiers'][$attribute][$id]);
                if ($state['modifiers'][$attribute] === []) {
                    unset($state['modifiers'][$attribute]);
                }
                $store($state, ['translate' => 'commands.attribute.modifier.remove.success', 'with' => [['text' => $id], $label, $name]]);
                return;
            case 'modifier value':
                $id = $modifierId($arguments[5]);
                $modifier = $state['modifiers'][$attribute][$id] ?? null;
                if ($modifier === null) {
                    throw new CommandFailure(['translate' => 'commands.attribute.failed.no_modifier', 'with' => [$label, $name, ['text' => $id]]]);
                }
                $this->sendSuccess($sender, ['translate' => 'commands.attribute.modifier.value.get.success', 'with' => [['text' => $id], $label, $name, new NbtDouble($modifier[0])]], false);
                return;
        }
        throw new \InvalidArgumentException('Usage: /attribute <target> <attribute> get|base|modifier ...');
    }
}
