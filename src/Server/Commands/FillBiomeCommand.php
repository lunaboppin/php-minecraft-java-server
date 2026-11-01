<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Server\Client;
use PhpMinecraftJavaServer\World\BiomeContainer;

trait FillBiomeCommand
{
    /**
     * FillBiomeCommand: the quarts whose corner lies in the box (corners rounded down to quarts)
     * take the biome, optionally only where the current biome passes a filter. Every section of
     * each chunk is rebuilt as LevelChunkSection.fillBiomesFromNoise does, and players are sent
     * the chunks that changed.
     */
    private function executeFillBiome(Client $sender, array $arguments): void
    {
        $from = $this->loadedBlockPosition(array_slice($arguments, 0, 3), $sender);
        $to = $this->loadedBlockPosition(array_slice($arguments, 3, 3), $sender);
        $biome = preg_replace('/^minecraft:/', '', $arguments[6] ?? '');
        $filter = static fn (string $current): bool => true;
        if (($arguments[7] ?? null) === 'replace') {
            $filter = $this->biomeFilter($arguments[8] ?? '');
        }
        // FillBiomeCommand.quantize: each corner rounds down to its quart's first block.
        $min = array_map(static fn (int $a, int $b): int => (min($a, $b) >> 2) << 2, $from, $to);
        $max = array_map(static fn (int $a, int $b): int => (max($a, $b) >> 2) << 2, $from, $to);
        $volume = ($max[0] - $min[0] + 1) * ($max[1] - $min[1] + 1) * ($max[2] - $min[2] + 1);
        $limit = (int) $this->gameRule('max_block_modifications');
        if ($volume > $limit) {
            throw new CommandFailure(['translate' => 'commands.fillbiome.toobig', 'with' => [$limit, $volume]]);
        }
        $dimension = $this->sourceDimension($sender);
        $chunks = [];
        for ($chunkZ = $min[2] >> 4; $chunkZ <= $max[2] >> 4; ++$chunkZ) {
            for ($chunkX = $min[0] >> 4; $chunkX <= $max[0] >> 4; ++$chunkX) {
                if (!$this->commandChunkLoaded($dimension, $chunkX, $chunkZ)) {
                    throw new CommandFailure(['translate' => 'argument.pos.unloaded']);
                }
                $chunks[] = [$chunkX, $chunkZ];
            }
        }
        $world = $this->worlds[$dimension];
        $first = substr($this->version->registryEntries('minecraft:worldgen/biome')[0], 10);
        $count = 0;
        $changed = [];
        foreach ($chunks as [$chunkX, $chunkZ]) {
            $before = $count;
            $containers = [];
            foreach ($world->biomeContainers($chunkX, $chunkZ) as $section => $old) {
                $container = BiomeContainer::recreate($old, $first);
                for ($x = 0; $x < 4; ++$x) {
                    for ($y = 0; $y < 4; ++$y) {
                        for ($z = 0; $z < 4; ++$z) {
                            $index = ($y << 4) | ($z << 2) | $x;
                            $current = BiomeContainer::get($old, $index);
                            $block = [$chunkX * 16 + $x * 4, $world->minY + $section * 16 + $y * 4, $chunkZ * 16 + $z * 4];
                            $inside = $block[0] >= $min[0] && $block[0] <= $max[0] && $block[1] >= $min[1] && $block[1] <= $max[1] && $block[2] >= $min[2] && $block[2] <= $max[2];
                            if ($inside && $filter($current)) {
                                $count += $current === $biome ? 0 : 1;
                                $current = $biome;
                            }
                            BiomeContainer::set($container, $index, $current);
                        }
                    }
                }
                $containers[] = $container;
            }
            if ($count !== $before) {
                $world->setBiomeContainers($chunkX, $chunkZ, $containers);
                $changed[] = [$chunkX, $chunkZ];
            }
        }
        if ($count === 0) {
            throw new CommandFailure(['translate' => 'commands.fillbiome.no_changes']);
        }
        $this->resendBiomes($dimension, $changed);
        $this->sendSuccess($sender, ['translate' => 'commands.fillbiome.success.count', 'with' => [$count, ...$min, ...$max]], true);
    }

    /** ResourceOrTagArgument for biomes: one biome, or any biome in a tag. */
    private function biomeFilter(string $text): \Closure
    {
        if (str_starts_with($text, '#')) {
            $tag = substr($text, 1);
            $members = array_fill_keys($this->version->registryTags('minecraft:worldgen/biome')[str_contains($tag, ':') ? $tag : 'minecraft:' . $tag] ?? [], true);
            return static fn (string $current): bool => isset($members[$current]);
        }
        $only = preg_replace('/^minecraft:/', '', $text);
        return static fn (string $current): bool => $current === $only;
    }

    /** ChunkMap.resendBiomesForChunks: each player gets one chunks_biomes packet for the chunks they see. */
    private function resendBiomes(string $dimension, array $chunks): void
    {
        $world = $this->worlds[$dimension];
        $id = fn (string $biome): int => $this->version->registryId('worldgen/biome', $biome);
        $bits = (int) ceil(log($this->version->registryCount('worldgen/biome'), 2));
        $data = [];
        foreach ($chunks as [$chunkX, $chunkZ]) {
            $buffer = '';
            foreach ($world->biomeContainers($chunkX, $chunkZ) as $container) {
                $buffer .= BiomeContainer::encode($container, $id, $bits);
            }
            $data[$chunkX . ':' . $chunkZ] = pack('J', ($chunkX & 0xFFFFFFFF) | (($chunkZ & 0xFFFFFFFF) << 32)) . Binary::writeVarInt(strlen($buffer)) . $buffer;
        }
        foreach ($this->players() as $player) {
            if ($player->dimension !== $dimension) {
                continue;
            }
            $seen = array_filter($chunks, fn (array $chunk): bool => $this->chunkInView($player, $chunk[0], $chunk[1]));
            if ($seen !== []) {
                $player->send($this->version, 'chunks_biomes', Binary::writeVarInt(count($seen)) . implode('', array_map(static fn (array $chunk): string => $data[$chunk[0] . ':' . $chunk[1]], $seen)));
            }
        }
    }
}
