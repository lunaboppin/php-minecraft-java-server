<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

/**
 * EntitySelectorParser: a player name, a UUID or an @-selector with options. Parsing only; the
 * server resolves the result against its players and mobs.
 */
final class EntitySelector
{
    /** Options this server does not evaluate; selecting with one fails. */
    private const UNSUPPORTED = ['nbt', 'advancements', 'predicate'];

    private const OPTIONS = ['name', 'distance', 'level', 'x', 'y', 'z', 'dx', 'dy', 'dz', 'x_rotation', 'y_rotation', 'limit', 'sort', 'gamemode', 'team', 'type', 'tag', 'nbt', 'scores', 'advancements', 'predicate'];

    private const GAME_MODES = ['survival', 'creative', 'adventure', 'spectator'];

    /**
     * @param string $kind p, a, r, s, e or n; '' for a name and 'uuid' for a UUID
     * @param array<string, list<array{bool, mixed}>> $options each option's (negated, value) entries
     */
    private function __construct(public readonly string $kind, public readonly ?string $name, public readonly ?string $uuid, public readonly array $options)
    {
    }

    /** A whole argument, as the command handlers receive it. */
    public static function parse(string $text, ?array $entityTypes = null): self
    {
        $reader = new StringReader($text);
        $selector = self::read($reader, true, $entityTypes);
        if ($reader->canRead()) {
            throw $reader->error('command.expected.separator');
        }
        return $selector;
    }

    /**
     * EntitySelectorParser.parse at the reader's cursor.
     *
     * @param array<string, mixed>|null $entityTypes entity type ids (minecraft:pig => ...) to validate type=, or null to accept any
     */
    public static function read(StringReader $reader, bool $allowSelectors, ?array $entityTypes = null): self
    {
        if (!$reader->canRead() || $reader->peek() !== '@') {
            // parseNameOrUUID
            $start = $reader->cursor;
            $text = $reader->readString();
            if (preg_match('/\A[0-9a-f]{1,8}-[0-9a-f]{1,4}-[0-9a-f]{1,4}-[0-9a-f]{1,4}-[0-9a-f]{1,12}\z/iD', $text) === 1) {
                return new self('uuid', null, self::uuid($text), []);
            }
            if ($text === '' || strlen($text) > 16) {
                $reader->cursor = $start;
                throw $reader->error('argument.entity.invalid');
            }
            return new self('', $text, null, []);
        }
        if (!$allowSelectors) {
            throw $reader->error('argument.entity.selector.not_allowed');
        }
        $reader->skip();
        if (!$reader->canRead()) {
            throw $reader->error('argument.entity.selector.missing');
        }
        $kind = $reader->read();
        if (!in_array($kind, ['p', 'a', 'r', 's', 'e', 'n'], true)) {
            --$reader->cursor;
            throw $reader->error('argument.entity.selector.unknown', ['@' . $kind]);
        }
        $options = [];
        if ($reader->canRead() && $reader->peek() === '[') {
            $reader->skip();
            $options = self::readOptions($reader, $kind, $entityTypes);
        }
        return new self($kind, null, null, $options);
    }

    /** EntitySelectorParser.parseOptions with EntitySelectorOptions' applicability rules. */
    private static function readOptions(StringReader $reader, string $kind, ?array $entityTypes): array
    {
        $options = [];
        $reader->skipWhitespace();
        while ($reader->canRead() && $reader->peek() !== ']') {
            $reader->skipWhitespace();
            $start = $reader->cursor;
            $key = $reader->readString();
            if (!in_array($key, self::OPTIONS, true)) {
                $reader->cursor = $start;
                throw $reader->error('argument.entity.options.unknown', [$key]);
            }
            if (!self::applicable($key, $kind, $options)) {
                $reader->cursor = $start;
                throw $reader->error('argument.entity.options.inapplicable', [$key]);
            }
            $reader->skipWhitespace();
            if (!$reader->canRead() || $reader->peek() !== '=') {
                $reader->cursor = $start;
                throw $reader->error('argument.entity.options.valueless', [$key]);
            }
            $reader->skip();
            $reader->skipWhitespace();
            $options[$key][] = self::readValue($reader, $key, $options, $entityTypes);
            $reader->skipWhitespace();
            if ($reader->canRead()) {
                if ($reader->peek() === ',') {
                    $reader->skip();
                    continue;
                }
                if ($reader->peek() !== ']') {
                    throw $reader->error('argument.entity.options.unterminated');
                }
                break;
            }
        }
        if (!$reader->canRead()) {
            throw $reader->error('argument.entity.options.unterminated');
        }
        $reader->skip();
        return $options;
    }

    /** Each option's canUse predicate. */
    private static function applicable(string $key, string $kind, array $options): bool
    {
        $positive = static fn (string $option): bool => in_array(false, array_column($options[$option] ?? [], 0), true);
        return match ($key) {
            'name', 'gamemode', 'team' => !$positive($key),
            // @p, @a and @r are limited to players; a positive type limits @e and @n.
            'type' => !in_array($kind, ['p', 'a', 'r'], true) && !$positive('type'),
            'limit', 'sort' => $kind !== 's' && !isset($options[$key]),
            'tag', 'nbt', 'predicate' => true,
            default => !isset($options[$key]),
        };
    }

    /** @return array{bool, mixed} */
    private static function readValue(StringReader $reader, string $key, array $options, ?array $entityTypes): array
    {
        $start = $reader->cursor;
        $negated = false;
        if (in_array($key, ['name', 'gamemode', 'team', 'type', 'tag', 'nbt', 'predicate'], true)) {
            // shouldInvertValue
            $reader->skipWhitespace();
            if ($reader->canRead() && $reader->peek() === '!') {
                $reader->skip();
                $reader->skipWhitespace();
                $negated = true;
            }
            if (in_array($key, ['name', 'gamemode', 'team', 'type'], true) && !$negated && isset($options[$key])) {
                $reader->cursor = $start;
                throw $reader->error('argument.entity.options.inapplicable', [$key]);
            }
        }
        switch ($key) {
            case 'name':
                return [$negated, $reader->readString()];
            case 'team':
            case 'tag':
                return [$negated, $reader->readUnquotedString()];
            case 'gamemode':
                $mode = $reader->readUnquotedString();
                if (!in_array($mode, self::GAME_MODES, true)) {
                    $reader->cursor = $start;
                    throw $reader->error('argument.entity.options.mode.invalid', [$mode]);
                }
                return [$negated, array_search($mode, self::GAME_MODES, true)];
            case 'type':
                $reader->skipWhitespace();
                $tag = $reader->canRead() && $reader->peek() === '#';
                if ($tag) {
                    $reader->skip();
                }
                $type = $reader->readIdentifier();
                if (!$tag && $entityTypes !== null && !isset($entityTypes[$type])) {
                    $reader->cursor = $start;
                    throw $reader->error('argument.entity.options.type.invalid', [$type]);
                }
                return [$negated, $tag ? '#' . $type : $type];
            case 'sort':
                $sort = $reader->readUnquotedString();
                if (!in_array($sort, ['nearest', 'furthest', 'random', 'arbitrary'], true)) {
                    $reader->cursor = $start;
                    throw $reader->error('argument.entity.options.sort.irreversible', [$sort]);
                }
                return [false, $sort];
            case 'limit':
                $limit = $reader->readInt();
                if ($limit < 1) {
                    $reader->cursor = $start;
                    throw $reader->error('argument.entity.options.limit.toosmall');
                }
                return [false, $limit];
            case 'x':
            case 'y':
            case 'z':
            case 'dx':
            case 'dy':
            case 'dz':
                return [false, $reader->readDouble()];
            case 'distance':
            case 'level':
                $bounds = self::readBounds($reader, $key === 'level');
                if (($bounds[0] ?? 0.0) < 0 || ($bounds[1] ?? 0.0) < 0) {
                    $reader->cursor = $start;
                    throw $reader->error('argument.entity.options.' . $key . '.negative');
                }
                return [false, $bounds];
            case 'x_rotation':
            case 'y_rotation':
                return [false, self::readBounds($reader, false)];
            default:
                // nbt, scores, advancements and predicate are consumed but not evaluated.
                return [$negated, self::readUnsupported($reader)];
        }
    }

    /**
     * MinMaxBounds.fromReader: "a", "a..", "..b" or "a..b".
     *
     * @return array{?float, ?float}
     */
    private static function readBounds(StringReader $reader, bool $integers): array
    {
        if (!$reader->canRead()) {
            throw $reader->error('argument.range.empty');
        }
        $start = $reader->cursor;
        try {
            $minimum = self::boundNumber($reader, $integers);
            if ($reader->canRead(2) && $reader->peek() === '.' && $reader->peek(1) === '.') {
                $reader->skip();
                $reader->skip();
                $maximum = self::boundNumber($reader, $integers);
            } else {
                $maximum = $minimum;
            }
            if ($minimum === null && $maximum === null) {
                throw $reader->error('argument.range.empty');
            }
            if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
                throw $reader->error('argument.range.swapped');
            }
        } catch (CommandSyntaxException $error) {
            $reader->cursor = $start;
            throw new CommandSyntaxException($error->component, $error->input, $start);
        }
        return [$minimum, $maximum];
    }

    private static function boundNumber(StringReader $reader, bool $integer): ?float
    {
        $start = $reader->cursor;
        // A '.' is part of the number unless it starts "..".
        while ($reader->canRead() && (preg_match('/[0-9\-]/', $reader->peek()) === 1 || ($reader->peek() === '.' && !($reader->canRead(2) && $reader->peek(1) === '.')))) {
            $reader->skip();
        }
        $number = substr($reader->string, $start, $reader->cursor - $start);
        if ($number === '') {
            return null;
        }
        if (preg_match($integer ? '/\A-?\d+\z/D' : '/\A-?(\d+\.?\d*|\.\d+)\z/D', $number) !== 1) {
            throw $reader->error($integer ? 'parsing.int.invalid' : 'parsing.double.invalid', [$number]);
        }
        return (float) $number;
    }

    /** A value this server cannot evaluate: up to the next ',' or ']' outside brackets and quotes. */
    private static function readUnsupported(StringReader $reader): string
    {
        $start = $reader->cursor;
        $depth = 0;
        $quote = null;
        while ($reader->canRead()) {
            $char = $reader->peek();
            if ($quote !== null) {
                if ($char === '\\') {
                    $reader->skip();
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{' || $char === '[') {
                ++$depth;
            } elseif ($char === '}' || $char === ']') {
                if ($depth === 0) {
                    break;
                }
                --$depth;
            } elseif ($char === ',' && $depth === 0) {
                break;
            }
            $reader->skip();
        }
        return substr($reader->string, $start, $reader->cursor - $start);
    }

    /** Whether unevaluated options were given. */
    public function unsupported(): ?string
    {
        foreach (self::UNSUPPORTED as $key) {
            if (isset($this->options[$key])) {
                return $key;
            }
        }
        return null;
    }

    /** EntitySelector.includesEntities: @e, @n, @s and UUIDs, unless limited to players. */
    public function includesEntities(): bool
    {
        return $this->kind === 'uuid' || ($this->kind === 's') || (in_array($this->kind, ['e', 'n'], true) && !$this->playersOnly());
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

    /** UUID.fromString: hex groups, each padded to its field width. */
    private static function uuid(string $text): string
    {
        $parts = explode('-', $text);
        $widths = [8, 4, 4, 4, 12];
        return hex2bin(implode('', array_map(static fn (string $part, int $width): string => str_pad($part, $width, '0', STR_PAD_LEFT), $parts, $widths)));
    }
}
