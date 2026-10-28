<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

/** The official minecraft:overworld noise settings with their seeded RandomState. */
final class OverworldSettings
{
    public readonly array $json;
    public readonly RandomState $state;
    public readonly int $minY;
    public readonly int $height;
    public readonly int $seaLevel;

    public function __construct(int $seed, public readonly string $directory)
    {
        $this->json = json_decode((string) file_get_contents($directory . '/noise_settings/overworld.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->state = new RandomState($seed, $directory, $this->json['legacy_random_source']);
        $this->minY = $this->json['noise']['min_y'];
        $this->height = $this->json['noise']['height'];
        $this->seaLevel = $this->json['sea_level'];
    }
}
