<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait FillCommand
{
    /**
     * FillCommand: replace (optionally only blocks matching a filter), outline, hollow, destroy,
     * keep or strict. Only positions that change are counted.
     */
    private function executeFill(Client $sender, array $arguments): void
    {
        if (count($arguments) < 7) {
            throw new \InvalidArgumentException('Usage: /fill x1 y1 z1 x2 y2 z2 block [mode]');
        }
        $start = $this->loadedBlockPosition(array_slice($arguments, 0, 3), $sender);
        $end = $this->loadedBlockPosition(array_slice($arguments, 3, 3), $sender);
        $volume = (abs($start[0] - $end[0]) + 1) * (abs($start[1] - $end[1]) + 1) * (abs($start[2] - $end[2]) + 1);
        $limit = (int) $this->gameRule('max_block_modifications');
        if ($volume > $limit) {
            throw new CommandFailure(['translate' => 'commands.fill.toobig', 'with' => [$limit, $volume]]);
        }
        $state = $this->blockArgument($arguments[6]);
        $rest = array_slice($arguments, 7);
        $filter = null;
        $mode = array_shift($rest) ?? 'replace';
        if ($mode === 'replace' && $rest !== []) {
            $filter = $this->blockPredicate(array_shift($rest));
            $mode = array_shift($rest) ?? 'replace';
        } elseif ($mode === 'keep') {
            $filter = fn (int $current): bool => $this->version->catalog->air($current);
            $mode = 'replace';
        }
        if ($rest !== [] || !in_array($mode, ['replace', 'outline', 'hollow', 'destroy', 'strict'], true)) {
            throw new \InvalidArgumentException('Usage: /fill x1 y1 z1 x2 y2 z2 block [mode]');
        }
        $min = array_map(min(...), $start, $end);
        $max = array_map(max(...), $start, $end);
        $chunks = [];
        $changed = 0;
        $destroyed = [];
        $this->world->transaction(function () use ($min, $max, $state, $mode, $filter, &$chunks, &$changed, &$destroyed) {
            // BlockPos.betweenClosed: X varies fastest, then Y, then Z.
            for ($z = $min[2]; $z <= $max[2]; ++$z) {
                for ($y = $min[1]; $y <= $max[1]; ++$y) {
                    for ($x = $min[0]; $x <= $max[0]; ++$x) {
                        $current = $this->world->get($x, $y, $z);
                        if ($filter !== null && !$filter($current)) {
                            continue;
                        }
                        $affected = false;
                        if ($mode === 'destroy' && !$this->version->catalog->air($current)) {
                            $destroyed[] = [[$x, $y, $z], $current];
                            $this->world->set($x, $y, $z, 0);
                            $current = 0;
                            $affected = true;
                        }
                        $edge = $x === $min[0] || $x === $max[0] || $y === $min[1] || $y === $max[1] || $z === $min[2] || $z === $max[2];
                        $target = match ($mode) {
                            'outline' => $edge ? $state : null,
                            'hollow' => $edge ? $state : 0,
                            default => $state,
                        };
                        if ($target !== null && $current !== $target) {
                            $this->world->set($x, $y, $z, $target);
                            $affected = true;
                        }
                        if ($affected) {
                            ++$changed;
                        }
                        $chunks[($x >> 4) . ':' . ($z >> 4)] = [$x >> 4, $z >> 4];
                    }
                }
            }
        });
        foreach ($destroyed as [$position, $previous]) {
            $this->levelEvent(2001, $position, $previous);
        }
        foreach ($this->localPlayers() as $player) {
            foreach ($chunks as $key => $chunk) {
                if (isset($player->loadedChunks[$key])) {
                    $player->queuedChunks[$key] = $chunk;
                }
            }
        }
        if ($changed === 0) {
            throw new CommandFailure(['translate' => 'commands.fill.failed']);
        }
        $this->sendSuccess($sender, ['translate' => 'commands.fill.success', 'with' => [$changed]], true);
    }

    /**
     * BlockPredicateArgument: a block or #tag, with the listed properties matching. NBT is not
     * compared.
     *
     * @return \Closure(int): bool
     */
    private function blockPredicate(string $text): \Closure
    {
        if (preg_match('/\A(#?)(?:minecraft:)?([a-z0-9_\/.\-:]+)(?:\[([^\]]*)\])?(\{.*\})?\z/D', $text, $match) !== 1) {
            throw new \InvalidArgumentException('Invalid block predicate');
        }
        $names = $match[1] === '#' ? ($this->version->blockTags()['minecraft:' . $match[2]] ?? throw new CommandFailure(['translate' => 'arguments.block.tag.unknown', 'with' => [['text' => 'minecraft:' . $match[2]]]])) : [$match[2]];
        $names = array_fill_keys($names, true);
        $properties = [];
        foreach (array_filter(explode(',', $match[3] ?? '')) as $pair) {
            [$key, $value] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];
            $properties[$key] = $value;
        }
        return function (int $state) use ($names, $properties): bool {
            if (!isset($names[$this->version->catalog->blockName($state)])) {
                return false;
            }
            $actual = $this->version->catalog->properties($state);
            foreach ($properties as $key => $value) {
                if (!isset($actual[$key]) || (string) $actual[$key] !== $value) {
                    return false;
                }
            }
            return true;
        };
    }
}
