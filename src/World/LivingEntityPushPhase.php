<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

/** Shared impulse iteration; cramming, subclass dispatch and scheduling are external. */
final class LivingEntityPushPhase
{
    private readonly EntitySectionIndex $index;

    private array $entities = [];

    public function __construct()
    {
        $this->index = new EntitySectionIndex();
    }

    public function upsert(int $id, array $state): void
    {
        $this->entities[$id] = $state;
        $this->index->upsert($id, $state['position'], $state['bounds']);
    }

    public function remove(int $id): void
    {
        unset($this->entities[$id]);
        $this->index->remove($id);
    }

    public function state(int $id): array
    {
        return $this->entities[$id] ?? throw new \InvalidArgumentException('Unknown pushing entity');
    }

    public function setAccessible(array $section, bool $accessible): void
    {
        $this->index->setAccessible($section, $accessible);
    }

    public function pushNeighbors(int $id): void
    {
        $caller = &$this->entities[$id];
        foreach ($this->index->query($caller['bounds']) as $otherId) {
            if ($otherId === $id) {
                continue;
            }
            $other = &$this->entities[$otherId];
            $sourceTeam = $caller['team'] ?? null;
            $otherTeam = $other['team'] ?? null;
            if (!EntityPushing::eligible(
                $other['pushable'],
                $other['spectator'],
                $caller['collisionRule'] ?? 'ALWAYS',
                $other['collisionRule'] ?? 'ALWAYS',
                $sourceTeam !== null && $sourceTeam === $otherTeam
            )) {
                continue;
            }
            $sameVehicle = ($caller['rootVehicle'] ?? $id) === ($other['rootVehicle'] ?? $otherId);
            EntityPushing::doPush($caller, $other, $sameVehicle);
        }
    }
}
