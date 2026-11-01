<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

use PhpMinecraftJavaServer\Protocol\Binary;

/**
 * A section's biomes as vanilla's PalettedContainer holds them once /fillbiome has rewritten
 * them, so the palette order sent to clients follows the same history: a single value, then a
 * linear palette of 1 to 3 bits that grows in the order values arrive, then registry IDs.
 *
 * A container is ['bits' => int, 'palette' => list<string>, 'values' => list<int>] with 64
 * values in y, z, x order indexing the palette. A global container's palette only names its IDs.
 */
final class BiomeContainer
{
    public const GLOBAL = 4;

    /** The container the chunk packet would describe for these 64 biomes, as Palette::biomes encodes them. */
    public static function fromBiomes(array $biomes): array
    {
        $palette = array_values(array_unique($biomes));
        $map = array_flip($palette);
        $bits = count($palette) === 1 ? 0 : max(1, (int) ceil(log(count($palette), 2)));
        return ['bits' => $bits > 3 ? self::GLOBAL : $bits, 'palette' => $palette, 'values' => array_map(static fn (string $biome): int => $map[$biome], $biomes)];
    }

    /** PalettedContainer.recreate: an empty container holding the old palette's first entry. */
    public static function recreate(array $container, string $firstRegistryEntry): array
    {
        $first = $container['bits'] === self::GLOBAL ? $firstRegistryEntry : $container['palette'][0];
        return ['bits' => 0, 'palette' => [$first], 'values' => array_fill(0, 64, 0)];
    }

    public static function get(array $container, int $index): string
    {
        return $container['palette'][$container['values'][$index]];
    }

    /** PalettedContainer.getAndSet: a value missing from a full palette resizes it first. */
    public static function set(array &$container, int $index, string $biome): void
    {
        $id = array_search($biome, $container['palette'], true);
        if ($id === false) {
            $capacity = match ($container['bits']) {
                0 => 1,
                self::GLOBAL => PHP_INT_MAX,
                default => 1 << $container['bits'],
            };
            if (count($container['palette']) >= $capacity) {
                $container = self::resize($container, $container['bits'] + 1);
            }
            $container['palette'][] = $biome;
            $id = count($container['palette']) - 1;
        }
        $container['values'][$index] = $id;
    }

    /** PalettedContainer.onResize: Data.copyFrom keeps only the values in use, in storage order. */
    private static function resize(array $old, int $bits): array
    {
        $resized = ['bits' => $bits > 3 ? self::GLOBAL : $bits, 'palette' => [], 'values' => []];
        if (count($old['palette']) === 1) {
            $resized['palette'] = [$old['palette'][0]];
            $resized['values'] = array_fill(0, 64, 0);
            return $resized;
        }
        $ids = [];
        foreach ($old['values'] as $value) {
            $biome = $old['palette'][$value];
            if (!isset($ids[$biome])) {
                $ids[$biome] = count($resized['palette']);
                $resized['palette'][] = $biome;
            }
            $resized['values'][] = $ids[$biome];
        }
        return $resized;
    }

    /**
     * PalettedContainer.write for the chunk and chunks_biomes packets.
     *
     * @param callable(string): int $id the biome's registry ID
     */
    public static function encode(array $container, callable $id, int $globalBits): string
    {
        if ($container['bits'] === 0) {
            return "\0" . Binary::writeVarInt($id($container['palette'][0]));
        }
        if ($container['bits'] === self::GLOBAL) {
            $ids = array_map($id, $container['palette']);
            return chr($globalBits) . Palette::pack(array_map(static fn (int $value): int => $ids[$value], $container['values']), $globalBits);
        }
        $out = chr($container['bits']) . Binary::writeVarInt(count($container['palette']));
        foreach ($container['palette'] as $biome) {
            $out .= Binary::writeVarInt($id($biome));
        }
        return $out . Palette::pack($container['values'], $container['bits']);
    }
}
