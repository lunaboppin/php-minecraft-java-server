<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait GameRuleCommand
{
    /** Rules with server behavior, mapped to the fields that implement them. */
    private const IMPLEMENTED_GAME_RULES = ['advance_time' => 'daylightCycle', 'keep_inventory' => 'keepInventory', 'natural_health_regeneration' => 'naturalRegeneration', 'spawn_mobs' => 'spawnMobs'];

    private function executeGameRule(Client $sender, array $arguments): void
    {
        $id = preg_replace('/^minecraft:/', '', $arguments[0] ?? '');
        $rule = $this->version->catalog->gameRules[$id] ?? null;
        if ($rule === null || !$rule['vanilla'] || count($arguments) > 2) {
            throw new \InvalidArgumentException('Usage: /gamerule <rule> [value]');
        }
        if (!isset($arguments[1])) {
            $this->sendSuccess($sender, ['translate' => 'commands.gamerule.query', 'with' => [['text' => $id], ['text' => $this->gameRule($id)]]], false);
            return;
        }
        $value = $rule['type'] === 'bool' ? $this->booleanRuleValue($arguments[1]) : $this->integerRuleValue($arguments[1], $rule);
        if ($value === $this->gameRule($id)) {
            throw new CommandFailure(['translate' => 'commands.gamerule.not_set', 'with' => [['text' => $id], ['text' => $value]]]);
        }
        if (isset(self::IMPLEMENTED_GAME_RULES[$id])) {
            $this->{self::IMPLEMENTED_GAME_RULES[$id]} = $value === 'true';
        } else {
            // Rules without behavior here are still stored and reported, as vanilla saves them.
            $rules = $this->world->setting('gameRules') ?? [];
            $rules[$id] = $value;
            $this->world->setting('gameRules', $rules);
        }
        $this->saveWorld();
        // advance_time changes every clock's effective rate.
        foreach ($this->players() as $player) {
            $player->send($this->version, 'set_time', $this->clockPacket(array_keys(self::CLOCKS)));
        }
        $this->sendSuccess($sender, ['translate' => 'commands.gamerule.set', 'with' => [['text' => $id], ['text' => $value]]], true);
    }

    /** A game rule's serialized value: implemented rules from their fields, others as stored or by default. */
    private function gameRule(string $id): string
    {
        if (isset(self::IMPLEMENTED_GAME_RULES[$id])) {
            return $this->{self::IMPLEMENTED_GAME_RULES[$id]} ? 'true' : 'false';
        }
        return ($this->world->setting('gameRules') ?? [])[$id] ?? $this->version->catalog->gameRules[$id]['default'];
    }

    private function booleanRuleValue(string $text): string
    {
        // BoolArgumentType accepts exactly true or false.
        if ($text !== 'true' && $text !== 'false') {
            throw new \InvalidArgumentException('Invalid boolean, expected true or false but found ' . $text);
        }
        return $text;
    }

    private function integerRuleValue(string $text, array $rule): string
    {
        $value = filter_var($text, FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new \InvalidArgumentException('Invalid integer: ' . $text);
        }
        if ($value < $rule['min'] || $value > $rule['max']) {
            throw new \InvalidArgumentException('Integer must be between ' . $rule['min'] . ' and ' . $rule['max'] . ', found ' . $value);
        }
        return (string) $value;
    }
}
