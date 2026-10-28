<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Protocol;

/** A Java long translation argument, written as a long tag (PHP ints otherwise encode as Java ints). */
final class NbtLong
{
    public function __construct(public readonly int $value)
    {
    }
}
