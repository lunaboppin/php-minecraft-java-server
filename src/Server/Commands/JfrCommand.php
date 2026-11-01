<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait JfrCommand
{
    /**
     * JfrCommand: Java Flight Recorder needs a JVM, so starting fails as JvmProfiler.start does
     * when no recording can begin, and stopping finds nothing being recorded.
     */
    private function executeJfr(Client $sender, array $arguments): void
    {
        if (($arguments[0] ?? '') === 'start') {
            throw new CommandFailure(['translate' => 'commands.jfr.start.failed']);
        }
        throw new CommandFailure(['translate' => 'commands.jfr.dump.failed', 'with' => [['text' => 'Not currently profiling']]]);
    }
}
