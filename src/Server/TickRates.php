<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\World\Generation\Float32;

/** ServerTickRateManager: the tick rate, freezing, stepping and sprinting that /tick controls. */
trait TickRates
{
    private float $tickRate = 20.0;

    private int $nanosecondsPerTick = 50000000;

    private bool $tickFrozen = false;

    private int $frozenTicksToRun = 0;

    private bool $runGameElements = true;

    private int $remainingSprintTicks = 0;

    private int $scheduledSprintTicks = 0;

    private int $sprintTimeSpent = 0;

    private int $sprintTickStart = 0;

    private bool $frozenBeforeSprint = false;

    /** @var list<int> the last 100 ticks' durations in nanoseconds (MinecraftServer.tickTimesNanos) */
    private array $tickTimes = [];

    private function setTickRate(float $rate): void
    {
        $this->tickRate = Float32::round(max($rate, 1.0));
        $this->nanosecondsPerTick = (int) (1000000000 / $this->tickRate);
        $this->broadcastTickingState();
    }

    private function setTickFrozen(bool $frozen): void
    {
        $this->tickFrozen = $frozen;
        $this->broadcastTickingState();
    }

    private function tickingStatePayload(): string
    {
        return pack('G', $this->tickRate) . ($this->tickFrozen ? "\1" : "\0");
    }

    private function broadcastTickingState(): void
    {
        foreach ($this->players() as $player) {
            $player->send($this->version, 'ticking_state', $this->tickingStatePayload());
        }
    }

    private function broadcastTickingStep(): void
    {
        foreach ($this->players() as $player) {
            $player->send($this->version, 'ticking_step', Binary::writeVarInt($this->frozenTicksToRun));
        }
    }

    /** ServerTickRateManager.updateJoiningPlayer. */
    private function sendTickingState(Client $c): void
    {
        $c->send($this->version, 'ticking_state', $this->tickingStatePayload());
        $c->send($this->version, 'ticking_step', Binary::writeVarInt($this->frozenTicksToRun));
    }

    /** TickRateManager.tick: frozen game elements run only while steps remain. */
    private function tickRateManagerTick(): void
    {
        $this->runGameElements = !$this->tickFrozen || $this->frozenTicksToRun > 0;
        if ($this->frozenTicksToRun > 0) {
            --$this->frozenTicksToRun;
        }
    }

    private function stepGameIfPaused(int $ticks): bool
    {
        if (!$this->tickFrozen) {
            return false;
        }
        $this->frozenTicksToRun = $ticks;
        $this->broadcastTickingStep();
        return true;
    }

    private function stopStepping(): bool
    {
        if ($this->frozenTicksToRun <= 0) {
            return false;
        }
        $this->frozenTicksToRun = 0;
        $this->broadcastTickingStep();
        return true;
    }

    private function requestGameToSprint(int $ticks): bool
    {
        $interrupted = $this->remainingSprintTicks > 0;
        $this->sprintTimeSpent = 0;
        $this->scheduledSprintTicks = $ticks;
        $this->remainingSprintTicks = $ticks;
        $this->frozenBeforeSprint = $this->tickFrozen;
        $this->setTickFrozen(false);
        return $interrupted;
    }

    private function stopSprinting(): bool
    {
        if ($this->remainingSprintTicks <= 0) {
            return false;
        }
        $this->finishTickSprint();
        return true;
    }

    /** ServerTickRateManager.finishTickSprint: report the sprint to operators and the console. */
    private function finishTickSprint(): void
    {
        $completed = $this->scheduledSprintTicks - $this->remainingSprintTicks;
        $milliseconds = max(1.0, (float) $this->sprintTimeSpent) / 1000000;
        $perSecond = (int) (1000 * $completed / $milliseconds);
        $perTick = sprintf('%.2f', $completed === 0 ? Float32::round($this->nanosecondsPerTick / 1000000) : $milliseconds / $completed);
        $this->scheduledSprintTicks = 0;
        $this->sprintTimeSpent = 0;
        $this->remainingSprintTicks = 0;
        $this->broadcastToOperators(['translate' => 'commands.tick.sprint.report', 'with' => [$perSecond, ['text' => $perTick]]]);
        $this->setTickFrozen($this->frozenBeforeSprint);
    }

    /** CommandSourceStack.sendSuccess for the server's own source: logged, and shown to operators. */
    private function broadcastToOperators(array $message): void
    {
        if ($this->gameRule('log_admin_commands') === 'true') {
            $this->log('[Server] ' . json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        if ($this->gameRule('send_command_feedback') !== 'true') {
            return;
        }
        $admin = \PhpMinecraftJavaServer\Protocol\Packets::systemChat(['translate' => 'chat.type.admin', 'with' => [['text' => 'Server'], $message], 'color' => 'gray', 'italic' => true]);
        foreach ($this->players() as $player) {
            if ($this->operator($player)) {
                $player->send($this->version, 'system_chat', $admin);
            }
        }
    }

    private function sprinting(): bool
    {
        return $this->scheduledSprintTicks > 0;
    }

    /** ServerTickRateManager.checkShouldSprintThisTick, which ends a finished sprint. */
    private function checkShouldSprintThisTick(): bool
    {
        if (!$this->runGameElements) {
            return false;
        }
        if ($this->remainingSprintTicks > 0) {
            $this->sprintTickStart = hrtime(true);
            --$this->remainingSprintTicks;
            return true;
        }
        $this->finishTickSprint();
        return false;
    }
}
