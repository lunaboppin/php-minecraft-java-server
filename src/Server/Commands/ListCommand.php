<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Client;

trait ListCommand
{
    private function executeList(Client $sender, array $arguments): void
    {
        $withUuids = $arguments === ['uuids'];
        if (!$withUuids && $arguments !== [] && $arguments !== ['']) {
            throw new \InvalidArgumentException('Usage: /list [uuids]');
        }
        $players = array_values($this->players());
        $entries = array_map(fn (Client $player): array => $withUuids
            ? ['translate' => 'commands.list.nameAndId', 'with' => [['text' => $player->name], ['text' => $this->formatUuid($player->uuid)]]]
            : $this->displayName($player), $players);
        $this->sendSuccess($sender, ['translate' => 'commands.list.players', 'with' => [count($players), $this->config->maxPlayers, $this->formatList($entries)]], false);
    }

    /** ComponentUtils.formatList with the default gray ", " separator. */
    private function formatList(array $components): array
    {
        if (count($components) <= 1) {
            return $components[0] ?? ['text' => ''];
        }
        $extra = [];
        foreach ($components as $index => $component) {
            if ($index > 0) {
                $extra[] = ['text' => ', ', 'color' => 'gray'];
            }
            $extra[] = $component;
        }
        return ['text' => '', 'extra' => $extra];
    }

    private function formatUuid(string $uuid): string
    {
        $hex = bin2hex($uuid);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
