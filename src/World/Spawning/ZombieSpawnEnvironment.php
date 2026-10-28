<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

/** Synchronous world effects preserve the ordering of the native spawn body. */
interface ZombieSpawnEnvironment
{
    public function makeBaby(): void;

    /** @return list<int> Eligible chickens in native entity-query order. */
    public function findChickens(): array;

    /** Construct and initialize a chicken before attempting to attach its rider. */
    public function createChicken(): ?int;

    /** Mark the selected chicken as a jockey and attempt to attach the zombie. */
    public function rideChicken(int $id): bool;

    public function addChicken(int $id): void;
}
