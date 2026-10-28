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
            $this->$handler($sender, $arguments);
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
