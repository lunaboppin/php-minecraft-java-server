<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait StopwatchCommand
{
    /** @var array<string, array{created: float, accumulated: int}>|null stopwatches by ID, timed in monotonic milliseconds */
    private ?array $stopwatches = null;

    /** StopwatchCommand: create, query (in seconds), restart and remove. */
    private function executeStopwatch(Client $sender, array $arguments): void
    {
        [$action, $text] = [$arguments[0] ?? '', $arguments[1] ?? ''];
        $id = str_contains($text, ':') ? $text : 'minecraft:' . $text;
        $stopwatches = &$this->stopwatchList();
        $now = self::stopwatchMillis();
        if ($action === 'create') {
            if (isset($stopwatches[$id])) {
                throw new CommandFailure(['translate' => 'commands.stopwatch.already_exists', 'with' => [['text' => $id]]]);
            }
            $stopwatches[$id] = ['created' => $now, 'accumulated' => 0];
            $this->sendSuccess($sender, ['translate' => 'commands.stopwatch.create.success', 'with' => [['text' => $id]]], true);
            return;
        }
        if (!isset($stopwatches[$id])) {
            throw new CommandFailure(['translate' => 'commands.stopwatch.does_not_exist', 'with' => [['text' => $id]]]);
        }
        switch ($action) {
            case 'query':
                // Stopwatch.elapsedSeconds: whole milliseconds over 1000.
                $elapsed = ($stopwatches[$id]['accumulated'] + (int) ($now - $stopwatches[$id]['created'])) / 1000.0;
                $this->sendSuccess($sender, ['translate' => 'commands.stopwatch.query', 'with' => [['text' => $id], new \PhpMinecraftJavaServer\Protocol\NbtDouble($elapsed)]], true);
                return;
            case 'restart':
                $stopwatches[$id] = ['created' => $now, 'accumulated' => 0];
                $this->sendSuccess($sender, ['translate' => 'commands.stopwatch.restart.success', 'with' => [['text' => $id]]], true);
                return;
            default:
                unset($stopwatches[$id]);
                $this->sendSuccess($sender, ['translate' => 'commands.stopwatch.remove.success', 'with' => [['text' => $id]]], true);
        }
    }

    /** Stopwatches.unpack: saved elapsed times resume from when the server loads them. */
    private function &stopwatchList(): array
    {
        if ($this->stopwatches === null) {
            $this->stopwatches = [];
            $now = self::stopwatchMillis();
            foreach ($this->worlds['overworld']->setting('stopwatches') ?? [] as $id => $elapsed) {
                $this->stopwatches[$id] = ['created' => $now, 'accumulated' => (int) $elapsed];
            }
        }
        return $this->stopwatches;
    }

    /** Stopwatches.pack: each stopwatch's elapsed milliseconds, by ID. */
    private function saveStopwatches(): void
    {
        if ($this->stopwatches === null) {
            return;
        }
        $now = self::stopwatchMillis();
        $packed = array_map(static fn (array $stopwatch): int => $stopwatch['accumulated'] + (int) ($now - $stopwatch['created']), $this->stopwatches);
        ksort($packed, SORT_STRING);
        $this->worlds['overworld']->setting('stopwatches', (object) $packed);
    }

    /** Util.getMillis: a monotonic clock in milliseconds. */
    private static function stopwatchMillis(): float
    {
        return hrtime(true) / 1e6;
    }
}
