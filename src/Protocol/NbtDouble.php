<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Protocol;

/** A Java double translation argument, written as a double tag (PHP floats otherwise encode as Java floats). */
final class NbtDouble
{
    public function __construct(public readonly float $value)
    {
    }
}
