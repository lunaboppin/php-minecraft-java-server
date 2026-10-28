<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Commands as CommandModules;

trait Commands
{
    use CommandModules\HelpCommand;

    use CommandModules\ListCommand;

    use CommandModules\VersionCommand;

    use CommandModules\SpawnCommand;

    use CommandModules\MeCommand;

    use CommandModules\MessageCommand;

    use CommandModules\DimensionCommand;

    use CommandModules\SummonCommand;

    use CommandModules\SeedCommand;

    use CommandModules\GameModeCommand;

    use CommandModules\GiveCommand;

    use CommandModules\TeleportCommand;

    use CommandModules\TimeCommand;

    use CommandModules\WeatherCommand;

    use CommandModules\GameRuleCommand;

    use CommandModules\HealCommand;

    use CommandModules\KillCommand;

    use CommandModules\SetWorldSpawnCommand;

    use CommandModules\SpawnPointCommand;

    use CommandModules\SetBlockCommand;

    use CommandModules\FillCommand;

    use CommandModules\SaveAllCommand;

    use CommandModules\DifficultyCommand;

    use CommandModules\DefaultGameModeCommand;

    use CommandModules\OpCommand;

    use CommandModules\DeOpCommand;

    use CommandModules\KickCommand;

    use CommandModules\SaveOnCommand;

    use CommandModules\SaveOffCommand;

    use CommandModules\StopCommand;

    private function operator(Client $client): bool
    {
        foreach ($this->config->operators as $name) {
            if (strcasecmp($name, $client->name) === 0) {
                return true;
            }
        }
        return false;
    }

    private function tell(Client $client, string $text): void
    {
        $this->log('[Server -> ' . $client->name . '] ' . $text);
        $client->send($this->version, 'system_chat', Packets::chat($text));
    }

    /** Vanilla sendSuccess: tell the source, and optionally echo to other operators as an admin message. */
    private function sendSuccess(Client $sender, array $message, bool $broadcastToOperators): void
    {
        $this->log('[' . $sender->name . '] ' . json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $sender->send($this->version, 'system_chat', Packets::systemChat($message));
        if (!$broadcastToOperators) {
            return;
        }
        $admin = ['translate' => 'chat.type.admin', 'with' => [Packets::playerName($sender->name, $sender->uuid), $message], 'color' => 'gray', 'italic' => true];
        foreach ($this->players() as $player) {
            if ($player !== $sender && $this->operator($player)) {
                $player->send($this->version, 'system_chat', Packets::systemChat($admin));
            }
        }
    }

    /** Vanilla sendFailure: the message in red, without a parse-context line. */
    private function sendFailure(Client $sender, array $message): void
    {
        $sender->send($this->version, 'system_chat', Packets::systemChat(['text' => '', 'color' => 'red', 'extra' => [$message]]));
    }

    /**
     * EntityArgument.players for names, UUIDs and the @s, @p, @r and @a selectors. Selector
     * arguments in brackets are not parsed yet (#10). An empty result is vanilla's failure.
     *
     * @return list<Client>
     */
    private function selectPlayers(string $selector, Client $sender): array
    {
        $players = array_values($this->players());
        $selected = match ($selector) {
            '@s' => [$sender],
            // The executing player is always the nearest to its own position.
            '@p' => [$sender],
            '@r' => $players === [] ? [] : [$players[array_rand($players)]],
            '@a' => $players,
            default => null,
        };
        if ($selected === null) {
            if (str_starts_with($selector, '@')) {
                throw new \InvalidArgumentException('Unsupported selector: ' . $selector);
            }
            $uuid = preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $selector) === 1 ? hex2bin(str_replace('-', '', $selector)) : null;
            $selected = array_values(array_filter($players, static fn (Client $player): bool => $uuid !== null ? $player->uuid === $uuid : strcasecmp($player->name, $selector) === 0));
        }
        if ($selected === []) {
            throw new CommandModules\CommandFailure(['translate' => 'argument.entity.notfound.player']);
        }
        return $selected;
    }

    /**
     * GameProfileArgument: online players by selector or name, otherwise a valid offline username.
     * Unlike vanilla, unknown names are not checked against Mojang's profile service.
     *
     * @return list<string>
     */
    private function selectProfiles(string $selector, Client $sender): array
    {
        if (str_starts_with($selector, '@')) {
            return array_map(static fn (Client $player): string => $player->name, $this->selectPlayers($selector, $sender));
        }
        foreach ($this->players() as $player) {
            if (strcasecmp($player->name, $selector) === 0) {
                return [$player->name];
            }
        }
        if (preg_match('/\A[A-Za-z0-9_]{1,16}\z/D', $selector) !== 1) {
            throw new CommandModules\CommandFailure(['translate' => 'argument.player.unknown']);
        }
        return [$selector];
    }

    /** PlayerList.sendPlayerPermissionLevel: entity event 24 + level, then the command tree. */
    private function sendPermissionLevel(Client $client): void
    {
        // Operators from server.json have vanilla's default op-permission-level of 4.
        $client->send($this->version, 'entity_event', pack('N', $client->entityId) . chr($this->operator($client) ? 28 : 24));
        $this->commands($client);
    }

    /** Replace the operator list, persisting it to server.json as vanilla persists ops.json. */
    private function setOperators(array $operators): void
    {
        $this->config = $this->config->with(['operators' => array_values($operators)]);
        if ($this->configPath === null) {
            return;
        }
        $settings = json_decode((string) file_get_contents($this->configPath), true, 512, JSON_THROW_ON_ERROR);
        $settings['operators'] = $this->config->operators;
        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // Keep server.json's two-space indentation.
        $json = preg_replace_callback('/^( +)/m', static fn (array $match): string => str_repeat(' ', intdiv(strlen($match[1]), 2)), $json) . "\n";
        $temporary = $this->configPath . '.tmp';
        if (file_put_contents($temporary, $json) === false || !rename($temporary, $this->configPath)) {
            throw new \RuntimeException('Cannot save operators to ' . $this->configPath);
        }
    }

    /**
     * RotationArgument: yaw then pitch in degrees, either absolute or ~relative to the sender.
     *
     * @return array{float, float}
     */
    private function rotationArguments(string $yaw, string $pitch, Client $sender): array
    {
        $parse = static function (string $text, float $relative): float {
            $offset = str_starts_with($text, '~');
            $number = $offset ? substr($text, 1) : $text;
            if (($number !== '' || !$offset) && !is_numeric($number)) {
                throw new \InvalidArgumentException('Invalid rotation: ' . $text);
            }
            // Java stores the angles as floats.
            return unpack('G', pack('G', ($offset ? $relative : 0.0) + (float) $number))[1];
        };
        return [$parse($yaw, $sender->yaw), $parse($pitch, $sender->pitch)];
    }

    /** set_default_spawn_position for the stored world spawn and its rotation. */
    private function worldSpawnPacket(): string
    {
        [$yaw, $pitch] = $this->worlds['overworld']->setting('spawnRotation') ?? [0.0, 0.0];
        return Packets::spawn($this->worlds['overworld']->spawn(), 'overworld', (float) $yaw, (float) $pitch);
    }

    private function playerByName(string $name, Client $sender): Client
    {
        if ($name === '@s') {
            $name = $sender->name;
        }
        foreach ($this->players() as $player) {
            if (strcasecmp($player->name, $name) === 0) {
                return $player;
            }
        }
        throw new \InvalidArgumentException('Player not found: ' . $name);
    }

    private function commandNames(Client $sender): array
    {
        return CommandModules\CommandRegistry::names($this->operator($sender));
    }

    private function commands(Client $client): void
    {
        $names = $this->commandNames($client);
        $nodes = [];
        $children = [];
        foreach ($names as $i => $name) {
            $children[] = 1 + $i * 2;
        }
        $root = "\0" . Binary::writeVarInt(count($children));
        foreach ($children as $id) {
            $root .= Binary::writeVarInt($id);
        }
        $nodes[] = $root;
        foreach ($names as $i => $name) {
            $nodes[] = "\5\1" . Binary::writeVarInt(2 + $i * 2) . Binary::writeString($name);
            $nodes[] = "\6\0" . Binary::writeString('arguments') . Binary::writeVarInt($this->version->blocks['argument_string']) . "\2";
        }
        $client->send($this->version, 'commands', Binary::writeVarInt(count($nodes)) . implode('', $nodes) . "\0");
    }

    private function coordinate(string $text, float $relative): float
    {
        if (str_starts_with($text, '~')) {
            $tail = substr($text, 1);
            if ($tail !== '' && !is_numeric($tail)) {
                throw new \InvalidArgumentException('Invalid relative coordinate');
            }
            $value = $relative + ($tail === '' ? 0 : (float) $tail);
        } else {
            if (!is_numeric($text)) {
                throw new \InvalidArgumentException('Invalid coordinate');
            }
            $value = (float) $text;
        }
        if (!is_finite($value) || abs($value) > 29999900) {
            throw new \InvalidArgumentException('Coordinate out of bounds');
        }
        return $value;
    }

    private function coordinates(array $arguments, Client $client): array
    {
        if (count($arguments) !== 3) {
            throw new \InvalidArgumentException('Expected x y z coordinates');
        }
        $result = [$this->coordinate($arguments[0], $client->x), $this->coordinate($arguments[1], $client->y), $this->coordinate($arguments[2], $client->z)];
        $world = $this->worlds[$client->dimension];
        if ($result[1] < $world->minY || $result[1] >= $world->minY + $world->height) {
            throw new \InvalidArgumentException('Y outside dimension build height');
        }
        return $result;
    }

    /**
     * Vec3Argument relative to the command's sender: absolute X and Z written without a decimal
     * point are centred on the block, and Y may be anywhere within the world's spawnable bounds.
     *
     * @return array{float, float, float}
     */
    private function positionArguments(array $arguments, Client $sender): array
    {
        if (count($arguments) !== 3) {
            throw new \InvalidArgumentException('Expected x y z coordinates');
        }
        $center = static fn (string $text): float => !str_starts_with($text, '~') && !str_contains($text, '.') ? 0.5 : 0.0;
        $position = [
            $this->coordinate($arguments[0], $sender->x) + $center($arguments[0]),
            $this->coordinate($arguments[1], $sender->y),
            $this->coordinate($arguments[2], $sender->z) + $center($arguments[2]),
        ];
        if (abs($position[1]) > 20000000) {
            throw new CommandModules\CommandFailure(['translate' => 'commands.teleport.invalidPosition']);
        }
        return $position;
    }

    /** ItemStack.getDisplayName for a default stack: its bracketed, rarity-colored hover name. */
    private function itemComponent(string $item): array
    {
        $display = $this->version->catalog->displayNames['items']['minecraft:' . $item];
        return [
            'translate' => 'chat.square_brackets',
            'with' => [['text' => '', 'extra' => [$display['name']]]],
            'color' => $display['color'],
            'hover_event' => ['action' => 'show_item', 'id' => 'minecraft:' . $item],
        ];
    }

    /** Entity.getDisplayName for an unnamed non-player entity. */
    private function entityComponent(string $kind, string $uuid): array
    {
        $name = $this->version->catalog->displayNames['entities']['minecraft:' . $kind];
        $hex = bin2hex($uuid);
        return [...$name, 'insertion' => substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20),
            'hover_event' => ['action' => 'show_entity', 'id' => 'minecraft:' . $kind, 'uuid' => $uuid, 'name' => $name]];
    }

    private function blockArgument(string $text): int
    {
        $text = preg_replace('/^minecraft:/', '', $text);
        if (!preg_match('/^([a-z0-9_]+)(?:\[([^\]]+)\])?$/', $text, $match)) {
            throw new \InvalidArgumentException('Invalid block state');
        }
        $properties = [];
        if (isset($match[2])) {
            foreach (explode(',', $match[2]) as $pair) {
                $parts = explode('=', $pair, 2);
                if (count($parts) !== 2) {
                    throw new \InvalidArgumentException('Invalid block property');
                }
                $properties[$parts[0]] = $parts[1];
            }
        }
        return $this->version->catalog->state($match[1], $properties);
    }

    private function command(Client $sender, string $text): void
    {
        try {
            [$name, $arguments] = CommandModules\CommandArguments::parse($text);
            $definition = CommandModules\CommandRegistry::resolve($name, $this->operator($sender));
            if ($definition === null) {
                throw new \InvalidArgumentException('Unknown command or insufficient permission. Use /help.');
            }
            $handler = $definition->handler;
            // Handlers that need verbatim input, such as a message argument, read the raw text.
            $this->$handler($sender, $arguments, $text);
        } catch (CommandModules\CommandFailure $failure) {
            $this->sendFailure($sender, $failure->component);
        } catch (\InvalidArgumentException $error) {
            $this->tell($sender, $error->getMessage());
        }
    }

    private function sendClock(Client $client): void
    {
        $client->send($this->version, 'set_time', Packets::clockTime($this->version, $this->worldAge, $this->dayTime, $this->daylightCycle));
    }

    private function sendWeather(Client $client): void
    {
        $rain = $client->dimension === 'overworld' && $this->weather !== 'clear' ? 1.0 : 0.0;
        $thunder = $client->dimension === 'overworld' && $this->weather === 'thunder' ? 1.0 : 0.0;
        $client->send($this->version, 'game_event', chr($rain > 0 ? 1 : 2) . pack('G', 0));
        $client->send($this->version, 'game_event', "\7" . pack('G', $rain));
        $client->send($this->version, 'game_event', "\x08" . pack('G', $thunder));
    }
}
