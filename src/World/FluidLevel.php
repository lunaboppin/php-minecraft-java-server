<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

/** The world as fluid ticks see it: blocks, updates, scheduled ticks and the rules they read. */
interface FluidLevel
{
    public function block(int $x, int $y, int $z): int;

    /** Level.setBlockAndUpdate: the block changes and its neighbours are told. */
    public function setBlockAndUpdate(int $x, int $y, int $z, int $state): void;

    /** LevelTicks.schedule for a fluid type (water, flowing_water, lava, flowing_lava), unless one is pending. */
    public function scheduleFluidTick(string $type, int $x, int $y, int $z, int $delay): void;

    public function levelEvent(int $event, int $x, int $y, int $z, int $data): void;

    /** Block.dropResources for a block flowing water destroys. */
    public function dropResources(int $x, int $y, int $z, int $state): void;

    public function gameRule(string $name): bool;

    /** EnvironmentAttributes.FAST_LAVA: lava flows further and faster (the Nether). */
    public function fastLava(): bool;

    /** Level.getRandom().nextInt(bound) */
    public function randomInt(int $bound): int;
}
