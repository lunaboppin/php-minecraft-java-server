<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\Server\Client;

trait PerfCommand
{
    /** @var ?array{sender: Client, start: int, last: int, ticks: list<array{int, int}>, stopping: bool} the metrics being recorded */
    private ?array $perfRecording = null;

    /**
     * PerfCommand: records each tick for up to ten seconds (ActiveMetricsRecorder), then reports
     * the duration and rate and saves a report zip to debug/profiling.
     */
    private function executePerf(Client $sender, array $arguments): void
    {
        if (($arguments[0] ?? '') === 'start') {
            if ($this->perfRecording !== null) {
                throw new CommandFailure(['translate' => 'commands.perf.alreadyRunning']);
            }
            $now = hrtime(true);
            $this->perfRecording = ['sender' => $sender, 'start' => $now, 'last' => $now, 'ticks' => [], 'stopping' => false];
            $this->sendSuccess($sender, ['translate' => 'commands.perf.started'], false);
            return;
        }
        if ($this->perfRecording === null) {
            throw new CommandFailure(['translate' => 'commands.perf.notRunning']);
        }
        // MinecraftServer.finishRecordingMetrics: the recording ends with the tick.
        $this->perfRecording['stopping'] = true;
    }

    /** ActiveMetricsRecorder.endTick: samples the tick, ending at the deadline or when asked. */
    private function recordPerfTick(int $duration): void
    {
        if ($this->perfRecording === null) {
            return;
        }
        $now = hrtime(true);
        $this->perfRecording['ticks'][] = [$now - $this->perfRecording['last'], $duration];
        $this->perfRecording['last'] = $now;
        if (!$this->perfRecording['stopping'] && $now - $this->perfRecording['start'] < 10_000_000_000) {
            return;
        }
        $recording = $this->perfRecording;
        $this->perfRecording = null;
        $sender = $recording['sender'];
        $ticks = count($recording['ticks']);
        $seconds = ($now - $recording['start']) / 1.0E9;
        $this->sendSuccess($sender, ['translate' => 'commands.perf.stopped', 'with' => [
            ['text' => sprintf('%.2f', $seconds)], $ticks, ['text' => sprintf('%.2f', $seconds > 0 ? $ticks / $seconds : 0.0)],
        ]], false);
        $this->savePerfReport($sender, $recording['ticks']);
    }

    /** PerfCommand.saveResults: the system report and the samples, zipped under an unused name. */
    private function savePerfReport(Client $sender, array $ticks): void
    {
        $directory = dirname($this->config->worldPath) . '/debug/profiling';
        $base = date('Y-m-d_H_i_s') . '-world-' . str_replace('.', '_', Version::NAME);
        $name = $base . '.zip';
        for ($index = 1; file_exists($directory . '/' . $name); ++$index) {
            $name = $base . ' (' . $index . ').zip';
        }
        $zip = new \ZipArchive();
        if ((!is_dir($directory) && !@mkdir($directory, 0775, true)) || $zip->open($directory . '/' . $name, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            $this->sendFailure($sender, ['translate' => 'commands.perf.reportFailed']);
            return;
        }
        $zip->addFromString('system.txt', $this->perfSystemReport());
        $csv = "@tick,ticktime\n";
        foreach ($ticks as $index => [$interval]) {
            $csv .= ($index + 1) . ',' . self::javaDouble($interval) . "\n";
        }
        $zip->addFromString('server/metrics/ticking.csv', $csv);
        $durations = array_column(array_slice($ticks, -100), 1);
        $zip->addFromString('server/stats.txt', 'pending_tasks: 0' . "\n" . 'average_tick_time: ' . sprintf('%F', $durations === [] ? 0.0 : array_sum($durations) / count($durations) / 1.0E6) . "\n" . 'tick_times: [' . implode(', ', $durations) . ']' . "\n");
        $rules = '';
        foreach (array_keys($this->version->catalog->gameRules) as $rule) {
            $rules .= 'minecraft:' . $rule . '=' . $this->gameRule($rule) . "\n";
        }
        $zip->addFromString('server/gamerules.txt', $rules);
        $zip->close();
        $this->sendSuccess($sender, ['translate' => 'commands.perf.reportSaved', 'with' => [['text' => $name]]], false);
    }

    /** SystemReport: the version and the machine this server runs on. */
    private function perfSystemReport(): string
    {
        $lines = [
            'Minecraft Version' => Version::NAME,
            'Minecraft Version ID' => Version::NAME,
            'Operating System' => PHP_OS_FAMILY . ' (' . php_uname('m') . ') version ' . php_uname('r'),
            'PHP Version' => PHP_VERSION,
            'Memory' => memory_get_usage(true) . ' bytes (' . intdiv(memory_get_usage(true), 1048576) . ' MiB) up to ' . ini_get('memory_limit'),
            'CPUs' => (string) preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo')),
            'Player Count' => count($this->players()) . ' / ' . $this->config->maxPlayers,
        ];
        $out = '';
        foreach ($lines as $key => $value) {
            $out .= $key . ': ' . $value . "\n";
        }
        return $out;
    }

    /** Double.toString for a whole number: scientific notation from 10^7 up. */
    private static function javaDouble(int $value): string
    {
        if ($value < 10_000_000) {
            return $value . '.0';
        }
        $digits = (string) $value;
        $fraction = rtrim(substr($digits, 1), '0');
        return $digits[0] . '.' . ($fraction === '' ? '0' : $fraction) . 'E' . (strlen($digits) - 1);
    }
}
