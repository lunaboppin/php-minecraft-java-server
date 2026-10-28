<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SetBlockCommand
{
    /** SetBlockCommand: replace (the default), keep, destroy or strict. */
    private function executeSetBlock(Client $sender, array $arguments): void
    {
        if (count($arguments) < 4 || count($arguments) > 5) {
            throw new \InvalidArgumentException('Usage: /setblock x y z block[properties] [replace|keep|destroy|strict]');
        }
        [$x, $y, $z] = $this->loadedBlockPosition(array_slice($arguments, 0, 3), $sender);
        $state = $this->blockArgument($arguments[3]);
        $mode = $arguments[4] ?? 'replace';
        if ($mode === 'keep' && !$this->version->catalog->air($this->world->get($x, $y, $z))) {
            throw new CommandFailure(['translate' => 'commands.setblock.failed']);
        }
        // A destroyed block leaves air, which needs no placement when air was requested.
        $place = true;
        if ($mode === 'destroy') {
            $this->destroyBlock($x, $y, $z);
            $place = !$this->version->catalog->air($state) || !$this->version->catalog->air($this->world->get($x, $y, $z));
        }
        if ($place) {
            if ($this->world->get($x, $y, $z) === $state) {
                throw new CommandFailure(['translate' => 'commands.setblock.failed']);
            }
            $this->setBlock($x, $y, $z, $state);
        }
        $this->sendSuccess($sender, ['translate' => 'commands.setblock.success', 'with' => [$x, $y, $z]], true);
    }

    /**
     * Level.destroyBlock: the break event and air. Loot tables are not modelled, so nothing drops.
     */
    private function destroyBlock(int $x, int $y, int $z): bool
    {
        $state = $this->world->get($x, $y, $z);
        if ($this->version->catalog->air($state)) {
            return false;
        }
        $this->levelEvent(2001, [$x, $y, $z], $state);
        $this->setBlock($x, $y, $z, 0);
        return true;
    }
}
