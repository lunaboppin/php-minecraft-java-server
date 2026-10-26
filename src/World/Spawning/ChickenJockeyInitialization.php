<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Spawning;

use PhpMinecraftJavaServer\World\Generation\RandomSource;

/** Fresh jockey chickens use their native variant and shared initialization draws. */
final class ChickenJockeyInitialization
{
    public static function initialize(
        SpawnAttributes $attributes,
        RandomSource $worldRandom,
        array $visualCandidates,
        array $soundVariants
    ): array {
        if ($visualCandidates === [] || $soundVariants === []) {
            throw new \InvalidArgumentException('Vanilla chicken variant candidates must be available');
        }
        $variant = $visualCandidates[$worldRandom->nextInt(count($visualCandidates))];
        $soundVariant = $soundVariants[$worldRandom->nextInt(count($soundVariants))];
        $leftHanded = MobInitialization::initialize($attributes, $worldRandom);
        return [
            'variant' => $variant,
            'soundVariant' => $soundVariant,
            'leftHanded' => $leftHanded,
            'baby' => false,
            'chickenJockey' => true,
        ];
    }
}
