<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * BasePressurePlateBlock: an entity inside a plate's block presses it once the plate counts the
 * entities in its touch box (stone plates only the living, weighted plates by number); a pressed
 * plate looks again after 20 ticks (10 for weighted plates) and powers its neighbours, strongly
 * the block below.
 */
trait PressurePlates
{
    private function isPressurePlate(string $name): bool
    {
        return str_ends_with($name, '_pressure_plate');
    }

    /** getSignalForState: powered as 15, or a weighted plate's power. */
    private function plateSignal(int $state): int
    {
        $p = $this->version->catalog->properties($state);
        return isset($p['power']) ? (int) $p['power'] : (($p['powered'] ?? 'false') === 'true' ? 15 : 0);
    }

    /** BlockSetType's pressure plate click sound. */
    private function plateSound(string $name, bool $on): string
    {
        $type = match (true) {
            str_contains($name, 'weighted') => 'metal',
            $name === 'stone_pressure_plate' || $name === 'polished_blackstone_pressure_plate' => 'stone',
            $name === 'crimson_pressure_plate' || $name === 'warped_pressure_plate' => 'nether_wood',
            $name === 'bamboo_pressure_plate' => 'bamboo_wood',
            $name === 'cherry_pressure_plate' => 'cherry_wood',
            default => 'wooden',
        };
        return 'block.' . $type . '_pressure_plate.click_' . ($on ? 'on' : 'off');
    }

    /**
     * The entities whose boxes meet a box, as [box, living]; spectators never count.
     *
     * @return list<array{0: array, 1: bool}>
     */
    private function entitiesIn(array $box): array
    {
        $meets = static fn (array $o): bool => $o[0] < $box[3] && $o[3] > $box[0] && $o[1] < $box[4] && $o[4] > $box[1] && $o[2] < $box[5] && $o[5] > $box[2];
        $found = [];
        foreach ($this->localPlayers() as $player) {
            $own = [$player->x - 0.3, $player->y, $player->z - 0.3, $player->x + 0.3, $player->y + 1.8, $player->z + 0.3];
            if (!$player->dead && $player->gameMode !== 3 && $meets($own)) {
                $found[] = [$own, true];
            }
        }
        foreach ($this->animals as $mob) {
            if (isset($mob['deadAt'])) {
                continue;
            }
            $info = $this->version->catalog->mobs['entities'][$mob['kind']];
            $half = $info['width'] / 2.0;
            $own = [$mob['x'] - $half, $mob['y'], $mob['z'] - $half, $mob['x'] + $half, $mob['y'] + $info['height'], $mob['z'] + $half];
            if ($meets($own)) {
                $found[] = [$own, true];
            }
        }
        foreach ([[$this->itemEntities, 0.125, 0.25], [$this->experienceOrbs, 0.25, 0.5], [$this->tntEntities, 0.49, 0.98], [$this->fallingBlocks ?? [], 0.49, 0.98]] as [$entities, $half, $height]) {
            foreach ($entities as $entity) {
                $own = [$entity['x'] - $half, $entity['y'], $entity['z'] - $half, $entity['x'] + $half, $entity['y'] + $height, $entity['z'] + $half];
                if ($meets($own)) {
                    $found[] = [$own, false];
                }
            }
        }
        return $found;
    }

    /** PressurePlateBlock and WeightedPressurePlateBlock.getSignalStrength over TOUCH_AABB. */
    private function plateStrength(string $name, int $x, int $y, int $z): int
    {
        $touch = [$x + 0.0625, $y, $z + 0.0625, $x + 0.9375, $y + 0.25, $z + 0.9375];
        $entities = $this->entitiesIn($touch);
        if ($name === 'stone_pressure_plate' || $name === 'polished_blackstone_pressure_plate') {
            $entities = array_filter($entities, static fn (array $e): bool => $e[1]);
        }
        if (!str_contains($name, 'weighted')) {
            return $entities === [] ? 0 : 15;
        }
        $max = $name === 'light_weighted_pressure_plate' ? 15 : 150;
        $count = min(count($entities), $max);
        // Mth.ceil of the float product: 0.2F * 15.0F is exactly 3.
        return $count > 0 ? (int) ceil(Float32::round(Float32::round($count / $max) * 15.0)) : 0;
    }

    /** BasePressurePlateBlock.checkPressed. */
    private function checkPressed(int $x, int $y, int $z, int $state): void
    {
        $catalog = $this->version->catalog;
        $name = $catalog->blockName($state);
        $old = $this->plateSignal($state);
        $signal = $this->plateStrength($name, $x, $y, $z);
        if ($old !== $signal) {
            $p = $catalog->properties($state);
            if (isset($p['power'])) {
                $p['power'] = (string) $signal;
            } else {
                $p['powered'] = $signal > 0 ? 'true' : 'false';
            }
            $this->setBlock($x, $y, $z, $catalog->state($name, $p));
            // updateNeighbours: around the plate and the block below.
            $this->changedSimulation($x, $y - 1, $z);
        }
        if (($signal > 0) !== ($old > 0)) {
            $payload = Packets::sound($this->version->blocks['sounds'][$this->plateSound($name, $signal > 0)], 4, $x + 0.5, $y + 0.5, $z + 0.5, 1.0, 1.0, unpack('J', random_bytes(8))[1]);
            foreach ($this->localPlayers() as $player) {
                if (($player->x - $x - 0.5) ** 2 + ($player->y - $y - 0.5) ** 2 + ($player->z - $z - 0.5) ** 2 < 256.0) {
                    $player->send($this->version, 'sound', $payload);
                }
            }
        }
        if ($signal > 0) {
            $this->scheduleBlock('plate', $x, $y, $z, str_contains($name, 'weighted') ? 10 : 20);
        }
    }

    /** BasePressurePlateBlock.tick: a pressed plate looks again. */
    private function tickPressurePlate(int $x, int $y, int $z): void
    {
        $state = $this->world->get($x, $y, $z);
        if ($this->isPressurePlate($this->version->catalog->blockName($state)) && $this->plateSignal($state) > 0) {
            $this->checkPressed($x, $y, $z, $state);
        }
    }

    /**
     * Entity.checkInsideBlocks for every entity, after they have moved: an unpressed plate in a
     * block an entity's box is in checks whether it is pressed.
     */
    private function tickPlateContacts(): void
    {
        $cells = [];
        foreach ($this->entitiesIn([-3.0E7, $this->world->minY - 64.0, -3.0E7, 3.0E7, 1.0E5, 3.0E7]) as [$box]) {
            for ($x = (int) floor($box[0] + 1.0E-5); $x <= (int) floor($box[3] - 1.0E-5); ++$x) {
                for ($y = (int) floor($box[1] + 1.0E-5); $y <= (int) floor($box[4] - 1.0E-5); ++$y) {
                    for ($z = (int) floor($box[2] + 1.0E-5); $z <= (int) floor($box[5] - 1.0E-5); ++$z) {
                        $cells[$x . ':' . $y . ':' . $z] = [$x, $y, $z];
                    }
                }
            }
        }
        foreach ($cells as [$x, $y, $z]) {
            $state = $this->world->get($x, $y, $z);
            if ($this->isPressurePlate($this->version->catalog->blockName($state)) && $this->plateSignal($state) === 0) {
                $this->checkPressed($x, $y, $z, $state);
            }
        }
    }
}
