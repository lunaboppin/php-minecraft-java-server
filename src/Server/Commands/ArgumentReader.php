<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Version;

/**
 * ArgumentType.parse for the argument types of the commands this server implements: what each
 * consumes and the CommandSyntaxException it raises, at vanilla's cursor. Values are not
 * returned; the command handlers read the arguments again.
 */
final class ArgumentReader
{
    private const TIME_UNITS = ['d' => 24000, 's' => 20, 't' => 1, '' => 1];

    /**
     * @param array<string, array<string, mixed>> $registries registry id => entries keyed by full id; also "item" and "block"
     * @param bool $selectors EntitySelectorParser.allowSelectors: whether the source may use @-selectors
     */
    public function __construct(private readonly array $registries, private readonly bool $selectors)
    {
    }

    /**
     * The registries argument parsing checks ids against, keyed by full id.
     *
     * @param list<string> $clocks the world clocks
     * @return array<string, array<string, true>>
     */
    public static function registries(Version $version, array $clocks): array
    {
        static $registries = [];
        $ids = static fn (array $names): array => array_fill_keys(array_map(static fn (string $name): string => str_contains($name, ':') ? $name : 'minecraft:' . $name, $names), true);
        return $registries[$version->directory] ??= [
            'item' => $ids(array_keys($version->blocks['items'])),
            // Each block's properties and their values.
            'block' => array_combine(array_map(static fn (string $name): string => 'minecraft:' . $name, array_keys($version->catalog->blocks)), array_map(static fn (array $block): array => array_map(static fn (array $property): array => array_map('strval', $property['values']), $block['properties']), $version->catalog->blocks)),
            'minecraft:data_component_type' => array_flip(array_keys(json_decode((string) file_get_contents($version->directory . '/equipment-stack-rules.json'), true, 512, JSON_THROW_ON_ERROR)['component_ids'])),
            'minecraft:entity_type' => $ids(array_keys($version->blocks['entity_types'])),
            'minecraft:damage_type' => $ids(array_keys($version->catalog->damageTypes['types'])),
            'minecraft:timeline' => $ids(array_keys($version->catalog->timelines)),
            'minecraft:world_clock' => $ids($clocks),
        ];
    }

    /** @param array{argument: string, properties?: array} $node */
    public function read(StringReader $reader, array $node): void
    {
        $properties = $node['properties'] ?? [];
        switch ($node['argument']) {
            case 'brigadier:bool':
                $reader->readBoolean();
                return;
            case 'brigadier:integer':
                $start = $reader->cursor;
                $this->bounded($reader, $start, $reader->readInt(), $properties, 'integer');
                return;
            case 'brigadier:float':
                $start = $reader->cursor;
                $this->bounded($reader, $start, $reader->readFloat(), $properties, 'float');
                return;
            case 'brigadier:double':
                $start = $reader->cursor;
                $this->bounded($reader, $start, $reader->readDouble(), $properties, 'double');
                return;
            case 'brigadier:string':
                match ($properties['type'] ?? 'word') {
                    'greedy' => $reader->cursor = strlen($reader->string),
                    'phrase' => $reader->readString(),
                    default => $reader->readUnquotedString(),
                };
                return;
            case 'minecraft:entity':
                $this->entity($reader, ($properties['amount'] ?? 'multiple') === 'single', ($properties['type'] ?? 'entities') === 'players');
                return;
            case 'minecraft:game_profile':
                if ($reader->canRead() && $reader->peek() === '@') {
                    if (EntitySelector::read($reader, $this->selectors, $this->registries['minecraft:entity_type'] ?? null)->includesEntities()) {
                        throw $reader->error('argument.player.entities');
                    }
                    return;
                }
                while ($reader->canRead() && $reader->peek() !== ' ') {
                    $reader->skip();
                }
                return;
            case 'minecraft:message':
                $this->message($reader);
                return;
            case 'minecraft:gamemode':
                $mode = $reader->readUnquotedString();
                if (!in_array($mode, ['survival', 'creative', 'adventure', 'spectator'], true)) {
                    throw $reader->error('argument.gamemode.invalid', [$mode]);
                }
                return;
            case 'minecraft:entity_anchor':
                $start = $reader->cursor;
                $anchor = $reader->readUnquotedString();
                if ($anchor !== 'feet' && $anchor !== 'eyes') {
                    $reader->cursor = $start;
                    throw $reader->error('argument.anchor.invalid', [$anchor]);
                }
                return;
            case 'minecraft:time':
                $this->time($reader, (int) ($properties['min'] ?? 0));
                return;
            case 'minecraft:resource_location':
                $reader->readIdentifier();
                return;
            case 'minecraft:resource':
            case 'minecraft:resource_key':
                $id = $reader->readIdentifier();
                $registry = $properties['registry'];
                if (isset($this->registries[$registry]) && !isset($this->registries[$registry][$id])) {
                    throw $reader->error('argument.resource.not_found', [$id, $registry]);
                }
                return;
            case 'minecraft:block_pos':
                $this->coordinates($reader, 3, true);
                return;
            case 'minecraft:vec3':
                $this->coordinates($reader, 3, false);
                return;
            case 'minecraft:rotation':
                $this->rotation($reader);
                return;
            case 'minecraft:item_stack':
                $this->item($reader);
                return;
            case 'minecraft:block_state':
                $this->block($reader, false);
                return;
            case 'minecraft:block_predicate':
                $this->block($reader, true);
                return;
            default:
                // nbt_compound_tag and other structured values: one balanced token.
                self::balanced($reader);
        }
    }

    /** IntegerArgumentType, FloatArgumentType and DoubleArgumentType bounds, reported at the number's start. */
    private function bounded(StringReader $reader, int $start, int|float $value, array $properties, string $kind): void
    {
        foreach (['min' => 'low', 'max' => 'big'] as $bound => $suffix) {
            if (!isset($properties[$bound])) {
                continue;
            }
            $limit = $kind === 'integer' ? (int) $properties[$bound] : (float) $properties[$bound];
            if ($kind === 'float') {
                $limit = unpack('G', pack('G', $limit))[1];
            }
            if ($bound === 'min' ? $value < $limit : $value > $limit) {
                $reader->cursor = $start;
                throw $reader->error('argument.' . $kind . '.' . $suffix, [$limit, $value]);
            }
        }
    }

    /** EntityArgument.parse: the selector, then the amount and player-only checks (which underline the whole input). */
    private function entity(StringReader $reader, bool $single, bool $players): void
    {
        $selector = EntitySelector::read($reader, $this->selectors, $this->registries['minecraft:entity_type'] ?? null);
        if ($single && !$selector->single()) {
            $reader->cursor = 0;
            throw $reader->error($players ? 'argument.player.toomany' : 'argument.entity.toomany');
        }
        if ($players && $selector->includesEntities() && $selector->kind !== 's') {
            $reader->cursor = 0;
            throw $reader->error('argument.player.entities');
        }
    }

    /** MessageArgument.Message.parseText: selectors in the text are parsed; an unknown or missing selector kind is plain text. */
    private function message(StringReader $reader): void
    {
        if (!$this->selectors) {
            $reader->cursor = strlen($reader->string);
            return;
        }
        while ($reader->canRead()) {
            if ($reader->peek() !== '@') {
                $reader->skip();
                continue;
            }
            $start = $reader->cursor;
            try {
                EntitySelector::read($reader, true, $this->registries['minecraft:entity_type'] ?? null);
            } catch (CommandSyntaxException $error) {
                if (!in_array($error->component['translate'], ['argument.entity.selector.missing', 'argument.entity.selector.unknown'], true)) {
                    throw $error;
                }
                $reader->cursor = $start + 1;
            }
        }
    }

    /** TimeArgument: a float with an optional d, s or t unit, rounded to ticks. */
    private function time(StringReader $reader, int $minimum): void
    {
        $amount = $reader->readFloat();
        $unit = $reader->readUnquotedString();
        if (!isset(self::TIME_UNITS[$unit])) {
            throw $reader->error('argument.time.invalid_unit');
        }
        $product = unpack('G', pack('G', $amount * self::TIME_UNITS[$unit]))[1];
        $ticks = (int) floor($product + 0.5);
        if ($ticks < $minimum) {
            throw $reader->error('argument.time.tick_count_too_low', [$minimum, $ticks]);
        }
    }

    /**
     * WorldCoordinates.parseInt/parseDouble and LocalCoordinates.parse. Incomplete positions
     * point at their start; a mixed '^' at the offending coordinate.
     */
    private function coordinates(StringReader $reader, int $count, bool $integers): void
    {
        $start = $reader->cursor;
        $local = $reader->canRead() && $reader->peek() === '^';
        for ($index = 0; $index < $count; ++$index) {
            if ($index > 0) {
                if (!$reader->canRead() || $reader->peek() !== ' ') {
                    $reader->cursor = $start;
                    throw $reader->error('argument.pos3d.incomplete');
                }
                $reader->skip();
            }
            $local ? $this->localCoordinate($reader, $start) : $this->worldCoordinate($reader, $integers);
        }
    }

    private function worldCoordinate(StringReader $reader, bool $integer): void
    {
        if ($reader->canRead() && $reader->peek() === '^') {
            throw $reader->error('argument.pos.mixed');
        }
        if (!$reader->canRead()) {
            throw $reader->error($integer ? 'argument.pos.missing.int' : 'argument.pos.missing.double');
        }
        $relative = $reader->peek() === '~';
        if ($relative) {
            $reader->skip();
        }
        if ($reader->canRead() && $reader->peek() !== ' ') {
            // A relative offset is a double even in a block position.
            $integer && !$relative ? $reader->readInt() : $reader->readDouble();
        }
    }

    private function localCoordinate(StringReader $reader, int $start): void
    {
        if (!$reader->canRead()) {
            throw $reader->error('argument.pos.missing.double');
        }
        if ($reader->peek() !== '^') {
            $reader->cursor = $start;
            throw $reader->error('argument.pos.mixed');
        }
        $reader->skip();
        if ($reader->canRead() && $reader->peek() !== ' ') {
            $reader->readDouble();
        }
    }

    /** RotationArgument: two world coordinates. */
    private function rotation(StringReader $reader): void
    {
        $start = $reader->cursor;
        if (!$reader->canRead()) {
            throw $reader->error('argument.rotation.incomplete');
        }
        $this->worldCoordinate($reader, false);
        if (!$reader->canRead() || $reader->peek() !== ' ') {
            $reader->cursor = $start;
            throw $reader->error('argument.rotation.incomplete');
        }
        $reader->skip();
        $this->worldCoordinate($reader, false);
    }

    /** ItemParser: a registered item, then optional [component=value,...] or [!component,...]. */
    private function item(StringReader $reader): void
    {
        $start = $reader->cursor;
        $id = $reader->readIdentifier();
        if (!isset($this->registries['item'][$id])) {
            $reader->cursor = $start;
            throw $reader->error('argument.item.id.invalid', [$id]);
        }
        if (!$reader->canRead() || $reader->peek() !== '[') {
            return;
        }
        $reader->skip();
        $seen = [];
        while ($reader->canRead() && $reader->peek() !== ']') {
            $reader->skipWhitespace();
            $removed = $reader->canRead() && $reader->peek() === '!';
            if ($removed) {
                $reader->skip();
            }
            $componentStart = $reader->cursor;
            $component = $reader->readIdentifier();
            if (!isset($this->registries['minecraft:data_component_type'][$component])) {
                $reader->cursor = $componentStart;
                throw $reader->error('arguments.item.component.unknown', [$component]);
            }
            if (isset($seen[$component])) {
                // Raised without context, so no context line follows.
                throw new CommandSyntaxException(['translate' => 'arguments.item.component.repeated', 'with' => [['text' => $component]]], $reader->string, -1);
            }
            $seen[$component] = true;
            if (!$removed) {
                $reader->skipWhitespace();
                self::expect($reader, '=');
                $reader->skipWhitespace();
                // The SNBT value is consumed but not validated.
                self::value($reader);
            }
            $reader->skipWhitespace();
            if (!$reader->canRead() || $reader->peek() !== ',') {
                break;
            }
            $reader->skip();
        }
        self::expect($reader, ']');
    }

    /**
     * BlockStateParser: a registered block with [property=value,...], or a block tag with any
     * properties when allowed, then optional NBT.
     */
    private function block(StringReader $reader, bool $tags): void
    {
        $start = $reader->cursor;
        $properties = null;
        if ($tags && $reader->canRead() && $reader->peek() === '#') {
            $reader->skip();
            $id = $reader->readIdentifier();
        } else {
            $id = $reader->readIdentifier();
            $properties = $this->registries['block'][$id] ?? null;
            if ($properties === null) {
                $reader->cursor = $start;
                throw $reader->error('argument.block.id.invalid', [$id]);
            }
        }
        if ($reader->canRead() && $reader->peek() === '[') {
            $reader->skip();
            $reader->skipWhitespace();
            $seen = [];
            while ($reader->canRead() && $reader->peek() !== ']') {
                $reader->skipWhitespace();
                $nameStart = $reader->cursor;
                $name = $reader->readString();
                if ($properties !== null && !isset($properties[$name])) {
                    $reader->cursor = $nameStart;
                    throw $reader->error('argument.block.property.unknown', [$id, $name]);
                }
                if (isset($seen[$name])) {
                    $reader->cursor = $nameStart;
                    throw $reader->error('argument.block.property.duplicate', [$name, $id]);
                }
                $seen[$name] = true;
                $reader->skipWhitespace();
                if (!$reader->canRead() || $reader->peek() !== '=') {
                    throw $reader->error('argument.block.property.novalue', [$name, $id]);
                }
                $reader->skip();
                $reader->skipWhitespace();
                $valueStart = $reader->cursor;
                $value = $reader->readString();
                if ($properties !== null && !in_array($value, $properties[$name], true)) {
                    $reader->cursor = $valueStart;
                    throw $reader->error('argument.block.property.invalid', [$id, $value, $name]);
                }
                $reader->skipWhitespace();
                if ($reader->canRead()) {
                    if ($reader->peek() === ',') {
                        $reader->skip();
                        continue;
                    }
                    if ($reader->peek() !== ']') {
                        throw $reader->error('argument.block.property.unclosed');
                    }
                    break;
                }
            }
            if (!$reader->canRead()) {
                throw $reader->error('argument.block.property.unclosed');
            }
            $reader->skip();
        }
        if ($reader->canRead() && $reader->peek() === '{') {
            self::balanced($reader);
        }
    }

    /** StringReader.expect */
    private static function expect(StringReader $reader, string $char): void
    {
        if (!$reader->canRead() || $reader->peek() !== $char) {
            throw $reader->error('parsing.expected', [$char]);
        }
        $reader->skip();
    }

    /** An SNBT value: up to a ',', ']' or space outside brackets, braces and quotes. */
    private static function value(StringReader $reader): void
    {
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
            } elseif ($char === '[' || $char === '{') {
                ++$depth;
            } elseif ($char === ']' || $char === '}') {
                if ($depth === 0) {
                    return;
                }
                --$depth;
            } elseif (($char === ',' || $char === ' ') && $depth === 0) {
                return;
            }
            $reader->skip();
        }
    }

    /** Up to the next space outside brackets, braces and quotes. */
    private static function balanced(StringReader $reader): void
    {
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
            } elseif ($char === '[' || $char === '{') {
                ++$depth;
            } elseif ($char === ']' || $char === '}') {
                --$depth;
            } elseif ($char === ' ' && $depth <= 0) {
                return;
            }
            $reader->skip();
        }
    }
}
