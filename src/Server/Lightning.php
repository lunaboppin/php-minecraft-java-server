<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;

/**
 * ServerLevel.tickThunder and LightningBolt: during a thunderstorm a chunk is struck one tick in
 * 100000 at its highest block or a living entity under the open sky there, where it rains. A
 * bolt lives a few ticks with one to three flashes, setting fire and hitting what stands close.
 */
trait Lightning
{
    /** @var array<int, array> lightning bolts by entity ID */
    private array $lightningBolts = [];

    /** ServerLevel.tickThunder for one ticking chunk, before its random ticks. */
    private function tickThunder(int $cx, int $cz): void
    {
        if ($this->world->dimension !== 'overworld' || !$this->raining() || $this->thunderStrength() <= 0.9 || random_int(0, 99999) !== 0) {
            return;
        }
        // Level.getBlockRandomPos within the chunk, then findLightningTargetAround.
        $this->randomTickValue = ($this->randomTickValue * 3 + 1013904223) & 0xffffffff;
        $k = ($this->randomTickValue >= 0x80000000 ? $this->randomTickValue - 0x100000000 : $this->randomTickValue) >> 2;
        [$x, $z] = [$cx * 16 + ($k & 15), $cz * 16 + (($k >> 8) & 15)];
        $y = $this->motionBlockingHeight($x, $z);
        $targets = [];
        foreach ($this->localPlayers() as $player) {
            if (!$player->dead && abs($player->x - ($x + 0.5)) <= 3.5 + 0.3 && abs($player->z - ($z + 0.5)) <= 3.5 + 0.3 && $player->y >= $y - 3
                && $this->rainingAt((int) floor($player->x), (int) floor($player->y), (int) floor($player->z))) {
                $targets[] = [(int) floor($player->x), (int) floor($player->y), (int) floor($player->z)];
            }
        }
        foreach ($this->animals as $mob) {
            if (!isset($mob['deadAt']) && abs($mob['x'] - ($x + 0.5)) <= 3.5 && abs($mob['z'] - ($z + 0.5)) <= 3.5 && $mob['y'] >= $y - 3
                && $this->rainingAt((int) floor($mob['x']), (int) floor($mob['y']), (int) floor($mob['z']))) {
                $targets[] = [(int) floor($mob['x']), (int) floor($mob['y']), (int) floor($mob['z'])];
            }
        }
        if ($targets !== []) {
            [$x, $y, $z] = $targets[random_int(0, count($targets) - 1)];
        } elseif ($y === $this->world->minY - 1) {
            $y += 2;
        }
        if ($this->rainingAt($x, $y, $z)) {
            $this->spawnLightning($x + 0.5, (float) $y, $z + 0.5);
        }
    }

    /** The MOTION_BLOCKING heightmap: the first Y above every motion-blocking block or fluid. */
    private function motionBlockingHeight(int $x, int $z): int
    {
        $blocks = $this->fireBlocks();
        for ($y = $this->world->minY + $this->world->height - 1; $y >= $this->world->minY; --$y) {
            $state = $this->world->get($x, $y, $z);
            if ($blocks->flag($state, \PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks::MOTION_HEIGHTMAP) || $blocks->hasFluid($state)) {
                return $y + 1;
            }
        }
        return $this->world->minY - 1 + 1;
    }

    /** A LightningBolt entity: life 2 and one to three flashes. */
    private function spawnLightning(float $x, float $y, float $z, bool $visualOnly = false): int
    {
        $id = $this->nextEntity++;
        $this->lightningBolts[$id] = ['id' => $id, 'uuid' => random_bytes(16), 'x' => $x, 'y' => $y, 'z' => $z, 'life' => 2,
            'flashes' => random_int(1, 3), 'visualOnly' => $visualOnly, 'hit' => []];
        $payload = Binary::writeVarInt($id) . $this->lightningBolts[$id]['uuid'] . Binary::writeVarInt($this->version->blocks['entity_types']['lightning_bolt'])
            . pack('EEE', $x, $y, $z) . "\0" . "\0\0\0" . Binary::writeVarInt(0);
        foreach ($this->localPlayers() as $player) {
            if ($this->tracksPosition($player, $x, $z, 'lightning_bolt')) {
                $player->send($this->version, 'add_entity', $payload);
                $player->entities[$id] = true;
            }
        }
        return $id;
    }

    /** LightningBolt.tick */
    private function tickLightning(): void
    {
        foreach (array_keys($this->lightningBolts) as $id) {
            $bolt = &$this->lightningBolts[$id];
            if ($bolt['life'] === 2) {
                if ($this->config->difficulty >= 2) {
                    $this->lightningFire($bolt, 4);
                }
            }
            --$bolt['life'];
            if ($bolt['life'] < 0) {
                if ($bolt['flashes'] === 0) {
                    unset($bolt, $this->lightningBolts[$id]);
                    foreach ($this->localPlayers() as $player) {
                        $this->forgetEntity($player, $id);
                    }
                    continue;
                }
                if ($bolt['life'] < -random_int(0, 9)) {
                    --$bolt['flashes'];
                    $bolt['life'] = 1;
                    $this->lightningFire($bolt, 0);
                }
            }
            if ($bolt['life'] >= 0 && !$bolt['visualOnly']) {
                $this->lightningHits($bolt);
            }
            unset($bolt);
        }
    }

    /** LightningBolt.spawnFire: fire at the bolt and at nearby spots where it can burn. */
    private function lightningFire(array $bolt, int $extra): void
    {
        if ($bolt['visualOnly']) {
            return;
        }
        [$x, $y, $z] = [(int) floor($bolt['x']), (int) floor($bolt['y']), (int) floor($bolt['z'])];
        if (!$this->canSpreadFireAround($x, $y, $z)) {
            return;
        }
        $place = function (int $a, int $b, int $c): void {
            if ($this->version->catalog->air($this->world->get($a, $b, $c)) && $this->fireSurvives($a, $b, $c)) {
                $this->setBlock($a, $b, $c, $this->fireWithAge($a, $b, $c, 0));
            }
        };
        $place($x, $y, $z);
        for ($i = 0; $i < $extra; ++$i) {
            $place($x + random_int(0, 2) - 1, $y + random_int(0, 2) - 1, $z + random_int(0, 2) - 1);
        }
    }

    /** Entity.thunderHit for everything within 3 blocks (6 above): fire and 5 lightning damage. */
    private function lightningHits(array &$bolt): void
    {
        $inside = static fn (float $x, float $y, float $z, float $half, float $height): bool => $x + $half > $bolt['x'] - 3.0 && $x - $half < $bolt['x'] + 3.0
            && $z + $half > $bolt['z'] - 3.0 && $z - $half < $bolt['z'] + 3.0 && $y + $height > $bolt['y'] - 3.0 && $y < $bolt['y'] + 9.0;
        foreach ($this->localPlayers() as $player) {
            if ($player->dead || !$inside($player->x, $player->y, $player->z, 0.3, 1.8)) {
                continue;
            }
            // A player's fire ticks rest at -1, so a hit sets them alight for 8 seconds.
            $player->fireTicks = $player->fireTicks <= 0 ? 160 : $player->fireTicks + 1;
            $this->damage($player, 5.0, 'minecraft:lightning_bolt');
        }
        foreach (array_keys($this->animals) as $id) {
            $mob = $this->animals[$id] ?? null;
            if ($mob === null || isset($mob['deadAt'])) {
                continue;
            }
            $info = $this->version->catalog->mobs['entities'][$mob['kind']];
            if ($inside($mob['x'], $mob['y'], $mob['z'], $info['width'] / 2.0, $info['height'])) {
                $this->hurtMob($id, 5.0, 'minecraft:lightning_bolt');
            }
        }
        foreach (array_keys($this->itemEntities) as $id) {
            $item = $this->itemEntities[$id] ?? null;
            if ($item !== null && $inside($item['x'], $item['y'], $item['z'], 0.125, 0.25)) {
                $this->hurtItem($id, 5.0, 'minecraft:lightning_bolt');
            }
        }
    }
}
