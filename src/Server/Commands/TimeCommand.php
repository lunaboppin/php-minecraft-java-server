<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\NbtLong;
use PhpMinecraftJavaServer\Server\Client;

trait TimeCommand
{
    /**
     * TimeCommand on world clocks: query, set (ticks or a time marker), add, pause, resume and
     * rate, for the sender's dimension's default clock or "of <clock>". Timeline queries are not
     * modelled yet.
     */
    private function executeTime(Client $sender, array $arguments): void
    {
        if (($arguments[0] ?? '') === 'of') {
            $clock = str_contains($arguments[1] ?? '', ':') ? $arguments[1] : 'minecraft:' . ($arguments[1] ?? '');
            if (!isset(self::CLOCKS[$clock])) {
                throw new CommandFailure(['translate' => 'argument.resource.not_found', 'with' => [['text' => $clock], ['text' => 'minecraft:world_clock']]]);
            }
            $arguments = array_slice($arguments, 2);
        } else {
            // A dimension type's default clock; the Nether has none.
            $clock = ['overworld' => 'minecraft:overworld', 'the_end' => 'minecraft:the_end'][$sender->dimension]
                ?? throw new CommandFailure(['translate' => 'commands.time.no_default_clock', 'with' => [['text' => 'minecraft:' . $sender->dimension]]]);
        }
        [$total, $paused, $rateField] = self::CLOCKS[$clock];
        $name = ['text' => $clock];
        // Clock totals and game time are Java longs; a set time echoes the int argument.
        $absolute = fn (): array => ['translate' => 'commands.time.set.absolute', 'with' => [$name, new NbtLong($this->$total)]];
        switch ($arguments[0] ?? '') {
            case 'query':
                $message = match ($arguments[1] ?? '') {
                    'time' => ['translate' => 'commands.time.query.absolute', 'with' => [$name, new NbtLong($this->$total)]],
                    'gametime' => ['translate' => 'commands.time.query.gametime', 'with' => [new NbtLong($this->worldAge)]],
                    default => throw new \InvalidArgumentException('Usage: /time query time|gametime'),
                };
                $this->sendSuccess($sender, $message, false);
                return;
            case 'set':
                $marker = $this->timeMarker($clock, $arguments[1] ?? '');
                if ($marker !== null) {
                    [$markerName, $ticks, $period] = $marker;
                    if ($this->$total % $period === $ticks) {
                        throw new CommandFailure(['translate' => 'commands.time.set.already_at_time_marker', 'with' => [$name, ['text' => $markerName]]]);
                    }
                    // ClockTimeMarker.resolveTimeToMoveTo: forward to the marker's next occurrence.
                    $until = $ticks - $this->$total % $period;
                    $this->setClockTicks($clock, $this->$total + ($until > 0 ? $until : $period + $until));
                    $this->sendSuccess($sender, ['translate' => 'commands.time.set.time_marker', 'with' => [$name, ['text' => $markerName]]], true);
                    return;
                }
                $ticks = $this->timeArgument($arguments[1] ?? '', 0);
                if ($ticks === $this->$total) {
                    throw new CommandFailure(['translate' => 'commands.time.set.already_at_time', 'with' => [$name, $ticks]]);
                }
                $this->setClockTicks($clock, $ticks);
                $this->sendSuccess($sender, ['translate' => 'commands.time.set.absolute', 'with' => [$name, $ticks]], true);
                return;
            case 'add':
                $this->setClockTicks($clock, $this->$total + $this->timeArgument($arguments[1] ?? '', -2147483648));
                $this->sendSuccess($sender, $absolute(), true);
                return;
            case 'pause':
            case 'resume':
                $pause = $arguments[0] === 'pause';
                if ($this->$paused === $pause) {
                    throw new CommandFailure(['translate' => $pause ? 'commands.time.pause.already_paused' : 'commands.time.pause.already_running', 'with' => [$name]]);
                }
                $this->$paused = $pause;
                $this->broadcastClock($clock);
                $this->sendSuccess($sender, ['translate' => $pause ? 'commands.time.pause' : 'commands.time.resume', 'with' => [$name]], true);
                return;
            case 'rate':
                // FloatArgumentType between 1.0E-5 and 1000, as a Java float.
                $rate = is_numeric($arguments[1] ?? '') ? unpack('G', pack('G', (float) $arguments[1]))[1] : null;
                if ($rate === null || $rate < 1.0E-5 || $rate > 1000.0) {
                    throw new \InvalidArgumentException('Rate must be between 1.0E-5 and 1000');
                }
                if ($rate === $this->$rateField) {
                    throw new CommandFailure(['translate' => 'commands.time.rate.already_same', 'with' => [$name, $rate]]);
                }
                $this->$rateField = $rate;
                $this->broadcastClock($clock);
                $this->sendSuccess($sender, ['translate' => 'commands.time.rate', 'with' => [$name, $rate]], true);
                return;
            default:
                throw new \InvalidArgumentException('Usage: /time [of <clock>] set|add|query|pause|resume|rate');
        }
    }

    /**
     * A time marker of the clock's timelines, named with or without "minecraft:".
     *
     * @return array{string, int, int}|null name, ticks and period
     */
    private function timeMarker(string $clock, string $text): ?array
    {
        if ($text === '' || preg_match('/\A-?[0-9.]+[dst]?\z/D', $text) === 1) {
            return null;
        }
        $name = str_contains($text, ':') ? $text : 'minecraft:' . $text;
        foreach ($this->version->catalog->timelines as $timeline) {
            if ($timeline['clock'] === $clock && isset($timeline['time_markers'][$name])) {
                return [$name, $timeline['time_markers'][$name]['ticks'], $timeline['period_ticks']];
            }
        }
        throw new CommandFailure(['translate' => 'commands.time.no_time_marker_found', 'with' => [['text' => $clock], ['text' => $name]]]);
    }

    /** TimeArgument: ticks, or seconds (s) and days (d), rounded to whole ticks. */
    private function timeArgument(string $text, int $minimum): int
    {
        if (preg_match('/\A(-?(?:\d+(?:\.\d*)?|\.\d+))([dst]?)\z/D', $text, $match) !== 1) {
            throw new \InvalidArgumentException('Invalid time: ' . $text);
        }
        $ticks = (int) round((float) $match[1] * ['' => 1, 't' => 1, 's' => 20, 'd' => 24000][$match[2]]);
        if ($ticks < $minimum) {
            throw new \InvalidArgumentException('Time must not be less than ' . $minimum . ', found ' . $ticks);
        }
        return $ticks;
    }

    private function setClockTicks(string $clock, int $ticks): void
    {
        $this->{self::CLOCKS[$clock][0]} = $ticks;
        $this->broadcastClock($clock);
    }
}
