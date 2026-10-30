<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\World;

/**
 * PlayerSpawnFinder: players without a spawn of their own join and respawn at a random safe
 * column within the respawn_radius game rule of the world spawn.
 */
trait PlayerSpawning
{
    /** The spawn for a player using the world spawn, as the bottom centre of a block. */
    private function sharedSpawn(): array
    {
        $world = $this->worlds['overworld'];
        [$spawnX, $spawnY, $spawnZ] = $world->spawn();
        $suggestion = [(int) floor($spawnX), (int) floor($spawnY), (int) floor($spawnZ)];
        // An adventure-mode world only lifts the spawn clear of blocks.
        if ($this->config->defaultGameMode === 2) {
            return $this->fixupSpawnHeight($world, $suggestion);
        }
        $radius = max(0, (int) $this->gameRule('respawn_radius'));
        $toBorder = (int) floor($this->border('overworld')->distanceToBorder((float) $suggestion[0], (float) $suggestion[2], $this->worldAge));
        if ($toBorder < $radius) {
            $radius = $toBorder;
        }
        if ($toBorder <= 1) {
            $radius = 1;
        }
        $side = $radius * 2 + 1;
        $count = min(1024, $side * $side);
        $coprime = $count <= 16 ? $count - 1 : 17;
        $offset = random_int(0, $count - 1);
        for ($index = 0; $index < $count; ++$index) {
            $value = ($offset + $coprime * $index) % $count;
            $x = $suggestion[0] + $value % $side - $radius;
            $z = $suggestion[2] + intdiv($value, $side) - $radius;
            // Vanilla loads each candidate's chunk. Here a chunk still generating ends the search at
            // the world spawn itself, since its blocks would read as air.
            if (!$world->ready($x >> 4, $z >> 4)) {
                $world->requestGeneration($x >> 4, $z >> 4, 0);
                return [$spawnX, $spawnY, $spawnZ];
            }
            $position = $this->levelRespawnPosition($world, $x, $z);
            if ($position !== null && $this->noCollisionNoLiquid($world, $position)) {
                return [$position[0] + 0.5, (float) $position[1], $position[2] + 0.5];
            }
        }
        return $this->fixupSpawnHeight($world, $suggestion);
    }

    /** PlayerSpawnFinder.getLevelRespawnPos: above the top full face below the motion-blocking height. */
    private function levelRespawnPosition(World $world, int $x, int $z): ?array
    {
        [$motion, $surface, $oceanFloor] = $this->columnHeights($world, $x, $z);
        if ($motion < $world->minY) {
            return null;
        }
        if ($surface <= $motion && $surface > $oceanFloor) {
            return null;
        }
        $catalog = $this->version->catalog;
        $collision = new Collision($catalog);
        for ($y = $motion + 1; $y >= $world->minY; --$y) {
            $state = $world->get($x, $y, $z);
            if ($catalog->fluid($state)) {
                break;
            }
            foreach ($collision->shape($state) as $box) {
                // Block.isFaceFull for the top face: a box spanning the whole block at its top.
                if ($box[4] >= 1.0 && $box[0] <= 0.0 && $box[2] <= 0.0 && $box[3] >= 1.0 && $box[5] >= 1.0) {
                    return [$x, $y + 1, $z];
                }
            }
        }
        return null;
    }

    /**
     * ChunkAccess.getHeight for MOTION_BLOCKING, WORLD_SURFACE and OCEAN_FLOOR: the highest
     * block of each kind, or one below the world.
     */
    private function columnHeights(World $world, int $x, int $z): array
    {
        $flags = $this->version->catalog->flags;
        $heights = [null, null, null];
        for ($y = $world->minY + $world->height - 1; $y >= $world->minY && in_array(null, $heights, true); --$y) {
            $flag = $flags[$world->get($x, $y, $z)] ?? 0;
            if (($flag & 1) !== 0) {
                continue;
            }
            $heights[1] ??= $y;
            if (($flag & 6) !== 0) {
                $heights[0] ??= $y;
            }
            if (($flag & 2) !== 0) {
                $heights[2] ??= $y;
            }
        }
        return array_map(static fn (?int $height): int => $height ?? $world->minY - 1, $heights);
    }

    /** CollisionGetter.noCollision with liquids, for a player's box standing at the position. */
    private function noCollisionNoLiquid(World $world, array $position): bool
    {
        [$x, $y, $z] = $position;
        $box = [$x + 0.2, $y, $z + 0.2, $x + 0.8, $y + 1.8, $z + 0.8];
        foreach ((new Collision($this->version->catalog))->boxes($world, $box) as $solid) {
            if ($solid[0] < $box[3] && $solid[3] > $box[0] && $solid[1] < $box[4] && $solid[4] > $box[1] && $solid[2] < $box[5] && $solid[5] > $box[2]) {
                return false;
            }
        }
        for ($by = $y; $by <= (int) floor($y + 1.8); ++$by) {
            if ($this->version->catalog->fluid($world->get($x, $by, $z))) {
                return false;
            }
        }
        return true;
    }

    /** PlayerSpawnFinder.fixupSpawnHeight: up out of blocks, then down onto the ground. */
    private function fixupSpawnHeight(World $world, array $position): array
    {
        [$x, $y, $z] = $position;
        $maxY = $world->minY + $world->height - 1;
        while (!$this->noCollisionNoLiquid($world, [$x, $y, $z]) && $y < $maxY) {
            ++$y;
        }
        --$y;
        while ($this->noCollisionNoLiquid($world, [$x, $y, $z]) && $y > $world->minY) {
            --$y;
        }
        return [$x + 0.5, (float) ($y + 1), $z + 0.5];
    }
}
