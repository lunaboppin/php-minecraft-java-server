<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Nbt;
use PhpMinecraftJavaServer\Server\Client;

trait BossBarCommand
{
    /** BossBarCommands: add, remove, list, set and get command boss bars. */
    private function executeBossBar(Client $sender, array $arguments, string $text = ''): void
    {
        $action = $arguments[0];
        if ($action === 'list') {
            $bars = $this->orderedBossBars();
            $this->sendSuccess($sender, $bars === [] ? ['translate' => 'commands.bossbar.list.bars.none']
                : ['translate' => 'commands.bossbar.list.bars.some', 'with' => [count($bars), $this->formatList(array_map($this->bossBarName(...), $bars))]], false);
            return;
        }
        $id = str_contains($arguments[1], ':') ? $arguments[1] : 'minecraft:' . $arguments[1];
        $bars = &$this->bossBarList();
        if ($action === 'add') {
            if (isset($bars[$id])) {
                throw new CommandFailure(['translate' => 'commands.bossbar.create.failed', 'with' => [['text' => $id]]]);
            }
            $bars[$id] = ['id' => $id, 'uuid' => random_bytes(16), 'name' => $this->bossBarComponent($sender, $text, 3), 'color' => 'white', 'style' => 'progress',
                'value' => 0, 'max' => 100, 'progress' => 0.0, 'visible' => true, 'players' => []];
            $this->saveBossBars();
            $this->sendSuccess($sender, ['translate' => 'commands.bossbar.create.success', 'with' => [$this->bossBarName($bars[$id])]], true);
            return;
        }
        if (!isset($bars[$id])) {
            throw new CommandFailure(['translate' => 'commands.bossbar.unknown', 'with' => [['text' => $id]]]);
        }
        $bar = &$bars[$id];
        if ($action === 'remove') {
            $this->sendBossEvent($bar, 1, '');
            $name = $this->bossBarName($bar);
            unset($bars[$id]);
            $this->saveBossBars();
            $this->sendSuccess($sender, ['translate' => 'commands.bossbar.remove.success', 'with' => [$name]], true);
            return;
        }
        $property = $arguments[2];
        if ($action === 'get') {
            $viewers = $this->bossBarViewers($bar);
            $message = match ($property) {
                'value' => ['translate' => 'commands.bossbar.get.value', 'with' => [$this->bossBarName($bar), $bar['value']]],
                'max' => ['translate' => 'commands.bossbar.get.max', 'with' => [$this->bossBarName($bar), $bar['max']]],
                'visible' => ['translate' => 'commands.bossbar.get.visible.' . ($bar['visible'] ? 'visible' : 'hidden'), 'with' => [$this->bossBarName($bar)]],
                default => $viewers === [] ? ['translate' => 'commands.bossbar.get.players.none', 'with' => [$this->bossBarName($bar)]]
                    : ['translate' => 'commands.bossbar.get.players.some', 'with' => [$this->bossBarName($bar), count($viewers), $this->formatList(array_map($this->displayName(...), $viewers))]],
            };
            $this->sendSuccess($sender, $message, true);
            return;
        }
        $unchanged = static fn (string $what): CommandFailure => new CommandFailure(['translate' => 'commands.bossbar.set.' . $what . '.unchanged']);
        switch ($property) {
            case 'name':
                $name = $this->bossBarComponent($sender, $text, 4);
                if ($name == $bar['name']) {
                    throw $unchanged('name');
                }
                $bar['name'] = $name;
                if ($bar['visible']) {
                    $this->sendBossEvent($bar, 3, Nbt::component($name));
                }
                $message = 'name.success';
                break;
            case 'color':
            case 'style':
                $value = $arguments[3];
                if ($bar[$property] === $value) {
                    throw $unchanged($property);
                }
                $bar[$property] = $value;
                if ($bar['visible']) {
                    $this->sendBossEvent($bar, 4, \PhpMinecraftJavaServer\Protocol\Binary::writeVarInt(array_search($bar['color'], array_keys(self::BOSS_BAR_COLORS), true))
                        . \PhpMinecraftJavaServer\Protocol\Binary::writeVarInt(array_search($bar['style'], self::BOSS_BAR_STYLES, true)));
                }
                $message = $property . '.success';
                break;
            case 'value':
            case 'max':
                $value = (int) $arguments[3];
                if ($bar[$property] === $value) {
                    throw $unchanged($property);
                }
                $bar[$property] = $value;
                $this->updateBossBarProgress($bar);
                $this->sendSuccess($sender, ['translate' => 'commands.bossbar.set.' . $property . '.success', 'with' => [$this->bossBarName($bar), $value]], true);
                $this->saveBossBars();
                return;
            case 'visible':
                $visible = $arguments[3] === 'true';
                if ($bar['visible'] === $visible) {
                    throw new CommandFailure(['translate' => 'commands.bossbar.set.visibility.unchanged.' . ($visible ? 'visible' : 'hidden')]);
                }
                $bar['visible'] = $visible;
                $this->sendBossEvent($bar, $visible ? 0 : 1, $visible ? $this->bossBarAdd($bar) : '');
                $message = 'visible.success.' . ($visible ? 'visible' : 'hidden');
                break;
            default:
                // CustomBossEvent.setPlayers: listed players not selected leave, selected ones join.
                $targets = isset($arguments[3]) ? $this->selectPlayers($arguments[3], $sender) : [];
                $selected = [];
                foreach ($targets as $target) {
                    $selected[bin2hex($target->uuid)] = $target;
                }
                $leaving = array_diff_key($bar['players'], $selected);
                $joining = array_diff_key($selected, $bar['players']);
                if ($leaving === [] && $joining === []) {
                    throw $unchanged('players');
                }
                foreach ($this->bossBarViewers($bar) as $viewer) {
                    if (isset($leaving[bin2hex($viewer->uuid)]) && $bar['visible']) {
                        $this->sendBossEvent($bar, 1, '', [$viewer]);
                    }
                }
                $bar['players'] = array_diff_key($bar['players'], $leaving);
                foreach ($joining as $key => $player) {
                    $bar['players'][$key] = true;
                    if ($bar['visible']) {
                        $this->sendBossEvent($bar, 0, $this->bossBarAdd($bar), [$player]);
                    }
                }
                $this->saveBossBars();
                $this->sendSuccess($sender, $bar['players'] === [] ? ['translate' => 'commands.bossbar.set.players.success.none', 'with' => [$this->bossBarName($bar)]]
                    : ['translate' => 'commands.bossbar.set.players.success.some', 'with' => [$this->bossBarName($bar), count($targets), $this->formatList(array_map($this->displayName(...), $targets))]], true);
                return;
        }
        $this->saveBossBars();
        $this->sendSuccess($sender, ['translate' => 'commands.bossbar.set.' . $message, 'with' => [$this->bossBarName($bar)]], true);
    }

    /** The component argument after the given number of words, resolved for the sender. */
    private function bossBarComponent(Client $sender, string $text, int $words): array
    {
        $text = ltrim($text);
        $start = 0;
        for ($word = 0; $word < $words; ++$word) {
            $start = strpos($text, ' ', $start) + 1;
        }
        return $this->resolveComponent(TextComponentArgument::read(new StringReader(substr($text, $start))), $sender);
    }
}
