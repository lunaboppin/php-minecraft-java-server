<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SetBlockCommand
{
    /** SetBlockCommand: replace (the default), keep, destroy or strict. */
    private function executeSetBlock(Client $sender, array $arguments, string $text = ''): void
    {
        [$block, $nbt, $rest] = $this->blockInputArgument($text, $arguments);
        if (count($arguments) < 4 || count($rest) > 1) {
            throw new \InvalidArgumentException('Usage: /setblock x y z block[properties]{data} [replace|keep|destroy|strict]');
        }
        [$x, $y, $z] = $this->loadedBlockPosition(array_slice($arguments, 0, 3), $sender);
        $state = $this->blockArgument($block);
        $mode = $rest[0] ?? 'replace';
        if ($mode === 'keep' && !$this->version->catalog->air($this->world->get($x, $y, $z))) {
            throw new CommandFailure(['translate' => 'commands.setblock.failed']);
        }
        // A destroyed block leaves air, which needs no placement when air was requested.
        $place = true;
        if ($mode === 'destroy') {
            $this->destroyBlock($x, $y, $z);
            $place = !$this->version->catalog->air($state) || !$this->version->catalog->air($this->world->get($x, $y, $z));
        }
        if ($place) {
            if ($this->world->get($x, $y, $z) === $state) {
                throw new CommandFailure(['translate' => 'commands.setblock.failed']);
            }
            if ($mode !== 'strict') {
                // SetBlockCommand: Block.updateFromNeighbourShapes shapes the block to its neighbours first.
                $state = $this->connectedState($state, $x, $y, $z);
            }
            $this->setBlock($x, $y, $z, $state);
            // BlockInput.place: the block entity loads the data given with the block.
            if ($nbt !== null) {
                $this->setBlockNbt($this->world->dimension, [$x, $y, $z], $nbt);
            }
            if ($mode !== 'strict') {
                $this->observerSetByCommand($x, $y, $z);
            }
        }
        $this->sendSuccess($sender, ['translate' => 'commands.setblock.success', 'with' => [$x, $y, $z]], true);
    }

    /**
     * BlockStateArgument with the block's data: the block (with its properties) after the three
     * coordinates, an SNBT compound straight after it, and the words that follow.
     *
     * @return array{string, ?NbtTag, list<string>}
     */
    private function blockInputArgument(string $text, array $arguments): array
    {
        $brace = strpos($arguments[3] ?? '', '{');
        if ($text === '' || $brace === false) {
            return [$arguments[3] ?? '', null, array_slice($arguments, 4)];
        }
        $reader = new StringReader($text);
        for ($word = 0; $word < 4; ++$word) {
            while ($reader->canRead() && $reader->peek() !== ' ') {
                $reader->skip();
            }
            while ($reader->canRead() && $reader->peek() === ' ') {
                $reader->skip();
            }
        }
        $block = '';
        while ($reader->canRead() && $reader->peek() !== '{') {
            $block .= $reader->peek();
            $reader->skip();
        }
        $nbt = NbtTag::fromSnbt(Snbt::read($reader));
        $rest = trim($reader->remaining());
        return [$block, $nbt, $rest === '' ? [] : preg_split('/\s+/', $rest)];
    }

    /** Level.destroyBlock: the break event, air, then the block's loot without a tool or entity. */
    private function destroyBlock(int $x, int $y, int $z): bool
    {
        $state = $this->world->get($x, $y, $z);
        if ($this->version->catalog->air($state)) {
            return false;
        }
        $this->levelEvent(2001, [$x, $y, $z], $state);
        $name = $this->version->catalog->blockName($state);
        $container = in_array($name, ['chest', 'barrel', 'furnace', 'blast_furnace', 'smoker', 'hopper', 'dispenser', 'dropper', 'brewing_stand'], true) ? $this->world->container([$x, $y, $z]) : null;
        $this->setBlock($x, $y, $z, 0);
        $this->dropBlockLoot($x, $y, $z, $state);
        // Level.setBlock after the loot: removing the block entity drops what it holds.
        if ($container !== null) {
            $this->removeContainer($x, $y, $z, $name, $container);
        }
        return true;
    }

    /** Block.dropResources without a tool: the loot table's stacks at the block's centre. */
    private function dropBlockLoot(int $x, int $y, int $z, int $state): void
    {
        if ($this->gameRule('block_drops') !== 'true') {
            return;
        }
        $blockAt = function (int $offset) use ($x, $y, $z): array {
            $other = $this->world->get($x, $y + $offset, $z);
            return ['minecraft:' . $this->version->catalog->blockName($other), array_map('strval', $this->version->catalog->properties($other))];
        };
        $properties = array_map('strval', $this->version->catalog->properties($state));
        $name = 'minecraft:' . $this->version->catalog->blockName($state);
        foreach ($this->loot()->drops('minecraft:blocks/' . substr($name, 10), ['block' => $name, 'state' => $properties, 'blockAt' => $blockAt]) as [$drop, $count]) {
            $item = $this->version->blocks['items'][substr($drop, 10)] ?? null;
            if ($item !== null) {
                $this->popResource($x, $y, $z, ['item' => $item, 'count' => $count]);
            }
        }
    }
}
