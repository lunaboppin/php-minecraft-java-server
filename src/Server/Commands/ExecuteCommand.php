<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait ExecuteCommand
{
    /**
     * ExecuteCommand: modifiers (as, at, positioned, rotated, facing, align, anchored, in) and
     * conditions (if/unless entity, block, score, loaded, dimension) applied to each source in turn,
     * then run, or the last condition's result. The command tree has already checked the syntax.
     */
    private function executeExecute(Client $sender, array $arguments, string $text = ''): void
    {
        $reader = new StringReader(ltrim($text));
        $reader->readUnquotedString();
        $contexts = [$this->commandContext ?? ['output' => $sender, 'entity' => $this->selectEntities('@s', $sender)[0] ?? null,
            'x' => $sender->x, 'y' => $sender->y, 'z' => $sender->z, 'yaw' => $sender->yaw, 'pitch' => $sender->pitch, 'dimension' => $sender->dimension, 'anchor' => 'feet', 'forked' => false]];
        while (true) {
            $reader->skipWhitespace();
            $word = $reader->readUnquotedString();
            $reader->skipWhitespace();
            if ($word === 'run') {
                $command = substr($reader->string, $reader->cursor);
                foreach ($contexts as $context) {
                    // The executing player, when there is one, runs it; feedback still goes to the output.
                    $this->withCommandContext($context, fn () => $this->runCommandText($context['entity']['client'] ?? $context['output'], $command));
                }
                return;
            }
            if ($word === 'if' || $word === 'unless') {
                $expected = $word === 'if';
                $kind = $reader->readUnquotedString();
                $reader->skipWhitespace();
                $test = $this->executeCondition($kind, $reader, $sender);
                $reader->skipWhitespace();
                if (!$reader->canRead()) {
                    // The condition ends the command: each source reports its own result.
                    foreach ($contexts as $context) {
                        $this->withCommandContext($context, function () use ($test, $expected, $kind, $sender): void {
                            $result = $test();
                            if ($kind === 'entity') {
                                // createNumericConditionalHandler: the count of matching entities.
                                if ($expected ? $result > 0 : $result === 0) {
                                    $this->sendSuccess($sender, $expected ? ['translate' => 'commands.execute.conditional.pass_count', 'with' => [$result]] : ['translate' => 'commands.execute.conditional.pass'], false);
                                } else {
                                    $this->sendFailure($sender, $expected ? ['translate' => 'commands.execute.conditional.fail'] : ['translate' => 'commands.execute.conditional.fail_count', 'with' => [$result]]);
                                }
                                return;
                            }
                            if ((bool) $result === $expected) {
                                $this->sendSuccess($sender, ['translate' => 'commands.execute.conditional.pass'], false);
                            } else {
                                $this->sendFailure($sender, ['translate' => 'commands.execute.conditional.fail']);
                            }
                        });
                    }
                    return;
                }
                // A condition forks: sources that fail it drop out silently.
                $contexts = array_values(array_filter(
                    array_map(fn (array $context): array => ['forked' => true] + $context, $contexts),
                    fn (array $context): bool => (bool) $this->withCommandContext($context, $test) === $expected
                ));
                continue;
            }
            $contexts = $this->executeModifier($word, $reader, $contexts, $sender);
        }
    }

    /**
     * One modifier applied to every source; the selector modifiers fork into one source per entity.
     *
     * @return list<array> the new sources
     */
    private function executeModifier(string $word, StringReader $reader, array $contexts, Client $sender): array
    {
        $select = function (array $context) use ($reader, $sender): array {
            $text = $this->executeToken($reader);
            return $this->withCommandContext($context, fn (): array => $this->selectEntities($text, $context['entity']['client'] ?? $sender, false, false, true));
        };
        $start = $reader->cursor;
        $result = [];
        foreach ($contexts as $context) {
            $reader->cursor = $start;
            switch ($word) {
                case 'as':
                    foreach ($select($context) as $entity) {
                        $result[] = ['entity' => $entity, 'forked' => true] + $context;
                    }
                    break;
                case 'at':
                    foreach ($select($context) as $entity) {
                        $result[] = ['x' => $entity['x'], 'y' => $entity['y'], 'z' => $entity['z'], 'yaw' => $entity['yaw'], 'pitch' => $entity['pitch'],
                            'dimension' => isset($entity['client']) ? $entity['client']->dimension : $context['dimension'], 'forked' => true] + $context;
                    }
                    break;
                case 'positioned':
                case 'rotated':
                    if ($reader->canRead(2) && substr($reader->string, $reader->cursor, 3) === 'as ') {
                        $reader->cursor += 3;
                        foreach ($select($context) as $entity) {
                            $result[] = ($word === 'positioned' ? ['x' => $entity['x'], 'y' => $entity['y'], 'z' => $entity['z']] : ['yaw' => $entity['yaw'], 'pitch' => $entity['pitch']])
                                + ['forked' => true] + $context;
                        }
                        break;
                    }
                    if ($word === 'positioned' && substr($reader->string, $reader->cursor, 5) === 'over ') {
                        $reader->cursor += 5;
                        $heightmap = $this->executeToken($reader);
                        $result[] = ['y' => (float) $this->withCommandContext($context, fn (): int => $this->executeHeight($heightmap, $context, $sender))] + $context;
                        break;
                    }
                    if ($word === 'positioned') {
                        $position = $this->withCommandContext($context, fn (): array => $this->executeVector($reader, $sender, true));
                        $result[] = ['x' => $position[0], 'y' => $position[1], 'z' => $position[2], 'anchor' => 'feet'] + $context;
                        break;
                    }
                    [$yaw, $pitch] = [$this->executeToken($reader), $this->executeToken($reader)];
                    $rotation = $this->withCommandContext($context, fn (): array => $this->rotationArguments($yaw, $pitch, $sender));
                    $result[] = ['yaw' => $rotation[0], 'pitch' => $rotation[1]] + $context;
                    break;
                case 'facing':
                    if (substr($reader->string, $reader->cursor, 7) === 'entity ') {
                        $reader->cursor += 7;
                        $entities = $select($context);
                        $reader->skipWhitespace();
                        $anchor = $reader->readUnquotedString();
                        foreach ($entities as $entity) {
                            $target = [$entity['x'], $entity['y'] + ($anchor === 'eyes' ? $this->entityEyeHeight($entity) : 0.0), $entity['z']];
                            $result[] = $this->executeFacing($context, $target) + ['forked' => true] + $context;
                        }
                        break;
                    }
                    $target = $this->withCommandContext($context, fn (): array => $this->executeVector($reader, $sender, true));
                    $result[] = $this->executeFacing($context, $target) + $context;
                    break;
                case 'align':
                    $axes = $reader->readUnquotedString();
                    foreach (['x', 'y', 'z'] as $axis) {
                        if (str_contains($axes, $axis)) {
                            $context[$axis] = floor($context[$axis]);
                        }
                    }
                    $result[] = $context;
                    break;
                case 'anchored':
                    $result[] = ['anchor' => $reader->readUnquotedString()] + $context;
                    break;
                case 'summon':
                    $kind = substr($reader->readIdentifier(), 10);
                    $result[] = ['entity' => $this->withCommandContext($context, fn (): array => $this->executeSummonEntity($kind, $context, $sender))] + $context;
                    break;
                case 'in':
                    $id = $reader->readIdentifier();
                    $result[] = ['dimension' => substr($id, 10)] + $context;
                    break;
                default:
                    throw new \InvalidArgumentException('The execute ' . $word . ' subcommand is not supported yet');
            }
        }
        if ($contexts === []) {
            // No sources left: still move past this modifier's arguments.
            $this->skipModifier($word, $reader);
        }
        return $result;
    }

    /** Moves the reader past a modifier's arguments when no source is left to apply it to. */
    private function skipModifier(string $word, StringReader $reader): void
    {
        $count = match ($word) {
            'as', 'at', 'align', 'anchored', 'in' => 1,
            'rotated' => substr($reader->string, $reader->cursor, 3) === 'as ' ? 2 : 2,
            'positioned' => in_array(substr($reader->string, $reader->cursor, 3), ['as ', 'ove'], true) ? 2 : 3,
            'summon' => 1,
            'facing' => substr($reader->string, $reader->cursor, 7) === 'entity ' ? 3 : 3,
            default => 0,
        };
        for ($index = 0; $index < $count; ++$index) {
            $reader->skipWhitespace();
            $this->executeToken($reader);
        }
    }

    /**
     * A condition's test, evaluated in the current source: a count of entities for "entity",
     * otherwise whether it holds.
     */
    private function executeCondition(string $kind, StringReader $reader, Client $sender): \Closure
    {
        switch ($kind) {
            case 'entity':
                $selector = $this->executeToken($reader);
                return fn (): int => count($this->selectEntities($selector, $this->commandContext['entity']['client'] ?? $sender, false, false, true));
            case 'block':
                $position = [$this->executeToken($reader), $this->executeToken($reader), $this->executeToken($reader)];
                $reader->skipWhitespace();
                $predicate = $this->blockPredicate($this->executeToken($reader));
                return fn (): bool => $predicate($this->world->get(...$this->loadedBlockPosition($position, $sender)));
            case 'loaded':
                $position = [$this->executeToken($reader), $this->executeToken($reader), $this->executeToken($reader)];
                return function () use ($position, $sender): bool {
                    try {
                        $this->loadedBlockPosition($position, $sender);
                        return true;
                    } catch (CommandFailure) {
                        return false;
                    }
                };
            case 'dimension':
                $id = $reader->readIdentifier();
                return fn (): bool => $this->commandContext['dimension'] === substr($id, 10);
            case 'score':
                $target = $this->executeToken($reader);
                $objective = $this->executeToken($reader);
                $operation = $this->executeToken($reader);
                if ($operation === 'matches') {
                    [$low, $high] = EntitySelector::readBounds(new StringReader($this->executeToken($reader)), true);
                    return function () use ($target, $objective, $low, $high, $sender): bool {
                        $score = $this->executeScore($target, $objective, $sender);
                        return $score !== null && ($low === null || $score >= $low) && ($high === null || $score <= $high);
                    };
                }
                $source = $this->executeToken($reader);
                $sourceObjective = $this->executeToken($reader);
                return function () use ($target, $objective, $operation, $source, $sourceObjective, $sender): bool {
                    $a = $this->executeScore($target, $objective, $sender);
                    $b = $this->executeScore($source, $sourceObjective, $sender);
                    return $a !== null && $b !== null && match ($operation) {
                        '<' => $a < $b, '<=' => $a <= $b, '=' => $a === $b, '>' => $a > $b, default => $a >= $b,
                    };
                };
            case 'stopwatch':
                $text = $this->executeToken($reader);
                $id = str_contains($text, ':') ? $text : 'minecraft:' . $text;
                [$low, $high] = EntitySelector::readBounds(new StringReader($this->executeToken($reader)), false);
                return function () use ($id, $low, $high): bool {
                    // checkStopwatch: an unknown stopwatch fails the command.
                    $stopwatch = $this->stopwatchList()[$id] ?? throw new CommandFailure(['translate' => 'commands.stopwatch.does_not_exist', 'with' => [['text' => $id]]]);
                    $elapsed = ($stopwatch['accumulated'] + (int) (self::stopwatchMillis() - $stopwatch['created'])) / 1000.0;
                    return ($low === null || $elapsed >= $low) && ($high === null || $elapsed <= $high);
                };
            default:
                throw new \InvalidArgumentException('The execute if ' . $kind . ' condition is not supported yet');
        }
    }

    /** Level.getHeight for a heightmap at the source's column, which must be loaded: one above its top block. */
    private function executeHeight(string $heightmap, array $context, Client $sender): int
    {
        [$x, $z] = [(int) floor($context['x']), (int) floor($context['z'])];
        $this->loadedBlockPosition([(string) $x, (string) $this->world->minY, (string) $z], $sender);
        $flags = $this->version->catalog->flags;
        for ($y = $this->world->minY + $this->world->height - 1; $y >= $this->world->minY; --$y) {
            $state = $this->world->get($x, $y, $z);
            $flag = $flags[$state] ?? 0;
            // Heightmap.Types: any non-air block, motion-blocking blocks or fluids (optionally not
            // leaves), or motion-blocking blocks only.
            $counts = match ($heightmap) {
                'world_surface' => ($flag & 1) === 0,
                'motion_blocking' => ($flag & 6) !== 0,
                'motion_blocking_no_leaves' => ($flag & 6) !== 0 && !str_ends_with($this->version->catalog->blockName($state), '_leaves'),
                default => ($flag & 2) !== 0,
            };
            if ($counts) {
                return $y + 1;
            }
        }
        return $this->world->minY;
    }

    /** ExecuteCommand.spawnEntityAndRedirect: a new entity at the source's position, which becomes the executor. */
    private function executeSummonEntity(string $kind, array $context, Client $sender): array
    {
        $info = $this->version->catalog->mobs['entities'][$kind] ?? null;
        if ($info !== null && (!$info['summonable'] || $kind === 'player')) {
            throw new CommandFailure(['translate' => 'entity.not_summonable', 'with' => [['text' => 'minecraft:' . $kind]]]);
        }
        if (!isset($info['attributes']['max_health'])) {
            throw new \InvalidArgumentException('Unknown living entity type');
        }
        $id = $this->spawnAnimal($kind, $context['x'], $context['y'], $context['z']) ?? throw new CommandFailure(['translate' => 'commands.summon.failed']);
        $animal = $this->animals[$id];
        return ['mob' => $id, 'x' => $animal['x'], 'y' => $animal['y'], 'z' => $animal['z'], 'yaw' => (float) ($animal['yaw'] ?? 0), 'pitch' => 0.0,
            'type' => 'minecraft:' . $kind, 'width' => $info['width'], 'height' => $info['height'], 'id' => $id, 'uuid' => hex2bin($animal['uuid'])];
    }

    /** Scoreboard.getPlayerScoreInfo for one score holder, or null when unset. */
    private function executeScore(string $holder, string $objective, Client $sender): ?int
    {
        $holders = str_starts_with($holder, '@') ? array_map(self::scoreboardName(...), $this->selectEntities($holder, $this->commandContext['entity']['client'] ?? $sender, false, false, true)) : [$holder];
        if (count($holders) !== 1) {
            return null;
        }
        return $this->scoreboard()->scores[$holders[0]][$objective]['value'] ?? null;
    }

    /** Vec3Argument at the reader, in the current source. */
    private function executeVector(StringReader $reader, Client $sender, bool $center): array
    {
        $words = [$this->executeToken($reader), $this->executeToken($reader), $this->executeToken($reader)];
        $offset = static fn (string $text): float => $center && !str_starts_with($text, '~') && !str_contains($text, '.') ? 0.5 : 0.0;
        $position = $this->sourcePosition($sender);
        return $this->localPosition($words, $sender) ?? [
            $this->coordinate($words[0], $position[0]) + $offset($words[0]),
            $this->coordinate($words[1], $position[1]),
            $this->coordinate($words[2], $position[2]) + $offset($words[2]),
        ];
    }

    /** CommandSourceStack.facing: the rotation from the anchored position towards the target. */
    private function executeFacing(array $context, array $target): array
    {
        $origin = [$context['x'], $context['y'] + ($context['anchor'] === 'eyes' && $context['entity'] !== null ? $this->entityEyeHeight($context['entity']) : 0.0), $context['z']];
        [$dx, $dy, $dz] = [$target[0] - $origin[0], $target[1] - $origin[1], $target[2] - $origin[2]];
        $horizontal = sqrt($dx * $dx + $dz * $dz);
        $f32 = \PhpMinecraftJavaServer\World\Generation\Float32::round(...);
        $pitch = $f32(self::wrapDegreesCommand(-(atan2($dy, $horizontal) * 57.2957763671875)));
        $yaw = $f32(self::wrapDegreesCommand(atan2($dz, $dx) * 57.2957763671875 - 90.0));
        return ['yaw' => $yaw, 'pitch' => $pitch];
    }

    private static function wrapDegreesCommand(float $degrees): float
    {
        $wrapped = fmod($degrees, 360.0);
        if ($wrapped >= 180.0) {
            $wrapped -= 360.0;
        }
        if ($wrapped < -180.0) {
            $wrapped += 360.0;
        }
        return $wrapped;
    }

    /** One argument at the reader: up to the next space outside brackets, braces and quotes. */
    private function executeToken(StringReader $reader): string
    {
        $reader->skipWhitespace();
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
            } elseif ($char === '[' || $char === '{') {
                ++$depth;
            } elseif ($char === ']' || $char === '}') {
                --$depth;
            } elseif ($char === ' ' && $depth === 0) {
                break;
            }
            $reader->skip();
        }
        return substr($reader->string, $start, $reader->cursor - $start);
    }
}
