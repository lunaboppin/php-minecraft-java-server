<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Packets;

/**
 * SpongeBlock and WetSpongeBlock: a sponge placed or updated takes up the water within six
 * steps of it, breadth first and at most 64 blocks of it, turning wet; a wet sponge set in the
 * Nether dries at once.
 */
trait Sponges
{
    private bool $spongeAbsorbing = false;

    private function spongeSound(int $x, int $y, int $z, string $sound): void
    {
        $payload = Packets::sound($this->version->blocks['sounds'][$sound], 4, $x + 0.5, $y + 0.5, $z + 0.5, 1.0, 1.0, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $x - 0.5) ** 2 + ($player->y - $y - 0.5) ** 2 + ($player->z - $z - 0.5) ** 2 < 256.0) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    /** SpongeBlock.onPlace and neighborChanged for the sponges at and beside a changed block; WetSpongeBlock.onPlace. */
    private function spongesChanged(int $x, int $y, int $z): void
    {
        // The water a sponge takes up changes its neighbours, but it searches only once.
        if ($this->spongeAbsorbing) {
            return;
        }
        $catalog = $this->version->catalog;
        if ($this->world->dimension === 'the_nether' && $catalog->blockName($this->world->get($x, $y, $z)) === 'wet_sponge') {
            // WetSpongeBlock.onPlace: ultrawarm dimensions dry it with a sound and smoke.
            $this->setBlock($x, $y, $z, $catalog->state('sponge'));
            $this->levelEvent(2009, [$x, $y, $z], 0);
            $this->spongeSound($x, $y, $z, 'block.wet_sponge.dries');
            return;
        }
        foreach ([[0, 0, 0], [0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]] as [$dx, $dy, $dz]) {
            [$a, $b, $c] = [$x + $dx, $y + $dy, $z + $dz];
            if ($catalog->blockName($this->world->get($a, $b, $c)) !== 'sponge') {
                continue;
            }
            $this->spongeAbsorbing = true;
            try {
                // A sponge just placed hears its first taken water at once and searches again
                // within the search; one reacting to a neighbour hears it only once it is wet.
                $this->tryAbsorbWater($a, $b, $c, [$dx, $dy, $dz] === [0, 0, 0]);
            } finally {
                $this->spongeAbsorbing = false;
            }
        }
    }

    /** SpongeBlock.tryAbsorbWater: a sponge that took up water turns wet. */
    private function tryAbsorbWater(int $x, int $y, int $z, bool $nested): void
    {
        if ($this->spongeAbsorb($x, $y, $z, $nested)) {
            $this->setBlock($x, $y, $z, $this->version->catalog->state('wet_sponge'));
            $this->spongeSound($x, $y, $z, 'block.sponge.absorb');
        }
    }

    /**
     * SpongeBlock.removeWaterBreadthFirstSearch with BlockPos.breadthFirstTraversal(start, 6, 65):
     * true when it took up any water.
     */
    private function spongeAbsorb(int $x, int $y, int $z, bool $nested): bool
    {
        $catalog = $this->version->catalog;
        $blocks = $this->fireBlocks();
        $queue = new \SplQueue();
        $queue->enqueue([$x, $y, $z, 0]);
        $visited = [];
        $count = 0;
        while (!$queue->isEmpty()) {
            [$a, $b, $c, $depth] = $queue->dequeue();
            $key = $a . ':' . $b . ':' . $c;
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;
            if ([$a, $b, $c] !== [$x, $y, $z]) {
                $state = $this->world->get($a, $b, $c);
                if (!$blocks->isWater($state)) {
                    continue;
                }
                $name = $catalog->blockName($state);
                $p = $catalog->properties($state);
                if (($p['waterlogged'] ?? null) === 'true') {
                    // SimpleWaterloggedBlock.pickupBlock.
                    $this->setBlock($a, $b, $c, $catalog->state($name, ['waterlogged' => 'false'] + $p));
                } elseif ($name === 'water' || $name === 'bubble_column') {
                    // LiquidBlock and BubbleColumnBlock: picked up as a source, or cleared when flowing.
                    $this->setBlock($a, $b, $c, $catalog->state('air'));
                } elseif (in_array($name, ['kelp', 'kelp_plant', 'seagrass', 'tall_seagrass'], true)) {
                    $this->dropBlockResources($a, $b, $c, $state);
                    $this->setBlock($a, $b, $c, $catalog->state('air'));
                } else {
                    continue;
                }
            }
            if ($nested && $count === 1 && [$a, $b, $c] !== [$x, $y, $z]) {
                // The first water taken updates the sponge beside it, which searches again.
                $nested = false;
                $this->tryAbsorbWater($x, $y, $z, false);
            }
            if (++$count >= 65) {
                return true;
            }
            if ($depth < 6) {
                foreach ([[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]] as [$dx, $dy, $dz]) {
                    $queue->enqueue([$a + $dx, $b + $dy, $c + $dz, $depth + 1]);
                }
            }
        }
        return $count > 1;
    }
}
