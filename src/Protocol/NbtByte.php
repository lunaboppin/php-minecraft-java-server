<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Protocol;

/** A data component's byte field in a hover, written as a byte tag (PHP ints otherwise encode as Java ints). */
final class NbtByte
{
    public function __construct(public readonly int $value)
    {
    }
}
