<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Nbt;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Client;

trait TitleCommand
{
    /** TitleCommand: titles, subtitles and the action bar, their times, clearing and resetting. */
    private function executeTitle(Client $sender, array $arguments, string $text = ''): void
    {
        $action = $arguments[1] ?? '';
        if (in_array($action, ['title', 'subtitle', 'actionbar'], true)) {
            [$targets, $component] = $this->targetsAndComponent($sender, $text, 2);
            $packet = ['title' => 'set_title_text', 'subtitle' => 'set_subtitle_text', 'actionbar' => 'set_action_bar_text'][$action];
            foreach ($targets as $target) {
                $target->send($this->version, $packet, Nbt::component($this->resolveComponent($component, $sender)));
            }
            $this->titleFeedback($sender, 'commands.title.show.' . $action, $targets);
            return;
        }
        $targets = $this->selectPlayers($arguments[0] ?? '', $sender);
        switch ($action) {
            case 'clear':
            case 'reset':
                foreach ($targets as $target) {
                    $target->send($this->version, 'clear_titles', $action === 'reset' ? "\1" : "\0");
                }
                $this->titleFeedback($sender, 'commands.title.' . ($action === 'clear' ? 'cleared' : 'reset'), $targets);
                return;
            case 'times':
                $times = array_map(fn (string $time): int => $this->timeArgument($time, 0), array_slice($arguments, 2, 3));
                foreach ($targets as $target) {
                    $target->send($this->version, 'set_titles_animation', pack('NNN', ...$times));
                }
                $this->titleFeedback($sender, 'commands.title.times', $targets);
                return;
        }
        throw new \InvalidArgumentException('Usage: /title <targets> clear|reset|title|subtitle|actionbar|times');
    }

    private function titleFeedback(Client $sender, string $key, array $targets): void
    {
        $this->sendSuccess($sender, count($targets) === 1
            ? ['translate' => $key . '.single', 'with' => [$this->displayName($targets[0])]]
            : ['translate' => $key . '.multiple', 'with' => [count($targets)]], true);
    }
}
