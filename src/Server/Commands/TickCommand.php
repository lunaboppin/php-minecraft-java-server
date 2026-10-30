<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;
use PhpMinecraftJavaServer\World\Generation\Float32;

trait TickCommand
{
    /** TickCommand: query, rate, freeze, unfreeze, step and sprint. */
    private function executeTick(Client $sender, array $arguments): void
    {
        $action = $arguments[0] ?? '';
        $milliseconds = static fn (int|float $nanos): string => sprintf('%.1f', Float32::round(Float32::round((float) $nanos) / 1000000.0));
        switch ($action) {
            case 'query':
                $average = $this->tickTimes === [] ? 0 : intdiv(array_sum($this->tickTimes), count($this->tickTimes));
                $busy = $milliseconds($average);
                $rate = sprintf('%.1f', $this->tickRate);
                if ($this->sprinting()) {
                    $this->sendSuccess($sender, ['translate' => 'commands.tick.status.sprinting'], false);
                    $this->sendSuccess($sender, ['translate' => 'commands.tick.query.rate.sprinting', 'with' => [['text' => $rate], ['text' => $busy]]], false);
                } else {
                    $status = $this->tickFrozen ? 'frozen' : ($this->nanosecondsPerTick < $average ? 'lagging' : 'running');
                    $this->sendSuccess($sender, ['translate' => 'commands.tick.status.' . $status], false);
                    $this->sendSuccess($sender, ['translate' => 'commands.tick.query.rate.running', 'with' => [['text' => $rate], ['text' => $busy], ['text' => $milliseconds($this->nanosecondsPerTick)]]], false);
                }
                // MinecraftServer.tickTimesNanos holds 100 samples, zero until filled.
                $samples = array_pad($this->tickTimes, 100, 0);
                sort($samples);
                $this->sendSuccess($sender, ['translate' => 'commands.tick.query.percentiles', 'with' => [['text' => $milliseconds($samples[50])], ['text' => $milliseconds($samples[95])], ['text' => $milliseconds($samples[99])], 100]], false);
                return;
            case 'rate':
                $this->setTickRate((float) $arguments[1]);
                $this->sendSuccess($sender, ['translate' => 'commands.tick.rate.success', 'with' => [['text' => sprintf('%.1f', $this->tickRate)]]], true);
                return;
            case 'freeze':
            case 'unfreeze':
                $freeze = $action === 'freeze';
                if ($freeze) {
                    if ($this->sprinting()) {
                        $this->stopSprinting();
                    }
                    if ($this->frozenTicksToRun > 0) {
                        $this->stopStepping();
                    }
                }
                $this->setTickFrozen($freeze);
                $this->sendSuccess($sender, ['translate' => 'commands.tick.status.' . ($freeze ? 'frozen' : 'running')], true);
                return;
            case 'step':
                if (($arguments[1] ?? null) === 'stop') {
                    if (!$this->stopStepping()) {
                        throw new CommandFailure(['translate' => 'commands.tick.step.stop.fail']);
                    }
                    $this->sendSuccess($sender, ['translate' => 'commands.tick.step.stop.success'], true);
                    return;
                }
                $ticks = isset($arguments[1]) ? $this->timeArgument($arguments[1], 1) : 1;
                if (!$this->stepGameIfPaused($ticks)) {
                    throw new CommandFailure(['translate' => 'commands.tick.step.fail']);
                }
                $this->sendSuccess($sender, ['translate' => 'commands.tick.step.success', 'with' => [$ticks]], true);
                return;
            case 'sprint':
                if (($arguments[1] ?? null) === 'stop') {
                    if (!$this->stopSprinting()) {
                        throw new CommandFailure(['translate' => 'commands.tick.sprint.stop.fail']);
                    }
                    $this->sendSuccess($sender, ['translate' => 'commands.tick.sprint.stop.success'], true);
                    return;
                }
                if ($this->requestGameToSprint($this->timeArgument($arguments[1] ?? '', 1))) {
                    $this->sendSuccess($sender, ['translate' => 'commands.tick.sprint.stop.success'], true);
                }
                $this->sendSuccess($sender, ['translate' => 'commands.tick.status.sprinting'], true);
                return;
            default:
                throw new \InvalidArgumentException('Usage: /tick query|rate|freeze|unfreeze|step|sprint');
        }
    }
}
