<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

/** Preserves the existing argument syntax; full Brigadier parsing remains in #10. */
final class CommandArguments
{
    /** @return array{string, list<string>} */
    public static function parse(string $text): array
    {
        $arguments = preg_split('/\s+/', trim($text));
        $name = strtolower(array_shift($arguments) ?? '');

        return [$name, $arguments];
    }
}
