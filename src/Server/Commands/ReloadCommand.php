<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait ReloadCommand
{
    /**
     * ReloadCommand: the server's only data pack, the built-in one, reloads unchanged, and as
     * PlayerList.reloadResources does, every player's advancements are read again (and sent whole
     * on the next tick), then the tags, recipes and recipe books are sent again.
     */
    private function executeReload(Client $sender, array $arguments): void
    {
        $this->sendSuccess($sender, ['translate' => 'commands.reload.success'], true);
        foreach ($this->players() as $player) {
            $this->reloadAdvancements($player);
        }
        foreach ($this->players() as $player) {
            $player->send($this->version, 'update_tags', $this->version->tags());
        }
        foreach ($this->players() as $player) {
            $this->sendUpdateRecipes($player);
            $this->sendInitialRecipeBook($player);
        }
    }
}
