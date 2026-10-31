<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

/**
 * NbtPathArgument.NbtPath: names, quoted names, [index], [], [{pattern}], name{pattern} and a
 * leading {pattern}, with each node's get, get-or-create, set and remove.
 */
final class NbtPath
{
    /**
     * @param list<array{string, mixed}> $nodes each node's kind and argument
     * @param list<int> $ends where each node ended in the original text
     */
    private function __construct(public readonly string $original, private readonly array $nodes, private readonly array $ends)
    {
    }

    /** NbtPathArgument.parse: nodes up to the next space. */
    public static function read(StringReader $reader): self
    {
        $start = $reader->cursor;
        $nodes = [];
        $ends = [];
        $first = true;
        while ($reader->canRead() && $reader->peek() !== ' ') {
            $nodes[] = self::node($reader, $first);
            $ends[] = $reader->cursor - $start;
            $first = false;
            if ($reader->canRead()) {
                $next = $reader->peek();
                if ($next !== ' ' && $next !== '[' && $next !== '{') {
                    if ($next !== '.') {
                        throw $reader->error('parsing.expected', ['.']);
                    }
                    $reader->skip();
                }
            }
        }
        return new self(substr($reader->string, $start, $reader->cursor - $start), $nodes, $ends);
    }

    public static function parse(string $text): self
    {
        return self::read(new StringReader($text));
    }

    private static function node(StringReader $reader, bool $first): array
    {
        $char = $reader->peek();
        if ($char === '"' || $char === "'") {
            return self::objectNode($reader, $reader->readQuotedString());
        }
        if ($char === '[') {
            $reader->skip();
            $next = $reader->canRead() ? $reader->peek() : '';
            if ($next === '{') {
                $pattern = NbtTag::fromSnbt(Snbt::read($reader));
                self::expect($reader, ']');
                return ['match_element', $pattern];
            }
            if ($next === ']') {
                $reader->skip();
                return ['all', null];
            }
            $index = $reader->readInt();
            self::expect($reader, ']');
            return ['index', $index];
        }
        if ($char === '{') {
            if (!$first) {
                throw $reader->error('arguments.nbtpath.node.invalid');
            }
            return ['match_root', NbtTag::fromSnbt(Snbt::read($reader))];
        }
        $start = $reader->cursor;
        while ($reader->canRead() && !in_array($reader->peek(), [' ', '"', "'", '[', ']', '.', '{', '}'], true)) {
            $reader->skip();
        }
        if ($reader->cursor === $start) {
            throw $reader->error('arguments.nbtpath.node.invalid');
        }
        return self::objectNode($reader, substr($reader->string, $start, $reader->cursor - $start));
    }

    private static function objectNode(StringReader $reader, string $name): array
    {
        if ($name === '') {
            throw $reader->error('arguments.nbtpath.node.invalid');
        }
        if ($reader->canRead() && $reader->peek() === '{') {
            return ['match_object', [$name, NbtTag::fromSnbt(Snbt::read($reader))]];
        }
        return ['child', $name];
    }

    private static function expect(StringReader $reader, string $char): void
    {
        if (!$reader->canRead() || $reader->peek() !== $char) {
            throw $reader->error('parsing.expected', [$char]);
        }
        $reader->skip();
    }

    /** @return list<NbtTag> */
    private static function getTag(array $node, NbtTag $parent): array
    {
        [$kind, $argument] = $node;
        switch ($kind) {
            case 'all':
                return $parent->isCollection() && $parent->size() > 0 ? array_map(static fn (int $index): NbtTag => $parent->at($index), range(0, $parent->size() - 1)) : [];
            case 'child':
                return $parent->type === 'compound' && $parent->has($argument) ? [$parent->get($argument)] : [];
            case 'index':
                if (!$parent->isCollection()) {
                    return [];
                }
                $index = $argument < 0 ? $parent->size() + $argument : $argument;
                return $index >= 0 && $index < $parent->size() ? [$parent->at($index)] : [];
            case 'match_element':
                return $parent->type === 'list' ? array_values(array_filter($parent->value, static fn (NbtTag $element): bool => NbtTag::matches($argument, $element))) : [];
            case 'match_object':
                if ($parent->type !== 'compound') {
                    return [];
                }
                $result = $parent->get($argument[0]);
                return NbtTag::matches($argument[1], $result) && $result !== null ? [$result] : [];
            default:
                return $parent->type === 'compound' && NbtTag::matches($argument, $parent) ? [$parent] : [];
        }
    }

    /** @return list<NbtTag> */
    private static function getOrCreateTag(array $node, NbtTag $parent, \Closure $child): array
    {
        [$kind, $argument] = $node;
        switch ($kind) {
            case 'all':
                if (!$parent->isCollection()) {
                    return [];
                }
                if ($parent->size() === 0) {
                    $result = $child();
                    return $parent->addTag(0, $result) ? [$result] : [];
                }
                return self::getTag($node, $parent);
            case 'child':
                if ($parent->type !== 'compound') {
                    return [];
                }
                if (!$parent->has($argument)) {
                    $parent->put($argument, $child());
                }
                return [$parent->get($argument)];
            case 'match_element':
                if ($parent->type !== 'list') {
                    return [];
                }
                $found = self::getTag($node, $parent);
                if ($found === []) {
                    $created = $argument->copy();
                    $parent->addTag($parent->size(), $created);
                    return [$created];
                }
                return $found;
            case 'match_object':
                if ($parent->type !== 'compound') {
                    return [];
                }
                $result = $parent->get($argument[0]);
                if ($result === null) {
                    $created = $argument[1]->copy();
                    $parent->put($argument[0], $created);
                    return [$created];
                }
                return NbtTag::matches($argument[1], $result) ? [$result] : [];
            default:
                return self::getTag($node, $parent);
        }
    }

    private static function preferredParent(array $node): NbtTag
    {
        return in_array($node[0], ['all', 'index', 'match_element'], true) ? new NbtTag('list', []) : NbtTag::compound();
    }

    private static function setTag(array $node, NbtTag $parent, \Closure $toAdd): int
    {
        [$kind, $argument] = $node;
        switch ($kind) {
            case 'all':
                if (!$parent->isCollection()) {
                    return 0;
                }
                $size = $parent->size();
                if ($size === 0) {
                    $parent->addTag(0, $toAdd());
                    return 1;
                }
                $value = $toAdd();
                $changed = $size - count(array_filter(array_map(static fn (int $index): NbtTag => $parent->at($index), range(0, $size - 1)), static fn (NbtTag $element): bool => $value->equals($element)));
                if ($changed === 0) {
                    return 0;
                }
                $parent->clear();
                if (!$parent->addTag(0, $value)) {
                    return 0;
                }
                for ($index = 1; $index < $size; ++$index) {
                    $parent->addTag($index, $toAdd());
                }
                return $changed;
            case 'child':
                if ($parent->type !== 'compound') {
                    return 0;
                }
                $value = $toAdd();
                $previous = $parent->put($argument, $value);
                return $value->equals($previous) ? 0 : 1;
            case 'index':
                if (!$parent->isCollection()) {
                    return 0;
                }
                $index = $argument < 0 ? $parent->size() + $argument : $argument;
                if ($index < 0 || $index >= $parent->size()) {
                    return 0;
                }
                $value = $toAdd();
                return !$value->equals($parent->at($index)) && $parent->setTag($index, $value) ? 1 : 0;
            case 'match_element':
                if ($parent->type !== 'list') {
                    return 0;
                }
                if ($parent->size() === 0) {
                    $parent->addTag(0, $toAdd());
                    return 1;
                }
                $changed = 0;
                foreach ($parent->value as $index => $current) {
                    if (NbtTag::matches($argument, $current)) {
                        $value = $toAdd();
                        if (!$value->equals($current) && $parent->setTag($index, $value)) {
                            ++$changed;
                        }
                    }
                }
                return $changed;
            case 'match_object':
                if ($parent->type !== 'compound') {
                    return 0;
                }
                $current = $parent->get($argument[0]);
                if (NbtTag::matches($argument[1], $current)) {
                    $value = $toAdd();
                    if (!$value->equals($current)) {
                        $parent->put($argument[0], $value);
                        return 1;
                    }
                }
                return 0;
            default:
                return 0;
        }
    }

    private static function removeTag(array $node, NbtTag $parent): int
    {
        [$kind, $argument] = $node;
        switch ($kind) {
            case 'all':
                if ($parent->isCollection() && $parent->size() > 0) {
                    $size = $parent->size();
                    $parent->clear();
                    return $size;
                }
                return 0;
            case 'child':
                if ($parent->type === 'compound' && $parent->has($argument)) {
                    $parent->remove($argument);
                    return 1;
                }
                return 0;
            case 'index':
                if (!$parent->isCollection()) {
                    return 0;
                }
                $index = $argument < 0 ? $parent->size() + $argument : $argument;
                if ($index >= 0 && $index < $parent->size()) {
                    $parent->removeAt($index);
                    return 1;
                }
                return 0;
            case 'match_element':
                $removed = 0;
                if ($parent->type === 'list') {
                    for ($index = $parent->size() - 1; $index >= 0; --$index) {
                        if (NbtTag::matches($argument, $parent->value[$index])) {
                            $parent->removeAt($index);
                            ++$removed;
                        }
                    }
                }
                return $removed;
            case 'match_object':
                if ($parent->type === 'compound' && NbtTag::matches($argument[1], $parent->get($argument[0])) && $parent->has($argument[0])) {
                    $parent->remove($argument[0]);
                    return 1;
                }
                return 0;
            default:
                return 0;
        }
    }

    /** @param list<NbtTag> $tags */
    private static function collect(array $tags, \Closure $each): array
    {
        $result = [];
        foreach ($tags as $tag) {
            array_push($result, ...$each($tag));
        }
        return $result;
    }

    /**
     * NbtPath.get: every match, failing at the first node that finds nothing.
     *
     * @return list<NbtTag>
     */
    public function get(NbtTag $root): array
    {
        $result = [$root];
        foreach ($this->nodes as $index => $node) {
            $result = self::collect($result, static fn (NbtTag $tag): array => self::getTag($node, $tag));
            if ($result === []) {
                throw $this->notFound($index);
            }
        }
        return $result;
    }

    /** @return list<NbtTag> */
    private function getOrCreateParents(NbtTag $root): array
    {
        $result = [$root];
        for ($index = 0; $index < count($this->nodes) - 1; ++$index) {
            $next = $this->nodes[$index + 1];
            $result = self::collect($result, fn (NbtTag $tag): array => self::getOrCreateTag($this->nodes[$index], $tag, static fn (): NbtTag => self::preferredParent($next)));
            if ($result === []) {
                throw $this->notFound($index);
            }
        }
        return $result;
    }

    /** @return list<NbtTag> */
    public function getOrCreate(NbtTag $root, \Closure $value): array
    {
        $last = $this->nodes[count($this->nodes) - 1];
        return self::collect($this->getOrCreateParents($root), static fn (NbtTag $tag): array => self::getOrCreateTag($last, $tag, $value));
    }

    /** NbtPath.set: the first target takes the copy, later ones copies of it. */
    public function set(NbtTag $root, NbtTag $value): int
    {
        if ($value->isTooDeep(count($this->nodes))) {
            throw new CommandFailure(['translate' => 'arguments.nbtpath.too_deep']);
        }
        $first = $value->copy();
        $used = false;
        $supply = static function () use ($first, &$used): NbtTag {
            if (!$used) {
                $used = true;
                return $first;
            }
            return $first->copy();
        };
        $last = $this->nodes[count($this->nodes) - 1];
        $changed = 0;
        foreach ($this->getOrCreateParents($root) as $parent) {
            $changed += self::setTag($last, $parent, $supply);
        }
        return $changed;
    }

    /**
     * NbtPath.insert: the sources go in at the index of each target collection, the end at -1.
     *
     * @param list<NbtTag> $sources
     */
    public function insert(int $index, NbtTag $root, array $sources): int
    {
        $copies = [];
        foreach ($sources as $source) {
            $copy = $source->copy();
            if ($copy->isTooDeep(count($this->nodes))) {
                throw new CommandFailure(['translate' => 'arguments.nbtpath.too_deep']);
            }
            $copies[] = $copy;
        }
        $modified = 0;
        $usedFirst = false;
        foreach ($this->getOrCreate($root, static fn (): NbtTag => new NbtTag('list', [])) as $target) {
            if (!$target->isCollection()) {
                throw new CommandFailure(['translate' => 'commands.data.modify.expected_list', 'with' => [['text' => $target->toText()]]]);
            }
            $changed = false;
            $at = $index < 0 ? $target->size() + $index + 1 : $index;
            foreach ($copies as $source) {
                try {
                    if ($target->addTag($at, $usedFirst ? $source->copy() : $source)) {
                        ++$at;
                        $changed = true;
                    }
                } catch (\OutOfRangeException) {
                    throw new CommandFailure(['translate' => 'commands.data.modify.invalid_index', 'with' => [$at]]);
                }
            }
            $usedFirst = true;
            $modified += $changed ? 1 : 0;
        }
        return $modified;
    }

    /** NbtPath.remove: the matches of every node but the last, then the last node's removal from each. */
    public function remove(NbtTag $root): int
    {
        $result = [$root];
        for ($index = 0; $index < count($this->nodes) - 1; ++$index) {
            $node = $this->nodes[$index];
            $result = self::collect($result, static fn (NbtTag $tag): array => self::getTag($node, $tag));
        }
        $last = $this->nodes[count($this->nodes) - 1];
        $removed = 0;
        foreach ($result as $parent) {
            $removed += self::removeTag($last, $parent);
        }
        return $removed;
    }

    private function notFound(int $index): CommandFailure
    {
        return new CommandFailure(['translate' => 'arguments.nbtpath.nothing_found', 'with' => [['text' => substr($this->original, 0, $this->ends[$index])]]]);
    }
}
