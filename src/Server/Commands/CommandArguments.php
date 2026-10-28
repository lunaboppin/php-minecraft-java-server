<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

/** Preserves the existing argument syntax; full Brigadier parsing remains in #10. */
final class CommandArguments
{
    /** @return array{string, list<string>} */
    public static function parse(string $text): array
    {
        // Whitespace separates arguments, except inside a selector's brackets or quotes.
        preg_match_all('/(?:[^\s\[\]"\']|\[(?:[^\]"\']|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')*\]|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')+|[\[\]"\']/', trim($text), $matches);
        $arguments = $matches[0] === [] ? [''] : $matches[0];
        $name = strtolower(array_shift($arguments) ?? '');

        return [$name, $arguments];
    }
}
