<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Authentication\ProfileFetcher;
use PhpMinecraftJavaServer\Protocol\NbtIntArray;
use PhpMinecraftJavaServer\Server\Client;

trait FetchProfileCommand
{
    private ?ProfileFetcher $profileFetcher = null;

    /**
     * FetchProfileCommand: a player's profile, or one looked up by name or UUID off the tick, with
     * links to copy it, give its head, summon a mannequin wearing it, or copy its head sprite.
     */
    private function executeFetchProfile(Client $sender, array $arguments, string $text): void
    {
        $reader = new StringReader(ltrim($text));
        $reader->readUnquotedString();
        $reader->skipWhitespace();
        $mode = $reader->readUnquotedString();
        $reader->skipWhitespace();
        if ($mode === 'entity') {
            $entity = $this->selectEntities(substr($reader->string, $reader->cursor), $sender, false, true)[0];
            if (!isset($entity['client'])) {
                throw new CommandFailure(['translate' => 'commands.fetchprofile.no_profile', 'with' => [$this->entityName($entity)]]);
            }
            $player = $entity['client'];
            $this->reportProfile($sender, 'commands.fetchprofile.entity.success', $this->displayName($player), $player->uuid, $player->name, $player->profileProperties);
            return;
        }
        $this->profileFetcher ??= new ProfileFetcher();
        if ($mode === 'name') {
            $name = substr($reader->string, $reader->cursor);
            $argument = ['text' => $name];
            // StringUtil.isValidPlayerName: at most 16 characters, none of them spaces or controls.
            $valid = mb_strlen($name, 'UTF-8') <= 16 && preg_match('/[\x00-\x20\x7f-\x{10ffff}]/u', $name) !== 1;
            $done = fn (?array $profile) => $this->profileFetched($sender, $profile, 'commands.fetchprofile.name', $argument);
            if ($valid) {
                $this->profileFetcher->byName($name, $done);
            } else {
                $this->profilesUnresolved[] = $done;
            }
            return;
        }
        $uuid = ArgumentReader::uuid($reader);
        $formatted = implode('-', [substr($uuid, 0, 8), substr($uuid, 8, 4), substr($uuid, 12, 4), substr($uuid, 16, 4), substr($uuid, 20)]);
        $this->profileFetcher->byId($uuid, fn (?array $profile) => $this->profileFetched($sender, $profile, 'commands.fetchprofile.id', ['text' => $formatted]));
    }

    /** @var list<\Closure> lookups that fail without a request, answered next tick as the IO pool would */
    private array $profilesUnresolved = [];

    /** Runs the replies of lookups that finished, between ticks as MinecraftServer.execute does. */
    private function pollProfileFetches(): void
    {
        foreach (array_splice($this->profilesUnresolved, 0) as $done) {
            $done(null);
        }
        $this->profileFetcher?->poll();
    }

    private function profileFetched(Client $sender, ?array $profile, string $key, array $argument): void
    {
        if ($profile === null) {
            $this->sendFailure($sender, ['translate' => $key . '.failure', 'with' => [$argument]]);
            return;
        }
        $this->reportProfile($sender, $key . '.success', $argument, $profile['uuid'], $profile['name'], $profile['properties']);
    }

    private function reportProfile(Client $sender, string $key, array $argument, string $uuid, string $name, array $properties): void
    {
        $ints = NbtIntArray::uuid($uuid);
        $profileTag = ['id' => ['type' => 'int_array', 'value' => $ints->values], 'name' => ['type' => 'string', 'value' => $name]];
        $profile = ['id' => $ints, 'name' => $name];
        if ($properties !== []) {
            $profileTag['properties'] = ['type' => 'list', 'value' => array_map(static fn (array $property): array => ['type' => 'compound', 'value' => array_map(
                static fn (string $value): array => ['type' => 'string', 'value' => $value],
                array_filter(['name' => $property['name'], 'value' => $property['value'], 'signature' => $property['signature'] ?? null], 'is_string'),
            )], $properties)];
            $profile['properties'] = array_map(static fn (array $property): array => array_filter(['name' => $property['name'], 'value' => $property['value'], 'signature' => $property['signature'] ?? null], 'is_string'), $properties);
        }
        $encoded = Snbt::toText(['type' => 'compound', 'value' => $profileTag]);
        $link = static fn (string $translate, array $clickEvent, array $with = []): array => ['translate' => 'chat.square_brackets', 'with' => [
            ['translate' => $translate] + ($with === [] ? [] : ['with' => $with]) + ['color' => 'green', 'click_event' => $clickEvent],
        ]];
        $links = [
            $link('commands.fetchprofile.copy_component', ['action' => 'copy_to_clipboard', 'value' => $encoded]),
            $link('commands.fetchprofile.give_item', ['action' => 'run_command', 'command' => 'give @s minecraft:player_head[profile=' . $encoded . ']']),
            $link('commands.fetchprofile.summon_mannequin', ['action' => 'run_command', 'command' => 'summon minecraft:mannequin ~ ~ ~ {profile:' . $encoded . '}']),
            $link('commands.fetchprofile.copy_text', ['action' => 'copy_to_clipboard', 'value' => '{player:' . $encoded . '}'], [['player' => $profile, 'color' => 'white']]),
        ];
        $extra = [];
        foreach ($links as $index => $component) {
            if ($index > 0) {
                $extra[] = ['text' => ' '];
            }
            $extra[] = $component;
        }
        $this->sendSuccess($sender, ['translate' => $key, 'with' => [$argument, ['text' => '', 'extra' => $extra]]], false);
    }
}
