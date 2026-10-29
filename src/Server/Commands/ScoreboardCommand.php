<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;
use PhpMinecraftJavaServer\World\Scoreboard;

trait ScoreboardCommand
{
    /** ScoreboardCommand: objectives and players. */
    private function executeScoreboard(Client $sender, array $arguments, string $text = ''): void
    {
        if (($arguments[0] ?? '') === 'objectives') {
            $this->scoreboardObjectives($sender, array_slice($arguments, 1), $text);
        } elseif (($arguments[0] ?? '') === 'players') {
            $this->scoreboardPlayers($sender, array_slice($arguments, 1));
        } else {
            throw new \InvalidArgumentException('Usage: /scoreboard objectives|players ...');
        }
        $this->saveScoreboard();
    }

    private function scoreboardObjectives(Client $sender, array $arguments, string $text): void
    {
        $scoreboard = $this->scoreboard();
        switch ($arguments[0] ?? '') {
            case 'list':
                $names = $scoreboard->objectiveNames();
                $this->sendSuccess($sender, $names === []
                    ? ['translate' => 'commands.scoreboard.objectives.list.empty']
                    : ['translate' => 'commands.scoreboard.objectives.list.success', 'with' => [count($names), $this->formatList(array_map($this->objectiveComponent(...), $names))]], false);
                return;
            case 'add':
                $name = $arguments[1];
                if (isset($scoreboard->objectives[$name])) {
                    throw new CommandFailure(['translate' => 'commands.scoreboard.objectives.add.duplicate']);
                }
                $display = isset($arguments[3]) ? $this->componentAfterWords($text, 5) : ['text' => $name];
                $scoreboard->addObjective($name, ['criteria' => $arguments[2], 'displayName' => $display, 'renderType' => in_array($arguments[2], ['health'], true) ? 'hearts' : 'integer', 'displayAutoUpdate' => false]);
                $this->sendSuccess($sender, ['translate' => 'commands.scoreboard.objectives.add.success', 'with' => [$this->objectiveComponent($name)]], true);
                return;
            case 'remove':
                $name = $this->objective($arguments[1]);
                $message = ['translate' => 'commands.scoreboard.objectives.remove.success', 'with' => [$this->objectiveComponent($name)]];
                $tracked = $this->tracked($name);
                $scoreboard->removeObjective($name);
                if ($tracked) {
                    $this->broadcastScoreboard('set_objective', \PhpMinecraftJavaServer\Protocol\Binary::writeString($name) . "\1");
                }
                $this->sendSuccess($sender, $message, true);
                return;
            case 'modify':
                $name = $this->objective($arguments[1]);
                if ($arguments[2] === 'displayname') {
                    $scoreboard->objectives[$name]['displayName'] = $this->componentAfterWords($text, 5);
                    $this->objectiveChanged($name);
                    $this->sendSuccess($sender, ['translate' => 'commands.scoreboard.objectives.modify.displayname', 'with' => [['text' => $name], $this->objectiveComponent($name)]], true);
                } elseif ($arguments[2] === 'rendertype') {
                    $scoreboard->objectives[$name]['renderType'] = $arguments[3];
                    $this->objectiveChanged($name);
                    $this->sendSuccess($sender, ['translate' => 'commands.scoreboard.objectives.modify.rendertype', 'with' => [$this->objectiveComponent($name)]], true);
                } elseif ($arguments[2] === 'displayautoupdate') {
                    $enabled = $arguments[3] === 'true';
                    $scoreboard->objectives[$name]['displayAutoUpdate'] = $enabled;
                    $this->sendSuccess($sender, ['translate' => 'commands.scoreboard.objectives.modify.displayAutoUpdate.' . ($enabled ? 'enable' : 'disable'), 'with' => [$this->objectiveComponent($name)]], true);
                } else {
                    throw new \InvalidArgumentException('Number formats are not supported yet');
                }
                return;
            case 'setdisplay':
                $slot = $arguments[1];
                if (!isset($arguments[2])) {
                    if (!isset($scoreboard->display[$slot])) {
                        throw new CommandFailure(['translate' => 'commands.scoreboard.objectives.display.alreadyEmpty']);
                    }
                    $this->setDisplayObjective($slot, null);
                    $this->sendSuccess($sender, ['translate' => 'commands.scoreboard.objectives.display.cleared', 'with' => [['text' => $slot]]], true);
                    return;
                }
                $name = $this->objective($arguments[2]);
                if (($scoreboard->display[$slot] ?? null) === $name) {
                    throw new CommandFailure(['translate' => 'commands.scoreboard.objectives.display.alreadySet']);
                }
                $this->setDisplayObjective($slot, $name);
                $this->sendSuccess($sender, ['translate' => 'commands.scoreboard.objectives.display.set', 'with' => [['text' => $slot], $scoreboard->objectives[$name]['displayName']]], true);
                return;
        }
        throw new \InvalidArgumentException('Usage: /scoreboard objectives list|add|remove|modify|setdisplay');
    }

    private function scoreboardPlayers(Client $sender, array $arguments): void
    {
        $scoreboard = $this->scoreboard();
        $action = $arguments[0] ?? '';
        if ($action === 'list') {
            if (!isset($arguments[1])) {
                $holders = $scoreboard->holders();
                $this->sendSuccess($sender, $holders === []
                    ? ['translate' => 'commands.scoreboard.players.list.empty']
                    : ['translate' => 'commands.scoreboard.players.list.success', 'with' => [count($holders), $this->formatList(array_map(static fn (string $holder): array => ['text' => $holder], $holders))]], false);
                return;
            }
            $holder = $this->scoreHolders($arguments[1], $sender)[0];
            $scores = array_filter($scoreboard->objectiveNames(), static fn (string $objective): bool => isset($scoreboard->scores[$holder][$objective]));
            if ($scores === []) {
                $this->sendSuccess($sender, ['translate' => 'commands.scoreboard.players.list.entity.empty', 'with' => [$this->holderComponent($holder)]], false);
                return;
            }
            $this->sendSuccess($sender, ['translate' => 'commands.scoreboard.players.list.entity.success', 'with' => [$this->holderComponent($holder), count($scores)]], false);
            foreach ($scores as $objective) {
                $this->sendSuccess($sender, ['translate' => 'commands.scoreboard.players.list.entity.entry', 'with' => [$this->objectiveComponent($objective), $scoreboard->scores[$holder][$objective]['value']]], false);
            }
            return;
        }
        $holders = $this->scoreHolders($arguments[1] ?? '', $sender);
        $single = count($holders) === 1;
        switch ($action) {
            case 'get':
                $objective = $this->objective($arguments[2]);
                $value = $scoreboard->scores[$holders[0]][$objective]['value'] ?? null;
                if ($value === null) {
                    throw new CommandFailure(['translate' => 'commands.scoreboard.players.get.null', 'with' => [['text' => $objective], $this->holderComponent($holders[0])]]);
                }
                $this->sendSuccess($sender, ['translate' => 'commands.scoreboard.players.get.success', 'with' => [$this->holderComponent($holders[0]), $value, $this->objectiveComponent($objective)]], false);
                return;
            case 'set':
            case 'add':
            case 'remove':
                $objective = $this->objective($arguments[2]);
                $amount = (int) $arguments[3];
                $result = 0;
                foreach ($holders as $holder) {
                    $current = $scoreboard->scores[$holder][$objective]['value'] ?? 0;
                    // Java int arithmetic wraps.
                    $result = match ($action) {
                        'set' => $amount,
                        'add' => self::int32($current + $amount),
                        default => self::int32($current - $amount),
                    };
                    $this->setScore($holder, $objective, $result);
                }
                if ($action === 'set') {
                    $message = $single
                        ? ['translate' => 'commands.scoreboard.players.set.success.single', 'with' => [$this->objectiveComponent($objective), $this->holderComponent($holders[0]), $amount]]
                        : ['translate' => 'commands.scoreboard.players.set.success.multiple', 'with' => [$this->objectiveComponent($objective), count($holders), $amount]];
                } else {
                    $message = $single
                        ? ['translate' => 'commands.scoreboard.players.' . $action . '.success.single', 'with' => [$amount, $this->objectiveComponent($objective), $this->holderComponent($holders[0]), $result]]
                        : ['translate' => 'commands.scoreboard.players.' . $action . '.success.multiple', 'with' => [$amount, $this->objectiveComponent($objective), count($holders)]];
                }
                $this->sendSuccess($sender, $message, true);
                return;
            case 'reset':
                $objective = isset($arguments[2]) ? $this->objective($arguments[2]) : null;
                foreach ($holders as $holder) {
                    $this->resetScore($holder, $objective);
                }
                if ($objective === null) {
                    $message = $single ? ['translate' => 'commands.scoreboard.players.reset.all.single', 'with' => [$this->holderComponent($holders[0])]] : ['translate' => 'commands.scoreboard.players.reset.all.multiple', 'with' => [count($holders)]];
                } else {
                    $message = $single ? ['translate' => 'commands.scoreboard.players.reset.specific.single', 'with' => [$this->objectiveComponent($objective), $this->holderComponent($holders[0])]] : ['translate' => 'commands.scoreboard.players.reset.specific.multiple', 'with' => [$this->objectiveComponent($objective), count($holders)]];
                }
                $this->sendSuccess($sender, $message, true);
                return;
            case 'enable':
                $objective = $this->objective($arguments[2]);
                if ($scoreboard->objectives[$objective]['criteria'] !== 'trigger') {
                    throw new CommandFailure(['translate' => 'commands.scoreboard.players.enable.invalid']);
                }
                $enabled = [];
                foreach ($holders as $holder) {
                    $score = $scoreboard->scores[$holder][$objective] ?? null;
                    if ($score === null || $score['locked']) {
                        $this->setScore($holder, $objective, $score['value'] ?? 0);
                        $scoreboard->scores[$holder][$objective]['locked'] = false;
                        $enabled[] = $holder;
                    }
                }
                if ($enabled === []) {
                    throw new CommandFailure(['translate' => 'commands.scoreboard.players.enable.failed']);
                }
                $this->sendSuccess($sender, count($enabled) === 1
                    ? ['translate' => 'commands.scoreboard.players.enable.success.single', 'with' => [$this->objectiveComponent($objective), $this->holderComponent($enabled[0])]]
                    : ['translate' => 'commands.scoreboard.players.enable.success.multiple', 'with' => [$this->objectiveComponent($objective), count($enabled)]], true);
                return;
            case 'operation':
                $objective = $this->objective($arguments[2]);
                $operation = $arguments[3];
                $sources = $this->scoreHolders($arguments[4], $sender);
                $sourceObjective = $this->objective($arguments[5]);
                $result = 0;
                // getOrCreatePlayerScore: missing target and source scores are created at 0 without an
                // update, even when the operation then fails; a created score is sent once it is set.
                $create = static function (string $holder, string $objective) use ($scoreboard): bool {
                    if (isset($scoreboard->scores[$holder][$objective])) {
                        return false;
                    }
                    $scoreboard->scores[$holder][$objective] = ['value' => 0, 'locked' => true];
                    $scoreboard->syncHolderOrder();
                    return true;
                };
                $assign = function (string $holder, string $objective, int $value, bool $created): void {
                    if ($created) {
                        $this->scoreboard()->scores[$holder][$objective]['value'] = $value;
                        $this->scoreChanged($holder, $objective);
                    } else {
                        $this->setScore($holder, $objective, $value);
                    }
                };
                foreach ($holders as $holder) {
                    $targetCreated = $create($holder, $objective);
                    foreach ($sources as $source) {
                        $sourceCreated = $create($source, $sourceObjective);
                        $target = $scoreboard->scores[$holder][$objective]['value'];
                        $value = $scoreboard->scores[$source][$sourceObjective]['value'];
                        if (in_array($operation, ['/=', '%='], true) && $value === 0) {
                            throw new CommandFailure(['translate' => 'arguments.operation.div0']);
                        }
                        // OperationArgument: floor division and modulo, minimum, maximum and swap.
                        $result = match ($operation) {
                            '=' => $value,
                            '+=' => self::int32($target + $value),
                            '-=' => self::int32($target - $value),
                            '*=' => self::int32($target * $value),
                            '/=' => self::int32(intdiv($target, $value) - (($target % $value !== 0 && (($target < 0) !== ($value < 0))) ? 1 : 0)),
                            '%=' => (($target % $value) + $value) % $value,
                            '<' => min($target, $value),
                            '>' => max($target, $value),
                            default => $value,
                        };
                        $assign($holder, $objective, $result, $targetCreated);
                        if ($operation === '><') {
                            $assign($source, $sourceObjective, $target, $sourceCreated);
                        }
                    }
                }
                $this->sendSuccess($sender, $single
                    ? ['translate' => 'commands.scoreboard.players.operation.success.single', 'with' => [$this->objectiveComponent($objective), $this->holderComponent($holders[0]), $result]]
                    : ['translate' => 'commands.scoreboard.players.operation.success.multiple', 'with' => [$this->objectiveComponent($objective), count($holders)]], true);
                return;
        }
        throw new \InvalidArgumentException('Usage: /scoreboard players list|get|set|add|remove|reset|enable|operation');
    }

    /** ObjectiveArgument.getObjective */
    private function objective(string $name): string
    {
        if (!isset($this->scoreboard()->objectives[$name])) {
            throw new CommandFailure(['translate' => 'arguments.objective.notFound', 'with' => [['text' => $name]]]);
        }
        return $name;
    }

    /** ScoreHolderArgument: "*" for every tracked holder, a selector, or a name (an online player's, if any). */
    private function scoreHolders(string $text, Client $sender): array
    {
        if ($text === '*') {
            $holders = $this->scoreboard()->holders();
        } elseif (str_starts_with($text, '@')) {
            $holders = array_map(self::scoreboardName(...), $this->selectEntities($text, $sender));
        } else {
            $holders = [$text];
        }
        if ($holders === []) {
            throw new CommandFailure(['translate' => 'argument.scoreHolder.empty']);
        }
        return $holders;
    }

    /** The component argument after a number of plain words of the raw input. */
    private function componentAfterWords(string $text, int $words): array
    {
        $reader = new StringReader($text);
        for ($word = 0; $word < $words; ++$word) {
            while ($reader->canRead() && $reader->peek() !== ' ') {
                $reader->skip();
            }
            $reader->skip();
        }
        return TextComponentArgument::read($reader);
    }

    private function objectiveChanged(string $name): void
    {
        if ($this->tracked($name)) {
            $this->broadcastScoreboard('set_objective', $this->objectivePacket($name, 2));
        }
    }

    private static function int32(int $value): int
    {
        $value &= 0xffffffff;
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
