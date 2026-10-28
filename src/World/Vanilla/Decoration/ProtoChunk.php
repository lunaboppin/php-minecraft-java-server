<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

/** A chunk between carving and decoration: block state ids in y, z, x order and quart biomes in y, z, x order. */
final class ProtoChunk
{
    /**
     * @param list<int> $blocks
     * @param list<string> $biomes
     */
    public function __construct(public readonly int $x, public readonly int $z, public array $blocks, public readonly array $biomes)
    {
    }
}
