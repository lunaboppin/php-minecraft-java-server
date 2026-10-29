<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;

/** What structure pieces share while they are placed: block states, transforms and shape marks. */
final class StructureContext
{
    public readonly BlockTransform $transform;

    /** @var array<string, int> */
    private array $states = [];

    /** @var array<string, array{0: int, 1: int, 2: int}> ProtoChunk.markPosForPostProcessing, by "x,y,z" */
    public array $postProcessing = [];

    public function __construct(public readonly Blocks $blocks)
    {
        $this->transform = new BlockTransform($blocks);
    }

    /** @param array<string, string> $properties */
    public function state(string $name, array $properties = []): int
    {
        $key = $name . json_encode($properties);
        return $this->states[$key] ??= $this->blocks->state($name, $properties);
    }

    public function markForPostProcessing(int $x, int $y, int $z): void
    {
        $this->postProcessing["$x,$y,$z"] = [$x, $y, $z];
    }
}
