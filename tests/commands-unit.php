<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Server\Commands\CommandArguments;
use PhpMinecraftJavaServer\Server\Commands\CommandRegistry;
use PhpMinecraftJavaServer\Server\Server;

$publicCommands = ['help', 'spawn', 'list', 'msg', 'tell', 'w', 'me', 'teammsg', 'tm', 'trigger'];
$operatorCommands = [
    ...$publicCommands,
    'version', 'gamemode', 'give', 'tp', 'teleport', 'time', 'weather', 'seed', 'setworldspawn', 'spawnpoint', 'kill', 'heal', 'setblock', 'fill', 'gamerule', 'save-all', 'summon', 'dimension', 'difficulty',
    'defaultgamemode', 'op', 'deop', 'kick', 'save-on', 'save-off', 'stop', 'damage', 'experience', 'xp', 'clear', 'worldborder', 'enchant', 'ban', 'pardon', 'banlist', 'ban-ip', 'pardon-ip', 'whitelist', 'tellraw',
    'title', 'scoreboard', 'team', 'effect', 'playsound', 'stopsound', 'rotate', 'attribute', 'bossbar', 'tag', 'setidletimeout', 'say',
];

if (CommandRegistry::names(false) !== $publicCommands || CommandRegistry::names(true) !== $operatorCommands) {
    throw new RuntimeException('Command names, order or permissions changed during modularization');
}

$commandServer = new ReflectionClass(Server::class);
$registeredNames = [];
$commandFiles = [];
foreach (CommandRegistry::definitions() as $definition) {
    $handler = $commandServer->getMethod($definition->handler);
    if (!$handler->isPrivate() || dirname($handler->getFileName()) !== realpath(__DIR__ . '/../src/Server/Commands')) {
        throw new RuntimeException('Command behavior must live in a private handler in the commands folder');
    }
    if (isset($commandFiles[$handler->getFileName()])) {
        throw new RuntimeException('Each command must have its own handler file');
    }
    $commandFiles[$handler->getFileName()] = true;
    foreach ([$definition->name, ...$definition->aliases] as $name) {
        if (isset($registeredNames[$name])) {
            throw new RuntimeException('Duplicate command or alias: ' . $name);
        }
        $registeredNames[$name] = true;
        if (CommandRegistry::resolve($name, true)?->handler !== $definition->handler) {
            throw new RuntimeException('Command alias resolves to the wrong handler: ' . $name);
        }
        if ((CommandRegistry::resolve($name, false) === null) !== $definition->requiresOperator) {
            throw new RuntimeException('Command permission bypass or unexpected denial: ' . $name);
        }
    }
}

foreach (['unknown', 'executeGive', '__construct', 'tp;stop'] as $unknownCommand) {
    if (CommandRegistry::resolve($unknownCommand, true) !== null) {
        throw new RuntimeException('Unregistered input selected a command handler');
    }
}

if (CommandArguments::parse("  GiVe\t@s  minecraft:stone   2  ") !== ['give', ['@s', 'minecraft:stone', '2']]) {
    throw new RuntimeException('Command argument case, whitespace or token content changed');
}
if (CommandArguments::parse('') !== ['', []]) {
    throw new RuntimeException('Empty command parsing changed');
}

echo "PASS: modular command registration, aliases, permissions and argument preservation\n";
