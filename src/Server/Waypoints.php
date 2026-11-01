<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;

/**
 * The locator bar as ServerWaypointManager keeps it for each dimension: every player who
 * receives waypoints holds a connection to each player transmitting one in range. A connection
 * sends the source's block while its chunk is in view, its chunk while it is not, and only its
 * direction once it is more than 332 blocks away, and is remade when it breaks.
 *
 * Positions are compared once a tick, after the clients' packets, where vanilla compares them on
 * each move. Mobs only transmit with a raised waypoint_transmit_range, so they are listed when
 * /waypoint changes their icon but never connect.
 */
trait Waypoints
{
    /** @var array<string, array<int, array<int, array>>> connections by dimension, receiver and source entity */
    private array $waypointConnections = [];

    /** @var array<int, array{string, string, float, float, float}> each player's dimension and position last compared */
    private array $waypointPositions = [];

    /** @var array<int, string> mobs /waypoint changed, with their dimension; they join the transmitters */
    private array $waypointMobs = [];

    /** TeamColor.rgb for the team colours a waypoint may take. */
    private const WAYPOINT_COLORS = ['black' => 0x000000, 'dark_blue' => 0x0000AA, 'dark_green' => 0x00AA00, 'dark_aqua' => 0x00AAAA, 'dark_red' => 0xAA0000,
        'dark_purple' => 0xAA00AA, 'gold' => 0xFFAA00, 'gray' => 0xAAAAAA, 'dark_gray' => 0x555555, 'blue' => 0x5555FF, 'green' => 0x55FF55, 'aqua' => 0x55FFFF,
        'red' => 0xFF5555, 'light_purple' => 0xFF55FF, 'yellow' => 0xFFFF55, 'white' => 0xFFFFFF];

    /** Entity.setPosRaw's waypoint updates, and ServerWaypointManager.addPlayer and removePlayer. */
    private function tickWaypoints(): void
    {
        $players = [];
        foreach ($this->players() as $player) {
            $players[$player->entityId] = $player;
            $last = $this->waypointPositions[$player->entityId] ?? null;
            $position = [$player->dimension, $player->x, $player->y, $player->z];
            if ($last !== null && $last[0] !== $player->dimension) {
                $this->removeWaypointPlayer($player, $last[0]);
                $last = null;
            }
            if ($last === null) {
                // ServerWaypointManager.addPlayer: the new player connects to the waypoints already
                // tracked; its own connects with its first move after its first tick.
                $this->waypointPositions[$player->entityId] = [$player->dimension, NAN, NAN, NAN];
                if ($this->playerAttribute($player, 'waypoint_receive_range') > 0.0) {
                    $this->updateWaypointReceiver($player);
                }
                continue;
            }
            $this->waypointPositions[$player->entityId] = $position;
            if ($last !== $position) {
                if ($this->isTransmittingWaypoint($player)) {
                    $this->updateWaypoint($player);
                }
                if ($this->playerAttribute($player, 'waypoint_receive_range') > 0.0) {
                    $this->updateWaypointReceiver($player);
                }
            }
        }
        foreach ($this->waypointPositions as $id => [$dimension]) {
            if (!isset($players[$id])) {
                $this->forgetWaypointEntity($id, $dimension);
            }
        }
    }

    private function isTransmittingWaypoint(Client $player): bool
    {
        return $this->playerAttribute($player, 'waypoint_transmit_range') > 0.0;
    }

    /** ServerWaypointManager.removePlayer: the player's connections close, and so do those to it. */
    private function removeWaypointPlayer(Client $player, ?string $dimension = null): void
    {
        $dimension ??= $player->dimension;
        foreach ($this->waypointConnections[$dimension][$player->entityId] ?? [] as $source => $connection) {
            $player->send($this->version, 'waypoint', $this->waypointUntrack($connection['uuid']));
        }
        unset($this->waypointConnections[$dimension][$player->entityId]);
        $this->untrackWaypoint($player->entityId, $dimension);
    }

    /** A player gone from the server: the others stop tracking it. */
    private function forgetWaypointEntity(int $id, string $dimension): void
    {
        unset($this->waypointConnections[$dimension][$id], $this->waypointPositions[$id]);
        $this->untrackWaypoint($id, $dimension);
    }

    /** ServerWaypointManager.untrackWaypoint: every receiver drops the source. */
    private function untrackWaypoint(int $source, string $dimension): void
    {
        foreach ($this->waypointConnections[$dimension] ?? [] as $receiver => $connections) {
            if (isset($connections[$source])) {
                $this->waypointReceiver($receiver)?->send($this->version, 'waypoint', $this->waypointUntrack($connections[$source]['uuid']));
                unset($this->waypointConnections[$dimension][$receiver][$source]);
            }
        }
    }

    /** ServerWaypointManager.updateWaypoint: each receiver's connection to the source updates, or is made. */
    private function updateWaypoint(Client $source): void
    {
        foreach ($this->players() as $receiver) {
            if ($receiver->dimension !== $source->dimension || $receiver === $source || $this->playerAttribute($receiver, 'waypoint_receive_range') <= 0.0) {
                continue;
            }
            $this->refreshWaypointConnection($receiver, $source);
        }
    }

    /** ServerWaypointManager.updatePlayer: each of the receiver's connections updates, and new sources connect. */
    private function updateWaypointReceiver(Client $receiver): void
    {
        foreach ($this->players() as $source) {
            if ($source->dimension === $receiver->dimension && $source !== $receiver && $this->isTransmittingWaypoint($source)) {
                $this->refreshWaypointConnection($receiver, $source);
            }
        }
    }

    private function refreshWaypointConnection(Client $receiver, Client $source): void
    {
        if ($this->gameRule('locator_bar') !== 'true') {
            return;
        }
        $connection = $this->waypointConnections[$receiver->dimension][$receiver->entityId][$source->entityId] ?? null;
        if ($connection === null) {
            $this->connectWaypoint($receiver, $source);
            return;
        }
        if (!$this->waypointConnectionBroken($connection, $receiver, $source)) {
            $this->updateWaypointConnection($receiver, $source, $connection);
            return;
        }
        if (!$this->connectWaypoint($receiver, $source)) {
            $receiver->send($this->version, 'waypoint', $this->waypointUntrack($connection['uuid']));
        }
    }

    /**
     * LivingEntity.makeWaypointConnectionWith and connect: a direction past 332 blocks, a chunk
     * outside the receiver's view, a block inside it.
     */
    private function connectWaypoint(Client $receiver, Client $source): bool
    {
        $dimension = $receiver->dimension;
        if ($this->waypointIgnores($source, $receiver)) {
            unset($this->waypointConnections[$dimension][$receiver->entityId][$source->entityId]);
            return false;
        }
        $icon = $this->waypointIcon($source);
        if ($this->waypointDistance($source, $receiver) > 332.0) {
            $connection = ['kind' => 3, 'last' => $this->waypointAngle($source, $receiver)];
        } elseif (!$this->waypointChunkVisible([(int) floor($source->x) >> 4, (int) floor($source->z) >> 4], $receiver)) {
            $connection = ['kind' => 2, 'last' => [(int) floor($source->x) >> 4, (int) floor($source->z) >> 4]];
        } else {
            $connection = ['kind' => 1, 'last' => [(int) floor($source->x), (int) floor($source->y), (int) floor($source->z)]];
        }
        $connection += ['uuid' => $source->uuid, 'icon' => $icon];
        $this->waypointConnections[$dimension][$receiver->entityId][$source->entityId] = $connection;
        $receiver->send($this->version, 'waypoint', $this->waypointPacket(0, $connection));
        return true;
    }

    /** Each connection's isBroken. */
    private function waypointConnectionBroken(array $connection, Client $receiver, Client $source): bool
    {
        $ignored = $this->waypointIgnores($source, $receiver);
        return match ($connection['kind']) {
            1 => abs((int) floor($source->x) - $connection['last'][0]) + abs((int) floor($source->y) - $connection['last'][1]) + abs((int) floor($source->z) - $connection['last'][2]) > 1 || $ignored,
            2 => max(abs(((int) floor($source->x) >> 4) - $connection['last'][0]), abs(((int) floor($source->z) >> 4) - $connection['last'][1])) > 1 || $ignored
                || $this->waypointChunkVisible($connection['last'], $receiver),
            default => $ignored || $this->waypointChunkVisible([(int) floor($source->x) >> 4, (int) floor($source->z) >> 4], $receiver) || $this->waypointDistance($source, $receiver) <= 332.0,
        };
    }

    /** Each connection's update: a moved block or chunk, or a direction turned by more than half a degree. */
    private function updateWaypointConnection(Client $receiver, Client $source, array $connection): void
    {
        $current = match ($connection['kind']) {
            1 => [(int) floor($source->x), (int) floor($source->y), (int) floor($source->z)],
            2 => [(int) floor($source->x) >> 4, (int) floor($source->z) >> 4],
            default => $this->waypointAngle($source, $receiver),
        };
        $changed = $connection['kind'] === 3 ? abs(self::waypointFloat($current - $connection['last'])) > self::waypointFloat(0.008726646) : $current !== $connection['last'];
        if ($changed) {
            $connection['last'] = $current;
            $this->waypointConnections[$receiver->dimension][$receiver->entityId][$source->entityId] = $connection;
            $receiver->send($this->version, 'waypoint', $this->waypointPacket(2, $connection));
        }
    }

    /** WaypointTransmitter.doesSourceIgnoreReceiver: spectating sources and receivers out of range. */
    private function waypointIgnores(Client $source, Client $receiver): bool
    {
        if ($receiver->gameMode === 3) {
            return false;
        }
        if ($source->gameMode === 3) {
            return true;
        }
        $range = min($this->playerAttribute($source, 'waypoint_transmit_range'), $this->playerAttribute($receiver, 'waypoint_receive_range'));
        return $this->waypointDistance($source, $receiver) >= $range;
    }

    /** Entity.distanceTo, in floats. */
    private function waypointDistance(Client $source, Client $receiver): float
    {
        [$dx, $dy, $dz] = [self::waypointFloat($source->x - $receiver->x), self::waypointFloat($source->y - $receiver->y), self::waypointFloat($source->z - $receiver->z)];
        $squares = self::waypointFloat(self::waypointFloat(self::waypointFloat($dx * $dx) + self::waypointFloat($dy * $dy)) + self::waypointFloat($dz * $dz));
        return self::waypointFloat(sqrt($squares));
    }

    /** ChunkTrackingView.isInViewDistance, around the receiver's chunk. */
    private function waypointChunkVisible(array $chunk, Client $receiver): bool
    {
        $view = $this->viewDistance($receiver);
        $dx = max(0, abs($chunk[0] - ((int) floor($receiver->x) >> 4)) - 1);
        $dz = max(0, abs($chunk[1] - ((int) floor($receiver->z) >> 4)) - 1);
        return $dx * $dx + $dz * $dz < $view * $view;
    }

    /** EntityAzimuthConnection: the bearing from the source to the receiver, turned clockwise a quarter. */
    private function waypointAngle(Client $source, Client $receiver): float
    {
        return self::waypointFloat(self::mthAtan2($receiver->x - $source->x, -($receiver->z - $source->z)));
    }

    private static function waypointFloat(float $value): float
    {
        return unpack('G', pack('G', $value))[1];
    }

    /** Waypoint.Icon.cloneAndAssignStyle: without a colour of its own, a player shows its team's. */
    private function waypointIcon(Client $source): array
    {
        $icon = $source->waypointIcon;
        if ($icon['color'] === null && ($team = $this->scoreboard()->teamOf($source->name)) !== null) {
            $color = $this->scoreboard()->teams[$team]['color'] ?? null;
            if ($color !== null) {
                $icon['color'] = $color === 'black' ? 0x303030 : self::WAYPOINT_COLORS[$color];
            }
        }
        return $icon;
    }

    /** ClientboundTrackedWaypointPacket for a connection: track (0) or update (2). */
    private function waypointPacket(int $operation, array $connection): string
    {
        $contents = match ($connection['kind']) {
            1 => Binary::writeVarInt($connection['last'][0]) . Binary::writeVarInt($connection['last'][1]) . Binary::writeVarInt($connection['last'][2]),
            2 => Binary::writeVarInt($connection['last'][0]) . Binary::writeVarInt($connection['last'][1]),
            default => pack('G', $connection['last']),
        };
        return Binary::writeVarInt($operation) . "\1" . $connection['uuid'] . Binary::writeString($connection['icon']['style'])
            . ($connection['icon']['color'] === null ? "\0" : "\1" . substr(pack('N', $connection['icon']['color']), 1))
            . Binary::writeVarInt($connection['kind']) . $contents;
    }

    /** ClientboundTrackedWaypointPacket.removeWaypoint: an empty waypoint with the default icon. */
    private function waypointUntrack(string $uuid): string
    {
        return "\1\1" . $uuid . Binary::writeString('minecraft:default') . "\0\0";
    }

    private function waypointReceiver(int $entityId): ?Client
    {
        foreach ($this->players() as $player) {
            if ($player->entityId === $entityId) {
                return $player;
            }
        }
        return null;
    }

    /** MinecraftServer.onGameRuleChanged for the locator bar: connections all close, or players reconnect. */
    private function locatorBarChanged(bool $enabled): void
    {
        if ($enabled) {
            foreach ($this->players() as $player) {
                if ($this->playerAttribute($player, 'waypoint_receive_range') > 0.0) {
                    $this->updateWaypointReceiver($player);
                }
            }
            return;
        }
        foreach ($this->waypointConnections as $dimension => $receivers) {
            foreach ($receivers as $receiver => $connections) {
                $client = $this->waypointReceiver($receiver);
                foreach ($connections as $connection) {
                    $client?->send($this->version, 'waypoint', $this->waypointUntrack($connection['uuid']));
                }
            }
        }
        $this->waypointConnections = [];
    }

    /** LivingEntity.onAttributeUpdated for the waypoint ranges: a transmitter (un)tracks, a receiver joins or leaves. */
    private function waypointRangeChanged(Client $player, string $attribute): void
    {
        if ($attribute === 'waypoint_transmit_range') {
            $this->untrackWaypoint($player->entityId, $player->dimension);
            if ($this->isTransmittingWaypoint($player)) {
                $this->updateWaypoint($player);
            }
        } elseif ($attribute === 'waypoint_receive_range') {
            foreach ($this->waypointConnections[$player->dimension][$player->entityId] ?? [] as $connection) {
                $player->send($this->version, 'waypoint', $this->waypointUntrack($connection['uuid']));
            }
            unset($this->waypointConnections[$player->dimension][$player->entityId]);
            if ($this->playerAttribute($player, 'waypoint_receive_range') > 0.0) {
                $this->updateWaypointReceiver($player);
            }
        }
    }

    /** ServerScoreboard.updatePlayerWaypoint: a team change remakes each named player's connections. */
    private function remakeWaypoints(array $names): void
    {
        foreach ($this->players() as $source) {
            if (!in_array($source->name, $names, true) || !$this->isTransmittingWaypoint($source) || $this->gameRule('locator_bar') !== 'true') {
                continue;
            }
            foreach ($this->players() as $receiver) {
                if ($receiver !== $source && $receiver->dimension === $source->dimension && $this->playerAttribute($receiver, 'waypoint_receive_range') > 0.0) {
                    $this->connectWaypoint($receiver, $source);
                }
            }
        }
    }

    /** WaypointCommand.mutateIcon: the source untracks, takes the new icon, and connects again. */
    private function changeWaypointIcon(array $entity, callable $change): void
    {
        if (isset($entity['client'])) {
            $player = $entity['client'];
            $this->untrackWaypoint($player->entityId, $player->dimension);
            $player->waypointIcon = $change($player->waypointIcon);
            if ($this->isTransmittingWaypoint($player)) {
                $this->updateWaypoint($player);
            }
            return;
        }
        $mob = &$this->animals[$entity['mob']];
        $mob['waypointIcon'] = $change($mob['waypointIcon'] ?? ['style' => 'minecraft:default', 'color' => null]);
        $this->waypointMobs[$entity['mob']] = $this->world->dimension;
        unset($mob);
    }
}
