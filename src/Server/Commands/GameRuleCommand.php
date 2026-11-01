<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait GameRuleCommand
{
    /** Rules with server behavior, mapped to the fields that implement them. */
    /** Stored values of the rules without a field, read once from the Overworld. */
    private ?array $storedGameRules = null;

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
            $rules = $this->worlds['overworld']->setting('gameRules') ?? [];
            $rules[$id] = $value;
            $this->worlds['overworld']->setting('gameRules', $rules);
            $this->storedGameRules = $rules;
        }
        $this->gameRuleChanged($id, $value);
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
        // Stored values are read once and kept until a rule changes.
        $this->storedGameRules ??= $this->worlds['overworld']->setting('gameRules') ?? [];
        return $this->storedGameRules[$id] ?? $this->version->catalog->gameRules[$id]['default'];
    }

    /**
     * GameRules change callbacks that reach clients: immediate respawn and limited crafting as game
     * events, reduced debug info as an entity event for each player.
     */
    private function gameRuleChanged(string $id, string $value): void
    {
        $enabled = $value === 'true';
        if ($id === 'locator_bar') {
            $this->locatorBarChanged($enabled);
        }
        foreach ($this->players() as $player) {
            match ($id) {
                'immediate_respawn' => $player->send($this->version, 'game_event', "\x0b" . pack('G', $enabled ? 1.0 : 0.0)),
                'limited_crafting' => $player->send($this->version, 'game_event', "\x0c" . pack('G', $enabled ? 1.0 : 0.0)),
                'reduced_debug_info' => $player->send($this->version, 'entity_event', pack('N', $player->entityId) . chr($enabled ? 22 : 23)),
                default => null,
            };
        }
    }

    private function booleanRuleValue(string $text): string
    {
        // BoolArgumentType reads a string, so a quoted "true" or 'false' is accepted too.
        if (preg_match('/\A(["\'])(true|false)\1\z/D', $text, $match) === 1) {
            $text = $match[2];
        }
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
