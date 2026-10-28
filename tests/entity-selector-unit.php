<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Server\Commands\CommandArguments;
use PhpMinecraftJavaServer\Server\Commands\CommandFailure;
use PhpMinecraftJavaServer\Server\Commands\EntitySelector;

$selectorChecks = 0;
$selectorCheck = static function (bool $condition, string $message) use (&$selectorChecks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    ++$selectorChecks;
};
$failure = static function (string $text): ?string {
    try {
        EntitySelector::parse($text);
        return null;
    } catch (CommandFailure $error) {
        return $error->component['translate'];
    }
};

$pigs = EntitySelector::parse('@e[type=pig, limit=2 ,sort=nearest,name=!"Big Pig"]');
$selectorCheck($pigs->kind === 'e' && $pigs->options['type'] === [[false, 'minecraft:pig']] && $pigs->options['limit'] === [[false, 2]], 'options parse with spaces and defaults');
$selectorCheck($pigs->options['name'] === [[true, 'Big Pig']] && !$pigs->playersOnly() && !$pigs->single(), 'quoted negated names');
$selectorCheck(EntitySelector::parse('@e[type=minecraft:player]')->playersOnly() && EntitySelector::parse('@a')->playersOnly(), 'player-only selectors');
$selectorCheck(EntitySelector::parse('@e[limit=1]')->single() && EntitySelector::parse('Steve')->single() && !EntitySelector::parse('@a')->single(), 'single-result selectors');
$selectorCheck(EntitySelector::parse('@a[distance=..5,level=2..,x_rotation=-90..0]')->options['distance'] === [[false, [null, 5.0]]], 'ranges');
$selectorCheck(EntitySelector::parse('0-0-0-0-1')->uuid === str_repeat("\0", 15) . "\1", 'short UUID groups are padded');
foreach ([
    '@x' => 'argument.entity.selector.unknown', '@s[limit=2]' => 'argument.entity.options.inapplicable', '@a[type=pig]' => 'argument.entity.options.inapplicable',
    '@e[limit=0]' => 'argument.entity.options.limit.toosmall', '@e[distance=-1]' => 'argument.entity.options.distance.negative', '@e[color=red]' => 'argument.entity.options.unknown',
    '@e[gamemode=hard]' => 'argument.entity.options.mode.invalid', '@e[sort=up]' => 'argument.entity.options.sort.irreversible', '@e[name]' => 'argument.entity.options.valueless',
    '@e[name=Bob' => 'argument.entity.options.unterminated', '@e[limit=1,limit=2]' => 'argument.entity.options.inapplicable', '@e[name=!a,name=b]' => 'argument.entity.options.inapplicable', 'Way_Too_Long_Name_' => 'argument.entity.invalid',
] as $text => $key) {
    $selectorCheck($failure($text) === $key, $text . ' fails with ' . $key);
}
// Vanilla limits and sorts @p, @r and @n too; only @s rejects them.
$selectorCheck($failure('@p[limit=2,sort=furthest]') === null && $failure('@e[name=!a,name=!b]') === null, 'repeatable and kind-independent options');
$selectorCheck(EntitySelector::parse('@e[nbt={a:[1,2]},type=pig]')->unsupported() === 'nbt' && EntitySelector::parse('@e[type=pig]')->unsupported() === null, 'unevaluated options are consumed');
$selectorCheck(CommandArguments::parse('kill @e[type=pig, limit=2] extra')[1] === ['@e[type=pig, limit=2]', 'extra'], 'tokenizing keeps selector brackets together');
echo "PASS: {$selectorChecks} entity selector parsing checks\n";
