<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Server\Pushing\WorldPushContext;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Pushing\SharedPushDispatch;

/** Live adapter for shared contact impulses; special contact controllers remain separate. */
trait MobPushing
{
    /** @var array<string, WorldPushContext> */
    private array $pushingWorlds = [];

    private function pushingContext(): WorldPushContext
    {
        return $this->pushingWorlds[$this->world->dimension] ??= new WorldPushContext();
    }

    private function mobIsTicking(array $mob): bool
    {
        // Use the current entity-ticking decision consistently. Replacing this
        // distance scheduler with native chunk ticket/visibility state is #8.
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $mob['x']) ** 2 + ($player->z - $mob['z']) ** 2 < 80 ** 2) {
                return true;
            }
        }
        return false;
    }

    private function pushingState(array $entity, string $kind, array $dimensions): array
    {
        $profile = $this->version->catalog->pushing[$kind];
        $state = [
            'position' => [$entity['x'], $entity['y'], $entity['z']],
            'bounds' => Collision::bounds($entity['x'], $entity['y'], $entity['z'], ...$dimensions),
            'velocity' => $entity['velocity'] ?? [0.0, 0.0, 0.0],
            'needsSync' => $entity['needsSync'] ?? false,
            'alive' => $entity['alive'],
            'spectator' => $entity['spectator'] ?? false,
            'fallFlying' => $entity['fallFlying'] ?? false,
            'ticking' => $entity['ticking'],
            'alwaysTicking' => $kind === 'player',
            'vehicle' => ($entity['passengers'] ?? []) !== [],
            'rootVehicle' => $entity['rootVehicle'] ?? $entity['id'],
            'sleeping' => ($entity['pose'] ?? 'standing') === 'sleeping',
            'noPhysics' => $entity['noPhysics'] ?? false,
            'pose' => $entity['pose'] ?? 'standing',
            'canMove' => $entity['canMove'] ?? true,
            'lastClimbablePos' => $entity['lastClimbablePos'] ?? null,
            'velocityMode' => $kind === 'player' ? 'normal' : $this->version->catalog->movement['profiles'][$kind]['velocity'],
            'kind' => $kind,
            'pushOwner' => $profile['push'],
            'doPushOwner' => $profile['doPush'],
            'team' => $entity['team'] ?? null,
            'collisionRule' => $entity['collisionRule'] ?? 'ALWAYS',
        ];
        // Eligibility consults blocks only after its ticking, alive and spectator checks.
        // Skipping the reads otherwise keeps idle saved mobs from generating far terrain.
        $feet = 0;
        $below = 0;
        if (($state['ticking'] || $state['alwaysTicking']) && $state['alive'] && !$state['spectator']) {
            [$x, $y, $z] = array_map(static fn (float $coordinate): int => (int) floor($coordinate), $state['position']);
            $feet = $this->world->get($x, $y, $z);
            $below = $this->world->get($x, $y - 1, $z);
        }
        $state['pushable'] = $this->version->catalog->pushEligibilityRegistry->test($profile['pushable'], $state, $feet, $below);
        return $state;
    }

    private function trackPushingMob(array &$mob): void
    {
        $definition = $this->version->catalog->mobs['entities'][$mob['kind']];
        $entity = $mob;
        $entity['alive'] = !isset($mob['deadAt']) && $mob['health'] > 0;
        $entity['ticking'] = $this->mobIsTicking($mob);
        $state = $this->pushingState($entity, $mob['kind'], [$definition['width'], $definition['height']]);
        $context = $this->pushingContext();
        $context->phase->upsert($mob['id'], $state);
        $context->members[$mob['id']] = 'mob';
        $mob['lastClimbablePos'] = $state['lastClimbablePos'];
    }

    private function trackPushingPlayer(Client $player): void
    {
        if ($player->entityId === 0) {
            return;
        }
        if ($player->dimension !== $this->world->dimension) {
            $this->withDimension($player->dimension, fn () => $this->trackPushingPlayer($player));
            return;
        }
        $pose = $player->dead ? 'dying' : ($player->sneaking && !$player->flying && $player->gameMode !== 3 ? 'crouching' : 'standing');
        $state = $this->pushingState([
            'id' => $player->entityId, 'x' => $player->x, 'y' => $player->y, 'z' => $player->z,
            'alive' => !$player->dead && $player->health > 0, 'spectator' => $player->gameMode === 3,
            'noPhysics' => $player->gameMode === 3, 'pose' => $pose, 'ticking' => true,
        ], 'player', $this->version->catalog->pushEligibility->playerDimensions($pose));
        $context = $this->pushingContext();
        $context->phase->upsert($player->entityId, $state);
        $context->members[$player->entityId] = 'player';
    }

    private function preparePushingWorld(): void
    {
        $context = $this->pushingContext();
        $members = array_fill_keys(array_keys($this->animals), 'mob');
        foreach ($this->localPlayers() as $player) {
            $members[$player->entityId] = 'player';
        }
        foreach ($context->members as $id => $_) {
            if (!isset($members[$id])) {
                $context->phase->remove($id);
                unset($context->members[$id]);
            }
        }
        // IDs preserve creation order when bootstrapping an existing world.
        uksort($members, static fn (int $first, int $second): int => $first <=> $second);
        $players = [];
        foreach ($this->localPlayers() as $player) {
            $players[$player->entityId] = $player;
        }
        foreach ($members as $id => $type) {
            if ($type === 'mob') {
                $this->trackPushingMob($this->animals[$id]);
            } else {
                $this->trackPushingPlayer($players[$id]);
            }
        }
    }

    private function applyPushingContacts(int $id): void
    {
        $context = $this->pushingContext();
        foreach ($context->phase->pushNeighbors($id, SharedPushDispatch::contact(...)) as $touched) {
            if (($context->members[$touched] ?? null) === 'mob' && isset($this->animals[$touched])) {
                $state = $context->phase->state($touched);
                $this->animals[$touched]['velocity'] = $state['velocity'];
                $this->animals[$touched]['needsSync'] = $state['needsSync'];
            }
        }
    }

    private function pushAfterMobTravel(array &$mob): void
    {
        $this->trackPushingMob($mob);
        $this->crammingDamage($mob);
        if (SharedPushDispatch::supportsSource($this->version->catalog->pushing[$mob['kind']]['doPush'])) {
            $this->applyPushingContacts($mob['id']);
        }
    }

    private function tickPlayerPushing(Client $player): void
    {
        $this->trackPushingPlayer($player);
        if ($this->crammed($player->entityId, static fn (): int => random_int(0, 3))) {
            $this->damage($player, 6.0, 'minecraft:cramming');
        }
        $this->applyPushingContacts($player->entityId);
    }

    private function crammingDamage(array $mob): void
    {
        if ($this->crammed($mob['id'], fn (): int => $this->mobRandom($mob['id'])->nextInt(4))) {
            $this->hurtMob($mob['id'], 6.0, 'minecraft:cramming');
        }
    }

    /**
     * LivingEntity.pushEntities: with more pushable entities around than max_entity_cramming allows,
     * one time in four, counting only those riding nothing, the entity is crammed.
     */
    private function crammed(int $id, \Closure $roll): bool
    {
        $limit = (int) $this->gameRule('max_entity_cramming');
        if ($limit <= 0) {
            return false;
        }
        [$total, $unmounted] = $this->pushingContext()->phase->pushableCount($id);
        return $total > $limit - 1 && $roll() === 0 && $unmounted > $limit - 1;
    }

    private function forgetPushingEntity(int $id, ?string $dimension = null): void
    {
        $context = $this->pushingWorlds[$dimension ?? $this->world->dimension] ?? null;
        if ($context !== null) {
            $context->phase->remove($id);
            unset($context->members[$id]);
        }
    }
}
