<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

/** A CommandSyntaxException raised during execution; vanilla reports it with sendFailure. */
final class CommandFailure extends \RuntimeException
{
    public function __construct(public readonly array $component)
    {
        parent::__construct($component['translate'] ?? 'Command failed');
    }
}
