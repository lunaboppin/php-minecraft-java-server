<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Server\Client;

trait DialogCommand
{
    /** DialogCommand: show a registered dialog to players, or clear theirs. */
    private function executeDialog(Client $sender, array $arguments): void
    {
        $action = $arguments[0] ?? '';
        $players = $this->selectPlayers($arguments[1] ?? '', $sender);
        if ($action === 'show') {
            $id = str_contains($arguments[2], ':') ? $arguments[2] : 'minecraft:' . $arguments[2];
            $index = array_search($id, $this->version->registryEntries('minecraft:dialog'), true);
            if ($index === false) {
                throw new \InvalidArgumentException('Inline dialogs are not supported yet');
            }
            // Dialog.STREAM_CODEC: a registry holder as its ID plus one.
            foreach ($players as $player) {
                $player->send($this->version, 'show_dialog', Binary::writeVarInt($index + 1));
            }
        } else {
            foreach ($players as $player) {
                $player->send($this->version, 'clear_dialog', '');
            }
        }
        $this->sendSuccess($sender, count($players) === 1 ? ['translate' => 'commands.dialog.' . $action . '.single', 'with' => [$this->displayName($players[0])]]
            : ['translate' => 'commands.dialog.' . $action . '.multiple', 'with' => [count($players)]], true);
    }
}
