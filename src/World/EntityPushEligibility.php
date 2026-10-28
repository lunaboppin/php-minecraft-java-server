<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

/** Shared native climbing and LivingEntity pushability, using actual block tags. */
final class EntityPushEligibility
{
    private readonly array $climbable;

    private readonly array $glideThrough;

    public function __construct(private readonly array $rules)
    {
        $this->climbable = array_fill_keys($rules['climbable_states'], true);
        $this->glideThrough = array_fill_keys($rules['glide_through_states'], true);
    }

    public function pushable(array &$state, int $feet, int $below): bool
    {
        if (!$state['alwaysTicking'] && !$state['ticking']) {
            return false;
        }
        return $state['alive'] && !$state['spectator'] && !$this->onClimbable($state, $feet, $below);
    }

    public function onClimbable(array &$state, int $feet, int $below): bool
    {
        if ($state['spectator'] || ($state['fallFlying'] && isset($this->glideThrough[$feet]))) {
            return false;
        }
        $trapdoor = $this->rules['trapdoors'][$feet] ?? null;
        $ladder = $this->rules['ladders'][$below] ?? null;
        $climbing = isset($this->climbable[$feet])
            || ($trapdoor !== null && $trapdoor['open'] && $ladder === $trapdoor['facing']);
        if ($climbing) {
            $state['lastClimbablePos'] = array_map(static fn (float $coordinate): int => (int) floor($coordinate), $state['position']);
        }
        return $climbing;
    }

    public function playerDimensions(string $pose): array
    {
        return $this->rules['player_dimensions'][$pose] ?? throw new \InvalidArgumentException('Unknown native player pose');
    }
}
