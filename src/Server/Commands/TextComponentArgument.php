<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

/**
 * ComponentArgument: an SNBT value decoded as ComponentSerialization.CODEC does (a string, a
 * list whose first entry carries the rest as siblings, or a compound with one kind of content,
 * style fields and extra components).
 */
final class TextComponentArgument
{
    private const CONTENT = ['text', 'translate', 'selector', 'score', 'keybind', 'nbt', 'object'];

    private const FLAGS = ['bold', 'italic', 'underlined', 'strikethrough', 'obfuscated'];

    public static function read(StringReader $reader): array
    {
        $start = $reader->cursor;
        $tag = Snbt::read($reader);
        $component = self::decode($tag);
        if (is_string($component)) {
            $reader->cursor = $start;
            throw $reader->error('argument.component.invalid', [$component]);
        }
        return $component;
    }

    /** @return array|string the component, or DataFixerUpper's error message */
    public static function decode(array $tag): array|string
    {
        $either = static fn (string $second): string => 'Failed to parse either. First: Failed to parse either. First: Not a string; Second: Not a list: ' . Snbt::toText($tag) . '; Second: ' . $second;
        return match ($tag['type']) {
            'string' => ['text' => $tag['value']],
            'list' => self::decodeList($tag) ?? $either('Not a map: ' . Snbt::toText($tag)),
            'compound' => self::decodeCompound($tag['value']) ?? $either('No matching codec found'),
            default => $either('Not a map: ' . Snbt::toText($tag)),
        };
    }

    private static function decodeList(array $tag): ?array
    {
        $components = [];
        foreach ($tag['value'] as $element) {
            $component = self::decode($element);
            if (is_string($component)) {
                return null;
            }
            $components[] = $component;
        }
        if ($components === []) {
            return null;
        }
        // ComponentSerialization.createFromList: the first component, with the others appended.
        $first = array_shift($components);
        if ($components !== []) {
            $first['extra'] = [...($first['extra'] ?? []), ...$components];
        }
        return $first;
    }

    /** @param array<string, array> $fields */
    private static function decodeCompound(array $fields): ?array
    {
        $component = [];
        $content = null;
        foreach (self::CONTENT as $key) {
            if (isset($fields[$key])) {
                $content = $key;
                break;
            }
        }
        switch ($content) {
            case 'text':
            case 'keybind':
            case 'selector':
                if ($fields[$content]['type'] !== 'string') {
                    return null;
                }
                $component[$content] = $fields[$content]['value'];
                if ($content === 'selector' && isset($fields['separator'])) {
                    $separator = self::decode($fields['separator']);
                    if (is_string($separator)) {
                        return null;
                    }
                    $component['separator'] = $separator;
                }
                break;
            case 'translate':
                if ($fields['translate']['type'] !== 'string') {
                    return null;
                }
                $component['translate'] = $fields['translate']['value'];
                if (isset($fields['fallback'])) {
                    $component['fallback'] = self::plain($fields['fallback']);
                }
                if (isset($fields['with'])) {
                    if ($fields['with']['type'] !== 'list') {
                        return null;
                    }
                    $component['with'] = [];
                    foreach ($fields['with']['value'] as $argument) {
                        // TranslatableContents arguments: numbers and booleans stay primitives.
                        $value = in_array($argument['type'], ['byte', 'short', 'int', 'long', 'float', 'double'], true) ? $argument['value'] : self::decode($argument);
                        if (is_string($value)) {
                            return null;
                        }
                        $component['with'][] = $value;
                    }
                }
                break;
            case 'score':
                if ($fields['score']['type'] !== 'compound') {
                    return null;
                }
                $component['score'] = self::plain($fields['score']);
                break;
            case 'nbt':
                $component['nbt'] = self::plain($fields['nbt']);
                break;
            default:
                return null;
        }
        foreach (self::FLAGS as $flag) {
            if (isset($fields[$flag])) {
                if ($fields[$flag]['type'] !== 'byte') {
                    return null;
                }
                $component[$flag] = $fields[$flag]['value'] !== 0;
            }
        }
        foreach (['color', 'font', 'insertion'] as $key) {
            if (isset($fields[$key])) {
                if ($fields[$key]['type'] !== 'string') {
                    return null;
                }
                $component[$key] = $fields[$key]['value'];
            }
        }
        if (isset($fields['shadow_color'])) {
            $component['shadow_color'] = self::plain($fields['shadow_color']);
        }
        if (isset($fields['click_event'])) {
            $component['click_event'] = self::plain($fields['click_event']);
        }
        if (isset($fields['hover_event'])) {
            $hover = self::plain($fields['hover_event']);
            if (($hover['action'] ?? null) === 'show_text' && isset($fields['hover_event']['value']['value'])) {
                $value = self::decode($fields['hover_event']['value']['value']);
                if (is_string($value)) {
                    return null;
                }
                $hover['value'] = $value;
            }
            $component['hover_event'] = $hover;
        }
        if (isset($fields['extra'])) {
            if ($fields['extra']['type'] !== 'list' || $fields['extra']['value'] === []) {
                return null;
            }
            $component['extra'] = [];
            foreach ($fields['extra']['value'] as $sibling) {
                $decoded = self::decode($sibling);
                if (is_string($decoded)) {
                    return null;
                }
                $component['extra'][] = $decoded;
            }
        }
        return $component;
    }

    /** A tag as plain PHP values, for event and score fields passed through unchanged. */
    private static function plain(array $tag): mixed
    {
        return match ($tag['type']) {
            'compound' => array_map(self::plain(...), $tag['value']),
            'list', 'byte_array', 'int_array', 'long_array' => $tag['type'] === 'list' ? array_map(self::plain(...), $tag['value']) : $tag['value'],
            default => $tag['value'],
        };
    }
}
