<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

/** LevelReader.getBlockState: what survival checks read, from a decoration region or the live world. */
interface BlockReader
{
    public function get(int $x, int $y, int $z): int;
}
