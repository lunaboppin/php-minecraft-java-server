<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

/** A context number provider that failed to decode, with the codec's message. */
final class ContextNumberError extends \RuntimeException
{
    /** @param bool $partial whether a list read part of its elements, so the error is reported unwrapped */
    public function __construct(string $message, public readonly bool $partial = false)
    {
        parent::__construct($message);
    }
}
