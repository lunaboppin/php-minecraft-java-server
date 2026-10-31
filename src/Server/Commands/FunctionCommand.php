<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait FunctionCommand
{
    /** A function or #tag argument's ID, namespaced. */
    private static function functionId(string $text): string
    {
        $name = ltrim($text, '#');
        return str_contains($name, ':') ? $name : 'minecraft:' . $name;
    }

    /**
     * FunctionArgument.getFunctions: a function by ID, or #tag for a function tag. Without data
     * packs no function exists, and every tag is empty.
     *
     * @return list<string> the functions named
     */
    private function functionsNamed(string $text): array
    {
        $id = self::functionId($text);
        if (!str_starts_with($text, '#')) {
            throw new CommandFailure(['translate' => 'arguments.function.unknown', 'with' => [['text' => $id]]]);
        }
        return [];
    }

    /** FunctionCommand.runFunction: a tag that names no functions fails. */
    private function executeFunction(Client $sender, array $arguments): void
    {
        $name = $arguments[0] ?? '';
        if ($this->functionsNamed($name) === []) {
            throw new CommandFailure(['translate' => 'commands.function.scheduled.no_functions', 'with' => [['text' => self::functionId($name)]]]);
        }
    }
}
