<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Nbt;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Trees;

/**
 * AbstractBedBlock.useWithoutItem, ServerPlayer.startSleepInBed and SleepStatus: a player sleeps
 * in a free bed in the dark, the Overworld skips to morning once enough players have slept 100
 * ticks, and they stand up beside the bed. Beds in the Nether and the End explode.
 */
trait Sleeping
{
    /** @var array<string, array{0: int, 1: int}> SleepStatus per dimension: active and sleeping players */
    private array $sleepStatus = [];

    /** BedSleepingProblem messages. */
    private const SLEEP_PROBLEMS = ['too_far' => 'block.minecraft.bed.too_far_away', 'obstructed' => 'block.minecraft.bed.obstructed', 'not_safe' => 'block.minecraft.bed.not_safe'];

    /** Using a bed: sleep, or the problem that stops it. The returned flag is SUCCESS_SERVER. */
    private function useBed(Client $c, int $x, int $y, int $z): bool
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $p = $catalog->properties($state);
        $forward = $this->facingStep($p['facing']);
        $other = $p['part'] === 'head' ? [$x - $forward[0], $y, $z - $forward[1]] : [$x + $forward[0], $y, $z + $forward[1]];
        $otherState = $this->world->get(...$other);
        if ($catalog->blockName($otherState) !== $catalog->blockName($state) || $catalog->properties($otherState)['part'] === $p['part']) {
            return false;
        }
        if ($p['part'] !== 'head') {
            [$x, $z] = [$other[0], $other[2]];
            $state = $otherState;
            $p = $catalog->properties($state);
        }
        if ($this->world->dimension !== 'overworld') {
            // BedRule.destroyOnUse: the bed is removed and explodes with fire.
            $air = $catalog->state('air');
            $this->setBlock($x, $y, $z, $air);
            if ($catalog->blockName($this->world->get($x - $forward[0], $y, $z - $forward[1])) === $catalog->blockName($state)) {
                $this->setBlock($x - $forward[0], $y, $z - $forward[1], $air);
            }
            $this->explode($x + 0.5, $y + 0.5, $z + 0.5, 5.0, null, null, true, 'minecraft:bad_respawn_point');
            return true;
        }
        if ($p['occupied'] === 'true') {
            $this->overlay($c, ['translate' => 'block.minecraft.bed.occupied']);
            return true;
        }
        $problem = $this->startSleeping($c, $x, $y, $z, $p['facing']);
        if ($problem !== null) {
            $this->overlay($c, ['translate' => $problem]);
        }
        return true;
    }

    /** @return array{0: int, 1: int} the x and z step of a horizontal facing */
    private function facingStep(string $facing): array
    {
        return ['north' => [0, -1], 'south' => [0, 1], 'west' => [-1, 0], 'east' => [1, 0]][$facing];
    }

    private function overlay(Client $c, array $message): void
    {
        $c->send($this->version, 'system_chat', Nbt::component($message) . "\1");
    }

    /** Level.isDarkOutside: no fixed time and the sky darkened by 4 or more. */
    private function darkOutside(): bool
    {
        return $this->world->dimension === 'overworld' && $this->skyDarken() >= 4;
    }

    /** ServerPlayer.startSleepInBed: the problem's message, or null once asleep. */
    private function startSleeping(Client $c, int $x, int $y, int $z, string $facing): ?string
    {
        if ($c->sleepingPos !== null || $c->dead) {
            return null;
        }
        $forward = $this->facingStep($facing);
        $reachable = static fn (int $bx, int $bz): bool => abs($c->x - ($bx + 0.5)) <= 3.0 && abs($c->y - $y) <= 2.0 && abs($c->z - ($bz + 0.5)) <= 3.0;
        if (!$reachable($x, $z) && !$reachable($x - $forward[0], $z - $forward[1])) {
            return self::SLEEP_PROBLEMS['too_far'];
        }
        $collision = new Collision($this->version->catalog);
        // ServerPlayer.freeAt: nothing suffocating above either half.
        foreach ([[$x, $z], [$x - $forward[0], $z - $forward[1]]] as [$bx, $bz]) {
            if ($collision->shape($this->world->get($bx, $y + 1, $bz)) !== [] && $this->version->catalog->blocks[$this->version->catalog->blockName($this->world->get($bx, $y + 1, $bz))]['full_cube']) {
                return self::SLEEP_PROBLEMS['obstructed'];
            }
        }
        // BedRule can_set_spawn: always in the Overworld.
        $c->personalSpawn = [$x + 0.5, (float) $y + 0.5625, $z + 0.5];
        $this->savePlayer($c);
        if (!$this->darkOutside()) {
            return 'block.minecraft.bed.no_sleep';
        }
        if ($c->gameMode !== 1) {
            foreach ($this->animals as $mob) {
                $info = $this->version->catalog->mobs['entities'][$mob['kind']] ?? null;
                if ($info !== null && ($info['category'] ?? '') === 'monster' && !isset($mob['deadAt']) && $mob['kind'] !== 'zombified_piglin'
                    && abs($mob['x'] - ($x + 0.5)) <= 8.0 && abs($mob['y'] - $y) <= 5.0 && abs($mob['z'] - ($z + 0.5)) <= 8.0) {
                    return self::SLEEP_PROBLEMS['not_safe'];
                }
            }
        }
        // LivingEntity.startSleeping: the sleeping pose, at the bed, with its position.
        $c->sleepingPos = [$x, $y, $z];
        $c->sleepCounter = 0;
        [$c->x, $c->y, $c->z] = [$x + 0.5, $y + 0.6875, $z + 0.5];
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $this->setBedOccupied($x, $y, $z, true);
        $this->sendSleepData($c);
        if ((int) $this->gameRule('players_sleeping_percentage') > 100) {
            $this->overlay($c, ['translate' => 'sleep.not_possible']);
        }
        $this->updateSleepingPlayers();
        return null;
    }

    /** BedBlock.setBedOccupied on the head; the foot follows through AbstractBedBlock.updateShape. */
    private function setBedOccupied(int $x, int $y, int $z, bool $occupied): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $p = $catalog->properties($state);
        $value = $occupied ? 'true' : 'false';
        $this->setBlock($x, $y, $z, $catalog->state($catalog->blockName($state), array_merge($p, ['occupied' => $value])));
        [$fx, $fz] = $this->facingStep($p['facing']);
        $foot = $this->world->get($x - $fx, $y, $z - $fz);
        if ($catalog->blockName($foot) === $catalog->blockName($state) && $catalog->properties($foot)['part'] === 'foot') {
            $this->setBlock($x - $fx, $y, $z - $fz, $catalog->state($catalog->blockName($foot), array_merge($catalog->properties($foot), ['occupied' => $value])));
        }
    }

    /** The pose and sleeping position, to the sleeper and those tracking them. */
    private function sendSleepData(Client $c): void
    {
        $pose = $this->version->catalog->tracking['poses'][$c->sleepingPos === null ? 'standing' : 'sleeping'];
        $payload = Binary::writeVarInt($c->entityId) . "\x06" . Binary::writeVarInt(20) . Binary::writeVarInt($pose)
            . "\x0e" . Binary::writeVarInt(11) . ($c->sleepingPos === null ? "\0" : "\1" . Binary::writePosition(...$c->sleepingPos)) . "\xff";
        foreach ($this->players() as $player) {
            if ($player === $c || isset($player->entities[$c->entityId])) {
                $player->send($this->version, 'set_entity_data', $payload);
            }
        }
    }

    /**
     * ServerPlayer.stopSleepInBed: the wake-up animation, the bed freed, the player standing up
     * beside it facing it, and their position sent.
     */
    private function stopSleeping(Client $c, bool $forceful, bool $updateList): void
    {
        if ($c->sleepingPos === null) {
            return;
        }
        $animate = Binary::writeVarInt($c->entityId) . "\0";
        foreach ($this->players() as $player) {
            if ($player === $c || isset($player->entities[$c->entityId])) {
                $player->send($this->version, 'animate', $animate);
            }
        }
        [$x, $y, $z] = $c->sleepingPos;
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        if (str_ends_with($catalog->blockName($state), '_bed')) {
            $p = $catalog->properties($state);
            $this->setBedOccupied($x, $y, $z, false);
            $stand = $this->standUpPosition($x, $y, $z, $p['facing'], $c->yaw) ?? [$x + 0.5, $y + 1.1, $z + 0.5];
            $look = [$x + 0.5 - $stand[0], $z + 0.5 - $stand[2]];
            [$c->x, $c->y, $c->z] = $stand;
            $c->yaw = (float) self::wrapDegrees(atan2($look[1], $look[0]) * 180.0 / M_PI - 90.0);
            $c->pitch = 0.0;
        }
        $c->sleepingPos = null;
        $c->sleepCounter = $forceful ? 0 : 100;
        $this->sendSleepData($c);
        if ($updateList) {
            $this->updateSleepingPlayers();
        }
        $this->teleport($c, [$c->x, $c->y, $c->z]);
    }

    /**
     * AbstractBedBlock.findStandUpPosition: the first offset around and above the bed where
     * DismountHelper finds a safe floor the player fits on, preferring the side away from where
     * the player faces, then any such place even if dangerous.
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    private function standUpPosition(int $x, int $y, int $z, string $facing, float $yaw): ?array
    {
        $forward = $this->facingStep($facing);
        $right = [-$forward[1], $forward[0]];
        // Direction.isFacingAngle with Mth's sine table: the player faces the right-hand side.
        $radians = Float32::round(Float32::round($yaw) * Float32::round(M_PI / 180.0));
        $facesRight = Float32::round($right[0] * -Trees::mthSin($radians) + $right[1] * Trees::mthCos($radians)) > 0.0;
        $side = $facesRight ? [-$right[0], -$right[1]] : $right;
        $offsets = [
            $side, [$side[0] - $forward[0], $side[1] - $forward[1]], [$side[0] - $forward[0] * 2, $side[1] - $forward[1] * 2],
            [-$forward[0] * 2, -$forward[1] * 2], [-$side[0] - $forward[0] * 2, -$side[1] - $forward[1] * 2],
            [-$side[0] - $forward[0], -$side[1] - $forward[1]], [-$side[0], -$side[1]], [-$side[0] + $forward[0], -$side[1] + $forward[1]],
            $forward, [$side[0] + $forward[0], $side[1] + $forward[1]], [0, 0], [-$forward[0], -$forward[1]],
        ];
        foreach ([true, false] as $safe) {
            foreach ($offsets as [$dx, $dz]) {
                $position = $this->bedDismountLocation($x + $dx, $y, $z + $dz, $safe);
                if ($position !== null) {
                    return $position;
                }
            }
        }
        return null;
    }

    /** DismountHelper.findSafeDismountLocation for a player. */
    private function bedDismountLocation(int $x, int $y, int $z, bool $checkDangerous): ?array
    {
        $catalog = $this->version->catalog;
        $collision = new Collision($catalog);
        $dangerous = static fn (string $name): bool => in_array($name, ['fire', 'soul_fire', 'lava', 'magma_block', 'lava_cauldron', 'wither_rose', 'sweet_berry_bush', 'cactus', 'powder_snow'], true)
            || ($name === 'campfire' || $name === 'soul_campfire');
        $here = $this->world->get($x, $y, $z);
        if ($checkDangerous && $dangerous($catalog->blockName($here))) {
            return null;
        }
        $top = static fn (int $state): ?float => ($boxes = $collision->shape($state)) === [] || in_array($catalog->blockName($state), ['ladder', 'vine', 'scaffolding'], true) ? null : max(array_column($boxes, 4));
        $floor = $top($here);
        if ($floor === null) {
            $below = $top($this->world->get($x, $y - 1, $z));
            $floor = $below !== null && $below >= 1.0 ? $below - 1.0 : -INF;
        }
        if (!is_finite($floor) || $floor >= 1.0) {
            return null;
        }
        if ($checkDangerous && $floor <= 0.0 && $dangerous($catalog->blockName($this->world->get($x, $y - 1, $z)))) {
            return null;
        }
        $position = [$x + 0.5, $y + $floor, $z + 0.5];
        $box = [$position[0] - 0.3, $position[1], $position[2] - 0.3, $position[0] + 0.3, $position[1] + 1.8, $position[2] + 0.3];
        // CollisionGetter.getBlockCollisions: only shapes the box truly overlaps.
        foreach ($collision->boxes($this->world, $box) as $other) {
            if ($other[0] < $box[3] - 1.0E-7 && $other[3] > $box[0] + 1.0E-7 && $other[1] < $box[4] - 1.0E-7 && $other[4] > $box[1] + 1.0E-7 && $other[2] < $box[5] - 1.0E-7 && $other[5] > $box[2] + 1.0E-7) {
                return null;
            }
        }
        return $position;
    }

    /** SleepStatus.update and ServerLevel.announceSleepStatus. */
    private function updateSleepingPlayers(): void
    {
        $players = array_values(array_filter($this->localPlayers(), static fn (Client $p): bool => $p->gameMode !== 3));
        $active = count($players);
        $sleeping = count(array_filter($players, static fn (Client $p): bool => $p->sleepingPos !== null));
        [$oldActive, $oldSleeping] = $this->sleepStatus[$this->world->dimension] ?? [0, 0];
        $this->sleepStatus[$this->world->dimension] = [$active, $sleeping];
        if (!(($oldSleeping > 0 || $sleeping > 0) && ($oldActive !== $active || $oldSleeping !== $sleeping))) {
            return;
        }
        $percentage = (int) $this->gameRule('players_sleeping_percentage');
        if ($percentage > 100) {
            return;
        }
        $needed = max(1, (int) ceil($active * $percentage / 100.0));
        $message = $sleeping >= $needed ? ['translate' => 'sleep.skipping_night'] : ['translate' => 'sleep.players_sleeping', 'with' => [$sleeping, $needed]];
        foreach ($this->localPlayers() as $player) {
            $this->overlay($player, $message);
        }
    }

    /**
     * Player.tick while asleep, then ServerLevel.tick's check: once enough players have slept 100
     * ticks, the Overworld clock moves to the next morning, everyone wakes and rain stops.
     */
    private function tickSleep(): void
    {
        foreach ($this->localPlayers() as $player) {
            if ($player->sleepingPos === null) {
                continue;
            }
            $player->sleepCounter = min(100, $player->sleepCounter + 1);
            $state = $this->world->get(...$player->sleepingPos);
            if (!str_ends_with($this->version->catalog->blockName($state), '_bed') || !$this->darkOutside()) {
                $this->stopSleeping($player, false, true);
            }
        }
        if ($this->world->dimension !== 'overworld') {
            return;
        }
        $percentage = (int) $this->gameRule('players_sleeping_percentage');
        $players = array_values(array_filter($this->localPlayers(), static fn (Client $p): bool => $p->gameMode !== 3));
        $needed = max(1, (int) ceil(count($players) * $percentage / 100.0));
        $sleeping = count(array_filter($players, static fn (Client $p): bool => $p->sleepingPos !== null));
        $deep = count(array_filter($players, static fn (Client $p): bool => $p->sleepingPos !== null && $p->sleepCounter >= 100));
        if ($players === [] || $sleeping < $needed || $deep < $needed) {
            return;
        }
        if ($this->gameRule('advance_time') === 'true') {
            // ClockTimeMarker wake_up_from_sleep: the next tick 0 of the day.
            $until = -($this->dayTime % 24000);
            $this->setClockTicks('minecraft:overworld', $this->dayTime + ($until > 0 ? $until : 24000 + $until));
        }
        $this->sleepStatus[$this->world->dimension] = [count($players), 0];
        foreach ($players as $player) {
            if ($player->sleepingPos !== null) {
                $this->stopSleeping($player, false, false);
            }
        }
        if ($this->gameRule('advance_weather') === 'true' && $this->weather !== 'clear') {
            $this->weather = 'clear';
            foreach ($this->players() as $player) {
                $this->sendWeather($player);
            }
        }
    }
}
