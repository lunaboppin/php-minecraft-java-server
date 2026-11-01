<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Protocol;

/** An int array field in a text component, such as a profile's UUID. */
final class NbtIntArray
{
    /** @param list<int> $values */
    public function __construct(public readonly array $values)
    {
    }

    /** UUIDUtil.uuidToIntArray: four signed big-endian ints. */
    public static function uuid(string $uuid): self
    {
        return new self(array_map(static fn (int $value): int => $value >= 0x80000000 ? $value - 0x100000000 : $value, array_values(unpack('N4', $uuid))));
    }
}
