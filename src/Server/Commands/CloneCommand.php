<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait CloneCommand
{
    /**
     * CloneCommands: copy a box, optionally between dimensions, keeping every block (replace),
     * only non-air ones (masked) or those matching a filter, then normal, force (may overlap) or
     * move (clears the source). Block entities' data is copied with their blocks.
     */
    private function executeClone(Client $sender, array $arguments): void
    {
        $usage = 'Usage: /clone [from <dimension>] begin end [to <dimension>] destination [strict] [replace|masked|filtered <filter>] [force|move|normal]';
        $from = $sender->dimension;
        if (($arguments[0] ?? null) === 'from') {
            $from = $this->cloneDimension($arguments[1] ?? throw new \InvalidArgumentException($usage));
            $arguments = array_slice($arguments, 2);
        }
        $to = $sender->dimension;
        $words = array_slice($arguments, 6);
        if (($words[0] ?? null) === 'to') {
            $to = $this->cloneDimension($words[1] ?? throw new \InvalidArgumentException($usage));
            $words = array_slice($words, 2);
        }
        if (count($arguments) < 9 || count($words) < 3) {
            throw new \InvalidArgumentException($usage);
        }
        $rest = array_slice($words, 3);
        $strict = ($rest[0] ?? null) === 'strict';
        if ($strict) {
            array_shift($rest);
        }
        $filter = null;
        $mode = 'normal';
        if ($rest !== []) {
            $mask = array_shift($rest);
            if ($mask === 'masked') {
                $filter = fn (int $state): bool => !$this->version->catalog->air($state);
            } elseif ($mask === 'filtered') {
                $filter = $this->blockPredicate(array_shift($rest) ?? throw new \InvalidArgumentException($usage));
            } elseif ($mask !== 'replace') {
                throw new \InvalidArgumentException($usage);
            }
            $mode = array_shift($rest) ?? 'normal';
        }
        if ($rest !== [] || !in_array($mode, ['normal', 'force', 'move'], true)) {
            throw new \InvalidArgumentException($usage);
        }
        $begin = $this->loadedBlockPosition(array_slice($arguments, 0, 3), $sender, $from);
        $end = $this->loadedBlockPosition(array_slice($arguments, 3, 3), $sender, $from);
        $destination = $this->loadedBlockPosition(array_slice($words, 0, 3), $sender, $to);
        $min = array_map(min(...), $begin, $end);
        $max = array_map(max(...), $begin, $end);
        $offset = array_map(static fn (int $a, int $b): int => $a - $b, $destination, $min);
        $destinationMax = array_map(static fn (int $a, int $b): int => $a + $b, $max, $offset);
        // BoundingBox.intersects: the boxes share at least one block.
        if ($mode === 'normal' && $from === $to && $destination[0] <= $max[0] && $destinationMax[0] >= $min[0]
            && $destination[1] <= $max[1] && $destinationMax[1] >= $min[1] && $destination[2] <= $max[2] && $destinationMax[2] >= $min[2]) {
            throw new CommandFailure(['translate' => 'commands.clone.overlap']);
        }
        $volume = ($max[0] - $min[0] + 1) * ($max[1] - $min[1] + 1) * ($max[2] - $min[2] + 1);
        $limit = (int) $this->gameRule('max_block_modifications');
        if ($volume > $limit) {
            throw new CommandFailure(['translate' => 'commands.clone.toobig', 'with' => [$limit, $volume]]);
        }
        // Level.hasChunksAt: every chunk of both boxes is loaded.
        if (!$this->cloneChunksLoaded($from, $min, $max) || !$this->cloneChunksLoaded($to, $destination, $destinationMax)) {
            throw new CommandFailure(['translate' => 'argument.pos.unloaded']);
        }
        $source = $this->worlds[$from];
        $catalog = $this->version->catalog;
        $blocks = [];
        for ($z = $min[2]; $z <= $max[2]; ++$z) {
            for ($y = $min[1]; $y <= $max[1]; ++$y) {
                for ($x = $min[0]; $x <= $max[0]; ++$x) {
                    $state = $source->get($x, $y, $z);
                    if ($filter === null || $filter($state)) {
                        $blocks[] = [[$x, $y, $z], $state, $source->blockEntityType($state) !== null ? $source->container([$x, $y, $z]) : null];
                    }
                }
            }
        }
        if ($mode === 'move') {
            $this->withDimension($from, function () use ($blocks): void {
                $this->world->transaction(function () use ($blocks): void {
                    foreach ($blocks as [$position, $state, $data]) {
                        if ($data !== null) {
                            $this->world->deleteContainer($position);
                        }
                        [$x, $y, $z] = $position;
                        $this->world->set($x, $y, $z, 0);
                    }
                });
                $this->resendCloneChunks(array_column($blocks, 0));
            });
        }
        // Each block is first replaced by a barrier, so every placement but a barrier's changes it.
        $barrier = $catalog->state('barrier');
        $count = 0;
        $this->withDimension($to, function () use ($blocks, $offset, $barrier, &$count): void {
            $placed = [];
            $this->world->transaction(function () use ($blocks, $offset, $barrier, &$count, &$placed): void {
                foreach ($blocks as [$position, $state, $data]) {
                    $target = array_map(static fn (int $a, int $b): int => $a + $b, $position, $offset);
                    if ($this->world->blockEntityType($this->world->get(...$target)) !== null) {
                        $this->world->deleteContainer($target);
                    }
                    [$x, $y, $z] = $target;
                    $this->world->set($x, $y, $z, $state);
                    if ($data !== null) {
                        $this->world->container($target, ['position' => $target] + $data);
                    }
                    if ($state !== $barrier) {
                        ++$count;
                    }
                    $placed[] = $target;
                }
            });
            $this->resendCloneChunks($placed);
        });
        if ($count === 0) {
            throw new CommandFailure(['translate' => 'commands.clone.failed']);
        }
        $this->sendSuccess($sender, ['translate' => 'commands.clone.success', 'with' => [$count]], true);
    }

    /** DimensionArgument: a level's identifier. */
    private function cloneDimension(string $text): string
    {
        $id = str_contains($text, ':') ? $text : 'minecraft:' . $text;
        $name = str_starts_with($id, 'minecraft:') ? substr($id, 10) : $id;
        if (!isset($this->worlds[$name])) {
            throw new CommandFailure(['translate' => 'argument.dimension.invalid', 'with' => [['text' => $id]]]);
        }
        return $name;
    }

    private function cloneChunksLoaded(string $dimension, array $min, array $max): bool
    {
        for ($chunkX = $min[0] >> 4; $chunkX <= $max[0] >> 4; ++$chunkX) {
            for ($chunkZ = $min[2] >> 4; $chunkZ <= $max[2] >> 4; ++$chunkZ) {
                if (array_filter($this->players(), fn (Client $player): bool => $player->dimension === $dimension && $this->chunkInView($player, $chunkX, $chunkZ)) === []) {
                    return false;
                }
            }
        }
        return true;
    }

    /** Players in the current dimension get the changed chunks again. */
    private function resendCloneChunks(array $positions): void
    {
        $chunks = [];
        foreach ($positions as [$x, , $z]) {
            $chunks[($x >> 4) . ':' . ($z >> 4)] = [$x >> 4, $z >> 4];
        }
        foreach ($this->localPlayers() as $player) {
            foreach ($chunks as $key => $chunk) {
                if (isset($player->loadedChunks[$key])) {
                    $player->queuedChunks[$key] = $chunk;
                }
            }
        }
    }
}
