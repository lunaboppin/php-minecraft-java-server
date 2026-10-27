<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

/** Native LivingEntity/Entity pushing, separate from collision-query and tick order. */
final class EntityPushing
{
    private const COLLISION_RULES = ['ALWAYS', 'NEVER', 'PUSH_OWN_TEAM', 'PUSH_OTHER_TEAMS'];

    /**
     * Each state supplies position, velocity, needsSync, pushable, vehicle,
     * sleeping and noPhysics. Positions and unrelated flags remain unchanged.
     */
    public static function push(array &$source, array &$other, bool $sameVehicle): void
    {
        if ($source['sleeping'] || $sameVehicle || $source['noPhysics'] || $other['noPhysics']) {
            return;
        }
        $deltaX = $other['position'][0] - $source['position'][0];
        $deltaZ = $other['position'][2] - $source['position'][2];
        $absoluteX = abs($deltaX);
        $absoluteZ = abs($deltaZ);
        // Java Math.max propagates NaN regardless of its argument position.
        $maximum = is_nan($absoluteX) || is_nan($absoluteZ) ? NAN : max($absoluteX, $absoluteZ);
        if ($maximum < 0.009999999776482582) {
            return;
        }
        $distance = sqrt($maximum);
        $forceX = $deltaX / $distance;
        $forceZ = $deltaZ / $distance;
        $inverse = 1.0 / $distance;
        if ($inverse > 1.0) {
            $inverse = 1.0;
        }
        $forceX *= $inverse;
        $forceZ *= $inverse;
        $forceX *= 0.05000000074505806;
        $forceZ *= 0.05000000074505806;
        if (!$source['vehicle'] && $source['pushable']) {
            self::addImpulse($source, [-$forceX, 0.0, -$forceZ]);
        }
        if (!$other['vehicle'] && $other['pushable']) {
            self::addImpulse($other, [$forceX, 0.0, $forceZ]);
        }
    }

    /** LivingEntity.doPush invokes the other entity's virtual push method. */
    public static function doPush(array &$caller, array &$other, bool $sameVehicle): void
    {
        self::push($other, $caller, $sameVehicle);
    }

    public static function eligible(
        bool $pushable,
        bool $spectator,
        string $sourceRule,
        string $otherRule,
        bool $allied
    ): bool {
        if (!in_array($sourceRule, self::COLLISION_RULES, true) || !in_array($otherRule, self::COLLISION_RULES, true)) {
            throw new \InvalidArgumentException('Unknown team collision rule');
        }
        if (!$pushable || $spectator || $sourceRule === 'NEVER' || $otherRule === 'NEVER') {
            return false;
        }
        if (($sourceRule === 'PUSH_OWN_TEAM' || $otherRule === 'PUSH_OWN_TEAM') && $allied) {
            return false;
        }
        if (($sourceRule === 'PUSH_OTHER_TEAMS' || $otherRule === 'PUSH_OTHER_TEAMS') && !$allied) {
            return false;
        }
        return true;
    }

    /** Strict native AABB intersection; touching faces do not overlap. */
    public static function intersects(array $first, array $second): bool
    {
        return $first[0] < $second[3] && $first[3] > $second[0]
            && $first[1] < $second[4] && $first[4] > $second[1]
            && $first[2] < $second[5] && $first[5] > $second[2];
    }

    private static function addImpulse(array &$entity, array $impulse): void
    {
        foreach ($impulse as $component) {
            if (!is_finite($component)) {
                return;
            }
        }
        if (($entity['velocityMode'] ?? 'normal') !== 'zero') {
            foreach ($impulse as $axis => $component) {
                $entity['velocity'][$axis] += $component;
            }
        }
        $entity['needsSync'] = true;
    }
}
