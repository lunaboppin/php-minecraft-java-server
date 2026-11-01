<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * A mutable NBT tag as the server's commands hold it. A compound keeps its keys the way CompoundTag's
 * HashMap does: in buckets of the key's String.hashCode, each bucket in insertion order, with the
 * table doubling from 16 as it fills. Lists may mix tag types.
 */
final class NbtTag
{
    private const NUMBERS = ['byte', 'short', 'int', 'long', 'float', 'double'];

    /** @var array<int|string, NbtTag> a compound's entries in insertion order */
    private array $entries = [];

    /** HashMap's table size, which only grows. */
    private int $capacity = 16;

    /**
     * @param string $type byte, short, int, long, float, double, string, list, compound, byte_array, int_array or long_array
     * @param mixed $value a number or string, a list of NbtTag for lists, or a list of ints for arrays
     */
    public function __construct(public readonly string $type, public mixed $value = null)
    {
    }

    public static function compound(): self
    {
        return new self('compound');
    }

    /** A tag from Snbt::read's typed values. */
    public static function fromSnbt(array $tag): self
    {
        return match ($tag['type']) {
            'compound' => self::compoundOf(array_map(static fn (int|string $key, array $value): array => [(string) $key, self::fromSnbt($value)], array_keys($tag['value']), $tag['value'])),
            'list' => new self('list', array_map(self::fromSnbt(...), $tag['value'])),
            'byte_array', 'int_array', 'long_array' => new self($tag['type'], array_map(static fn (array $element): int => (int) $element['value'], $tag['value'])),
            // The grammar applies a number's sign to its magnitude, so -0.0 reads as 0.0.
            'float' => new self('float', Float32::round((float) $tag['value']) + 0.0),
            'double' => new self('double', (float) $tag['value'] + 0.0),
            'string' => new self('string', (string) $tag['value']),
            default => new self($tag['type'], self::wrap($tag['type'], (int) $tag['value'])),
        };
    }

    /** @param list<array{string, NbtTag}> $pairs */
    private static function compoundOf(array $pairs): self
    {
        $compound = self::compound();
        foreach ($pairs as [$key, $value]) {
            $compound->put($key, $value);
        }
        return $compound;
    }

    /** The integer types' two's-complement widths. */
    private static function wrap(string $type, int $value): int
    {
        $bits = ['byte' => 8, 'short' => 16, 'int' => 32][$type] ?? 64;
        if ($bits === 64) {
            return $value;
        }
        $value &= (1 << $bits) - 1;
        return $value >= 1 << ($bits - 1) ? $value - (1 << $bits) : $value;
    }

    public function isNumeric(): bool
    {
        return in_array($this->type, self::NUMBERS, true);
    }

    public function isCollection(): bool
    {
        return in_array($this->type, ['list', 'byte_array', 'int_array', 'long_array'], true);
    }

    /** NumericTag.doubleValue. */
    public function doubleValue(): float
    {
        return (float) $this->value;
    }

    /** Tag.copy: a compound's copy is a new HashMap filled in the original's iteration order. */
    public function copy(): self
    {
        return match ($this->type) {
            'compound' => self::compoundOf(array_map(fn (string $key): array => [$key, $this->entries[$key]->copy()], $this->keys())),
            'list' => new self('list', array_map(static fn (NbtTag $element): NbtTag => $element->copy(), $this->value)),
            default => new self($this->type, $this->value),
        };
    }

    public function equals(?self $other): bool
    {
        if ($other === null || $other->type !== $this->type) {
            return false;
        }
        if ($this->type === 'compound') {
            if (count($this->entries) !== count($other->entries)) {
                return false;
            }
            foreach ($this->entries as $key => $value) {
                if (!$value->equals($other->entries[$key] ?? null)) {
                    return false;
                }
            }
            return true;
        }
        if ($this->type === 'list') {
            if (count($this->value) !== count($other->value)) {
                return false;
            }
            foreach ($this->value as $index => $element) {
                if (!$element->equals($other->value[$index])) {
                    return false;
                }
            }
            return true;
        }
        if ($this->type === 'float' || $this->type === 'double') {
            // Float.equals and Double.equals compare the bits.
            return pack('E', $this->value) === pack('E', $other->value);
        }
        return $this->value === $other->value;
    }

    public function size(): int
    {
        return $this->type === 'compound' ? count($this->entries) : count($this->value);
    }

    public function get(string $key): ?self
    {
        return $this->entries[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    /** HashMap.put: a new key may double the table; an existing one keeps its place. Returns the previous value. */
    public function put(string $key, self $value): ?self
    {
        $previous = $this->entries[$key] ?? null;
        $this->entries[$key] = $value;
        if ($previous === null && count($this->entries) > $this->capacity * 3 / 4) {
            $this->capacity *= 2;
        }
        return $previous;
    }

    public function remove(string $key): void
    {
        unset($this->entries[$key]);
    }

    /** CompoundTag.merge: compounds merge into compounds; other values replace with copies. */
    public function merge(self $other): self
    {
        foreach ($other->keys() as $key) {
            $value = $other->entries[$key];
            $mine = $this->entries[$key] ?? null;
            if ($value->type === 'compound' && $mine !== null && $mine->type === 'compound') {
                $mine->merge($value);
            } else {
                $this->put($key, $value->copy());
            }
        }
        return $this;
    }

    /** @return list<string> the keys in HashMap iteration order */
    public function keys(): array
    {
        $order = [];
        $position = 0;
        foreach (array_keys($this->entries) as $key) {
            $key = (string) $key;
            $order[] = [self::bucket($key, $this->capacity), $position++, $key];
        }
        usort($order, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_column($order, 2);
    }

    /** HashMap.hash and the table index: String.hashCode spread by its upper half. */
    private static function bucket(string $key, int $capacity): int
    {
        $hash = 0;
        foreach (self::utf16Units($key) as $unit) {
            $hash = ($hash * 31 + $unit) & 0xffffffff;
        }
        return ($hash ^ ($hash >> 16)) & ($capacity - 1);
    }

    /** @return list<int> */
    private static function utf16Units(string $text): array
    {
        $encoded = iconv('UTF-8', 'UTF-16BE', $text);
        return $encoded === false || $encoded === '' ? [] : array_values(unpack('n*', $encoded));
    }

    /** CollectionTag.addTag: lists take anything; arrays take numbers, narrowed to their element type. */
    public function addTag(int $index, self $tag): bool
    {
        if ($index < 0 || $index > count($this->value)) {
            throw new \OutOfRangeException();
        }
        $element = $this->element($tag);
        if ($element === null) {
            return false;
        }
        array_splice($this->value, $index, 0, [$element]);
        return true;
    }

    /** CollectionTag.setTag. */
    public function setTag(int $index, self $tag): bool
    {
        $element = $this->element($tag);
        if ($element === null) {
            return false;
        }
        $this->value[$index] = $element;
        return true;
    }

    private function element(self $tag): self|int|null
    {
        if ($this->type === 'list') {
            return $tag;
        }
        if (!$tag->isNumeric()) {
            return null;
        }
        // NumericTag.byteValue, intValue and longValue truncate toward zero and narrow.
        $whole = $tag->type === 'float' || $tag->type === 'double' ? (int) $tag->value : $tag->value;
        return self::wrap(['byte_array' => 'byte', 'int_array' => 'int', 'long_array' => 'long'][$this->type], $whole);
    }

    /** CollectionTag.get as a tag. */
    public function at(int $index): self
    {
        return $this->type === 'list' ? $this->value[$index] : new self(['byte_array' => 'byte', 'int_array' => 'int', 'long_array' => 'long'][$this->type], $this->value[$index]);
    }

    public function removeAt(int $index): void
    {
        array_splice($this->value, $index, 1);
    }

    public function clear(): void
    {
        $this->value = [];
    }

    /** NbtUtils.compareNbt with partial list matching, as path patterns use it. */
    public static function matches(?self $expected, ?self $actual): bool
    {
        if ($expected === null) {
            return true;
        }
        if ($actual === null || $expected->type !== $actual->type) {
            return false;
        }
        if ($expected->type === 'compound') {
            if ($actual->size() < $expected->size()) {
                return false;
            }
            foreach ($expected->keys() as $key) {
                if (!self::matches($expected->entries[$key], $actual->entries[$key] ?? null)) {
                    return false;
                }
            }
            return true;
        }
        if ($expected->type === 'list') {
            if ($expected->value === []) {
                return $actual->value === [];
            }
            if (count($actual->value) < count($expected->value)) {
                return false;
            }
            foreach ($expected->value as $wanted) {
                $found = false;
                foreach ($actual->value as $candidate) {
                    if (self::matches($wanted, $candidate)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return false;
                }
            }
            return true;
        }
        return $expected->equals($actual);
    }

    /** NbtPath.isTooDeep: nesting of 512 or more. */
    public function isTooDeep(int $depth): bool
    {
        if ($depth >= 512) {
            return true;
        }
        $children = match ($this->type) {
            'compound' => $this->entries,
            'list' => $this->value,
            default => [],
        };
        foreach ($children as $child) {
            if ($child->isTooDeep($depth + 1)) {
                return true;
            }
        }
        return false;
    }

    /** Tag.toString: SNBT as StringTagVisitor writes it, keys sorted. */
    public function toText(): string
    {
        return match ($this->type) {
            'byte' => $this->value . 'b',
            'short' => $this->value . 's',
            'int' => (string) $this->value,
            'long' => $this->value . 'L',
            'float' => self::javaFloat($this->value) . 'f',
            'double' => self::javaDouble($this->value) . 'd',
            'string' => self::quote($this->value),
            'list' => '[' . implode(',', array_map(static fn (NbtTag $element): string => $element->toText(), $this->value)) . ']',
            'compound' => '{' . implode(',', array_map(fn (string $key): string => self::key($key) . ':' . $this->entries[$key]->toText(), self::sortedKeys(array_keys($this->entries)))) . '}',
            default => '[' . ['byte_array' => 'B', 'int_array' => 'I', 'long_array' => 'L'][$this->type] . ';' . implode(',', array_map(fn (int $element): string => $element . ['byte_array' => 'B', 'int_array' => '', 'long_array' => 'L'][$this->type], $this->value)) . ']',
        };
    }

    /** @return list<string> */
    private static function sortedKeys(array $keys): array
    {
        $keys = array_map('strval', $keys);
        usort($keys, static fn (string $a, string $b): int => self::utf16Units($a) <=> self::utf16Units($b));
        return $keys;
    }

    private static function key(string $key): string
    {
        return preg_match('/\A[A-Za-z0-9._+-]+\z/D', $key) === 1 ? $key : self::quote($key);
    }

    /** StringTag.quoteAndEscape: double quotes unless the first quote character in the text is one. */
    public static function quote(string $text): string
    {
        $quote = null;
        $out = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if ($char === '\\') {
                $out .= '\\\\';
            } elseif ($char === '"' || $char === "'") {
                $quote ??= $char === '"' ? "'" : '"';
                $out .= ($quote === $char ? '\\' : '') . $char;
            } else {
                $out .= match ($char) {
                    "\x08" => '\\b',
                    "\t" => '\\t',
                    "\n" => '\\n',
                    "\x0c" => '\\f',
                    "\r" => '\\r',
                    default => ord($char[0]) < 0x20 ? sprintf('\\x%02x', ord($char)) : $char,
                };
            }
        }
        $quote ??= '"';
        return $quote . $out . $quote;
    }

    /**
     * NbtUtils.toPrettyComponent: TextComponentTagVisitor's rich styling, with no indentation.
     * Keys are aqua, strings green, numbers gold and their type letters red.
     */
    public function pretty(): array
    {
        $parts = [];
        $this->visit($parts, 0);
        return ['text' => '', 'extra' => $parts];
    }

    private const SUFFIXES = ['byte' => 'b', 'short' => 's', 'long' => 'L', 'float' => 'f', 'double' => 'd'];

    private function visit(array &$parts, int $depth): void
    {
        $plain = static fn (string $text): array => ['text' => $text];
        $number = static fn (string $text): array => ['text' => $text, 'color' => 'gold'];
        $red = static fn (string $text): array => ['text' => $text, 'color' => 'red'];
        switch ($this->type) {
            case 'string':
                $quoted = self::quote($this->value);
                array_push($parts, $plain(substr($quoted, 0, 1)), ['text' => substr($quoted, 1, -1), 'color' => 'green'], $plain(substr($quoted, 0, 1)));
                return;
            case 'byte':
            case 'short':
            case 'int':
            case 'long':
                $parts[] = $number((string) $this->value);
                if (isset(self::SUFFIXES[$this->type])) {
                    $parts[] = $red(self::SUFFIXES[$this->type]);
                }
                return;
            case 'float':
            case 'double':
                array_push($parts, $number($this->type === 'float' ? self::javaFloat($this->value) : self::javaDouble($this->value)), $red(self::SUFFIXES[$this->type]));
                return;
            case 'byte_array':
            case 'int_array':
            case 'long_array':
                array_push($parts, $plain('['), $red(['byte_array' => 'B', 'int_array' => 'I', 'long_array' => 'L'][$this->type]), $plain(';'));
                $count = count($this->value);
                foreach (array_slice($this->value, 0, 128) as $index => $element) {
                    array_push($parts, $plain(' '), $number((string) $element));
                    if ($this->type !== 'int_array') {
                        $parts[] = $red($this->type === 'byte_array' ? 'b' : 'L');
                    }
                    if ($index !== $count - 1) {
                        $parts[] = $plain(',');
                    }
                }
                if ($count > 128) {
                    $parts[] = ['text' => '<...>', 'color' => 'gray'];
                }
                $parts[] = $plain(']');
                return;
            case 'list':
                if ($this->value === []) {
                    array_push($parts, $plain('['), $plain(']'));
                    return;
                }
                if ($depth >= 64) {
                    array_push($parts, $plain('['), ['text' => '<...>', 'color' => 'gray'], $plain(']'));
                    return;
                }
                $parts[] = $plain('[');
                // shouldWrapListElements: short lists of anything but numbers are laid out entry by entry.
                $wrap = count($this->value) < 8 && array_filter($this->value, static fn (NbtTag $element): bool => !$element->isNumeric()) !== [];
                $count = count($this->value);
                foreach ($wrap ? array_slice($this->value, 0, 128) : $this->value as $index => $element) {
                    if ($wrap) {
                        $parts[] = $plain('');
                    } elseif ($index !== 0) {
                        array_push($parts, $plain(','), $plain(' '));
                    }
                    $element->visit($parts, $depth + 1);
                    if ($wrap && $index !== $count - 1) {
                        array_push($parts, $plain(','), $plain(' '));
                    }
                }
                if ($wrap && $count > 128) {
                    array_push($parts, $plain(''), ['text' => '<...>', 'color' => 'gray']);
                }
                $parts[] = $plain(']');
                return;
            case 'compound':
                if ($this->entries === []) {
                    array_push($parts, $plain('{'), $plain('}'));
                    return;
                }
                if ($depth >= 64) {
                    array_push($parts, $plain('{'), ['text' => '<...>', 'color' => 'gray'], $plain('}'));
                    return;
                }
                $parts[] = $plain('{');
                $keys = $this->keys();
                foreach ($keys as $index => $key) {
                    $parts[] = $plain('');
                    if (preg_match('/\A[A-Za-z0-9._+-]+\z/D', $key) === 1) {
                        $parts[] = ['text' => $key, 'color' => 'aqua'];
                    } else {
                        $quoted = self::quote($key);
                        $parts[] = ['text' => substr($quoted, 0, 1), 'extra' => [['text' => substr($quoted, 1, -1), 'color' => 'aqua'], ['text' => substr($quoted, 0, 1)]]];
                    }
                    array_push($parts, $plain(':'), $plain(' '));
                    $this->entries[$key]->visit($parts, $depth + 1);
                    if ($index !== count($keys) - 1) {
                        array_push($parts, $plain(','), $plain(' '));
                    }
                }
                $parts[] = $plain('}');
                return;
        }
    }

    /** Double.toString: the shortest digits that read back, plain from 10^-3 to 10^7, else scientific. */
    public static function javaDouble(float $value): string
    {
        return self::javaNumber($value, static fn (string $text): bool => (float) $text === $value, 17);
    }

    /** Float.toString, read back as a float. */
    public static function javaFloat(float $value): string
    {
        return self::javaNumber($value, static fn (string $text): bool => Float32::round((float) $text) === $value, 9);
    }

    private static function javaNumber(float $value, \Closure $readsBack, int $maximum): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'Infinity' : '-Infinity';
        }
        if ($value == 0.0) {
            return fdiv(1.0, $value) < 0 ? '-0.0' : '0.0';
        }
        // Float and Double.toString give at least two digits, the closest pair when one would do.
        for ($digits = 2; $digits < $maximum; ++$digits) {
            if ($readsBack(sprintf('%.' . ($digits - 1) . 'e', $value))) {
                break;
            }
        }
        [$mantissa, $exponent] = explode('e', sprintf('%.' . ($digits - 1) . 'e', $value));
        $exponent = (int) $exponent;
        $sign = $mantissa[0] === '-' ? '-' : '';
        $significand = str_replace(['-', '.'], '', $mantissa);
        $magnitude = abs($value);
        if ($magnitude >= 1.0E-3 && $magnitude < 1.0E7) {
            if ($exponent >= 0) {
                $whole = substr(str_pad($significand, $exponent + 1, '0'), 0, $exponent + 1);
                $fraction = rtrim(substr($significand, $exponent + 1), '0');
                return $sign . $whole . '.' . ($fraction === '' ? '0' : $fraction);
            }
            return $sign . '0.' . str_repeat('0', -$exponent - 1) . rtrim($significand, '0');
        }
        $fraction = rtrim(substr($significand, 1), '0');
        return $sign . $significand[0] . '.' . ($fraction === '' ? '0' : $fraction) . 'E' . $exponent;
    }

    /** @return array{type: string, value: mixed} back into Snbt's form, as stored. */
    public function toArray(): array
    {
        return match ($this->type) {
            'compound' => ['type' => 'compound', 'value' => array_map(fn (string $key): array => [$key, $this->entries[$key]->toArray()], $this->keys())],
            'list' => ['type' => 'list', 'value' => array_map(static fn (NbtTag $element): array => $element->toArray(), $this->value)],
            default => ['type' => $this->type, 'value' => $this->value],
        };
    }

    /** A tag saved with toArray; a compound reads back in its saved iteration order, as NbtIo does. */
    public static function fromArray(array $data): self
    {
        return match ($data['type']) {
            'compound' => self::compoundOf(array_map(static fn (array $pair): array => [(string) $pair[0], self::fromArray($pair[1])], $data['value'])),
            'list' => new self('list', array_map(self::fromArray(...), $data['value'])),
            default => new self($data['type'], $data['value']),
        };
    }
}
