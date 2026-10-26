<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

final readonly class CommandDefinition
{
    /** @param list<string> $aliases */
    public function __construct(
        public string $name,
        public string $handler,
        public bool $requiresOperator,
        public array $aliases = []
    ) {
    }
}
