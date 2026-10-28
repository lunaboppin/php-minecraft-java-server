<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Loot;

// Official 26.3 block and entity loot tables, with a fixed random source.
$version = new Version();
$data = json_decode((string) file_get_contents($version->directory . '/loot.json'), true, 512, JSON_THROW_ON_ERROR);
$tags = static fn (string $registry, string $tag): array => $registry === 'minecraft:damage_type' ? $version->catalog->damageTypes['tags'][$tag] : array_map(static fn (string $name): string => 'minecraft:' . $name, $version->registryTags($registry)[$tag] ?? []);
$smelt = static fn (string $item): ?string => ['minecraft:beef' => 'minecraft:cooked_beef', 'minecraft:porkchop' => 'minecraft:cooked_porkchop'][$item] ?? null;
$table = static function (float $random) use ($data, $tags, $smelt): Closure {
    $loot = new Loot($data, $tags, $smelt, static fn (): float => $random);
    return static fn (string $block, array $state, ?array $tool, Closure $blockAt): array => $loot->drops('minecraft:blocks/' . substr($block, 10), ['block' => $block, 'state' => $state, 'tool' => $tool, 'blockAt' => $blockAt]);
};
$entity = static fn (float $random, string $type, array $context): array => (new Loot($data, $tags, $smelt, static fn (): float => $random))->drops('minecraft:entities/' . $type, $context);
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

$check($table(0.5)('minecraft:stone', [], $pickaxe, $air) === [['minecraft:cobblestone', 1]], 'stone drops cobblestone');
$check($table(0.5)('minecraft:stone', [], $silk, $air) === [['minecraft:stone', 1]], 'silk touch keeps stone');
$check($table(0.5)('minecraft:grass_block', ['snowy' => 'false'], null, $air) === [['minecraft:dirt', 1]], 'grass drops dirt by hand');
$check($table(0.99)('minecraft:diamond_ore', [], $pickaxe, $air) === [['minecraft:diamond', 1]], 'ore drops without fortune');
// ApplyBonusCount.OreDrops: nextInt(level + 2) - 1 extra multiples, at least none.
$check($table(0.99)('minecraft:diamond_ore', [], $fortune, $air) === [['minecraft:diamond', 4]], 'fortune III at its maximum');
$check($table(0.0)('minecraft:diamond_ore', [], $fortune, $air) === [['minecraft:diamond', 1]], 'fortune III at its minimum');
$check($table(0.5)('minecraft:glass', [], $pickaxe, $air) === [] && $table(0.5)('minecraft:glass', [], $silk, $air) === [['minecraft:glass', 1]], 'glass needs silk touch');
$check($table(0.5)('minecraft:short_grass', [], $shears, $air) === [['minecraft:short_grass', 1]], 'shears keep grass');
$check($table(0.5)('minecraft:short_grass', [], null, $air) === [] && $table(0.01)('minecraft:short_grass', [], null, $air) === [['minecraft:wheat_seeds', 1]], 'grass seeds have a 1/8 chance');
$check($table(0.99)('minecraft:oak_leaves', ['distance' => '7', 'persistent' => 'false', 'waterlogged' => 'false'], $shears, $air) === [['minecraft:oak_leaves', 1]], 'shears keep leaves');
$check($table(0.99)('minecraft:oak_leaves', ['distance' => '7', 'persistent' => 'false', 'waterlogged' => 'false'], null, $air) === [], 'leaves rarely drop by hand');
// Ripe wheat: the crop, then seeds with three binomial bonus trials.
$check($table(0.0)('minecraft:wheat', ['age' => '7'], null, $air) === [['minecraft:wheat', 1], ['minecraft:wheat_seeds', 4]], 'ripe wheat');
$check($table(0.0)('minecraft:wheat', ['age' => '3'], null, $air) === [['minecraft:wheat_seeds', 1]], 'unripe wheat drops a seed');
$check($table(0.5)('minecraft:amethyst_cluster', ['facing' => 'up', 'waterlogged' => 'false'], $pickaxe, $air) === [['minecraft:amethyst_shard', 4]], 'item tags in tool predicates');
$upper = static fn (int $offset): array => $offset === 1 ? ['minecraft:tall_grass', ['half' => 'upper']] : ['minecraft:air', []];
$check($table(0.5)('minecraft:tall_grass', ['half' => 'lower'], $shears, $upper) === [['minecraft:short_grass', 2]] && $table(0.5)('minecraft:tall_grass', ['half' => 'lower'], $shears, $air) === [], 'location checks see the upper half');

$cow = ['type' => 'minecraft:cow', 'flags' => ['is_on_fire' => false]];
$player = static fn (array $enchantments = []): array => ['type' => 'minecraft:player', 'equipment' => ['mainhand' => ['item' => 'minecraft:diamond_sword', 'enchantments' => $enchantments]]];
$check($entity(0.99, 'cow', ['this' => $cow]) === [['minecraft:leather', 2], ['minecraft:beef', 3]], 'cow loot at its maximum');
$check($entity(0.0, 'cow', ['this' => $cow]) === [['minecraft:beef', 1]], 'cow loot at its minimum');
$check($entity(0.0, 'cow', ['this' => ['flags' => ['is_on_fire' => true]] + $cow]) === [['minecraft:cooked_beef', 1]], 'burning mobs drop cooked meat');
$check($entity(0.0, 'cow', ['this' => $cow, 'attacker' => $player(['minecraft:fire_aspect' => 1]), 'direct_attacker' => $player(['minecraft:fire_aspect' => 1])]) === [['minecraft:cooked_beef', 1]], 'fire aspect smelts loot');
// Looting III adds round(3 * uniform(0, 1)) to each stack.
$check($entity(0.99, 'cow', ['this' => $cow, 'attacker' => $player(['minecraft:looting' => 3])]) === [['minecraft:leather', 5], ['minecraft:beef', 6]], 'looting raises counts');
$sheep = static fn (string $color, bool $sheared): array => ['type' => 'minecraft:sheep', 'components' => ['minecraft:sheep/color' => $color], 'type_specific' => ['sheep' => ['sheared' => $sheared]]];
$check($entity(0.0, 'sheep', ['this' => $sheep('red', false)]) === [['minecraft:mutton', 1], ['minecraft:red_wool', 1]] && $entity(0.0, 'sheep', ['this' => $sheep('red', true)]) === [['minecraft:mutton', 1]], 'sheep drop wool of their colour unless sheared');
$check($entity(0.99, 'spider', ['this' => ['type' => 'minecraft:spider']]) === [['minecraft:string', 2]] && $entity(0.99, 'spider', ['this' => ['type' => 'minecraft:spider'], 'killed_by_player' => true]) === [['minecraft:string', 2], ['minecraft:spider_eye', 1]], 'spider eyes need a player kill');
$check(count($entity(0.0, 'creeper', ['this' => ['type' => 'minecraft:creeper'], 'attacker' => ['type' => 'minecraft:skeleton']])) === 1 && $entity(0.0, 'creeper', ['this' => ['type' => 'minecraft:creeper']]) === [], 'a skeleton kill drops a music disc');
echo "PASS: {$checks} loot table checks\n";
