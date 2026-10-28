<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait FillCommand
{
    private function executeFill(Client $sender, array $arguments): void
    {
        if (count($arguments) !== 7) {
            throw new \InvalidArgumentException('Usage: /fill x1 y1 z1 x2 y2 z2 block');
        }
        $start = array_map('intval', array_map('floor', $this->coordinates(array_slice($arguments, 0, 3), $sender)));
        $end = array_map('intval', array_map('floor', $this->coordinates(array_slice($arguments, 3, 3), $sender)));
        $volume = (abs($start[0] - $end[0]) + 1) * (abs($start[1] - $end[1]) + 1) * (abs($start[2] - $end[2]) + 1);
        $limit = (int) $this->gameRule('max_block_modifications');
        if ($volume > $limit) {
            throw new CommandFailure(['translate' => 'commands.fill.toobig', 'with' => [$limit, $volume]]);
        }
        $state = $this->blockArgument($arguments[6]);
        $chunks = [];
        $changed = 0;
        $this->world->transaction(function () use ($start, $end, $state, &$chunks, &$changed) {
            for ($x = min($start[0], $end[0]); $x <= max($start[0], $end[0]); ++$x) {
                for ($z = min($start[2], $end[2]); $z <= max($start[2], $end[2]); ++$z) {
                    for ($y = min($start[1], $end[1]); $y <= max($start[1], $end[1]); ++$y) {
                        // Only positions whose state changes are counted, as in vanilla.
                        if ($this->world->get($x, $y, $z) !== $state) {
                            $this->world->set($x, $y, $z, $state);
                            ++$changed;
                        }
                    }
                    $chunks[($x >> 4) . ':' . ($z >> 4)] = [$x >> 4, $z >> 4];
                }
            }
        });
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
}
