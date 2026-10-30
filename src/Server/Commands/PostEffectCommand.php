<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait PostEffectCommand
{
    /** PostEffectCommand: add, remove, clear and list the post effects players see. */
    private function executePostEffect(Client $sender, array $arguments): void
    {
        $action = $arguments[0] ?? '';
        if ($action === 'list') {
            $player = isset($arguments[1]) ? $this->selectPlayers($arguments[1], $sender, true)[0] : $sender;
            $this->sendSuccess($sender, $player->postEffects === [] ? ['translate' => 'commands.posteffect.list.empty', 'with' => [$this->displayName($player)]]
                : ['translate' => 'commands.posteffect.list.success', 'with' => [$this->displayName($player), count($player->postEffects), ['text' => implode(', ', $player->postEffects)]]], false);
            return;
        }
        $players = $this->selectPlayers($arguments[1] ?? '', $sender);
        $id = isset($arguments[2]) ? (str_contains($arguments[2], ':') ? $arguments[2] : 'minecraft:' . $arguments[2]) : null;
        // CommandResponseTracker: only players whose post effects changed count.
        $changed = [];
        foreach ($players as $player) {
            $index = $id === null ? null : array_search($id, $player->postEffects, true);
            $change = match ($action) {
                'add' => $index === false,
                'remove' => $index !== false,
                default => $player->postEffects !== [],
            };
            if (!$change) {
                continue;
            }
            if ($action === 'add') {
                $player->postEffects[] = $id;
            } elseif ($action === 'remove') {
                array_splice($player->postEffects, $index, 1);
            } else {
                $player->postEffects = [];
            }
            $player->postEffectsDirty = true;
            $changed[] = $player;
        }
        if ($changed === []) {
            throw new CommandFailure(['translate' => 'commands.posteffect.' . $action . '.failed']);
        }
        $with = $action === 'clear' ? [] : [['text' => $id]];
        $this->sendSuccess($sender, count($changed) === 1 ? ['translate' => 'commands.posteffect.' . $action . '.success.single', 'with' => [...$with, $this->displayName($changed[0])]]
            : ['translate' => 'commands.posteffect.' . $action . '.success.multiple', 'with' => [...$with, count($changed)]], true);
    }
}
