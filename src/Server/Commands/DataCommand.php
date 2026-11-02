<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait DataCommand
{
    /** @var array<string, NbtTag>|null CommandStorage: each storage ID's compound, loaded on first use. */
    private ?array $commandStorage = null;

    /**
     * DataCommands for command storage, entities and block entities: get (a path, optionally
     * scaled), merge, remove and modify with set, merge, append, prepend and insert from a value,
     * another path, or a string.
     */
    private function executeData(Client $sender, array $arguments, string $text): void
    {
        $reader = new StringReader((string) preg_replace('/\A\/?\s*data\s+/', '', $text));
        $action = $reader->readUnquotedString();
        $reader->skipWhitespace();
        $target = $this->dataTarget($reader, $sender);
        $reader->skipWhitespace();
        switch ($action) {
            case 'get':
                if (!$reader->canRead()) {
                    $this->sendSuccess($sender, $this->dataQuery($target, $this->dataOf($target)), false);
                    return;
                }
                $path = NbtPath::read($reader);
                $reader->skipWhitespace();
                $tag = $this->singleTag($path, $this->dataOf($target));
                if (!$reader->canRead()) {
                    $this->sendSuccess($sender, $this->dataQuery($target, $tag), false);
                    return;
                }
                $scale = $reader->readDouble();
                if (!$tag->isNumeric()) {
                    throw new CommandFailure(['translate' => 'commands.data.get.invalid', 'with' => [['text' => $path->original]]]);
                }
                $this->sendSuccess($sender, $this->dataGet($target, $path, $scale, (int) floor($tag->doubleValue() * $scale)), false);
                return;
            case 'merge':
                $patch = NbtTag::fromSnbt(Snbt::read($reader));
                if ($patch->isTooDeep(0)) {
                    throw new CommandFailure(['translate' => 'arguments.nbtpath.too_deep']);
                }
                $old = $this->dataOf($target);
                $result = $old->copy()->merge($patch);
                if ($old->equals($result)) {
                    throw new CommandFailure(['translate' => 'commands.data.merge.failed']);
                }
                $this->setDataOf($target, $result);
                $this->sendSuccess($sender, $this->dataModified($target), true);
                return;
            case 'remove':
                $data = $this->dataOf($target);
                if (NbtPath::read($reader)->remove($data) === 0) {
                    throw new CommandFailure(['translate' => 'commands.data.merge.failed']);
                }
                $this->setDataOf($target, $data);
                $this->sendSuccess($sender, $this->dataModified($target), true);
                return;
            case 'modify':
                $this->modifyData($sender, $target, $reader);
                return;
        }
    }

    /**
     * The accessor a target names: a storage ID, one entity, or a block's position.
     *
     * @return array{0: string, 1: mixed, 2?: mixed}
     */
    private function dataTarget(StringReader $reader, Client $sender): array
    {
        $kind = $reader->readUnquotedString();
        $reader->skipWhitespace();
        if ($kind === 'storage') {
            $id = $reader->readIdentifier();
            return ['storage', str_contains($id, ':') ? $id : 'minecraft:' . $id];
        }
        if ($kind === 'entity') {
            // An entity selector's text runs to the space after its closing bracket.
            $start = $reader->cursor;
            $depth = 0;
            while ($reader->canRead() && ($reader->peek() !== ' ' || $depth > 0)) {
                $char = $reader->read();
                $depth += $char === '[' ? 1 : ($char === ']' ? -1 : 0);
            }
            return ['entity', $this->selectEntities(substr($reader->string, $start, $reader->cursor - $start), $sender, false, true)[0]];
        }
        $words = [];
        for ($index = 0; $index < 3; ++$index) {
            $reader->skipWhitespace();
            $start = $reader->cursor;
            while ($reader->canRead() && $reader->peek() !== ' ') {
                $reader->skip();
            }
            $words[] = substr($reader->string, $start, $reader->cursor - $start);
        }
        return ['block', $this->sourceDimension($sender), $this->loadedBlockPosition($words, $sender)];
    }

    /** DataAccessor.getData. */
    private function dataOf(array $target): NbtTag
    {
        return match ($target[0]) {
            'storage' => $this->storageData($target[1]),
            'entity' => match (true) {
                isset($target[1]['client']) => $this->playerNbt($target[1]['client']),
                isset($target[1]['mob']) => $this->mobNbt($this->animals[$target[1]['mob']]),
                isset($target[1]['item']) => $this->itemEntityNbt($this->itemEntities[$target[1]['item']]),
                isset($target[1]['orb']) => $this->orbNbt($this->experienceOrbs[$target[1]['orb']]),
                default => throw new \InvalidArgumentException('Only players, mobs, items and experience orbs have entity data here'),
            },
            default => $this->blockNbt($target[1], $target[2]) ?? throw new CommandFailure(['translate' => 'commands.data.block.invalid']),
        };
    }

    /** DataAccessor.setData: players' data cannot be changed. */
    private function setDataOf(array $target, NbtTag $data): void
    {
        match ($target[0]) {
            'storage' => $this->setStorageData($target[1], $data),
            'entity' => match (true) {
                isset($target[1]['client']) => throw new CommandFailure(['translate' => 'commands.data.entity.invalid']),
                isset($target[1]['mob']) => $this->setMobNbt($target[1]['mob'], $data),
                default => throw new \InvalidArgumentException('Only mobs\' entity data can be changed here'),
            },
            default => $this->setBlockNbt($target[1], $target[2], $data),
        };
    }

    private function dataQuery(array $target, NbtTag $tag): array
    {
        return match ($target[0]) {
            'storage' => $this->storageQuery($target[1], $tag),
            'entity' => ['translate' => 'commands.data.entity.query', 'with' => [$this->entityName($target[1]), $tag->pretty()]],
            default => ['translate' => 'commands.data.block.query', 'with' => [...$target[2], $tag->pretty()]],
        };
    }

    private function dataModified(array $target): array
    {
        return match ($target[0]) {
            'storage' => $this->storageModified($target[1]),
            'entity' => ['translate' => 'commands.data.entity.modified', 'with' => [$this->entityName($target[1])]],
            default => ['translate' => 'commands.data.block.modified', 'with' => $target[2]],
        };
    }

    private function dataGet(array $target, NbtPath $path, float $scale, int $value): array
    {
        $scaleText = ['text' => sprintf('%.2f', $scale)];
        return match ($target[0]) {
            'storage' => ['translate' => 'commands.data.storage.get', 'with' => [['text' => $path->original], ['text' => $target[1]], $scaleText, $value]],
            'entity' => ['translate' => 'commands.data.entity.get', 'with' => [['text' => $path->original], $this->entityName($target[1]), $scaleText, $value]],
            default => ['translate' => 'commands.data.block.get', 'with' => [['text' => $path->original], ...$target[2], $scaleText, $value]],
        };
    }

    /** DataCommands.manipulateData for each modification and source. */
    private function modifyData(Client $sender, array $target, StringReader $reader): void
    {
        $targetPath = NbtPath::read($reader);
        $reader->skipWhitespace();
        $operation = $reader->readUnquotedString();
        $reader->skipWhitespace();
        $index = null;
        if ($operation === 'insert') {
            $index = $reader->readInt();
            $reader->skipWhitespace();
        }
        $sources = $this->dataSources($reader, $sender);
        $data = $this->dataOf($target);
        $changed = match ($operation) {
            'set' => $targetPath->set($data, $sources[count($sources) - 1]),
            'merge' => $this->mergeInto($targetPath, $data, $sources),
            'append' => $targetPath->insert(-1, $data, $sources),
            'prepend' => $targetPath->insert(0, $data, $sources),
            default => $targetPath->insert((int) $index, $data, $sources),
        };
        if ($changed === 0) {
            throw new CommandFailure(['translate' => 'commands.data.merge.failed']);
        }
        $this->setDataOf($target, $data);
        $this->sendSuccess($sender, $this->dataModified($target), true);
    }

    /**
     * The modification's source tags: value, from a storage (its root or a path), or string of one,
     * optionally cut to a substring.
     *
     * @return list<NbtTag>
     */
    private function dataSources(StringReader $reader, Client $sender): array
    {
        $kind = $reader->readUnquotedString();
        $reader->skipWhitespace();
        if ($kind === 'value') {
            return [NbtTag::fromSnbt(Snbt::read($reader))];
        }
        $source = $this->dataTarget($reader, $sender);
        $reader->skipWhitespace();
        $data = $this->dataOf($source);
        $tags = $reader->canRead() ? NbtPath::read($reader)->get($data) : [$data];
        if ($kind !== 'string') {
            return $tags;
        }
        $reader->skipWhitespace();
        $start = $reader->canRead() ? $reader->readInt() : null;
        $reader->skipWhitespace();
        $end = $reader->canRead() ? $reader->readInt() : null;
        return array_map(static function (NbtTag $tag) use ($start, $end): NbtTag {
            // DataCommands.getAsText: a string's value, or a primitive's SNBT.
            if ($tag->type === 'string') {
                $text = $tag->value;
            } elseif ($tag->isNumeric()) {
                $text = $tag->toText();
            } else {
                throw new CommandFailure(['translate' => 'commands.data.modify.expected_value', 'with' => [['text' => $tag->toText()]]]);
            }
            if ($start !== null) {
                // String.substring counts UTF-16 units.
                $units = (string) iconv('UTF-8', 'UTF-16BE', $text);
                $length = intdiv(strlen($units), 2);
                $from = $start >= 0 ? $start : $length + $start;
                $to = $end === null ? $length : ($end >= 0 ? $end : $length + $end);
                if ($from < 0 || $to > $length || $from > $to) {
                    throw new CommandFailure(['translate' => 'commands.data.modify.invalid_substring', 'with' => [$from, $to]]);
                }
                $text = (string) iconv('UTF-16BE', 'UTF-8', substr($units, $from * 2, ($to - $from) * 2));
            }
            return new NbtTag('string', $text);
        }, $tags);
    }

    /** The merge modification: compound sources combined, merged into each target compound. */
    private function mergeInto(NbtPath $path, NbtTag $data, array $sources): int
    {
        $combined = NbtTag::compound();
        foreach ($sources as $source) {
            if ($source->isTooDeep(0)) {
                throw new CommandFailure(['translate' => 'arguments.nbtpath.too_deep']);
            }
            if ($source->type !== 'compound') {
                throw new CommandFailure(['translate' => 'commands.data.modify.expected_object', 'with' => [['text' => $source->toText()]]]);
            }
            $combined->merge($source);
        }
        $changed = 0;
        foreach ($path->getOrCreate($data, static fn (): NbtTag => NbtTag::compound()) as $target) {
            if ($target->type !== 'compound') {
                throw new CommandFailure(['translate' => 'commands.data.modify.expected_object', 'with' => [['text' => $target->toText()]]]);
            }
            $original = $target->copy();
            $target->merge($combined);
            $changed += $original->equals($target) ? 0 : 1;
        }
        return $changed;
    }

    /** DataCommands.getSingleTag. */
    private function singleTag(NbtPath $path, NbtTag $data): NbtTag
    {
        $tags = $path->get($data);
        if (count($tags) > 1) {
            throw new CommandFailure(['translate' => 'commands.data.get.multiple']);
        }
        return $tags[0];
    }

    private function storageQuery(string $id, NbtTag $tag): array
    {
        return ['translate' => 'commands.data.storage.query', 'with' => [['text' => $id], $tag->pretty()]];
    }

    private function storageModified(string $id): array
    {
        return ['translate' => 'commands.data.storage.modified', 'with' => [['text' => $id]]];
    }

    private function commandStoragePath(): string
    {
        return $this->config->worldPath . '.command-storage.json';
    }

    /** CommandStorage.get: the stored compound itself, or a new empty one. */
    private function storageData(string $id): NbtTag
    {
        if ($this->commandStorage === null) {
            $this->commandStorage = [];
            $saved = is_file($this->commandStoragePath()) ? json_decode((string) file_get_contents($this->commandStoragePath()), true, 1024, JSON_THROW_ON_ERROR) : [];
            foreach ($saved as $key => $data) {
                $this->commandStorage[(string) $key] = NbtTag::fromArray($data);
            }
        }
        return $this->commandStorage[$id] ?? NbtTag::compound();
    }

    /** CommandStorage.Container.put: an empty compound is dropped; the storage is saved at once. */
    private function setStorageData(string $id, NbtTag $data): void
    {
        if ($data->size() === 0) {
            unset($this->commandStorage[$id]);
        } else {
            $this->commandStorage[$id] = $data;
        }
        $saved = array_map(static fn (NbtTag $tag): array => $tag->toArray(), $this->commandStorage);
        file_put_contents($this->commandStoragePath() . '.tmp', json_encode($saved, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        rename($this->commandStoragePath() . '.tmp', $this->commandStoragePath());
    }
}
