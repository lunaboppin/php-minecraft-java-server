<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\Server\Commands\ArgumentReader;
use PhpMinecraftJavaServer\Server\Commands\CommandRegistry;
use PhpMinecraftJavaServer\Server\Commands\CommandTree;

// Brigadier parse errors: the key and cursor the official 26.3 server reported for each input.
$version = new Version();
$graph = json_decode((string) file_get_contents($version->directory . '/commands.json'), true, 512, JSON_THROW_ON_ERROR)['nodes'];
$vanilla = array_map(static fn (int $child): string => $graph[$child]['name'], $graph[0]['network_children']);
$tree = static function (bool $operator) use ($graph, $vanilla): CommandTree {
    $names = CommandRegistry::names($operator);
    return new CommandTree($graph, $operator ? 4 : 0, array_values(array_intersect($names, $vanilla)), array_values(array_diff($names, $vanilla)));
};
$registries = ArgumentReader::registries($version, ['minecraft:overworld', 'minecraft:the_end']);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    ++$checks;
};
$parse = static fn (CommandTree $tree, string $input, bool $selectors = true): ?array => ($error = $tree->parse($input, new ArgumentReader($registries, $selectors))) === null ? null : [$error->component['translate'], $error->cursor];

$operator = $tree(true);
foreach ([
    ["foo", 'command.unknown.command', 0],
    ["foo bar", 'command.unknown.command', 0],
    ["time", 'command.unknown.command', 4],
    ["time foo", 'command.unknown.argument', 5],
    ["time set", 'command.unknown.command', 8],
    ["time add 1.5.5", 'parsing.float.invalid', 9],
    ["time add 1q", 'argument.time.invalid_unit', 11],
    ["timeset 10", 'command.unknown.command', 0],
    ["gamemode", 'command.unknown.command', 8],
    ["gamemode bogus", 'argument.gamemode.invalid', 14],
    ["gamemode creative @x", 'argument.entity.selector.unknown', 19],
    ["gamemode creative @e[foo=1]", 'argument.entity.options.unknown', 21],
    ["gamemode creative @a[", 'argument.entity.options.unterminated', 21],
    ["difficulty bogus", 'command.unknown.argument', 11],
    ["difficulty peaceful extra", 'command.unknown.argument', 20],
    ["give Witness minecraft:dirt abc", 'parsing.int.expected', 28],
    ["give Witness minecraft:dirt 0", 'argument.integer.low', 28],
    ["give Witness minecraft:dirt 5x", 'command.expected.separator', 29],
    ["give Witness notanitem", 'argument.item.id.invalid', 13],
    ["gamerule keep_inventory maybe", 'parsing.bool.invalid', 24],
    ["gamerule random_tick_speed 1.5", 'parsing.int.invalid', 27],
    ["gamerule random_tick_speed -1", 'argument.integer.low', 27],
    ["gamerule nonexistent_rule", 'command.unknown.argument', 9],
    ["setblock 1 2", 'argument.pos3d.incomplete', 9],
    ["setblock 1 2 3 minecraft:nope", 'argument.block.id.invalid', 15],
    ["setblock ~ ~ ^1 minecraft:stone", 'argument.pos.mixed', 13],
    ["setblock a b c minecraft:stone", 'parsing.int.expected', 9],
    ["setblock 1 2 3", 'command.unknown.command', 14],
    ["tp ~ ~ ~ ~ x", 'command.unknown.argument', 9],
    ["summon minecraft:nope", 'argument.resource.not_found', 21],
    ["summon minecraft:pig 1 2", 'argument.pos3d.incomplete', 21],
    ["summon", 'command.unknown.command', 6],
    ["damage Witness -1", 'argument.float.low', 15],
    ["damage Witness 5 nope", 'argument.resource.not_found', 21],
    // ResourceOrTagArgument: an unknown biome or biome tag.
    ['locate biome minecraft:nope', 'argument.resource.not_found', 27],
    ['locate biome #minecraft:nope', 'argument.resource_tag.not_found', 28],
    ["damage Witness abc", 'parsing.float.expected', 15],
    ["weather rain -5", 'argument.time.tick_count_too_low', 15],
    ["weather rain 5x", 'argument.time.invalid_unit', 15],
    ["weather bogus", 'command.unknown.argument', 8],
    ["kill @e[type=nope]", 'argument.entity.options.type.invalid', 13],
    ["kill @e[limit=0]", 'argument.entity.options.limit.toosmall', 14],
    ["kill @a[distance=-1]", 'argument.entity.options.distance.negative', 17],
    ["kill averyveryverylongplayername", 'argument.entity.invalid', 5],
    ["op", 'command.unknown.command', 2],
    ["deop", 'command.unknown.command', 4],
    ["kick", 'command.unknown.command', 4],
    ["list extra", 'command.unknown.argument', 5],
    ["list uuids extra", 'command.unknown.argument', 11],
    ["seed extra", 'command.unknown.argument', 5],
    ["save-all flushx", 'command.unknown.argument', 9],
    ["version extra", 'command.unknown.argument', 8],
    // Others from the official server: integer-only block positions, local coordinates, repeated options.
    ['setblock 1.5 2 3 stone', 'parsing.int.invalid', 9],
    ['kill @e[type=pig]x', 'command.expected.separator', 17],
    ['kill @s[limit=1]', 'argument.entity.options.inapplicable', 8],
] as [$input, $key, $cursor]) {
    $check($parse($operator, $input) === [$key, $cursor], $input . ' fails with ' . $key . ' at ' . $cursor . ', got ' . json_encode($parse($operator, $input)));
}
foreach (['time set 1000', 'time set noon', 'gamemode creative @a[gamemode=survival]', 'give @s diamond_sword[damage=5] 2', 'tp @s ~ ~1 ~', 'tp 1 2', 'setblock ~ ~ ~ oak_stairs[facing=north]', 'kill @e[type=pig,limit=1,sort=nearest]', 'msg @a hi @x', 'weather rain 3d', 'help time', 'spawn', 'gamerule keep_inventory true', 'locate biome plains', 'locate biome #minecraft:is_forest', 'locate structure #village', 'locate structure minecraft:nope', 'locate poi minecraft:home'] as $input) {
    $check($parse($operator, $input) === null, $input . ' parses');
}
$player = $tree(false);
$check($parse($player, 'gamemode creative', false) === ['command.unknown.command', 0], 'operator commands are unknown to players');
$check($parse($player, 'msg @a hi', false) === ['argument.entity.selector.not_allowed', 4], 'players cannot use selectors');
$check($parse($player, 'msg ParityProbe hi @a', false) === null, 'a message keeps selectors as text for players');
echo "PASS: {$checks} command parse checks\n";
