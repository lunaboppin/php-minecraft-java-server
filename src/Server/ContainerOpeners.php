<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * ContainerOpenersCounter for chests and barrels: the first viewer opens the container with its
 * sound (and a barrel's open state), the last one closes it, and a chest's lid follows each
 * change of viewer count through a block event at the end of the tick's block updates.
 */
trait ContainerOpeners
{
    /** @var array<string, int> openers per dimension and position */
    private array $containerOpeners = [];

    /** @var array<string, list<array{array, string, int, int}>> ServerLevel.blockEvents per dimension */
    private array $blockEvents = [];

    /** @var array<string, array{int, string, array}> ContainerOpenersCounter.scheduleRecheck: due tick, dimension, position */
    private array $openerRechecks = [];

    private const HORIZONTAL_STEPS = ['north' => [0, -1], 'south' => [0, 1], 'west' => [-1, 0], 'east' => [1, 0]];

    private const CLOCKWISE = ['north' => 'east', 'east' => 'south', 'south' => 'west', 'west' => 'north'];

    /** ChestBlock.getConnectedDirection: a left half joins on its clockwise side, a right half on the other. */
    private static function chestConnection(array $properties): string
    {
        return $properties['type'] === 'left' ? self::CLOCKWISE[$properties['facing']] : array_search($properties['facing'], self::CLOCKWISE, true);
    }

    /** ChestBlock.candidatePartnerFacing: the facing of a single chest of the same kind beside the position. */
    private function chestPartnerFacing(string $name, array $position, string $direction): ?string
    {
        [$dx, $dz] = self::HORIZONTAL_STEPS[$direction];
        $state = $this->world->get($position[0] + $dx, $position[1], $position[2] + $dz);
        if ($this->version->catalog->blockName($state) !== $name) {
            return null;
        }
        $properties = $this->version->catalog->properties($state);
        return $properties['type'] === 'single' ? $properties['facing'] : null;
    }

    /**
     * ChestBlock.getStateForPlacement: facing the placer, and joining a single chest beside it that
     * faces the same way; sneaking against a chest's side joins that chest whichever way it faces.
     *
     * @return array{string, string} facing and type
     */
    private function chestPlacement(string $name, array $position, string $looking, int $face, bool $sneaking): array
    {
        $opposite = ['north' => 'south', 'south' => 'north', 'west' => 'east', 'east' => 'west'];
        $facing = $opposite[$looking];
        $type = 'single';
        if ($face >= 2 && $sneaking) {
            $clicked = [2 => 'north', 3 => 'south', 4 => 'west', 5 => 'east'][$face];
            $partner = $this->chestPartnerFacing($name, $position, $opposite[$clicked]);
            if ($partner !== null && in_array($partner, ['north', 'south'], true) !== in_array($clicked, ['north', 'south'], true)) {
                $facing = $partner;
                $type = array_search($facing, self::CLOCKWISE, true) === $opposite[$clicked] ? 'right' : 'left';
            }
        }
        if ($type === 'single' && !$sneaking) {
            if ($this->chestPartnerFacing($name, $position, self::CLOCKWISE[$facing]) === $facing) {
                $type = 'left';
            } elseif ($this->chestPartnerFacing($name, $position, array_search($facing, self::CLOCKWISE, true)) === $facing) {
                $type = 'right';
            }
        }
        return [$facing, $type];
    }

    /** ChestBlock.updateShape on the partner of a newly placed half: it becomes the other half. */
    private function joinChest(array $position): void
    {
        $state = $this->world->get(...$position);
        $name = $this->version->catalog->blockName($state);
        $properties = $this->version->catalog->properties($state);
        [$dx, $dz] = self::HORIZONTAL_STEPS[self::chestConnection($properties)];
        $partner = $this->world->get($position[0] + $dx, $position[1], $position[2] + $dz);
        $partnerProperties = $this->version->catalog->properties($partner);
        if ($this->version->catalog->blockName($partner) === $name && $partnerProperties['type'] === 'single' && $partnerProperties['facing'] === $properties['facing']) {
            $partnerProperties['type'] = $properties['type'] === 'left' ? 'right' : 'left';
            $this->setBlock($position[0] + $dx, $position[1], $position[2] + $dz, $this->version->catalog->state($name, $partnerProperties));
        }
    }

    /** ChestBlock.updateShape: a half whose partner has gone becomes a single chest again. */
    private function unjoinChest(array $position, int $previous, int $state): void
    {
        $name = $this->version->catalog->blockName($previous);
        if (($name !== 'chest' && $name !== 'trapped_chest') || $this->version->catalog->blockName($state) === $name) {
            return;
        }
        $properties = $this->version->catalog->properties($previous);
        if ($properties['type'] === 'single') {
            return;
        }
        [$dx, $dz] = self::HORIZONTAL_STEPS[self::chestConnection($properties)];
        $partner = $this->world->get($position[0] + $dx, $position[1], $position[2] + $dz);
        $partnerProperties = $this->version->catalog->properties($partner);
        if ($this->version->catalog->blockName($partner) === $name && $partnerProperties['type'] !== 'single') {
            $partnerProperties['type'] = 'single';
            $this->setBlock($position[0] + $dx, $position[1], $position[2] + $dz, $this->version->catalog->state($name, $partnerProperties));
        }
    }

    /** The other half of a double chest, or null for a single chest. */
    private function chestPartner(array $position): ?array
    {
        $state = $this->world->get(...$position);
        $properties = $this->version->catalog->properties($state);
        if (($properties['type'] ?? 'single') === 'single') {
            return null;
        }
        [$dx, $dz] = self::HORIZONTAL_STEPS[self::chestConnection($properties)];
        $partner = [$position[0] + $dx, $position[1], $position[2] + $dz];
        $other = $this->version->catalog->properties($this->world->get(...$partner));
        return $this->version->catalog->blockName($this->world->get(...$partner)) === $this->version->catalog->blockName($state) && ($other['type'] ?? 'single') !== 'single' ? $partner : null;
    }

    /** TrappedChestBlock.getSignal: its viewers, at most 15. */
    private function trappedChestSignal(array $position): int
    {
        return min(15, $this->containerOpeners[$this->containerKey($this->world->dimension, $position)] ?? 0);
    }

    private function containerKey(string $dimension, array $position): string
    {
        return $dimension . ':' . implode(':', $position);
    }

    /** ContainerOpenersCounter.incrementOpeners as a player opens the container's menu. */
    private function containerOpened(Client $player, array $position): void
    {
        $name = $this->version->catalog->blockName($this->world->get(...$position));
        if (!in_array($name, ['chest', 'trapped_chest', 'barrel', 'ender_chest'], true)) {
            return;
        }
        $key = $this->containerKey($player->dimension, $position);
        $previous = $this->containerOpeners[$key] ?? 0;
        $this->containerOpeners[$key] = $previous + 1;
        if ($previous === 0) {
            $this->containerSound($position, $name, true);
            $this->openerRechecks[$key] = [$this->ticks + 5, $player->dimension, $position];
        }
        $this->openerCountChanged($position, $name, $previous + 1, $previous);
    }

    /** ContainerOpenersCounter.decrementOpeners as the menu closes. */
    private function containerClosed(Client $player, array $position): void
    {
        $key = $this->containerKey($player->dimension, $position);
        if (!isset($this->containerOpeners[$key])) {
            return;
        }
        $name = $this->version->catalog->blockName($this->world->get(...$position));
        $previous = $this->containerOpeners[$key];
        $count = --$this->containerOpeners[$key];
        if ($count <= 0) {
            unset($this->containerOpeners[$key]);
        }
        if (!in_array($name, ['chest', 'trapped_chest', 'barrel', 'ender_chest'], true)) {
            return;
        }
        if ($count === 0) {
            $this->containerSound($position, $name, false);
        }
        $this->openerCountChanged($position, $name, $count, $previous);
    }

    /** ContainerOpenersCounter.recheckOpeners: count the players still viewing, and tell the lid again. */
    private function recheckOpeners(string $key, array $position): void
    {
        $name = $this->version->catalog->blockName($this->world->get(...$position));
        if (!in_array($name, ['chest', 'trapped_chest', 'barrel', 'ender_chest'], true)) {
            unset($this->containerOpeners[$key]);
            return;
        }
        $count = count(array_filter($this->localPlayers(), static fn (Client $player): bool => in_array($player->windowType, ['chest', 'ender'], true) && ($player->windowPosition === $position || $player->windowPartner === $position)));
        $previous = $this->containerOpeners[$key] ?? 0;
        if ($previous !== $count) {
            if ($count !== 0 && $previous === 0) {
                $this->containerSound($position, $name, true);
            } elseif ($count === 0) {
                $this->containerSound($position, $name, false);
            }
            $this->containerOpeners[$key] = $count;
            if ($count === 0) {
                unset($this->containerOpeners[$key]);
            }
        }
        $this->openerCountChanged($position, $name, $count, $previous);
        if ($count > 0) {
            $this->openerRechecks[$key] = [$this->ticks + 5, $this->world->dimension, $position];
        }
    }

    /** ChestBlockEntity.signalOpenCount queues the lid's event; a barrel shows its open state. */
    private function openerCountChanged(array $position, string $name, int $count, int $previous): void
    {
        // TrappedChestBlockEntity.signalOpenCount: its redstone signal follows the viewer count.
        if ($name === 'trapped_chest' && $previous !== $count) {
            $this->changedSimulation(...$position);
            $this->changedSimulation($position[0], $position[1] - 1, $position[2]);
        }
        if ($name === 'barrel') {
            // BarrelBlockEntity.updateBlockState: the state changes now; clients see it with the tick's block changes.
            $properties = $this->version->catalog->properties($this->world->get(...$position));
            $open = $count > 0 ? 'true' : 'false';
            if ($properties['open'] === $open) {
                return;
            }
            $this->world->set($position[0], $position[1], $position[2], $this->version->catalog->state('barrel', ['open' => $open] + $properties));
        }
        // ServerLevel.blockEvent: an identical event already queued this tick is not added again.
        $event = [$position, $name, 1, $count];
        if (!in_array($event, $this->blockEvents[$this->world->dimension] ?? [], true)) {
            $this->blockEvents[$this->world->dimension][] = $event;
        }
    }

    /** ChestBlockEntity.playSound and BarrelBlockEntity.playSound, half volume and a random pitch. */
    private function containerSound(array $position, string $name, bool $open): void
    {
        $properties = $this->version->catalog->properties($this->world->get(...$position));
        [$x, $y, $z] = [$position[0] + 0.5, $position[1] + 0.5, $position[2] + 0.5];
        $steps = ['north' => [0, -1], 'south' => [0, 1], 'west' => [-1, 0], 'east' => [1, 0], 'up' => [0, 0], 'down' => [0, 0]];
        if ($name === 'barrel') {
            // The sound comes from the barrel's open face.
            $vertical = ['up' => 1, 'down' => -1][$properties['facing']] ?? 0;
            [$x, $y, $z] = [$x + $steps[$properties['facing']][0] / 2.0, $y + $vertical / 2.0, $z + $steps[$properties['facing']][1] / 2.0];
            $sound = $open ? 'block.barrel.open' : 'block.barrel.close';
        } elseif ($name === 'ender_chest') {
            $sound = $open ? 'block.ender_chest.open' : 'block.ender_chest.close';
        } else {
            // A double chest sounds once, from its right half, at the middle of both.
            if ($properties['type'] === 'left') {
                return;
            }
            if ($properties['type'] === 'right') {
                $clockwise = ['north' => 'east', 'east' => 'south', 'south' => 'west', 'west' => 'north'];
                $connected = array_search($properties['facing'], $clockwise, true);
                [$x, $z] = [$x + $steps[$connected][0] * 0.5, $z + $steps[$connected][1] * 0.5];
            }
            $sound = $open ? 'block.chest.open' : 'block.chest.close';
        }
        $pitch = Float32::round(Float32::round(mt_rand() / mt_getrandmax() * 0.1) + 0.9);
        $payload = Packets::sound($this->version->blocks['sounds'][$sound], 4, $x, $y, $z, 0.5, $pitch, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $x) ** 2 + ($player->y - $y) ** 2 + ($player->z - $z) ** 2 < 16.0 ** 2) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    /** ServerLevel.runBlockEvents: each event reaches players within 64 blocks while its block is still there. */
    private function runBlockEvents(): void
    {
        foreach ($this->openerRechecks as $key => [$due, $dimension, $position]) {
            if ($dimension === $this->world->dimension && $due <= $this->ticks) {
                unset($this->openerRechecks[$key]);
                $this->recheckOpeners($key, $position);
            }
        }
        // ServerLevel.runBlockEvents: events queued while running run in the same pass.
        while (($this->blockEvents[$this->world->dimension] ?? []) !== []) {
            [$position, $name, $type, $data] = array_shift($this->blockEvents[$this->world->dimension]);
            if ($this->version->catalog->blockName($this->world->get(...$position)) !== $name) {
                continue;
            }
            if (($name === 'piston' || $name === 'sticky_piston') && !$this->pistonEvent($position, $name, $type, $data)) {
                continue;
            }
            if ($name === 'barrel') {
                foreach ($this->localPlayers() as $player) {
                    if (isset($player->loadedChunks[($position[0] >> 4) . ':' . ($position[2] >> 4)])) {
                        $player->send($this->version, 'block_update', Binary::writePosition(...$position) . Binary::writeVarInt($this->world->get(...$position)));
                    }
                }
                continue;
            }
            $payload = Binary::writePosition(...$position) . chr($type) . chr($data) . Binary::writeVarInt($this->version->catalog->simulation['block_ids'][$name]);
            [$x, $y, $z] = [$position[0] + 0.5, $position[1] + 0.5, $position[2] + 0.5];
            foreach ($this->localPlayers() as $player) {
                if (($player->x - $x) ** 2 + ($player->y - $y) ** 2 + ($player->z - $z) ** 2 < 64.0 ** 2) {
                    $player->send($this->version, 'block_event', $payload);
                }
            }
        }
    }
}
