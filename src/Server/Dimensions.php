<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\World;

/** World-scoped simulation contexts are entered for each network action and tick. */
trait Dimensions
{
    private array $worlds = [];

    private array $dimensionState = [];
    private const LOCAL_STATE = ['animals', 'naturalSpawns', 'itemEntities', 'activeFurnaces', 'pendingLight', 'blockTicks', 'jukeboxes'];

    private function withDimension(string $dimension, callable $action): mixed
    {
        $previous = $this->world->dimension;
        if ($previous === $dimension) {
            return $action();
        }
        if (!isset($this->worlds[$dimension])) {
            throw new \InvalidArgumentException('Unknown dimension');
        }
        foreach (self::LOCAL_STATE as $field) {
            $this->dimensionState[$previous][$field] = $this->$field;
            $this->$field = $this->dimensionState[$dimension][$field] ?? [];
        }
        $this->world = $this->worlds[$dimension];
        try {
            return $action();
        } finally {
            foreach (self::LOCAL_STATE as $field) {
                $this->dimensionState[$dimension][$field] = $this->$field;
                $this->$field = $this->dimensionState[$previous][$field];
            }
            $this->world = $this->worlds[$previous];
        }
    }

    private function localPlayers(): array
    {
        return array_filter($this->players(), fn (Client $c): bool => $c->dimension === $this->world->dimension);
    }

    private function tickDimensions(): void
    {
        foreach (array_keys($this->worlds) as $dimension) {
            $this->withDimension($dimension, function (): void {
                $this->tickBlocks();
                $this->tickItems();
                $this->tickFurnaces();
                $this->tickAnimals();
                foreach (array_slice($this->pendingLight, 0, 1, true) as $key => [$cx,$cz]) {
                    $payload = Binary::writeVarInt($cx) . Binary::writeVarInt($cz) . $this->world->chunkLight($cx, $cz);
                    foreach ($this->localPlayers() as $p) {
                        if (isset($p->loadedChunks[$key])) {
                            $p->send($this->version, 'light_update', $payload);
                        }
                    }
                    unset($this->pendingLight[$key]);
                }
            });
        }
    }

    private function changeDimension(Client $c, string $dimension, ?array $position = null, bool $force = false): void
    {
        if (!isset($this->worlds[$dimension])) {
            throw new \InvalidArgumentException('Choose overworld, the_nether or the_end');
        }
        if ($c->dimension === $dimension && !$force) {
            $this->teleport($c, $position);
            return;
        }
        $this->withDimension($c->dimension, function () use ($c): void {
            $this->closeWindow($c, true);
            foreach ($this->localPlayers() as $other) {
                if ($other !== $c) {
                    $this->forgetEntity($other, $c->entityId);
                }
            }
        });
        // Respawning after death must allow immediate portal entry, as the vanilla lifecycle probe verifies.
        $this->forgetPushingEntity($c->entityId, $c->dimension);
        $c->dimension = $dimension;
        $c->portalTicks = 0;
        $c->portalCooldown = $force ? 0 : $this->ticks + 100;
        $this->withDimension($dimension, function () use ($c, $position): void {
            $c->loadedChunks = [];
            $c->queuedChunks = [];
            $c->entities = [];
            $c->awaitingBatch = false;
            $c->mining = null;
            $c->eating = null;
            $c->playerLoaded = false;
            $c->send($this->version, 'respawn', Packets::respawn($this->version, $c->gameMode, $this->world->generator === 'flat' && $c->dimension === 'overworld', $this->world->hashedSeed(), $c->dimension));
            $this->abilities($c);
            $this->health($c);
            $this->syncInventory($c);
            $this->sendClock($c);
            $this->sendWeather($c);
            $c->send($this->version, 'set_default_spawn_position', Packets::spawn($this->worlds['overworld']->spawn()));
            $c->send($this->version, 'game_event', "\x0d" . pack('G', 0));
            $this->teleport($c, $position ?? $this->safeArrival($this->world->spawn()));
            foreach ($this->localPlayers() as $other) {
                if ($other !== $c) {
                    $this->spawnPlayer($c, $other);
                    $this->spawnPlayer($other, $c);
                }
            }
            $this->savePlayer($c);
        });
    }

    /** Search a column for a solid floor and two clear blocks; construct a protected fallback. */
    private function safeArrival(array $position): array
    {
        [$x,$y,$z] = array_map('intval', array_map('floor', $position));
        $catalog = $this->version->catalog;
        $top = $this->world->dimension === 'the_nether' ? 120 : $this->world->minY + $this->world->height - 3;
        $preferred = min($top, max($this->world->minY + 2, $y));
        for ($d = 0; $d <= $this->world->height; ++$d) {
            foreach (array_unique([$preferred + $d, $preferred - $d]) as $candidate) {
                if ($candidate <= $this->world->minY || $candidate > $top) {
                    continue;
                }
                $floor = $this->world->get($x, $candidate - 1, $z);
                $feet = $this->world->get($x, $candidate, $z);
                $head = $this->world->get($x, $candidate + 1, $z);
                if ($catalog->motionBlocking($floor) && !$catalog->fluid($floor) && !in_array($catalog->blockName($floor), ['magma_block', 'cactus'], true) && $catalog->air($feet) && $catalog->air($head)) {
                    return [$x + 0.5, (float) $candidate, $z + 0.5];
                }
            }
        }
        $y = max($this->world->minY + 3, min($top, $preferred));
        for ($dx = -2; $dx <= 2; ++$dx) {
            for ($dz = -2; $dz <= 2; ++$dz) {
                $this->setBlock($x + $dx, $y - 1, $z + $dz, $catalog->state('obsidian'));
                for ($dy = 0; $dy < 4; ++$dy) {
                    $this->setBlock($x + $dx, $y + $dy, $z + $dz, 0);
                }
            }
        }
        return [$x + 0.5, (float) $y, $z + 0.5];
    }

    private function tickPortals(Client $c): void
    {
        if ($c->dead || $c->gameMode === 3 || $c->teleport !== null) {
            return;
        }
        $name = $this->version->catalog->blockName($this->world->get((int) floor($c->x), (int) floor($c->y), (int) floor($c->z)));
        // Stepping away from the arrival portal is required before another transition.
        if (!in_array($name, ['nether_portal', 'end_portal'], true)) {
            $c->portalTicks = 0;
            return;
        }
        if ($this->ticks < $c->portalCooldown) {
            $c->portalCooldown = $this->ticks + 20;
            return;
        }
        if ($name === 'end_portal') {
            $target = $c->dimension === 'the_end' ? 'overworld' : 'the_end';
            $this->changeDimension($c, $target, $target === 'overworld' ? ($c->personalSpawn ?? $this->worlds['overworld']->spawn()) : null);
            return;
        }
        if ($c->dimension === 'the_end') {
            return;
        }
        if (++$c->portalTicks < ($c->gameMode === 1 ? 1 : 80)) {
            return;
        }
        $target = $c->dimension === 'overworld' ? 'the_nether' : 'overworld';
        $scale = $target === 'the_nether' ? 1 / 8 : 8;
        $position = [max(-29999872, min(29999872, $c->x * $scale)), $c->y, max(-29999872, min(29999872, $c->z * $scale))];
        $arrival = $this->withDimension($target, function () use ($position): array {
            // Prefer a previously built portal, preserving the return link without duplicate frames.
            $known = $this->world->setting('portals') ?? [];
            $best = null;
            $distance = 128 ** 2;
            foreach ($known as $portal) {
                [$x,$y,$z] = $portal;
                $d = ($position[0] - $x) ** 2 + ($position[2] - $z) ** 2;
                if ($d < $distance && $this->version->catalog->blockName($this->world->get((int) floor($x), (int) floor($y), (int) floor($z))) === 'nether_portal') {
                    $best = $portal;
                    $distance = $d;
                }
            }
            if ($best !== null) {
                return $best;
            }
            $safe = $this->safeArrival($position);
            [$x,$y,$z] = array_map('intval', array_map('floor', $safe));
            for ($dx = -1; $dx <= 2; ++$dx) {
                for ($dz = -1; $dz <= 1; ++$dz) {
                    $this->setBlock($x + $dx, $y - 1, $z + $dz, $this->version->catalog->state('obsidian'));
                }
            }
            for ($dx = -1; $dx <= 2; ++$dx) {
                for ($dy = -1; $dy <= 3; ++$dy) {
                    $edge = $dx === -1 || $dx === 2 || $dy === -1 || $dy === 3;
                    $this->setBlock($x + $dx, $y + $dy, $z, $this->version->catalog->state($edge ? 'obsidian' : 'nether_portal'));
                }
            }
            $known[] = [$x + 0.5, (float) $y, $z + 0.5];
            $this->world->setting('portals', array_slice($known, -1024));
            return [$x + 0.5, (float) $y, $z + 0.5];
        });
        $this->changeDimension($c, $target, $arrival);
    }

    private function insertEndEye(Client $c, int $x, int $y, int $z, int $slot): bool
    {
        if (!$this->editable($c, $x, $y, $z)) {
            return false;
        }
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        if ($catalog->blockName($state) !== 'end_portal_frame' || $catalog->properties($state)['eye'] === 'true') {
            return false;
        }
        $props = $catalog->properties($state);
        $props['eye'] = 'true';
        $this->setBlock($x, $y, $z, $catalog->state('end_portal_frame', $props));
        if ($c->gameMode === 0) {
            \PhpMinecraftJavaServer\World\Inventory::consume($c->inventory, $slot);
            $this->syncInventory($c);
        }
        for ($cx = $x - 2; $cx <= $x + 2; ++$cx) {
            for ($cz = $z - 2; $cz <= $z + 2; ++$cz) {
                $valid = true;
                foreach ([[-2, 0, 'east'], [2, 0, 'west'], [0, -2, 'south'], [0, 2, 'north']] as [$dx,$dz,$facing]) {
                    for ($i = -1; $i <= 1; ++$i) {
                        $fx = $cx + $dx + ($dx === 0 ? $i : 0);
                        $fz = $cz + $dz + ($dz === 0 ? $i : 0);
                        $frame = $this->world->get($fx, $y, $fz);
                        if ($catalog->blockName($frame) !== 'end_portal_frame') {
                            $valid = false;
                            break 2;
                        }
                        $p = $catalog->properties($frame);
                        if ($p['eye'] !== 'true' || $p['facing'] !== $facing) {
                            $valid = false;
                            break 2;
                        }
                    }
                }
                if ($valid) {
                    for ($dx = -1; $dx <= 1; ++$dx) {
                        for ($dz = -1; $dz <= 1; ++$dz) {
                            $this->setBlock($cx + $dx, $y, $cz + $dz, $catalog->state('end_portal'));
                        }
                    }
                    return true;
                }
            }
        }
        return true;
    }

    private function invalidatePortal(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $queue = [[$x, $y, $z]];
        $seen = [];
        $portals = [];
        while ($queue !== [] && count($seen) < 1024) {
            [$px,$py,$pz] = array_pop($queue);
            $key = $px . ':' . $py . ':' . $pz;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            foreach ([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$dx,$dy,$dz]) {
                $a = $px + $dx;
                $b = $py + $dy;
                $c = $pz + $dz;
                $state = $this->world->get($a, $b, $c);
                if ($catalog->blockName($state) === 'nether_portal' && !isset($seen[$a . ':' . $b . ':' . $c])) {
                    $portals[$a . ':' . $b . ':' . $c] = [$a, $b, $c, $state];
                    $queue[] = [$a, $b, $c];
                }
            }
        }
        $valid = true;
        foreach ($portals as [$a,$b,$c,$state]) {
            $axis = $catalog->properties($state)['axis'];
            foreach ([[0, 1, 0], [0, -1, 0], [$axis === 'x' ? 1 : 0, 0, $axis === 'z' ? 1 : 0], [$axis === 'x' ? -1 : 0, 0, $axis === 'z' ? -1 : 0]] as [$dx,$dy,$dz]) {
                if (!in_array($catalog->blockName($this->world->get($a + $dx, $b + $dy, $c + $dz)), ['obsidian', 'nether_portal'], true)) {
                    $valid = false;
                    break 2;
                }
            }
        }
        if (!$valid) {
            foreach ($portals as [$a,$b,$c]) {
                $this->setBlock($a, $b, $c, 0);
            }
        }
    }

    /** Validate a rectangular obsidian frame, including variable vanilla interior sizes. */
    private function ignitePortal(Client $c, int $x, int $y, int $z, int $slot): bool
    {
        if ($this->world->dimension === 'the_end' || !$this->editable($c, $x, $y, $z)) {
            return false;
        }
        $catalog = $this->version->catalog;
        foreach (['x', 'z'] as $axis) {
            $dx = $axis === 'x' ? 1 : 0;
            $dz = $axis === 'z' ? 1 : 0;
            $bottom = $y;
            while ($bottom > $this->world->minY + 1 && $y - $bottom < 21 && $catalog->air($this->world->get($x, $bottom - 1, $z))) {
                --$bottom;
            }
            $left = 0;
            while ($left < 21 && $catalog->air($this->world->get($x - ($left + 1) * $dx, $bottom, $z - ($left + 1) * $dz))) {
                ++$left;
            }
            $sx = $x - $left * $dx;
            $sz = $z - $left * $dz;
            $width = 0;
            while ($width < 22 && $catalog->air($this->world->get($sx + $width * $dx, $bottom, $sz + $width * $dz))) {
                ++$width;
            }
            if ($width < 2 || $width > 21) {
                continue;
            }
            $height = 0;
            $valid = true;
            for ($h = 0; $h <= 21; ++$h) {
                $top = $catalog->blockName($this->world->get($sx, $bottom + $h, $sz)) === 'obsidian';
                if ($top) {
                    $height = $h;
                    break;
                }
                if ($catalog->blockName($this->world->get($sx - $dx, $bottom + $h, $sz - $dz)) !== 'obsidian' || $catalog->blockName($this->world->get($sx + $width * $dx, $bottom + $h, $sz + $width * $dz)) !== 'obsidian') {
                    $valid = false;
                    break;
                }
                for ($w = 0; $w < $width; ++$w) {
                    if (!$catalog->air($this->world->get($sx + $w * $dx, $bottom + $h, $sz + $w * $dz))) {
                        $valid = false;
                        break 2;
                    }
                }
            }
            if (!$valid || $height < 3 || $height > 21 || $bottom + $height >= $this->world->minY + $this->world->height) {
                continue;
            }
            for ($w = 0; $w < $width; ++$w) {
                if ($catalog->blockName($this->world->get($sx + $w * $dx, $bottom - 1, $sz + $w * $dz)) !== 'obsidian' || $catalog->blockName($this->world->get($sx + $w * $dx, $bottom + $height, $sz + $w * $dz)) !== 'obsidian') {
                    $valid = false;
                    break;
                }
            }
            if (!$valid) {
                continue;
            }
            for ($h = 0; $h < $height; ++$h) {
                for ($w = 0; $w < $width; ++$w) {
                    $this->setBlock($sx + $w * $dx, $bottom + $h, $sz + $w * $dz, $catalog->state('nether_portal', ['axis' => $axis]));
                }
            }
            $known = $this->world->setting('portals') ?? [];
            $known[] = [$sx + 0.5, (float) $bottom, $sz + 0.5];
            $this->world->setting('portals', array_slice($known, -1024));
            if ($c->gameMode === 0) {
                $c->inventory[$slot]['damage'] = ($c->inventory[$slot]['damage'] ?? 0) + 1;
                if ($c->inventory[$slot]['damage'] >= $catalog->item($c->inventory[$slot]['item'])['durability']) {
                    unset($c->inventory[$slot]);
                }
                $this->syncInventory($c);
            }
            return true;
        }
        return false;
    }
}
