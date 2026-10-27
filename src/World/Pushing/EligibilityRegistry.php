<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Pushing;

use PhpMinecraftJavaServer\World\EntityPushEligibility;
use PhpMinecraftJavaServer\World\Pushing\Eligibility\BatEligibility;
use PhpMinecraftJavaServer\World\Pushing\Eligibility\CreakingEligibility;
use PhpMinecraftJavaServer\World\Pushing\Eligibility\HorseEligibility;
use PhpMinecraftJavaServer\World\Pushing\Eligibility\LivingEligibility;
use PhpMinecraftJavaServer\World\Pushing\Eligibility\ParrotEligibility;
use PhpMinecraftJavaServer\World\Pushing\Eligibility\WardenEligibility;

final class EligibilityRegistry
{
    private const OWNERS = [
        'net.minecraft.world.entity.LivingEntity' => LivingEligibility::class,
        'net.minecraft.world.entity.animal.equine.AbstractHorse' => HorseEligibility::class,
        'net.minecraft.world.entity.ambient.Bat' => BatEligibility::class,
        'net.minecraft.world.entity.animal.parrot.Parrot' => ParrotEligibility::class,
        'net.minecraft.world.entity.monster.creaking.Creaking' => CreakingEligibility::class,
        'net.minecraft.world.entity.monster.warden.Warden' => WardenEligibility::class,
    ];

    private array $instances = [];

    public function __construct(private readonly EntityPushEligibility $shared)
    {
    }

    public function test(string $owner, array &$state, int $feet, int $below): bool
    {
        $class = self::OWNERS[$owner] ?? throw new \LogicException('Unimplemented native pushability owner: ' . $owner);
        $handler = $this->instances[$class] ??= new $class();
        return $handler->test($state, $this->shared, $feet, $below);
    }
}
