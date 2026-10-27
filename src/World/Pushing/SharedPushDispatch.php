<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Pushing;

use PhpMinecraftJavaServer\World\EntityPushing;

/** Shared impulse dispatch and simple overrides; additional contact effects are separate. */
final class SharedPushDispatch
{
    public static function supportsSource(string $owner): bool
    {
        return in_array($owner, [
            'net.minecraft.world.entity.LivingEntity',
            'net.minecraft.world.entity.animal.parrot.Parrot',
        ], true);
    }

    public static function contact(array &$caller, array &$other, bool $sameVehicle): void
    {
        if ($caller['doPushOwner'] === 'net.minecraft.world.entity.animal.parrot.Parrot' && $other['kind'] === 'player') {
            return;
        }
        if ($other['pushOwner'] === 'net.minecraft.world.entity.monster.Shulker') {
            return;
        }
        // AbstractCubeMob adds an iron-golem damage interaction. The golem's
        // own contact controller must supply that interaction before this path.
        if ($other['pushOwner'] === 'net.minecraft.world.entity.monster.cubemob.AbstractCubeMob' && $caller['kind'] === 'iron_golem') {
            throw new \LogicException('Cube/golem contact requires the species damage controller');
        }
        EntityPushing::doPush($caller, $other, $sameVehicle);
    }
}
