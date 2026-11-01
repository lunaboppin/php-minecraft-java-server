<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait WaypointCommand
{
    /**
     * WaypointCommand: list the dimension's transmitters, or change a living entity's locator bar
     * colour (a team colour, a hex colour, or none) or style.
     */
    private function executeWaypoint(Client $sender, array $arguments, string $text): void
    {
        $dimension = $this->sourceDimension($sender);
        if (($arguments[0] ?? '') === 'list') {
            $this->listWaypoints($sender, $dimension);
            return;
        }
        $reader = new StringReader((string) preg_replace('/\A\/?\s*waypoint\s+modify\s+/', '', $text));
        $start = $reader->cursor;
        EntitySelector::read($reader, true);
        $entity = $this->selectEntities(substr($reader->string, $start, $reader->cursor - $start), $sender, false, true)[0];
        if (!isset($entity['client']) && !isset($entity['mob'])) {
            throw new CommandFailure(['translate' => 'argument.waypoint.invalid']);
        }
        $rest = preg_split('/\s+/', trim($reader->remaining()));
        switch (implode(' ', array_slice($rest, 0, 2))) {
            case 'color reset':
                $this->changeWaypointIcon($entity, static fn (array $icon): array => ['color' => null] + $icon);
                $this->sendSuccess($sender, ['translate' => 'commands.waypoint.modify.color.reset'], false);
                return;
            case 'color hex':
                $color = hexdec($rest[2]);
                $this->changeWaypointIcon($entity, static fn (array $icon): array => ['color' => $color] + $icon);
                $hex = sprintf('%06X', $color);
                $this->sendSuccess($sender, ['translate' => 'commands.waypoint.modify.color', 'with' => [['text' => $hex, 'color' => '#' . $hex]]], false);
                return;
            case 'style reset':
            case 'style set':
                $style = $rest[1] === 'reset' ? 'minecraft:default' : (str_contains($rest[2], ':') ? $rest[2] : 'minecraft:' . $rest[2]);
                $this->changeWaypointIcon($entity, static fn (array $icon): array => ['style' => $style] + $icon);
                $this->sendSuccess($sender, ['translate' => 'commands.waypoint.modify.style'], false);
                return;
            default:
                $name = $rest[1];
                $this->changeWaypointIcon($entity, static fn (array $icon): array => ['color' => self::WAYPOINT_COLORS[$name]] + $icon);
                $this->sendSuccess($sender, ['translate' => 'commands.waypoint.modify.color', 'with' => [['text' => $name, 'color' => $name]]], false);
        }
    }

    /** The transmitters by feedback name, each suggesting a teleport to it and coloured as its icon. */
    private function listWaypoints(Client $sender, string $dimension): void
    {
        $names = [];
        foreach ($this->players() as $player) {
            if ($player->dimension === $dimension && $this->isTransmittingWaypoint($player)) {
                $names[] = $this->waypointListEntry($this->displayName($player), [$player->x, $player->y, $player->z], $player->waypointIcon['color'], $dimension);
            }
        }
        $this->withDimension($dimension, function () use (&$names, $dimension): void {
            foreach ($this->waypointMobs as $id => $mobDimension) {
                $mob = $this->animals[$id] ?? null;
                if ($mobDimension === $dimension && $mob !== null && !isset($mob['deadAt'])) {
                    $names[] = $this->waypointListEntry($this->entityName(['mob' => $id]), [$mob['x'], $mob['y'], $mob['z']], $mob['waypointIcon']['color'] ?? null, $dimension);
                }
            }
        });
        $id = 'minecraft:' . $dimension;
        $this->sendSuccess($sender, $names === [] ? ['translate' => 'commands.waypoint.list.empty', 'with' => [['text' => $id]]]
            : ['translate' => 'commands.waypoint.list.success', 'with' => [count($names), ['text' => $id], $this->formatList($names)]], false);
    }

    private function waypointListEntry(array $name, array $position, ?int $color, string $dimension): array
    {
        $block = array_map(static fn (float $value): int => (int) floor($value), $position);
        unset($name['click_event'], $name['hover_event'], $name['color']);
        return $name + ['click_event' => ['action' => 'suggest_command', 'command' => '/execute in minecraft:' . $dimension . ' run tp @s ' . implode(' ', $block)],
            'hover_event' => ['action' => 'show_text', 'value' => ['translate' => 'chat.coordinates.tooltip']], 'color' => sprintf('#%06X', $color ?? 0xFFFFFF)];
    }
}
