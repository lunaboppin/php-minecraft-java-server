<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Pushing\Eligibility;

use PhpMinecraftJavaServer\World\EntityPushEligibility;

final class WardenEligibility implements EligibilityRule
{
    public function test(array &$state, EntityPushEligibility $shared, int $feet, int $below): bool
    {
        return !in_array($state['pose'], ['digging', 'emerging'], true) && $shared->pushable($state, $feet, $below);
    }
}
