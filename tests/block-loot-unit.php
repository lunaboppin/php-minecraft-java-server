<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\BlockLoot;

// Official 26.3 block loot tables, with a fixed random source.
$version = new Version();
$data = json_decode((string) file_get_contents($version->directory . '/block-loot.json'), true, 512, JSON_THROW_ON_ERROR);
$tags = static fn (string $registry): Closure => static fn (string $tag): array => array_map(static fn (string $name): string => 'minecraft:' . $name, $version->registryTags($registry)[$tag] ?? []);
$loot = static function (float $random) use ($data, $tags): BlockLoot {
    return new BlockLoot($data, $tags('minecraft:item'), $tags('minecraft:block'), static fn (): float => $random);
};
$air = static fn (int $offset): array => ['minecraft:air', []];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    ++$checks;
};
$pickaxe = ['item' => 'minecraft:diamond_pickaxe'];
$silk = ['item' => 'minecraft:diamond_pickaxe', 'enchantments' => ['minecraft:silk_touch' => 1]];
$fortune = ['item' => 'minecraft:diamond_pickaxe', 'enchantments' => ['minecraft:fortune' => 3]];
$shears = ['item' => 'minecraft:shears'];

$check($loot(0.5)->drops('minecraft:stone', [], $pickaxe, $air) === [['minecraft:cobblestone', 1]], 'stone drops cobblestone');
$check($loot(0.5)->drops('minecraft:stone', [], $silk, $air) === [['minecraft:stone', 1]], 'silk touch keeps stone');
$check($loot(0.5)->drops('minecraft:grass_block', ['snowy' => 'false'], null, $air) === [['minecraft:dirt', 1]], 'grass drops dirt by hand');
$check($loot(0.99)->drops('minecraft:diamond_ore', [], $pickaxe, $air) === [['minecraft:diamond', 1]], 'ore drops without fortune');
// ApplyBonusCount.OreDrops: nextInt(level + 2) - 1 extra multiples, at least none.
$check($loot(0.99)->drops('minecraft:diamond_ore', [], $fortune, $air) === [['minecraft:diamond', 4]], 'fortune III at its maximum');
$check($loot(0.0)->drops('minecraft:diamond_ore', [], $fortune, $air) === [['minecraft:diamond', 1]], 'fortune III at its minimum');
$check($loot(0.5)->drops('minecraft:glass', [], $pickaxe, $air) === [] && $loot(0.5)->drops('minecraft:glass', [], $silk, $air) === [['minecraft:glass', 1]], 'glass needs silk touch');
$check($loot(0.5)->drops('minecraft:short_grass', [], $shears, $air) === [['minecraft:short_grass', 1]], 'shears keep grass');
$check($loot(0.5)->drops('minecraft:short_grass', [], null, $air) === [] && $loot(0.01)->drops('minecraft:short_grass', [], null, $air) === [['minecraft:wheat_seeds', 1]], 'grass seeds have a 1/8 chance');
$check($loot(0.99)->drops('minecraft:oak_leaves', ['distance' => '7', 'persistent' => 'false', 'waterlogged' => 'false'], $shears, $air) === [['minecraft:oak_leaves', 1]], 'shears keep leaves');
$check($loot(0.99)->drops('minecraft:oak_leaves', ['distance' => '7', 'persistent' => 'false', 'waterlogged' => 'false'], null, $air) === [], 'leaves rarely drop by hand');
// Ripe wheat: the crop, then seeds with three binomial bonus trials.
$check($loot(0.0)->drops('minecraft:wheat', ['age' => '7'], null, $air) === [['minecraft:wheat', 1], ['minecraft:wheat_seeds', 4]], 'ripe wheat');
$check($loot(0.0)->drops('minecraft:wheat', ['age' => '3'], null, $air) === [['minecraft:wheat_seeds', 1]], 'unripe wheat drops a seed');
$check($loot(0.5)->drops('minecraft:amethyst_cluster', ['facing' => 'up', 'waterlogged' => 'false'], $pickaxe, $air) === [['minecraft:amethyst_shard', 4]], 'item tags in tool predicates');
$upper = static fn (int $offset): array => $offset === 1 ? ['minecraft:tall_grass', ['half' => 'upper']] : ['minecraft:air', []];
$check($loot(0.5)->drops('minecraft:tall_grass', ['half' => 'lower'], $shears, $upper) === [['minecraft:short_grass', 2]] && $loot(0.5)->drops('minecraft:tall_grass', ['half' => 'lower'], $shears, $air) === [], 'location checks see the upper half');
echo "PASS: {$checks} block loot table checks\n";
