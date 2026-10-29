<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Client;

trait TellrawCommand
{
    /** TellRawCommand: each target receives the component, resolved for the sender. */
    private function executeTellraw(Client $sender, array $arguments, string $text = ''): void
    {
        [$targets, $component] = $this->targetsAndComponent($sender, $text, 1);
        foreach ($targets as $target) {
            $target->send($this->version, 'system_chat', Packets::systemChat($this->resolveComponent($component, $sender)));
        }
    }

    /**
     * The player targets and the component argument from the raw input, read as the parser does
     * (a selector may contain spaces, and so may SNBT).
     *
     * @return array{list<Client>, array}
     */
    private function targetsAndComponent(Client $sender, string $text, int $literals): array
    {
        $reader = new StringReader($text);
        $reader->readUnquotedString();
        $reader->skip();
        $start = $reader->cursor;
        EntitySelector::read($reader, true);
        $targets = $this->selectPlayers(substr($text, $start, $reader->cursor - $start), $sender);
        for ($literal = 1; $literal < $literals; ++$literal) {
            $reader->skip();
            $reader->readUnquotedString();
        }
        $reader->skip();
        return [$targets, TextComponentArgument::read($reader)];
    }

    /**
     * ComponentUtils.updateForEntity: selectors become the matching names (joined by the
     * separator), scores and NBT resolve to nothing here, and the component's style fills in
     * what the resolved content leaves unset.
     */
    private function resolveComponent(array $component, Client $sender): array
    {
        $style = array_diff_key($component, array_flip(['text', 'translate', 'with', 'fallback', 'selector', 'separator', 'score', 'nbt', 'keybind', 'extra']));
        if (isset($component['selector'])) {
            $names = array_map(fn (array $entity): array => $this->entityName($entity), $this->selectEntities($component['selector'], $sender));
            $separator = isset($component['separator']) ? $this->resolveComponent($component['separator'], $sender) : ['text' => ', ', 'color' => 'gray'];
            $resolved = match (count($names)) {
                0 => ['text' => ''],
                1 => $names[0],
                default => ['text' => '', 'extra' => array_merge(...array_map(static fn (array $name, int $index): array => $index === 0 ? [$name] : [$separator, $name], $names, array_keys($names)))],
            };
        } elseif (isset($component['score'])) {
            // ScoreContents.resolve: a selector names its single match; a missing score is empty text.
            $name = (string) ($component['score']['name'] ?? '');
            if (str_starts_with($name, '@')) {
                $matches = $this->selectEntities($name, $sender);
                $name = $matches === [] ? $name : self::scoreboardName($matches[0]);
            }
            $value = $this->scoreboard()->scores[$name][(string) ($component['score']['objective'] ?? '')]['value'] ?? null;
            $resolved = ['text' => $value === null ? '' : (string) $value];
        } elseif (isset($component['nbt'])) {
            // No entity or block NBT sources yet: vanilla resolves a missing value to empty text.
            $resolved = ['text' => ''];
        } else {
            $resolved = array_diff_key($component, $style + ['extra' => true]);
            if (isset($resolved['with'])) {
                $resolved['with'] = array_map(fn (mixed $argument): mixed => is_array($argument) ? $this->resolveComponent($argument, $sender) : $argument, $resolved['with']);
            }
        }
        $resolved += $style;
        foreach ($component['extra'] ?? [] as $sibling) {
            $resolved['extra'][] = $this->resolveComponent($sibling, $sender);
        }
        return $resolved;
    }
}
