<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Protocol;

/** Network NBT has an unnamed root (no two-byte root-name prefix). */
final class Nbt
{
    public static function text(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            throw new \InvalidArgumentException('Invalid UTF-8');
        }
        return "\x08" . self::string($text);
    }

    /**
     * Encode a text component as vanilla does: text-only components are bare strings, anything
     * else a compound whose fields follow Java HashMap iteration order (CompoundTag is a HashMap).
     */
    public static function component(array $component): string
    {
        if (array_keys($component) === ['text']) {
            return self::text($component['text']);
        }
        return "\x0a" . self::compoundBody($component);
    }

    private static function compoundBody(array $fields): string
    {
        $out = '';
        foreach (self::hashMapOrder(array_keys($fields)) as $key) {
            $out .= self::field((string) $key, $fields[$key]);
        }
        return $out . "\0";
    }

    private static function field(string $key, mixed $value): string
    {
        $name = self::string($key);
        return match (true) {
            is_bool($value) => "\x01" . $name . chr((int) $value),
            // UUIDs are stored as four big-endian ints, the vanilla UUIDUtil codec.
            $key === 'uuid' => "\x0b" . $name . pack('N', 4) . self::uuid($value),
            $value instanceof NbtIntArray => "\x0b" . $name . pack('N', count($value->values)) . pack('N*', ...$value->values),
            is_string($value) => "\x08" . $name . self::string($value),
            // Data component values in item hovers, such as minecraft:damage.
            is_int($value) => "\x03" . $name . pack('N', $value),
            is_float($value) => "\x05" . $name . pack('G', $value),
            // A plain text component is written as its bare string, as the codec does anywhere.
            is_array($value) && array_keys($value) === ['text'] && is_string($value['text']) => "\x08" . $name . self::string($value['text']),
            is_array($value) && array_is_list($value) => "\x09" . $name . self::componentList($value),
            is_array($value) => "\x0a" . $name . self::compoundBody($value),
            // An object stands for a compound that may be empty, which an empty PHP array cannot.
            $value instanceof \stdClass => "\x0a" . $name . self::compoundBody(get_object_vars($value)),
            default => throw new \InvalidArgumentException('Unsupported text component field: ' . $key),
        };
    }

    /**
     * A list of components and primitive arguments (translation arguments may be numbers).
     * Elements of one tag type form a plain list. Mixed types are written as compounds, with
     * every non-compound element wrapped as {"": element} (ListTag.identifyRawElementType and
     * wrapIfNeeded).
     */
    private static function componentList(array $components): string
    {
        $types = array_map(static fn (int|float|NbtLong|NbtDouble|array $component): int => match (true) {
            is_int($component) => 3,
            $component instanceof NbtLong => 4,
            $component instanceof NbtDouble => 6,
            is_float($component) => 5,
            array_keys($component) === ['text'] => 8,
            default => 10,
        }, $components);
        $type = $components === [] ? 10 : (count(array_unique($types)) === 1 ? $types[0] : 10);
        $elements = array_map(static function (int|float|NbtLong|NbtDouble|array $component) use ($type): string {
            $payload = match (true) {
                is_int($component) => pack('N', $component),
                $component instanceof NbtLong => pack('J', $component->value),
                $component instanceof NbtDouble => pack('E', $component->value),
                // Float translation arguments are single precision, as Java's Float.
                is_float($component) => pack('G', $component),
                array_keys($component) === ['text'] => self::string($component['text']),
                default => self::compoundBody($component),
            };
            if ($type !== 10 || (is_array($component) && array_keys($component) !== ['text'])) {
                return $payload;
            }
            $tag = match (true) {
                is_int($component) => "\x03",
                $component instanceof NbtLong => "\x04",
                $component instanceof NbtDouble => "\x06",
                is_float($component) => "\x05",
                default => "\x08",
            };
            return $tag . self::string('') . $payload . "\0";
        }, $components);
        return chr($type) . pack('N', count($components)) . implode('', $elements);
    }

    private static function uuid(string $uuid): string
    {
        if (strlen($uuid) !== 16) {
            throw new \InvalidArgumentException('UUID must be 16 bytes');
        }
        return $uuid;
    }

    /** @param list<string> $keys */
    /** Java HashMap iteration order for string keys. */
    public static function hashMapOrder(array $keys): array
    {
        $capacity = 16;
        while (count($keys) > $capacity * 3 / 4) {
            $capacity *= 2;
        }
        $bucket = static function (string $key) use ($capacity): int {
            $hash = 0;
            foreach (unpack('n*', iconv('UTF-8', 'UTF-16BE', $key)) ?: [] as $unit) {
                $hash = (31 * $hash + $unit) & 0xffffffff;
            }
            return ($hash ^ ($hash >> 16)) & ($capacity - 1);
        };
        // Stable sort keeps insertion order within a bucket, as HashMap's chains do.
        usort($keys, static fn (string $a, string $b): int => $bucket($a) <=> $bucket($b));
        return $keys;
    }

    public static function sign(array $data): string
    {
        // CompoundTag keeps its keys in a HashMap, whose order puts back_text, is_waxed, then front_text,
        // and within each side has_glowing_text, color, then messages.
        $side = static function (string $side) use ($data): string {
            $out = "\x0a" . self::string($side . '_text') . "\x01" . self::string('has_glowing_text') . "\0"
                . "\x08" . self::string('color') . self::string('black') . "\x09" . self::string('messages') . "\x08" . pack('N', 4);
            foreach ($data[$side] ?? ['', '', '', ''] as $line) {
                $out .= self::string($line);
            }
            return $out . "\0";
        };
        return "\x0a" . $side('back') . "\x01" . self::string('is_waxed') . "\0" . $side('front') . "\0";
    }

    // NBT uses Java modified UTF-8, including surrogate pairs for supplementary characters.
    private static function string(string $value): string
    {
        $utf16 = iconv('UTF-8', 'UTF-16BE', $value);
        if ($utf16 === false) {
            throw new \InvalidArgumentException('Invalid text');
        }
        $out = '';
        foreach (unpack('n*', $utf16) as $unit) {
            if ($unit >= 1 && $unit <= 127) {
                $out .= chr($unit);
            } elseif ($unit <= 2047) {
                $out .= chr(0xc0 | ($unit >> 6)) . chr(0x80 | ($unit & 63));
            } else {
                $out .= chr(0xe0 | ($unit >> 12)) . chr(0x80 | (($unit >> 6) & 63)) . chr(0x80 | ($unit & 63));
            }
        }
        if (strlen($out) > 65535) {
            throw new \InvalidArgumentException('NBT string too long');
        }
        return pack('n', strlen($out)) . $out;
    }
}
