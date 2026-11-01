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

    /** @var array<string, array<int, int>> MOTION_BLOCKING heights by chunk and column */
    private array $motionHeights = [];

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
        $rod = $this->findLightningRod($x, $y, $z);
        if ($rod !== null) {
            [$x, $y, $z] = $rod;
            if ($this->rainingAt($x, $y, $z)) {
                $this->spawnLightning($x + 0.5, (float) $y, $z + 0.5);
            }
            return;
        }
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

    /** Directions a lightning rod can face, as offsets. */
    private const ROD_FACES = ['down' => [0, -1, 0], 'up' => [0, 1, 0], 'north' => [0, 0, -1], 'south' => [0, 0, 1], 'west' => [-1, 0, 0], 'east' => [1, 0, 0]];

    private function lightningRod(string $name): bool
    {
        return (bool) preg_match('/^(waxed_)?((exposed|weathered|oxidized)_)?lightning_rod$/', $name);
    }

    /**
     * ServerLevel.findLightningRod: the closest lightning rod within 128 blocks that tops its
     * column (WORLD_SURFACE), struck from the block above it.
     *
     * @return array{int, int, int}|null
     */
    private function findLightningRod(int $x, int $y, int $z): ?array
    {
        static $states = null;
        $catalog = $this->version->catalog;
        if ($states === null) {
            $states = [];
            for ($id = 0; $id < $this->version->blocks['state_count']; ++$id) {
                if ($this->lightningRod($catalog->blockName($id))) {
                    $states[] = $id;
                }
            }
        }
        $best = null;
        $bestDistance = 128 ** 2 + 1;
        foreach ($this->world->editedStates($states, $x - 128, $x + 128, $z - 128, $z + 128) as [$rx, $ry, $rz, $state]) {
            $distance = ($rx - $x) ** 2 + ($ry - $y) ** 2 + ($rz - $z) ** 2;
            if ($distance >= $bestDistance || $this->world->get($rx, $ry, $rz) !== $state) {
                continue;
            }
            // Heightmap.Types.WORLD_SURFACE: the first Y above every block that is not air.
            $top = $this->world->minY + $this->world->height - 1;
            while ($top > $ry && $catalog->air($this->world->get($rx, $top, $rz))) {
                --$top;
            }
            if ($top === $ry) {
                [$best, $bestDistance] = [[$rx, $ry + 1, $rz], $distance];
            }
        }
        return $best;
    }

    /** LightningRodBlock.onLightningStrike: powered for 8 ticks, with sparks along its axis. */
    private function strikeLightningRod(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        if (!$this->lightningRod($catalog->blockName($state))) {
            return;
        }
        $p = $catalog->properties($state);
        $this->setBlock($x, $y, $z, $catalog->state($catalog->blockName($state), ['powered' => 'true'] + $p));
        [$fx, $fy, $fz] = self::ROD_FACES[$p['facing']];
        $this->changedSimulation($x - $fx, $y - $fy, $z - $fz);
        $this->scheduleBlock('lightning_rod', $x, $y, $z, 8);
        $this->levelEvent(3002, [$x, $y, $z], $fx !== 0 ? 0 : ($fy !== 0 ? 1 : 2));
    }

    private function unpowerLightningRod(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        if (!$this->lightningRod($catalog->blockName($state))) {
            return;
        }
        $p = $catalog->properties($state);
        $this->setBlock($x, $y, $z, $catalog->state($catalog->blockName($state), ['powered' => 'false'] + $p));
        [$fx, $fy, $fz] = self::ROD_FACES[$p['facing']];
        $this->changedSimulation($x - $fx, $y - $fy, $z - $fz);
    }

    /**
     * LightningBolt.clearCopperOnLightningStrike: struck copper loses its oxidation, then three to
     * five random walks through nearby copper each take a stage off the copper they step on.
     */
    private function clearCopperOnStrike(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $name = $catalog->blockName($this->world->get($x, $y, $z));
        $age = self::copperAge($name);
        if ($age === null && !(str_starts_with($name, 'waxed_') && self::copperAge(preg_replace('/^waxed_/', '', $name)) !== null)) {
            return;
        }
        if ($age !== null) {
            $this->setCopperStage($x, $y, $z, $age[0], 0);
        }
        for ($strike = 0, $strikes = random_int(0, 2) + 3; $strike < $strikes; ++$strike) {
            [$wx, $wy, $wz] = [$x, $y, $z];
            for ($step = 0, $steps = random_int(0, 7) + 1; $step < $steps; ++$step) {
                // BlockPos.randomInCube: ten random tries within a block of the walk.
                $found = false;
                for ($try = 0; $try < 10; ++$try) {
                    [$cx, $cy, $cz] = [$wx + random_int(-1, 1), $wy + random_int(-1, 1), $wz + random_int(-1, 1)];
                    $other = self::copperAge($catalog->blockName($this->world->get($cx, $cy, $cz)));
                    if ($other === null) {
                        continue;
                    }
                    if ($other[1] > 0) {
                        $this->setCopperStage($cx, $cy, $cz, $other[0], $other[1] - 1);
                    }
                    $this->levelEvent(3002, [$cx, $cy, $cz], -1);
                    [$wx, $wy, $wz, $found] = [$cx, $cy, $cz, true];
                    break;
                }
                if (!$found) {
                    break;
                }
            }
        }
    }

    /** A weathering copper block at another stage, a copper door's other half following. */
    private function setCopperStage(int $x, int $y, int $z, string $base, int $stage): void
    {
        $catalog = $this->version->catalog;
        $stem = $base === 'copper_block' && $stage > 0 ? 'copper' : $base;
        $next = ['', 'exposed_', 'weathered_', 'oxidized_'][$stage] . $stem;
        $properties = $catalog->properties($this->world->get($x, $y, $z));
        $this->setBlock($x, $y, $z, $catalog->state($next, $properties));
        if (str_ends_with($base, 'copper_door')) {
            $other = $y + (($properties['half'] ?? '') === 'lower' ? 1 : -1);
            if (str_ends_with($catalog->blockName($this->world->get($x, $other, $z)), 'copper_door')) {
                $this->setBlock($x, $other, $z, $catalog->state($next, $catalog->properties($this->world->get($x, $other, $z))));
            }
        }
    }

    /** The MOTION_BLOCKING heightmap: the first Y above every motion-blocking block or fluid. */
    private function motionBlockingHeight(int $x, int $z): int
    {
        // Heights stay known until a block in their column changes (heightChanged).
        $key = ($x >> 4) . ':' . ($z >> 4);
        $column = (($z & 15) << 4) | ($x & 15);
        if (isset($this->motionHeights[$key][$column])) {
            return $this->motionHeights[$key][$column];
        }
        $blocks = $this->fireBlocks();
        $height = $this->world->minY;
        for ($y = $this->world->minY + $this->world->height - 1; $y >= $this->world->minY; --$y) {
            $state = $this->world->get($x, $y, $z);
            if ($blocks->flag($state, \PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks::MOTION_HEIGHTMAP) || $blocks->hasFluid($state)) {
                $height = $y + 1;
                break;
            }
        }
        if (count($this->motionHeights) > 256) {
            array_shift($this->motionHeights);
        }
        return $this->motionHeights[$key][$column] = $height;
    }

    /** A changed block can change its column's MOTION_BLOCKING height. */
    private function heightChanged(int $x, int $z): void
    {
        unset($this->motionHeights[($x >> 4) . ':' . ($z >> 4)][(($z & 15) << 4) | ($x & 15)]);
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
                // LightningBolt.getStrikePosition: the block just below the bolt.
                $strike = [(int) floor($bolt['x']), (int) floor($bolt['y'] - 1.0E-6), (int) floor($bolt['z'])];
                $this->strikeLightningRod(...$strike);
                $this->clearCopperOnStrike(...$strike);
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
