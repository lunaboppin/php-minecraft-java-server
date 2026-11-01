<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;
use PhpMinecraftJavaServer\World\ContextNumbers;
use PhpMinecraftJavaServer\World\Generation\Float32;

trait ComputeCommand
{
    /**
     * ComputeCommand: an int or float provider evaluated in a loot context: the default one, a
     * block's, or one targeting an entity. A float is scaled and rounded down, and reported as
     * rounded when that changed it.
     */
    private function executeCompute(Client $sender, array $arguments, string $text): void
    {
        $reader = new StringReader(ltrim($text));
        $reader->readUnquotedString();
        $reader->skipWhitespace();
        $reader->readUnquotedString();
        $reader->skipWhitespace();
        $source = $this->commandContext === null ? ['client' => $sender] : $this->commandContext['entity'];
        $context = ['holders' => ['this' => $source === null ? null : self::scoreboardName($source)]];
        $loot = [];
        if ($arguments[0] === 'block') {
            $position = $this->loadedBlockPosition(array_slice($arguments, 1, 3), $sender);
            for ($word = 0; $word < 3; ++$word) {
                $reader->readUnquotedString();
                $reader->skipWhitespace();
            }
            $state = $this->worlds[$this->sourceDimension($sender)]->get(...$position);
            $loot = ['block' => 'minecraft:' . $this->version->catalog->blockName($state), 'state' => array_map('strval', $this->version->catalog->properties($state))];
        } elseif ($arguments[0] === 'entity') {
            $start = $reader->cursor;
            EntitySelector::read($reader, true);
            $target = $this->selectEntities(substr($reader->string, $start, $reader->cursor - $start), $sender, false, true)[0];
            $context['holders']['target_entity'] = self::scoreboardName($target);
            $reader->skipWhitespace();
        }
        $kind = $reader->readUnquotedString() === 'integer' ? 'int' : 'float';
        $reader->skipWhitespace();
        [$name, $provider] = ArgumentReader::contextNumber($reader, $kind);
        $reader->skipWhitespace();
        $scale = $reader->canRead() ? Float32::round($reader->readFloat()) : 1.0;
        $context += [
            'random' => static fn (): float => mt_rand() / (mt_getrandmax() + 1),
            'randomInt' => static fn (int $bound): int => mt_rand(0, $bound - 1),
            'score' => fn (string $holder, string $objective): ?int => isset($this->scoreboard()->objectives[$objective]) ? ($this->scoreboard()->scores[$holder][$objective]['value'] ?? null) : null,
            // Command storage is not kept here, so a stored number is never found.
            'storage' => static fn (string $id, string $path): int|float|null => null,
            'condition' => fn (mixed $condition): bool => $this->loot()->test($condition, $loot),
        ];
        $prefix = $name === null ? 'command.compute.result.unnamed' : 'command.compute.result.named';
        $with = $name === null ? [] : [['text' => $name]];
        try {
            $value = ContextNumbers::evaluate($provider, $kind, $context);
        } catch (\ArithmeticError $error) {
            throw new CommandFailure(['translate' => $prefix . '.invalid', 'with' => [...$with, ['text' => $error->getMessage()]]]);
        }
        if ($kind === 'int') {
            $this->sendSuccess($sender, ['translate' => $prefix . '.exact', 'with' => [...$with, $value]], false);
            return;
        }
        if (!is_finite($value)) {
            throw new CommandFailure(['translate' => $prefix . '.invalid', 'with' => [...$with, ['text' => ContextNumbers::floatText($value)]]]);
        }
        // Mth.floor of the scaled value; an int cast saturates at the int range.
        $result = ContextNumbers::saturate(floor(Float32::round($value * $scale)));
        $this->sendSuccess($sender, (float) $result === $value
            ? ['translate' => $prefix . '.exact', 'with' => [...$with, $result]]
            : ['translate' => $prefix . '.rounded', 'with' => [...$with, $value, $result]], false);
    }
}
