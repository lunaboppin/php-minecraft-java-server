<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

/** Preserves the existing argument syntax; full Brigadier parsing remains in #10. */
final class CommandArguments
{
    // Whitespace separates arguments, except inside a selector's brackets or quotes.
    private const TOKEN = '/(?:[^\s\[\]"\']|\[(?:[^\]"\']|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')*\]|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')+|[\[\]"\']/';

    /** @return array{string, list<string>} */
    public static function parse(string $text): array
    {
        preg_match_all(self::TOKEN, trim($text), $matches);
        $arguments = $matches[0] === [] ? [''] : $matches[0];
        $name = strtolower(array_shift($arguments) ?? '');

        return [$name, $arguments];
    }

    /**
     * The verbatim text after the command name and the given number of arguments, as a greedy
     * message argument reads it: everything after the single separating space.
     */
    public static function rest(string $text, int $skip): string
    {
        $text = ltrim($text);
        preg_match_all(self::TOKEN, $text, $matches, PREG_OFFSET_CAPTURE);
        $token = $matches[0][$skip] ?? null;
        return $token === null ? '' : (string) substr($text, $token[1] + strlen($token[0]) + 1);
    }
}
