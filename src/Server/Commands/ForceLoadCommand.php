<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait ForceLoadCommand
{
    /** ForceLoadCommand: add, remove, list and query the sender's dimension's forced chunks. */
    private function executeForceLoad(Client $sender, array $arguments): void
    {
        $usage = 'Usage: /forceload add|remove <from> [<to>] | remove all | query [<pos>]';
        $action = $arguments[0] ?? throw new \InvalidArgumentException($usage);
        $world = $this->worlds[$sender->dimension];
        $dimension = ['text' => 'minecraft:' . $sender->dimension];
        $forced = $this->forcedChunks($sender->dimension);
        if ($action === 'query' && count($arguments) === 1) {
            if ($forced === []) {
                $this->sendFailure($sender, ['translate' => 'commands.forceload.added.none', 'with' => [$dimension]]);
                return;
            }
            // LongSet sorted by ChunkPos.pack: Z in the high half, X in the low.
            $packed = array_map(static fn (array $chunk): int => ($chunk[0] & 0xffffffff) | ($chunk[1] << 32), $forced);
            array_multisort($packed, $forced);
            $list = implode(', ', array_map(self::chunkText(...), $forced));
            $this->sendSuccess($sender, count($forced) === 1 ? ['translate' => 'commands.forceload.list.single', 'with' => [$dimension, ['text' => $list]]]
                : ['translate' => 'commands.forceload.list.multiple', 'with' => [count($forced), $dimension, ['text' => $list]]], false);
            return;
        }
        if ($action === 'query' && count($arguments) === 3) {
            [$x, $z] = $this->columnPosition($arguments[1], $arguments[2], $sender);
            $chunk = ['text' => self::chunkText([$x >> 4, $z >> 4])];
            if (!isset($forced[($x >> 4) . ':' . ($z >> 4)])) {
                throw new CommandFailure(['translate' => 'commands.forceload.query.failure', 'with' => [$chunk, $dimension]]);
            }
            $this->sendSuccess($sender, ['translate' => 'commands.forceload.query.success', 'with' => [$chunk, $dimension]], false);
            return;
        }
        if ($action === 'remove' && ($arguments[1] ?? null) === 'all' && count($arguments) === 2) {
            $world->setting('forcedChunks', []);
            $this->sendSuccess($sender, ['translate' => 'commands.forceload.removed.all', 'with' => [$dimension]], true);
            return;
        }
        if (!in_array($action, ['add', 'remove'], true) || !in_array(count($arguments), [3, 5], true)) {
            throw new \InvalidArgumentException($usage);
        }
        $from = $this->columnPosition($arguments[1], $arguments[2], $sender);
        $to = count($arguments) === 5 ? $this->columnPosition($arguments[3], $arguments[4], $sender) : $from;
        $min = array_map(min(...), $from, $to);
        $max = array_map(max(...), $from, $to);
        if ($min[0] < -30000000 || $min[1] < -30000000 || $max[0] >= 30000000 || $max[1] >= 30000000) {
            throw new CommandFailure(['translate' => 'argument.pos.outofworld']);
        }
        [$minX, $minZ, $maxX, $maxZ] = [$min[0] >> 4, $min[1] >> 4, $max[0] >> 4, $max[1] >> 4];
        $count = ($maxX - $minX + 1) * ($maxZ - $minZ + 1);
        if ($count > 256) {
            throw new CommandFailure(['translate' => 'commands.forceload.toobig', 'with' => [256, $count]]);
        }
        // CommandResponseTracker: the chunks whose forced state changed.
        $changed = [];
        for ($x = $minX; $x <= $maxX; ++$x) {
            for ($z = $minZ; $z <= $maxZ; ++$z) {
                $key = $x . ':' . $z;
                if ($action === 'add' && !isset($forced[$key])) {
                    $forced[$key] = [$x, $z];
                    $changed[] = [$x, $z];
                } elseif ($action === 'remove' && isset($forced[$key])) {
                    unset($forced[$key]);
                    $changed[] = [$x, $z];
                }
            }
        }
        $kind = $action === 'add' ? 'added' : 'removed';
        if ($changed === []) {
            throw new CommandFailure(['translate' => 'commands.forceload.' . $kind . '.failure']);
        }
        $world->setting('forcedChunks', array_values($forced));
        $this->sendSuccess($sender, count($changed) === 1 ? ['translate' => 'commands.forceload.' . $kind . '.single', 'with' => [['text' => self::chunkText($changed[0])], $dimension]]
            : ['translate' => 'commands.forceload.' . $kind . '.multiple', 'with' => [count($changed), $dimension, ['text' => self::chunkText([$minX, $minZ])], ['text' => self::chunkText([$maxX, $maxZ])]]], true);
    }

    /** @return array<string, array{int, int}> the dimension's forced chunks by "x:z" */
    private function forcedChunks(string $dimension): array
    {
        $chunks = [];
        foreach ($this->worlds[$dimension]->setting('forcedChunks') ?? [] as [$x, $z]) {
            $chunks[$x . ':' . $z] = [$x, $z];
        }
        return $chunks;
    }

    /** ChunkPos.toString. */
    private static function chunkText(array $chunk): string
    {
        return '[' . $chunk[0] . ', ' . $chunk[1] . ']';
    }

    /** ColumnPosArgument: block X and Z, either relative to the sender. */
    private function columnPosition(string $x, string $z, Client $sender): array
    {
        return [(int) floor($this->coordinate($x, $sender->x)), (int) floor($this->coordinate($z, $sender->z))];
    }
}
