<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;

/**
 * Per-chunk mob storage, as vanilla keeps entities with their chunk: mobs in chunks no player
 * has loaded are saved with that chunk and leave the world until it is sent to a player again.
 * The mob limit then counts only the mobs around players.
 */
trait ChunkMobs
{
    /** Every second, save the mobs whose chunk no player in this dimension has loaded. */
    private function unloadDistantMobs(): void
    {
        if ($this->ticks % 20 !== 0) {
            return;
        }
        $loaded = [];
        foreach ($this->localPlayers() as $player) {
            $loaded += $player->loadedChunks;
        }
        $byChunk = [];
        foreach ($this->animals as $id => $mob) {
            // Dying mobs finish dying; riders and their mounts stay together in the world.
            if (isset($mob['deadAt']) || isset($mob['vehicle']) || ($mob['passengers'] ?? []) !== []) {
                continue;
            }
            $key = (int) floor($mob['x'] / 16) . ':' . (int) floor($mob['z'] / 16);
            if (!isset($loaded[$key])) {
                $byChunk[$key][] = $id;
            }
        }
        if ($byChunk === []) {
            return;
        }
        foreach ($byChunk as $key => $ids) {
            [$cx, $cz] = array_map('intval', explode(':', $key));
            $this->world->storeChunkMobs($cx, $cz, array_map(fn (int $id): array => $this->animals[$id], $ids));
            foreach ($ids as $id) {
                $this->unloadMob($id);
            }
        }
        // The stored mobs leave the saved list at once, so a crash cannot keep them twice.
        $this->world->setting('animals', $this->animals);
    }

    /** A chunk's stored mobs back in the world as it is sent to a player. */
    private function loadChunkMobs(int $cx, int $cz): void
    {
        $mobs = $this->world->takeChunkMobs($cx, $cz);
        if ($mobs === []) {
            return;
        }
        foreach ($mobs as $mob) {
            $id = $this->restoreAnimal($mob);
            if ($id === null) {
                continue;
            }
            foreach ($this->localPlayers() as $player) {
                $this->showAnimal($player, $this->animals[$id]);
            }
        }
        $this->world->setting('animals', $this->animals);
    }

    /** Remove a mob that is saved elsewhere: unlike removeAnimal, its scores and team stay. */
    private function unloadMob(int $id): void
    {
        unset($this->mobRandomSources[$id], $this->mobMovementTrackers[$id]);
        $this->forgetPushingEntity($id);
        unset($this->animals[$id]);
        foreach ($this->localPlayers() as $player) {
            if (isset($player->entities[$id])) {
                $player->send($this->version, 'remove_entities', "\1" . Binary::writeVarInt($id));
                unset($player->entities[$id]);
            }
        }
    }
}
