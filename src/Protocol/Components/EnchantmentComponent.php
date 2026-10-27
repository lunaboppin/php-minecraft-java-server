<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Protocol\Components;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\ProtocolException;

/** Shared payload of ENCHANTMENTS and STORED_ENCHANTMENTS in protocol 777. */
final class EnchantmentComponent
{
    /** @var array<int, string> */
    private array $names = [];

    /** @param array<string, int> $ids Client-synchronized enchantment registry IDs. */
    public function __construct(private readonly array $ids)
    {
        foreach ($ids as $name => $id) {
            if (!is_string($name) || !is_int($id) || $id < 0 || isset($this->names[$id])) {
                throw new \InvalidArgumentException('Invalid enchantment registry');
            }
            $this->names[$id] = $name;
        }
    }

    /** @return array<string, int> */
    public function read(Binary $input): array
    {
        $count = $input->varInt();
        // Every entry needs at least one byte for its holder and one for its level.
        if ($count < 0 || $count > intdiv($input->remaining(), 2)) {
            throw new ProtocolException('Invalid enchantment entry count');
        }
        $values = [];
        for ($index = 0; $index < $count; ++$index) {
            $name = $this->names[$input->varInt()] ?? throw new ProtocolException('Unknown enchantment holder');
            $values[$name] = $input->varInt();
        }
        // The native map decoder overwrites duplicates before its constructor
        // validates the final levels. An overwritten invalid level can be valid.
        foreach ($values as $level) {
            if ($level < 0 || $level > 255) {
                throw new ProtocolException('Enchantment level outside 0..255');
            }
        }
        return $values;
    }

    /** @param array<string, int> $values */
    public function write(array $values): string
    {
        $output = Binary::writeVarInt(count($values));
        foreach ($values as $name => $level) {
            $id = $this->ids[$name] ?? throw new \InvalidArgumentException('Unknown enchantment holder');
            if (!is_int($level) || $level < 0 || $level > 255) {
                throw new \InvalidArgumentException('Enchantment level outside 0..255');
            }
            $output .= Binary::writeVarInt($id) . Binary::writeVarInt($level);
        }
        return $output;
    }
}
