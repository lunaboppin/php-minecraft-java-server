<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait DebugCommand
{
    /** @var array{float, int}|null MinecraftServer.startRecordingMetrics: when the profiler started, and the tick then. */
    private ?array $debugProfile = null;

    /**
     * DebugCommand: start and stop time the server's ticks, reporting the seconds, ticks and ticks
     * per second; function traces a function, and none exist.
     */
    private function executeDebug(Client $sender, array $arguments): void
    {
        switch ($arguments[0] ?? '') {
            case 'start':
                if ($this->debugProfile !== null) {
                    throw new CommandFailure(['translate' => 'commands.debug.alreadyRunning']);
                }
                $this->debugProfile = [hrtime(true) / 1.0E9, $this->ticks];
                $this->sendSuccess($sender, ['translate' => 'commands.debug.started'], true);
                return;
            case 'stop':
                if ($this->debugProfile === null) {
                    throw new CommandFailure(['translate' => 'commands.debug.notRunning']);
                }
                [$start, $tick] = $this->debugProfile;
                $this->debugProfile = null;
                $seconds = hrtime(true) / 1.0E9 - $start;
                $ticks = $this->ticks - $tick;
                $this->sendSuccess($sender, ['translate' => 'commands.debug.stopped', 'with' => [
                    ['text' => sprintf('%.2f', $seconds)], $ticks, ['text' => sprintf('%.2f', $seconds > 0 ? $ticks / $seconds : 0.0)],
                ]], true);
                return;
            case 'function':
                // DebugCommand.traceFunction: the trace of the tag's functions, here none, goes to a file.
                $this->functionsNamed($arguments[1] ?? '');
                $name = 'debug-trace-' . date('Y-m-d_H.i.s') . '.txt';
                $directory = dirname($this->config->worldPath) . '/debug';
                if (!is_dir($directory)) {
                    mkdir($directory, 0775, true);
                }
                file_put_contents($directory . '/' . $name, '');
                $this->sendSuccess($sender, ['translate' => 'commands.debug.function.success.multiple', 'with' => [0, 0, ['text' => $name]]], true);
                return;
        }
    }
}
