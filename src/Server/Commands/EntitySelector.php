<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

/**
 * EntitySelectorParser: a player name, a UUID or an @-selector with options. Parsing only; the
 * server resolves the result against its players and mobs.
 */
final class EntitySelector
{
    /** Options this server evaluates; the other vanilla options are recognized but unsupported. */
    private const SUPPORTED = ['name', 'distance', 'level', 'x', 'y', 'z', 'dx', 'dy', 'dz', 'x_rotation', 'y_rotation', 'limit', 'sort', 'gamemode', 'team', 'type', 'tag'];

    private const UNSUPPORTED = ['nbt', 'scores', 'advancements', 'predicate'];

    /**
     * @param string $kind p, a, r, s, e or n; '' for a name and 'uuid' for a UUID
     * @param array<string, list<array{bool, mixed}>> $options each option's (negated, value) entries
     */
    private function __construct(public readonly string $kind, public readonly ?string $name, public readonly ?string $uuid, public readonly array $options)
    {
    }

    public static function parse(string $text): self
    {
        if (!str_starts_with($text, '@')) {
            if (preg_match('/\A[0-9a-f]{1,8}-[0-9a-f]{1,4}-[0-9a-f]{1,4}-[0-9a-f]{1,4}-[0-9a-f]{1,12}\z/iD', $text) === 1) {
                return new self('uuid', null, self::uuid($text), []);
            }
            if ($text === '' || strlen($text) > 16 || preg_match('/[\s\[\]@"]/', $text) === 1) {
                throw new CommandFailure(['translate' => 'argument.entity.invalid']);
            }
            return new self('', $text, null, []);
        }
        $kind = substr($text, 1, 1);
        if (!in_array($kind, ['p', 'a', 'r', 's', 'e', 'n'], true)) {
            throw new CommandFailure(['translate' => $kind === '' ? 'argument.entity.selector.missing' : 'argument.entity.selector.unknown', 'with' => [['text' => '@' . $kind]]]);
        }
        $rest = substr($text, 2);
        $options = [];
        if ($rest !== '') {
            if (!str_starts_with($rest, '[') || !str_ends_with($rest, ']')) {
                throw new CommandFailure(['translate' => 'argument.entity.options.unterminated']);
            }
            foreach (self::split(substr($rest, 1, -1)) as $pair) {
                $separator = strpos($pair, '=');
                if ($separator === false) {
                    throw new CommandFailure(['translate' => 'argument.entity.options.valueless', 'with' => [['text' => trim($pair)]]]);
                }
                $key = trim(substr($pair, 0, $separator));
                $value = trim(substr($pair, $separator + 1));
                if (!in_array($key, self::SUPPORTED, true) && !in_array($key, self::UNSUPPORTED, true)) {
                    throw new CommandFailure(['translate' => 'argument.entity.options.unknown', 'with' => [['text' => $key]]]);
                }
                if (in_array($key, self::UNSUPPORTED, true)) {
                    throw new \InvalidArgumentException('The ' . $key . ' selector option is not supported yet');
                }
                // EntitySelectorOptions: these options cannot be repeated or used where the kind fixes them.
                $limited = in_array($kind, ['p', 'r', 's', 'n'], true);
                if (($key === 'limit' && $limited) || ($key === 'sort' && $limited) || ($key === 'type' && in_array($kind, ['p', 'a', 'r'], true))) {
                    throw new CommandFailure(['translate' => 'argument.entity.options.inapplicable', 'with' => [['text' => $key]]]);
                }
                $negated = in_array($key, ['name', 'gamemode', 'team', 'type', 'tag'], true) && str_starts_with($value, '!');
                $value = $negated ? ltrim(substr($value, 1)) : $value;
                if (!in_array($key, ['type', 'tag', 'team'], true) && isset($options[$key]) && !$negated) {
                    throw new CommandFailure(['translate' => 'argument.entity.options.inapplicable', 'with' => [['text' => $key]]]);
                }
                $options[$key][] = [$negated, self::value($key, $value)];
            }
        }
        return new self($kind, null, null, $options);
    }

    /** Players only: @p, @a and @r, @s, names, UUIDs, or @e and @n limited to the player type. */
    public function playersOnly(): bool
    {
        if (!in_array($this->kind, ['e', 'n'], true)) {
            return true;
        }
        foreach ($this->options['type'] ?? [] as [$negated, $type]) {
            if (!$negated && $type === 'minecraft:player') {
                return true;
            }
        }
        return false;
    }

    /** At most one result: @p, @r, @s, @n, a name, a UUID, or limit=1. */
    public function single(): bool
    {
        return in_array($this->kind, ['p', 'r', 's', 'n', '', 'uuid'], true) || ($this->options['limit'][0][1] ?? 0) === 1;
    }

    /** Split options on commas outside quotes. */
    private static function split(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }
        $parts = [];
        $current = '';
        $quote = null;
        for ($index = 0, $length = strlen($text); $index < $length; ++$index) {
            $char = $text[$index];
            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $index + 1 < $length) {
                    $current .= $text[++$index];
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
                $current .= $char;
            } elseif ($char === ',') {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        if ($quote !== null) {
            throw new CommandFailure(['translate' => 'argument.entity.options.unterminated']);
        }
        $parts[] = $current;
        return $parts;
    }

    private static function value(string $key, string $value): mixed
    {
        return match ($key) {
            'name', 'team', 'tag' => self::unquote($value),
            'type' => self::resource($value),
            'gamemode' => in_array($value, ['survival', 'creative', 'adventure', 'spectator'], true) ? array_search($value, ['survival', 'creative', 'adventure', 'spectator'], true)
                : throw new CommandFailure(['translate' => 'argument.entity.options.mode.invalid', 'with' => [['text' => $value]]]),
            'sort' => in_array($value, ['nearest', 'furthest', 'random', 'arbitrary'], true) ? $value
                : throw new CommandFailure(['translate' => 'argument.entity.options.sort.irreversible', 'with' => [['text' => $value]]]),
            'limit' => self::integer($value, 1, 'argument.entity.options.limit.toosmall'),
            'x', 'y', 'z', 'dx', 'dy', 'dz' => is_numeric($value) ? (float) $value : throw new \InvalidArgumentException('Expected a number for ' . $key),
            'distance' => self::range($value, 'argument.entity.options.distance.negative'),
            'level' => self::range($value, 'argument.entity.options.level.negative', true),
            'x_rotation', 'y_rotation' => self::range($value, null),
        };
    }

    private static function integer(string $value, int $minimum, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('Expected an integer, found ' . $value);
        }
        if ((int) $value < $minimum) {
            throw new CommandFailure(['translate' => $error]);
        }
        return (int) $value;
    }

    /**
     * MinMaxBounds: "a", "a..", "..b" or "a..b".
     *
     * @return array{?float, ?float}
     */
    private static function range(string $value, ?string $negative, bool $integers = false): array
    {
        $parts = str_contains($value, '..') ? explode('..', $value, 2) : [$value, $value];
        $number = $integers ? '/\A-?\d+\z/D' : '/\A-?(\d+(\.\d*)?|\.\d+)\z/D';
        $bounds = array_map(static function (string $part) use ($number): ?float {
            if ($part === '') {
                return null;
            }
            if (preg_match($number, $part) !== 1) {
                throw new \InvalidArgumentException('Invalid range: ' . $part);
            }
            return (float) $part;
        }, $parts);
        if ($bounds === [null, null]) {
            throw new \InvalidArgumentException('Expected a value or range');
        }
        if ($negative !== null && (($bounds[0] ?? 0.0) < 0 || ($bounds[1] ?? 0.0) < 0)) {
            throw new CommandFailure(['translate' => $negative]);
        }
        return $bounds;
    }

    private static function resource(string $value): string
    {
        $value = self::unquote($value);
        return str_contains($value, ':') ? $value : 'minecraft:' . $value;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            return stripslashes(substr($value, 1, -1));
        }
        return $value;
    }

    /** UUID.fromString: hex groups, each padded to its field width. */
    private static function uuid(string $text): string
    {
        $parts = explode('-', $text);
        $widths = [8, 4, 4, 4, 12];
        return hex2bin(implode('', array_map(static fn (string $part, int $width): string => str_pad($part, $width, '0', STR_PAD_LEFT), $parts, $widths)));
    }
}
