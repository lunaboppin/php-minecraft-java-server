<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait ScheduleCommand
{
    /** @var list<array{string, int}> TimerQueue: the scheduled function and tag names with their due game times. */
    private array $scheduledFunctions = [];

    /**
     * ScheduleCommand: a function or tag runs after a time in ticks (or with s or d); replace, the
     * default, drops earlier schedules of the name. clear drops them all.
     */
    private function executeSchedule(Client $sender, array $arguments): void
    {
        if (($arguments[0] ?? '') === 'clear') {
            $name = $arguments[1] ?? '';
            $before = count($this->scheduledFunctions);
            $this->scheduledFunctions = array_values(array_filter($this->scheduledFunctions, static fn (array $entry): bool => $entry[0] !== $name));
            $removed = $before - count($this->scheduledFunctions);
            if ($removed === 0) {
                throw new CommandFailure(['translate' => 'commands.schedule.cleared.failure', 'with' => [['text' => $name]]]);
            }
            $this->sendSuccess($sender, ['translate' => 'commands.schedule.cleared.success', 'with' => [$removed, ['text' => $name]]], true);
            return;
        }
        $name = $arguments[1] ?? '';
        $this->functionsNamed($name);
        $ticks = self::scheduleTicks($arguments[2] ?? '');
        if ($ticks === 0) {
            throw new CommandFailure(['translate' => 'commands.schedule.same_tick']);
        }
        // The queue names a tag by # and its namespaced ID, which clear must spell the same way.
        $label = self::functionId($name);
        $key = '#' . $label;
        if (($arguments[3] ?? 'replace') === 'replace') {
            $this->scheduledFunctions = array_values(array_filter($this->scheduledFunctions, static fn (array $entry): bool => $entry[0] !== $key));
        }
        $due = $this->worldAge + $ticks;
        $this->scheduledFunctions[] = [$key, $due];
        $this->sendSuccess($sender, ['translate' => 'commands.schedule.created.tag', 'with' => [['text' => $label], $ticks, $due]], true);
    }

    /** TimeArgument: ticks, or seconds and days with s and d, rounded. */
    private static function scheduleTicks(string $text): int
    {
        $unit = substr($text, -1);
        $scale = match ($unit) {
            's' => 20,
            'd' => 24000,
            't' => 1,
            default => null,
        };
        $number = $scale === null ? $text : substr($text, 0, -1);
        return (int) round((float) $number * ($scale ?? 1));
    }

    /** ServerFunctionManager.tick: due schedules run their functions, of which the vanilla tags have none. */
    private function tickScheduledFunctions(): void
    {
        $this->scheduledFunctions = array_values(array_filter($this->scheduledFunctions, fn (array $entry): bool => $entry[1] > $this->worldAge));
    }
}
