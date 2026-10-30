<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\FluidLevel;
use PhpMinecraftJavaServer\World\Fluids;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;

/**
 * Scheduled fluid ticks in the world: water and lava spread through World\Fluids as vanilla's
 * FlowingFluid does, ticked by fluid type after the block ticks of the same game tick.
 */
trait FluidSimulation
{
    private ?Fluids $fluids = null;

    private ?FluidLevel $fluidLevel = null;

    private const FLUID_TICKS = ['water', 'flowing_water', 'lava', 'flowing_lava'];

    /** Direction.UPDATE_ORDER for neighborChanged: west, east, down, up, north, south. */
    private const NEIGHBOUR_ORDER = [[-1, 0, 0], [1, 0, 0], [0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1]];

    /** BlockBehaviour.UPDATE_SHAPE_ORDER: west, east, north, south, down, up. */
    private const SHAPE_ORDER = [[-1, 0, 0], [1, 0, 0], [0, 0, -1], [0, 0, 1], [0, -1, 0], [0, 1, 0]];

    private function fluids(): Fluids
    {
        return $this->fluids ??= new Fluids(new Blocks($this->version, $this->version->directory . '/worldgen/block-behaviour.json'), new Collision($this->version->catalog));
    }

    private function fluidLevel(): FluidLevel
    {
        return $this->fluidLevel ??= new class ($this->worldBlock(...), $this->setBlock(...), $this->scheduleFluid(...), $this->levelEvent(...), $this->dropBlockResources(...), $this->gameRule(...), $this->fastLava(...)) implements FluidLevel {
            public function __construct(
                private readonly \Closure $block,
                private readonly \Closure $set,
                private readonly \Closure $schedule,
                private readonly \Closure $event,
                private readonly \Closure $drop,
                private readonly \Closure $rule,
                private readonly \Closure $fast,
            ) {
            }

            public function block(int $x, int $y, int $z): int
            {
                return ($this->block)($x, $y, $z);
            }

            public function setBlockAndUpdate(int $x, int $y, int $z, int $state): void
            {
                ($this->set)($x, $y, $z, $state);
            }

            public function scheduleFluidTick(string $type, int $x, int $y, int $z, int $delay): void
            {
                ($this->schedule)($type, $x, $y, $z, $delay);
            }

            public function levelEvent(int $event, int $x, int $y, int $z, int $data): void
            {
                ($this->event)($event, [$x, $y, $z], $data);
            }

            public function dropResources(int $x, int $y, int $z, int $state): void
            {
                ($this->drop)($x, $y, $z, $state);
            }

            public function gameRule(string $name): bool
            {
                return ($this->rule)($name) === 'true';
            }

            public function fastLava(): bool
            {
                return ($this->fast)();
            }

            public function randomInt(int $bound): int
            {
                return random_int(0, $bound - 1);
            }
        };
    }

    private function worldBlock(int $x, int $y, int $z): int
    {
        return $this->world->get($x, $y, $z);
    }

    private function fastLava(): bool
    {
        return $this->world->dimension === 'the_nether';
    }

    /** LevelTicks.schedule: a fluid tick is kept once pending, however soon a later one would be due. */
    private function scheduleFluid(string $type, int $x, int $y, int $z, int $delay): void
    {
        if (!isset($this->blockTicks[$type . ':' . $x . ':' . $y . ':' . $z])) {
            $this->scheduleBlock($type, $x, $y, $z, $delay);
        }
    }

    /**
     * After a block changes: LiquidBlock.onPlace for fluid placed there, then neighborChanged for
     * liquid neighbours (lava meeting water hardens) and updateShape for waterlogged ones, which
     * schedule their fluid's tick.
     */
    private function fluidsChanged(int $x, int $y, int $z): void
    {
        $fluids = $this->fluids();
        $level = $this->fluidLevel();
        $this->bubbleColumnsChanged($x, $y, $z);
        $schedule = function (int $a, int $b, int $c) use ($fluids, $level): void {
            $fluid = $fluids->fluid($this->world->get($a, $b, $c));
            if ($fluid !== null && $fluids->shouldSpread($level, $a, $b, $c)) {
                $fluid = $fluids->fluid($this->world->get($a, $b, $c));
                if ($fluid !== null) {
                    $this->scheduleFluid(Fluids::tickType($fluid), $a, $b, $c, $fluids->tickDelay($fluid[0], $level));
                }
            }
        };
        $schedule($x, $y, $z);
        $liquid = fn (int $a, int $b, int $c): bool => in_array($this->version->catalog->blockName($this->world->get($a, $b, $c)), ['water', 'lava'], true);
        foreach (self::NEIGHBOUR_ORDER as [$dx, $dy, $dz]) {
            if ($liquid($x + $dx, $y + $dy, $z + $dz)) {
                $schedule($x + $dx, $y + $dy, $z + $dz);
            }
        }
        foreach (self::SHAPE_ORDER as [$dx, $dy, $dz]) {
            if (!$liquid($x + $dx, $y + $dy, $z + $dz)) {
                $schedule($x + $dx, $y + $dy, $z + $dz);
            }
        }
    }

    /**
     * LiquidBlock.onPlace and updateShape from below: a water source over soul sand or magma
     * builds its bubble column 20 ticks later. BubbleColumnBlock.updateShape: a column re-checks
     * itself 5 ticks after the block below changes, when it can no longer stand, or when water it
     * could fill appears above.
     */
    private function bubbleColumnsChanged(int $x, int $y, int $z): void
    {
        $fluids = $this->fluids();
        $get = fn (int $a, int $b, int $c): int => $this->world->get($a, $b, $c);
        foreach ([[$x, $y, $z], [$x, $y + 1, $z]] as [$a, $b, $c]) {
            $state = $get($a, $b, $c);
            if ($this->version->catalog->blockName($state) === 'water' && $fluids->canHoldColumn($state) && $fluids->raisesColumn($get($a, $b - 1, $c))) {
                $this->scheduleFluid('water_block', $a, $b, $c, 20);
            }
        }
        foreach ([[0, 1, 0], [0, -1, 0], [-1, 0, 0], [1, 0, 0], [0, 0, -1], [0, 0, 1]] as [$dx, $dy, $dz]) {
            [$a, $b, $c] = [$x + $dx, $y + $dy, $z + $dz];
            if ($this->version->catalog->blockName($get($a, $b, $c)) !== 'bubble_column') {
                continue;
            }
            $above = $get($a, $b + 1, $c);
            if (!$fluids->columnSurvives($get($a, $b - 1, $c)) || $dy === 1 || $dy === -1 && $fluids->canHoldColumn($above) && $this->version->catalog->blockName($above) !== 'bubble_column') {
                $this->scheduleFluid('bubble_column', $a, $b, $c, 5);
            }
        }
    }

    /** LiquidBlock.tick and BubbleColumnBlock.tick: build or clear the column from this block up. */
    private function tickColumn(string $kind, int $x, int $y, int $z): void
    {
        $name = $this->version->catalog->blockName($this->world->get($x, $y, $z));
        if ($kind === 'bubble_column' ? $name === 'bubble_column' : $name === 'water' && $this->fluids()->canHoldColumn($this->world->get($x, $y, $z))) {
            $this->fluids()->updateColumn($this->fluidLevel(), $x, $y, $z);
        }
    }

    /** ServerLevel.tickFluid: the tick runs only while that fluid type is still there. */
    private function tickFluid(string $type, int $x, int $y, int $z): void
    {
        $fluids = $this->fluids();
        $fluid = $fluids->fluid($this->world->get($x, $y, $z));
        // Ticks saved before fluid types were kept (kind "water") run for either water type.
        if ($fluid === null || ($type !== 'water' || $fluid[0] !== 'water') && Fluids::tickType($fluid) !== $type) {
            return;
        }
        $fluids->tick($this->fluidLevel(), $x, $y, $z);
    }

    /** Block.dropResources for a block a fluid destroys: its loot table without a tool. */
    private function dropBlockResources(int $x, int $y, int $z, int $state): void
    {
        if ($this->gameRule('block_drops') !== 'true') {
            return;
        }
        $name = $this->version->catalog->blockName($state);
        $blockAt = function (int $offset) use ($x, $y, $z): array {
            $above = $this->world->get($x, $y + $offset, $z);
            return ['minecraft:' . $this->version->catalog->blockName($above), array_map('strval', $this->version->catalog->properties($above))];
        };
        $context = ['block' => 'minecraft:' . $name, 'state' => array_map('strval', $this->version->catalog->properties($state)), 'tool' => null, 'blockAt' => $blockAt];
        foreach ($this->loot()->drops('minecraft:blocks/' . $name, $context) as [$drop, $count]) {
            $item = $this->version->blocks['items'][substr($drop, 10)] ?? null;
            if ($item !== null) {
                $this->popResource($x, $y, $z, ['item' => $item, 'count' => $count]);
            }
        }
    }
}
