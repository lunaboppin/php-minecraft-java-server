<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait WhitelistCommand
{
    /** WhitelistCommand. The whitelist is not enforced on players already online (enforce-whitelist=false). */
    private function executeWhitelist(Client $sender, array $arguments): void
    {
        switch ($arguments[0] ?? '') {
            case 'on':
            case 'off':
                $enabled = $arguments[0] === 'on';
                if ($this->config->whiteList === $enabled) {
                    throw new CommandFailure(['translate' => $enabled ? 'commands.whitelist.alreadyOn' : 'commands.whitelist.alreadyOff']);
                }
                $this->persistConfig(['whiteList' => $enabled]);
                $this->sendSuccess($sender, ['translate' => $enabled ? 'commands.whitelist.enabled' : 'commands.whitelist.disabled'], true);
                return;
            case 'list':
                $names = array_map(static fn (array $entry): string => $entry['name'], $this->orderedEntries('whitelist.json'));
                $this->sendSuccess($sender, $names === [] ? ['translate' => 'commands.whitelist.none'] : ['translate' => 'commands.whitelist.list', 'with' => [count($names), ['text' => implode(', ', $names)]]], false);
                return;
            case 'add':
            case 'remove':
                $add = $arguments[0] === 'add';
                $changed = 0;
                foreach ($this->gameProfiles($arguments[1] ?? '', $sender) as $profile) {
                    if ($this->setListed('whitelist.json', $profile, $add)) {
                        ++$changed;
                        $this->sendSuccess($sender, ['translate' => 'commands.whitelist.' . $arguments[0] . '.success', 'with' => [['text' => $profile[0]]]], true);
                    }
                }
                if ($changed === 0) {
                    throw new CommandFailure(['translate' => 'commands.whitelist.' . $arguments[0] . '.failed']);
                }
                return;
            case 'reload':
                [$this->whitelist, $this->bans] = [null, null];
                $this->sendSuccess($sender, ['translate' => 'commands.whitelist.reloaded'], true);
                return;
        }
        throw new \InvalidArgumentException('Usage: /whitelist on|off|list|add|remove|reload');
    }
}
