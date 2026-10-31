<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait DataCommand
{
    /** @var array<string, NbtTag>|null CommandStorage: each storage ID's compound, loaded on first use. */
    private ?array $commandStorage = null;

    /**
     * DataCommands for command storage: get (a path, optionally scaled), merge, remove and modify
     * with set, merge, append, prepend and insert from a value, another path, or a string.
     */
    private function executeData(Client $sender, array $arguments, string $text): void
    {
        $reader = new StringReader((string) preg_replace('/\A\/?\s*data\s+/', '', $text));
        $action = $reader->readUnquotedString();
        $reader->skipWhitespace();
        $target = $this->dataTarget($reader);
        $reader->skipWhitespace();
        switch ($action) {
            case 'get':
                if (!$reader->canRead()) {
                    $this->sendSuccess($sender, $this->storageQuery($target, $this->storageData($target)), false);
                    return;
                }
                $path = NbtPath::read($reader);
                $reader->skipWhitespace();
                $tag = $this->singleTag($path, $this->storageData($target));
                if (!$reader->canRead()) {
                    $this->sendSuccess($sender, $this->storageQuery($target, $tag), false);
                    return;
                }
                $scale = $reader->readDouble();
                if (!$tag->isNumeric()) {
                    throw new CommandFailure(['translate' => 'commands.data.get.invalid', 'with' => [['text' => $path->original]]]);
                }
                $this->sendSuccess($sender, ['translate' => 'commands.data.storage.get', 'with' => [
                    ['text' => $path->original], ['text' => $target], ['text' => sprintf('%.2f', $scale)], (int) floor($tag->doubleValue() * $scale),
                ]], false);
                return;
            case 'merge':
                $patch = NbtTag::fromSnbt(Snbt::read($reader));
                if ($patch->isTooDeep(0)) {
                    throw new CommandFailure(['translate' => 'arguments.nbtpath.too_deep']);
                }
                $old = $this->storageData($target);
                $result = $old->copy()->merge($patch);
                if ($old->equals($result)) {
                    throw new CommandFailure(['translate' => 'commands.data.merge.failed']);
                }
                $this->setStorageData($target, $result);
                $this->sendSuccess($sender, $this->storageModified($target), true);
                return;
            case 'remove':
                $data = $this->storageData($target);
                if (NbtPath::read($reader)->remove($data) === 0) {
                    throw new CommandFailure(['translate' => 'commands.data.merge.failed']);
                }
                $this->setStorageData($target, $data);
                $this->sendSuccess($sender, $this->storageModified($target), true);
                return;
            case 'modify':
                $this->modifyStorage($sender, $target, $reader);
                return;
        }
    }

    /** Only storage targets are supported; entity and block data are not. */
    private function dataTarget(StringReader $reader): string
    {
        $kind = $reader->readUnquotedString();
        if ($kind !== 'storage') {
            throw new \InvalidArgumentException('Only /data storage is supported so far');
        }
        $reader->skipWhitespace();
        $id = $reader->readIdentifier();
        return str_contains($id, ':') ? $id : 'minecraft:' . $id;
    }

    /** DataCommands.manipulateData for each modification and source. */
    private function modifyStorage(Client $sender, string $target, StringReader $reader): void
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
        $sources = $this->dataSources($reader);
        $data = $this->storageData($target);
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
        $this->setStorageData($target, $data);
        $this->sendSuccess($sender, $this->storageModified($target), true);
    }

    /**
     * The modification's source tags: value, from a storage (its root or a path), or string of one,
     * optionally cut to a substring.
     *
     * @return list<NbtTag>
     */
    private function dataSources(StringReader $reader): array
    {
        $kind = $reader->readUnquotedString();
        $reader->skipWhitespace();
        if ($kind === 'value') {
            return [NbtTag::fromSnbt(Snbt::read($reader))];
        }
        $source = $this->dataTarget($reader);
        $reader->skipWhitespace();
        $data = $this->storageData($source);
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
