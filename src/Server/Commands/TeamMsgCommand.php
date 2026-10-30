<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait TeamMsgCommand
{
    /**
     * TeamMsgCommand: to everyone on the sender's team, naming the team; the sender sees
     * team_msg_command_outgoing and the others team_msg_command_incoming.
     */
    private function executeTeamMsg(Client $sender, array $arguments, string $text): void
    {
        $team = $this->scoreboard()->teamOf($sender->name);
        if ($team === null) {
            throw new CommandFailure(['translate' => 'commands.teammsg.failed.noteam']);
        }
        $name = $this->teamComponent($team);
        $name['hover_event'] = ['action' => 'show_text', 'value' => ['translate' => 'chat.type.team.hover']];
        $name['click_event'] = ['action' => 'suggest_command', 'command' => '/teammsg '];
        $content = $this->messageComponent($sender, CommandArguments::rest($text, 0));
        $this->log('[' . $sender->name . ' -> team ' . $team . '] ' . CommandArguments::rest($text, 0));
        foreach ($this->players() as $player) {
            if ($player === $sender || $this->scoreboard()->teamOf($player->name) === $team) {
                $this->sendDisguisedChat($player, $content, $player === $sender ? 'team_msg_command_outgoing' : 'team_msg_command_incoming', $this->displayName($sender), $name);
            }
        }
    }
}
