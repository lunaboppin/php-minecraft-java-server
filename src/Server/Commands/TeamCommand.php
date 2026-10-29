<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait TeamCommand
{
    /** TeamCommand: list, add, remove, empty, join, leave and modify. */
    private function executeTeam(Client $sender, array $arguments, string $text = ''): void
    {
        $scoreboard = $this->scoreboard();
        switch ($arguments[0] ?? '') {
            case 'list':
                if (!isset($arguments[1])) {
                    $teams = $scoreboard->teamNames();
                    $this->sendSuccess($sender, $teams === []
                        ? ['translate' => 'commands.team.list.teams.empty']
                        : ['translate' => 'commands.team.list.teams.success', 'with' => [count($teams), $this->formatList(array_map($this->teamComponent(...), $teams))]], false);
                    return;
                }
                $team = $this->team($arguments[1]);
                $members = $scoreboard->members($team);
                $this->sendSuccess($sender, $members === []
                    ? ['translate' => 'commands.team.list.members.empty', 'with' => [$this->teamComponent($team)]]
                    : ['translate' => 'commands.team.list.members.success', 'with' => [$this->teamComponent($team), count($members), $this->formatList(array_map(static fn (string $member): array => ['text' => $member, 'color' => 'green'], $members))]], false);
                return;
            case 'add':
                $name = $arguments[1];
                if (isset($scoreboard->teams[$name])) {
                    throw new CommandFailure(['translate' => 'commands.team.add.duplicate']);
                }
                // Scoreboard.addPlayerTeam reports the team under its name, then setDisplayName reports a change.
                $scoreboard->addTeam($name, ['text' => $name]);
                $this->broadcastScoreboard('set_player_team', $this->teamPacket($name, 0));
                $scoreboard->teams[$name]['displayName'] = isset($arguments[2]) ? $this->componentAfterWords($text, 3) : ['text' => $name];
                $this->broadcastScoreboard('set_player_team', $this->teamPacket($name, 2));
                $this->sendSuccess($sender, ['translate' => 'commands.team.add.success', 'with' => [$this->teamComponent($name)]], true);
                break;
            case 'remove':
                $team = $this->team($arguments[1]);
                $message = ['translate' => 'commands.team.remove.success', 'with' => [$this->teamComponent($team)]];
                $scoreboard->removeTeam($team);
                $this->broadcastScoreboard('set_player_team', $this->teamPacket($team, 1));
                $this->sendSuccess($sender, $message, true);
                break;
            case 'empty':
                $team = $this->team($arguments[1]);
                $members = $scoreboard->teams[$team]['players'];
                if ($members === []) {
                    throw new CommandFailure(['translate' => 'commands.team.empty.unchanged']);
                }
                foreach ($members as $member) {
                    $scoreboard->leaveTeam($member);
                    $this->broadcastScoreboard('set_player_team', $this->teamPacket($team, 4, [$member]));
                }
                $this->sendSuccess($sender, ['translate' => 'commands.team.empty.success', 'with' => [count($members), $this->teamComponent($team)]], true);
                break;
            case 'join':
                $team = $this->team($arguments[1]);
                $holders = isset($arguments[2]) ? $this->scoreHolders($arguments[2], $sender) : [$sender->name];
                foreach ($holders as $holder) {
                    $previous = $scoreboard->teamOf($holder);
                    if ($scoreboard->joinTeam($holder, $team)) {
                        if ($previous !== null) {
                            $this->broadcastScoreboard('set_player_team', $this->teamPacket($previous, 4, [$holder]));
                        }
                        $this->broadcastScoreboard('set_player_team', $this->teamPacket($team, 3, [$holder]));
                    }
                }
                $this->sendSuccess($sender, count($holders) === 1
                    ? ['translate' => 'commands.team.join.success.single', 'with' => [$this->holderComponent($holders[0]), $this->teamComponent($team)]]
                    : ['translate' => 'commands.team.join.success.multiple', 'with' => [count($holders), $this->teamComponent($team)]], true);
                break;
            case 'leave':
                $holders = $this->scoreHolders($arguments[1] ?? '', $sender);
                $left = [];
                foreach ($holders as $holder) {
                    $team = $scoreboard->leaveTeam($holder);
                    if ($team !== null) {
                        $this->broadcastScoreboard('set_player_team', $this->teamPacket($team, 4, [$holder]));
                        $left[] = $holder;
                    }
                }
                // The single reply needs exactly one player to have left a team.
                $this->sendSuccess($sender, count($left) === 1
                    ? ['translate' => 'commands.team.leave.success.single', 'with' => [$this->holderComponent($left[0])]]
                    : ['translate' => 'commands.team.leave.success.multiple', 'with' => [count($left)]], true);
                break;
            case 'modify':
                $this->modifyTeam($sender, $this->team($arguments[1]), $arguments[2] ?? '', $arguments[3] ?? '', $text);
                break;
            default:
                throw new \InvalidArgumentException('Usage: /team list|add|remove|empty|join|leave|modify');
        }
        $this->saveScoreboard();
    }

    private function modifyTeam(Client $sender, string $team, string $option, string $value, string $text): void
    {
        $settings = &$this->scoreboard()->teams[$team];
        $changed = function (string $key, mixed $new, string $unchanged) use (&$settings): void {
            if ($settings[$key] === $new) {
                throw new CommandFailure(['translate' => $unchanged]);
            }
            $settings[$key] = $new;
        };
        switch ($option) {
            case 'color':
                $changed('color', $value === 'reset' ? null : $value, 'commands.team.option.color.unchanged');
                $message = ['translate' => 'commands.team.option.color.success', 'with' => [$this->teamComponent($team), ['text' => $value]]];
                break;
            case 'displayName':
                $changed('displayName', $this->componentAfterWords($text, 4), 'commands.team.option.name.unchanged');
                $message = ['translate' => 'commands.team.option.name.success', 'with' => [$this->teamComponent($team)]];
                break;
            case 'prefix':
            case 'suffix':
                $settings[$option] = $this->componentAfterWords($text, 4);
                $message = ['translate' => 'commands.team.option.' . $option . '.success', 'with' => [$settings[$option]]];
                break;
            case 'friendlyFire':
            case 'seeFriendlyInvisibles':
                $enabled = $value === 'true';
                $key = $option === 'friendlyFire' ? 'friendlyfire' : 'seeFriendlyInvisibles';
                $changed($option, $enabled, 'commands.team.option.' . $key . '.' . ($enabled ? 'alreadyEnabled' : 'alreadyDisabled'));
                $message = ['translate' => 'commands.team.option.' . $key . '.' . ($enabled ? 'enabled' : 'disabled'), 'with' => [$this->teamComponent($team)]];
                break;
            case 'nametagVisibility':
            case 'deathMessageVisibility':
                $changed($option, $value, 'commands.team.option.' . $option . '.unchanged');
                $message = ['translate' => 'commands.team.option.' . $option . '.success', 'with' => [$this->teamComponent($team), ['translate' => 'team.visibility.' . $value]]];
                break;
            case 'collisionRule':
                $changed('collisionRule', $value, 'commands.team.option.collisionRule.unchanged');
                $message = ['translate' => 'commands.team.option.collisionRule.success', 'with' => [$this->teamComponent($team), ['translate' => 'team.collision.' . $value]]];
                break;
            default:
                throw new \InvalidArgumentException('Unknown team option');
        }
        $this->broadcastScoreboard('set_player_team', $this->teamPacket($team, 2));
        $this->sendSuccess($sender, $message, true);
    }

    /** TeamArgument.getTeam */
    private function team(string $name): string
    {
        if (!isset($this->scoreboard()->teams[$name])) {
            throw new CommandFailure(['translate' => 'team.notFound', 'with' => [['text' => $name]]]);
        }
        return $name;
    }
}
