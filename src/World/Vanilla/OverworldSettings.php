<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

/** Official noise settings (minecraft:overworld by default) with their seeded RandomState. */
final class OverworldSettings
{
    public readonly array $json;

    public readonly RandomState $state;

    public readonly int $minY;

    public readonly int $height;

    public readonly int $seaLevel;

    /** Block names without namespace or properties. */
    public readonly string $defaultBlock;

    public readonly string $defaultFluid;

    public function __construct(int $seed, public readonly string $directory, public readonly string $name = 'overworld')
    {
        $this->json = json_decode((string) file_get_contents($directory . '/noise_settings/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->state = new RandomState($seed, $directory, $this->json['legacy_random_source']);
        $this->minY = $this->json['noise']['min_y'];
        $this->height = $this->json['noise']['height'];
        $this->seaLevel = $this->json['sea_level'];
        $this->defaultBlock = preg_replace('/^minecraft:/', '', is_array($this->json['default_block']) ? $this->json['default_block']['Name'] : $this->json['default_block']);
        $this->defaultFluid = preg_replace('/^minecraft:/', '', is_array($this->json['default_fluid']) ? $this->json['default_fluid']['Name'] : $this->json['default_fluid']);
    }
}
