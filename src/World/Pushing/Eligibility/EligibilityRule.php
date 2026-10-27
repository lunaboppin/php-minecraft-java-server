<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Pushing\Eligibility;

use PhpMinecraftJavaServer\World\EntityPushEligibility;

interface EligibilityRule
{
    public function test(array &$state, EntityPushEligibility $shared, int $feet, int $below): bool;
}
