<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

/**
 * TagParser for command arguments: one SNBT value at the reader's cursor. Values keep their
 * tag type as ['type' => ..., 'value' => ...] so codecs can tell strings from numbers.
 */
final class Snbt
{
    private const NUMBER_SUFFIXES = ['b' => 'byte', 's' => 'short', 'l' => 'long', 'f' => 'float', 'd' => 'double'];

    /** @return array{type: string, value: mixed} */
    public static function read(StringReader $reader): array
    {
        $reader->skipWhitespace();
        if (!$reader->canRead()) {
            throw $reader->error('parsing.expected', ['value']);
        }
        $char = $reader->peek();
        if ($char === '{') {
            return self::compound($reader);
        }
        if ($char === '[') {
            return self::listOrArray($reader);
        }
        if ($char === '"' || $char === "'") {
            return ['type' => 'string', 'value' => $reader->readQuotedString()];
        }
        return self::scalar($reader);
    }

    private static function compound(StringReader $reader): array
    {
        $reader->skip();
        $entries = [];
        $reader->skipWhitespace();
        while ($reader->canRead() && $reader->peek() !== '}') {
            $reader->skipWhitespace();
            $key = $reader->canRead() && ($reader->peek() === '"' || $reader->peek() === "'") ? $reader->readQuotedString() : $reader->readUnquotedString();
            if ($key === '') {
                throw $reader->error('parsing.expected', ['key']);
            }
            $reader->skipWhitespace();
            self::expect($reader, ':');
            $entries[$key] = self::read($reader);
            $reader->skipWhitespace();
            if (!$reader->canRead() || $reader->peek() !== ',') {
                break;
            }
            $reader->skip();
            $reader->skipWhitespace();
        }
        $reader->skipWhitespace();
        self::expect($reader, '}', ',');
        return ['type' => 'compound', 'value' => $entries];
    }

    private static function listOrArray(StringReader $reader): array
    {
        $reader->skip();
        if ($reader->canRead(2) && in_array($reader->peek(), ['B', 'I', 'L'], true) && $reader->peek(1) === ';') {
            $type = ['B' => 'byte_array', 'I' => 'int_array', 'L' => 'long_array'][$reader->peek()];
            $reader->skip();
            $reader->skip();
        } else {
            $type = 'list';
        }
        $values = [];
        $reader->skipWhitespace();
        while ($reader->canRead() && $reader->peek() !== ']') {
            $values[] = self::read($reader);
            $reader->skipWhitespace();
            if (!$reader->canRead() || $reader->peek() !== ',') {
                break;
            }
            $reader->skip();
            $reader->skipWhitespace();
        }
        $reader->skipWhitespace();
        self::expect($reader, ']', ',');
        return ['type' => $type, 'value' => $values];
    }

    /** Unquoted text: booleans, numbers with an optional type suffix, or a plain string. */
    private static function scalar(StringReader $reader): array
    {
        $start = $reader->cursor;
        $text = $reader->readUnquotedString();
        if ($text === '') {
            $reader->cursor = $start;
            throw $reader->error('parsing.expected', ['value']);
        }
        if ($text === 'true' || $text === 'false') {
            return ['type' => 'byte', 'value' => $text === 'true' ? 1 : 0];
        }
        $lower = strtolower($text);
        $suffix = self::NUMBER_SUFFIXES[substr($lower, -1)] ?? null;
        $number = $suffix === null ? $text : substr($text, 0, -1);
        if (preg_match('/\A[+-]?\d+\z/D', $number) === 1 && $suffix !== 'float' && $suffix !== 'double') {
            return ['type' => $suffix ?? 'int', 'value' => (int) $number];
        }
        if (preg_match('/\A[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?\z/Di', $number) === 1 && ($suffix === null || $suffix === 'float' || $suffix === 'double')) {
            return ['type' => $suffix ?? 'double', 'value' => (float) $number];
        }
        return ['type' => 'string', 'value' => $text];
    }

    /** The grammar's literal: missing at the cursor, reported as the first literal that would continue. */
    private static function expect(StringReader $reader, string $char, ?string $reported = null): void
    {
        if (!$reader->canRead() || $reader->peek() !== $char) {
            throw $reader->error('argument.literal.incorrect', [$reported ?? $char]);
        }
        $reader->skip();
    }

    /** Tag.toString, as codec errors quote the value. */
    public static function toText(array $tag): string
    {
        return match ($tag['type']) {
            'string' => '"' . addcslashes($tag['value'], '"\\') . '"',
            'byte' => $tag['value'] . 'b',
            'short' => $tag['value'] . 's',
            'long' => $tag['value'] . 'L',
            'float' => self::decimal($tag['value']) . 'f',
            'double' => self::decimal($tag['value']) . 'd',
            'int' => (string) $tag['value'],
            'list' => '[' . implode(',', array_map(self::toText(...), $tag['value'])) . ']',
            'compound' => '{' . implode(',', array_map(static fn (string $key, array $value): string => (preg_match('/\A[0-9A-Za-z_\-.+]+\z/D', $key) === 1 ? $key : '"' . addcslashes($key, '"\\') . '"') . ':' . self::toText($value), array_keys($tag['value']), $tag['value'])) . '}',
            default => '[' . strtoupper($tag['type'][0]) . ';' . implode(',', $tag['value']) . ']',
        };
    }

    private static function decimal(float $value): string
    {
        $text = (string) $value;
        return str_contains($text, '.') || str_contains($text, 'E') ? $text : $text . '.0';
    }
}
