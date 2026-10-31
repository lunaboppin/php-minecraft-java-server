<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

final class CommandRegistry
{
    /** @return list<CommandDefinition> */
    public static function definitions(): array
    {
        return [
            new CommandDefinition('help', 'executeHelp', false),
            new CommandDefinition('spawn', 'executeSpawn', false),
            new CommandDefinition('list', 'executeList', false),
            new CommandDefinition('msg', 'executeMessage', false, ['tell', 'w']),
            new CommandDefinition('me', 'executeMe', false),
            new CommandDefinition('teammsg', 'executeTeamMsg', false, ['tm']),
            new CommandDefinition('trigger', 'executeTrigger', false),
            // Anyone may draw; sequences and resets need game-master permission in the command tree.
            new CommandDefinition('random', 'executeRandom', false),
            // A dedicated server requires game-master permission for /version.
            new CommandDefinition('version', 'executeVersion', true),
            new CommandDefinition('gamemode', 'executeGameMode', true),
            new CommandDefinition('give', 'executeGive', true),
            new CommandDefinition('tp', 'executeTeleport', true, ['teleport']),
            new CommandDefinition('time', 'executeTime', true),
            new CommandDefinition('weather', 'executeWeather', true),
            new CommandDefinition('seed', 'executeSeed', true),
            new CommandDefinition('locate', 'executeLocate', true),
            new CommandDefinition('setworldspawn', 'executeSetWorldSpawn', true),
            new CommandDefinition('spawnpoint', 'executeSpawnPoint', true),
            new CommandDefinition('kill', 'executeKill', true),
            new CommandDefinition('heal', 'executeHeal', true),
            new CommandDefinition('setblock', 'executeSetBlock', true),
            new CommandDefinition('fill', 'executeFill', true),
            new CommandDefinition('gamerule', 'executeGameRule', true),
            new CommandDefinition('save-all', 'executeSaveAll', true),
            new CommandDefinition('summon', 'executeSummon', true),
            new CommandDefinition('dimension', 'executeDimension', true),
            new CommandDefinition('difficulty', 'executeDifficulty', true),
            new CommandDefinition('defaultgamemode', 'executeDefaultGameMode', true),
            new CommandDefinition('op', 'executeOp', true),
            new CommandDefinition('deop', 'executeDeOp', true),
            new CommandDefinition('kick', 'executeKick', true),
            new CommandDefinition('save-on', 'executeSaveOn', true),
            new CommandDefinition('save-off', 'executeSaveOff', true),
            new CommandDefinition('stop', 'executeStop', true),
            new CommandDefinition('damage', 'executeDamage', true),
            new CommandDefinition('experience', 'executeExperience', true, ['xp']),
            new CommandDefinition('clear', 'executeClear', true),
            new CommandDefinition('worldborder', 'executeWorldBorder', true),
            new CommandDefinition('enchant', 'executeEnchant', true),
            new CommandDefinition('ban', 'executeBan', true),
            new CommandDefinition('pardon', 'executePardon', true),
            new CommandDefinition('banlist', 'executeBanList', true),
            new CommandDefinition('ban-ip', 'executeBanIp', true),
            new CommandDefinition('pardon-ip', 'executePardonIp', true),
            new CommandDefinition('whitelist', 'executeWhitelist', true),
            new CommandDefinition('tellraw', 'executeTellraw', true),
            new CommandDefinition('title', 'executeTitle', true),
            new CommandDefinition('scoreboard', 'executeScoreboard', true),
            new CommandDefinition('team', 'executeTeam', true),
            new CommandDefinition('effect', 'executeEffect', true),
            new CommandDefinition('playsound', 'executePlaySound', true),
            new CommandDefinition('stopsound', 'executeStopSound', true),
            new CommandDefinition('rotate', 'executeRotate', true),
            new CommandDefinition('attribute', 'executeAttribute', true),
            new CommandDefinition('bossbar', 'executeBossBar', true),
            new CommandDefinition('tag', 'executeTag', true),
            new CommandDefinition('setidletimeout', 'executeSetIdleTimeout', true),
            new CommandDefinition('say', 'executeSay', true),
            new CommandDefinition('execute', 'executeExecute', true),
            new CommandDefinition('dialog', 'executeDialog', true),
            new CommandDefinition('clone', 'executeClone', true),
            new CommandDefinition('forceload', 'executeForceLoad', true),
            new CommandDefinition('particle', 'executeParticle', true),
            new CommandDefinition('spectate', 'executeSpectate', true),
            new CommandDefinition('swing', 'executeSwing', true),
            new CommandDefinition('stopwatch', 'executeStopwatch', true),
            new CommandDefinition('tick', 'executeTick', true),
            new CommandDefinition('transfer', 'executeTransfer', true),
            new CommandDefinition('posteffect', 'executePostEffect', true),
            new CommandDefinition('ride', 'executeRide', true),
            new CommandDefinition('item', 'executeItem', true),
            new CommandDefinition('function', 'executeFunction', true),
            new CommandDefinition('schedule', 'executeSchedule', true),
            new CommandDefinition('return', 'executeReturn', true),
            new CommandDefinition('reload', 'executeReload', true),
            new CommandDefinition('debug', 'executeDebug', true),
            new CommandDefinition('data', 'executeData', true),
            new CommandDefinition('loot', 'executeLoot', true),
            new CommandDefinition('spreadplayers', 'executeSpreadPlayers', true),
        ];
    }

    /** @return list<string> */
    public static function names(bool $operator): array
    {
        $names = [];
        foreach (self::definitions() as $definition) {
            if (!$definition->requiresOperator || $operator) {
                array_push($names, $definition->name, ...$definition->aliases);
            }
        }
        return $names;
    }

    public static function resolve(string $name, bool $operator): ?CommandDefinition
    {
        foreach (self::definitions() as $definition) {
            if ($definition->name !== $name && !in_array($name, $definition->aliases, true)) {
                continue;
            }
            return !$definition->requiresOperator || $operator ? $definition : null;
        }
        return null;
    }
}
