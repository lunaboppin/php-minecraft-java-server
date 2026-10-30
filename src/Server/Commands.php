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

    use CommandModules\BossBarCommand;

    use CommandModules\TagCommand;

    use CommandModules\SetIdleTimeoutCommand;

    use CommandModules\BanIpCommand;

    use CommandModules\PardonIpCommand;

    use CommandModules\SayCommand;

    use CommandModules\TeamMsgCommand;

    use ChatMessages;

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

    use CommandModules\CloneCommand;

    use CommandModules\SaveAllCommand;

    use CommandModules\DifficultyCommand;

    use CommandModules\DefaultGameModeCommand;

    use CommandModules\OpCommand;

    use CommandModules\DeOpCommand;

    use CommandModules\KickCommand;

    use CommandModules\SaveOnCommand;

    use CommandModules\SaveOffCommand;

    use CommandModules\StopCommand;

    use CommandModules\DamageCommand;

    use CommandModules\ExperienceCommand;

    use CommandModules\ClearCommand;

    use CommandModules\WorldBorderCommand;

    use CommandModules\EnchantCommand;

    use CommandModules\BanCommand;

    use CommandModules\PardonCommand;

    use CommandModules\BanListCommand;

    use CommandModules\WhitelistCommand;

    use CommandModules\TellrawCommand;

    use CommandModules\TitleCommand;

    use CommandModules\ScoreboardCommand;

    use CommandModules\TeamCommand;

    use CommandModules\TriggerCommand;

    use CommandModules\EffectCommand;

    use CommandModules\AttributeCommand;

    use CommandModules\PlaySoundCommand;

    use CommandModules\StopSoundCommand;

    use CommandModules\RotateCommand;

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
        // ServerPlayer.acceptsSuccess and broadcastToAdmins follow send_command_feedback.
        $feedback = $this->gameRule('send_command_feedback') === 'true';
        if ($feedback) {
            $sender->send($this->version, 'system_chat', Packets::systemChat($message));
        }
        if (!$broadcastToOperators) {
            $this->log('[' . $sender->name . '] ' . json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            return;
        }
        // The server console sees admin messages only while log_admin_commands is on.
        if ($this->gameRule('log_admin_commands') === 'true') {
            $this->log('[' . $sender->name . '] ' . json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        if (!$feedback) {
            return;
        }
        $admin = ['translate' => 'chat.type.admin', 'with' => [$this->displayName($sender), $message], 'color' => 'gray', 'italic' => true];
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
     * EntityArgument.players: the players matching a name, UUID or selector. An empty result is
     * vanilla's failure.
     *
     * @return list<Client>
     */
    private function selectPlayers(string $selector, Client $sender, bool $single = false): array
    {
        return array_map(static fn (array $entity): Client => $entity['client'], $this->selectEntities($selector, $sender, true, $single));
    }

    /**
     * EntitySelector.findEntities over online players and the sender's dimension's mobs: kind
     * defaults, options, sort and limit as in vanilla. Mobs in other dimensions are not searched.
     *
     * @return list<array{client?: Client, mob?: int, x: float, y: float, z: float, yaw: float, pitch: float, type: string, width: float, height: float}>
     */
    private function selectEntities(string $text, Client $sender, bool $playersOnly = false, bool $single = false): array
    {
        $selector = CommandModules\EntitySelector::parse($text);
        if ($selector->unsupported() !== null) {
            throw new \InvalidArgumentException('The ' . $selector->unsupported() . ' selector option is not supported yet');
        }
        if ($playersOnly && !$selector->playersOnly()) {
            throw new CommandModules\CommandFailure(['translate' => 'argument.player.entities']);
        }
        if ($single && !$selector->single()) {
            throw new CommandModules\CommandFailure(['translate' => $playersOnly ? 'argument.player.toomany' : 'argument.entity.toomany']);
        }
        $player = static fn (Client $client): array => ['client' => $client, 'x' => $client->x, 'y' => $client->y, 'z' => $client->z, 'yaw' => $client->yaw, 'pitch' => $client->pitch, 'type' => 'minecraft:player', 'width' => 0.6, 'height' => 1.8, 'id' => $client->entityId];
        $players = array_map($player, array_values($this->players()));
        $mobs = [];
        $items = [];
        if (!$playersOnly && $sender->dimension === $this->world->dimension) {
            // Dropped item entities, which @e and UUIDs can select.
            foreach ($this->itemEntities as $id => $item) {
                $items[] = ['item' => $id, 'x' => $item['x'], 'y' => $item['y'], 'z' => $item['z'], 'yaw' => 0.0, 'pitch' => 0.0, 'type' => 'minecraft:item', 'width' => 0.25, 'height' => 0.25, 'id' => $id, 'uuid' => $item['uuid']];
            }
            foreach ($this->experienceOrbs as $id => $orb) {
                $items[] = ['orb' => $id, 'x' => $orb['x'], 'y' => $orb['y'], 'z' => $orb['z'], 'yaw' => $orb['yaw'], 'pitch' => 0.0, 'type' => 'minecraft:experience_orb', 'width' => 0.5, 'height' => 0.5, 'id' => $id, 'uuid' => $orb['uuid']];
            }
            foreach ($this->animals as $id => $animal) {
                if (!isset($animal['deadAt'])) {
                    $info = $this->version->catalog->mobs['entities'][$animal['kind']];
                    $mobs[] = ['mob' => $id, 'x' => $animal['x'], 'y' => $animal['y'], 'z' => $animal['z'], 'yaw' => (float) ($animal['yaw'] ?? 0), 'pitch' => 0.0, 'type' => 'minecraft:' . $animal['kind'], 'width' => $info['width'], 'height' => $info['height'], 'id' => $id, 'uuid' => hex2bin($animal['uuid'])];
                }
            }
        }
        $candidates = match ($selector->kind) {
            '' => array_values(array_filter($players, static fn (array $entity): bool => strcasecmp($entity['client']->name, (string) $selector->name) === 0)),
            'uuid' => array_values(array_filter([...$players, ...$mobs, ...$items], static fn (array $entity): bool => ($entity['uuid'] ?? $entity['client']->uuid) === $selector->uuid)),
            's' => [$player($sender)],
            'p', 'a', 'r' => $players,
            // @e and @n consider living entities only.
            default => [...array_values(array_filter($players, static fn (array $entity): bool => !$entity['client']->dead)), ...$mobs, ...$items],
        };
        $options = $selector->options;
        $origin = [$options['x'][0][1] ?? $sender->x, $options['y'][0][1] ?? $sender->y, $options['z'][0][1] ?? $sender->z];
        $distanceSquared = static fn (array $entity): float => ($entity['x'] - $origin[0]) ** 2 + ($entity['y'] - $origin[1]) ** 2 + ($entity['z'] - $origin[2]) ** 2;
        $matches = array_values(array_filter($candidates, function (array $entity) use ($options, $origin, $distanceSquared): bool {
            $client = $entity['client'] ?? null;
            foreach ($options['type'] ?? [] as [$negated, $type]) {
                if (($entity['type'] === $type) === $negated) {
                    return false;
                }
            }
            foreach ($options['name'] ?? [] as [$negated, $name]) {
                // Entity.getName().getString(): a mob's custom name, otherwise its type's English name.
                $mob = isset($entity['mob']) ? $this->animals[$entity['mob']] : null;
                $actual = $client !== null ? $client->name : (isset($mob['customName']) ? self::plainText($mob['customName']) : $this->englishName('entity.minecraft.' . substr($entity['type'], 10)));
                if (($actual === $name) === $negated) {
                    return false;
                }
            }
            foreach ($options['tag'] ?? [] as [$negated, $value]) {
                // "" matches entities without tags.
                $tags = $this->entityTags($entity);
                if (($value === '' ? $tags === [] : in_array($value, $tags, true)) === $negated) {
                    return false;
                }
            }
            foreach ($options['team'] ?? [] as [$negated, $value]) {
                // "" matches entities without a team; mobs join teams by UUID.
                $team = $this->scoreboard()->teamOf(self::scoreboardName($entity));
                if (($value === '' ? $team === null : $team === $value) === $negated) {
                    return false;
                }
            }
            if (isset($options['scores']) && !$this->matchesScores($options['scores'][0][1], $entity)) {
                return false;
            }
            foreach ($options['gamemode'] ?? [] as [$negated, $mode]) {
                if ($client === null || ($client->gameMode === $mode) === $negated) {
                    return false;
                }
            }
            if (isset($options['level']) && ($client === null || !self::inRange((float) $this->experienceLevel($client), $options['level'][0][1]))) {
                return false;
            }
            if (isset($options['distance'])) {
                [$minimum, $maximum] = $options['distance'][0][1];
                $distance = $distanceSquared($entity);
                if (($minimum !== null && $distance < $minimum * $minimum) || ($maximum !== null && $distance > $maximum * $maximum)) {
                    return false;
                }
            }
            if (isset($options['dx']) || isset($options['dy']) || isset($options['dz'])) {
                // EntitySelectorParser.createAabb: from the origin to the offset, one block beyond.
                $box = [];
                foreach (['dx', 'dy', 'dz'] as $axis => $key) {
                    $delta = $options[$key][0][1] ?? 0.0;
                    $box[$axis] = [$origin[$axis] + min(0.0, $delta), $origin[$axis] + max(0.0, $delta) + 1.0];
                }
                $half = $entity['width'] / 2;
                if (!($entity['x'] - $half < $box[0][1] && $entity['x'] + $half > $box[0][0] && $entity['y'] < $box[1][1] && $entity['y'] + $entity['height'] > $box[1][0] && $entity['z'] - $half < $box[2][1] && $entity['z'] + $half > $box[2][0])) {
                    return false;
                }
            }
            if (isset($options['x_rotation']) && !self::inRange($entity['pitch'], $options['x_rotation'][0][1], true)) {
                return false;
            }
            if (isset($options['y_rotation']) && !self::inRange($entity['yaw'], $options['y_rotation'][0][1], true)) {
                return false;
            }
            return true;
        }));
        $sort = $options['sort'][0][1] ?? match ($selector->kind) {
            'p', 'n' => 'nearest',
            'r' => 'random',
            default => 'arbitrary',
        };
        if ($sort === 'nearest' || $sort === 'furthest') {
            usort($matches, static fn (array $a, array $b): int => $sort === 'nearest' ? $distanceSquared($a) <=> $distanceSquared($b) : $distanceSquared($b) <=> $distanceSquared($a));
        } elseif ($sort === 'random') {
            shuffle($matches);
        } else {
            // Arbitrary order: players, then mobs, as each list is kept.
            usort($matches, static fn (array $a, array $b): int => isset($a['mob']) <=> isset($b['mob']));
        }
        $limit = $options['limit'][0][1] ?? (in_array($selector->kind, ['p', 'r', 'n', 's', '', 'uuid'], true) ? 1 : PHP_INT_MAX);
        $matches = array_slice($matches, 0, $limit);
        if ($matches === []) {
            throw new CommandModules\CommandFailure(['translate' => $playersOnly ? 'argument.entity.notfound.player' : 'argument.entity.notfound.entity']);
        }
        return $matches;
    }

    /** MinMaxBounds.matches, or WrappedMinMaxBounds for rotations (degrees wrapped to [-180, 180)). */
    private static function inRange(float $value, array $bounds, bool $wrapped = false): bool
    {
        [$minimum, $maximum] = $bounds;
        if ($wrapped) {
            $wrap = static fn (float $angle): float => fmod(fmod($angle + 180.0, 360.0) + 360.0, 360.0) - 180.0;
            $value = $wrap($value);
            [$minimum, $maximum] = [$minimum === null ? null : $wrap($minimum), $maximum === null ? null : $wrap($maximum)];
            if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
                return $value >= $minimum || $value <= $maximum;
            }
        }
        return ($minimum === null || $value >= $minimum) && ($maximum === null || $value <= $maximum);
    }

    /** Component.getString: the text of a component and its siblings, without translation. */
    private static function plainText(array $component): string
    {
        return ($component['text'] ?? '') . implode('', array_map(self::plainText(...), $component['extra'] ?? []));
    }

    private function experienceLevel(Client $client): int
    {
        return $client->experienceLevel;
    }

    /** Entity.entityTags for a selected player, mob or item, in the order they were added. */
    private function entityTags(array $entity): array
    {
        return match (true) {
            isset($entity['client']) => $entity['client']->tags,
            isset($entity['mob']) => $this->animals[$entity['mob']]['tags'] ?? [],
            isset($entity['item']) => $this->itemEntities[$entity['item']]['tags'] ?? [],
            default => [],
        };
    }

    private function setEntityTags(array $entity, array $tags): void
    {
        if (isset($entity['client'])) {
            $entity['client']->tags = $tags;
        } elseif (isset($entity['mob'])) {
            $this->animals[$entity['mob']]['tags'] = $tags;
        } elseif (isset($entity['item'])) {
            $this->itemEntities[$entity['item']]['tags'] = $tags;
        }
    }

    /** An entity's display name: the player name or the mob's type description. */
    private function entityName(array $entity): array
    {
        if (isset($entity['orb'])) {
            return $this->entityComponent('experience_orb', $entity['uuid'], null);
        }
        if (isset($entity['item'])) {
            // ItemEntity.getName: the item's name.
            $stack = $this->itemEntities[$entity['item']]['stack'];
            return $this->entityComponent('item', $entity['uuid'], $this->version->catalog->displayNames['items']['minecraft:' . $this->version->catalog->itemName($stack['item'])]['name']);
        }
        return isset($entity['client']) ? $this->displayName($entity['client']) : $this->entityComponent(substr($entity['type'], 10), $entity['uuid'], $this->animals[$entity['mob']]['customName'] ?? null);
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
        $this->persistConfig(['operators' => array_values($operators)]);
    }

    /** Change settings and write them to server.json, as vanilla rewrites ops.json and server.properties. */
    private function persistConfig(array $changes): void
    {
        $this->config = $this->config->with($changes);
        if ($this->configPath === null) {
            return;
        }
        $settings = array_replace(json_decode((string) file_get_contents($this->configPath), true, 512, JSON_THROW_ON_ERROR), $changes);
        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // Keep server.json's two-space indentation.
        $json = preg_replace_callback('/^( +)/m', static fn (array $match): string => str_repeat(' ', intdiv(strlen($match[1]), 2)), $json) . "\n";
        $temporary = $this->configPath . '.tmp';
        if (file_put_contents($temporary, $json) === false || !rename($temporary, $this->configPath)) {
            throw new \RuntimeException('Cannot save settings to ' . $this->configPath);
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

    /**
     * The command tree: the official graph for the player's permission level (4 for operators,
     * as vanilla's default op level, otherwise 0) for the vanilla commands implemented here, and
     * a string argument for this server's own commands.
     */
    private function commandTree(Client $client): CommandModules\CommandTree
    {
        static $graph = null;
        static $trees = [];
        $operator = $this->operator($client);
        $graph ??= json_decode((string) file_get_contents($this->version->directory . '/commands.json'), true, 512, JSON_THROW_ON_ERROR)['nodes'];
        if (!isset($trees[$operator])) {
            $vanilla = array_map(static fn (int $child): string => $graph[$child]['name'], $graph[0]['network_children']);
            $names = $this->commandNames($client);
            $trees[$operator] = new CommandModules\CommandTree($graph, $operator ? 4 : 0, array_values(array_intersect($names, $vanilla)), array_values(array_diff($names, $vanilla)));
        }
        return $trees[$operator];
    }

    /**
     * ServerCommandSuggestionsProvider for this server's ask_server arguments: /op suggests online
     * players who are not operators, /deop the operators, and /time set the clock's time markers.
     * Other requests are answered with no suggestions.
     */
    private function commandSuggestions(Client $client, int $id, string $text): void
    {
        $input = str_starts_with($text, '/') ? substr($text, 1) : $text;
        $tokens = explode(' ', $input);
        $partial = (string) end($tokens);
        $start = strlen($text) - strlen($partial);
        $candidates = [];
        $resources = false;
        if ($this->operator($client) && count($tokens) === 2 && in_array($tokens[0], ['op', 'deop'], true)) {
            $candidates = $tokens[0] === 'op'
                ? array_map(static fn (Client $player): string => $player->name, array_filter($this->players(), fn (Client $player): bool => !$this->operator($player)))
                : $this->config->operators;
        } elseif ($this->operator($client) && ($tokens[0] ?? '') === 'time' && (array_slice($tokens, 0, 2) === ['time', 'set'] && count($tokens) === 3 || ($tokens[1] ?? '') === 'of' && ($tokens[3] ?? '') === 'set' && count($tokens) === 5)) {
            $clock = count($tokens) === 5 ? (str_contains($tokens[2], ':') ? $tokens[2] : 'minecraft:' . $tokens[2]) : 'minecraft:overworld';
            foreach ($this->version->catalog->timelines as $timeline) {
                if ($timeline['clock'] === $clock) {
                    foreach ($timeline['time_markers'] as $marker => $data) {
                        if ($data['show_in_commands'] ?? false) {
                            $candidates[] = $marker;
                        }
                    }
                }
            }
            $resources = true;
        }
        $remaining = strtolower($partial);
        $matches = array_values(array_unique(array_filter($candidates, static function (string $candidate) use ($remaining, $resources): bool {
            if (!$resources || str_contains($remaining, ':')) {
                return self::matchesSubString($remaining, strtolower($candidate));
            }
            [$namespace, $path] = explode(':', $candidate, 2);
            return self::matchesSubString($remaining, $namespace) || self::matchesSubString($remaining, $path);
        })));
        // Suggestions.create orders suggestions case-insensitively.
        usort($matches, static fn (string $a, string $b): int => strcasecmp($a, $b));
        $payload = Binary::writeVarInt($id) . Binary::writeVarInt($start) . Binary::writeVarInt(strlen($partial)) . Binary::writeVarInt(count($matches));
        foreach ($matches as $match) {
            $payload .= Binary::writeString($match) . "\0";
        }
        $client->send($this->version, 'command_suggestions', $payload);
    }

    /** SharedSuggestionProvider.matchesSubStr: a match at the start or after any '.', '_' or '/'. */
    private static function matchesSubString(string $input, string $candidate): bool
    {
        for ($index = 0; ; ++$index) {
            if (str_starts_with(substr($candidate, $index), $input)) {
                return true;
            }
            $next = strcspn($candidate, '._/', $index);
            if ($index + $next >= strlen($candidate)) {
                return false;
            }
            $index += $next;
        }
    }

    private function commands(Client $client): void
    {
        static $packets = [];
        $tree = $this->commandTree($client);
        $packets[spl_object_id($tree)] ??= $tree->packet($this->version->blocks['argument_string']);
        $client->send($this->version, 'commands', $packets[spl_object_id($tree)]);
    }

    /**
     * LocalCoordinates.getPosition: "^left ^up ^forwards" from the sender's feet and rotation,
     * with Mth's float sine table; null unless every coordinate is local.
     *
     * @return array{float, float, float}|null
     */
    private function localPosition(array $arguments, Client $sender): ?array
    {
        if (count($arguments) !== 3 || array_filter($arguments, static fn (string $text): bool => !str_starts_with($text, '^')) !== []) {
            return null;
        }
        [$left, $up, $forwards] = array_map(static fn (string $text): float => (float) substr($text, 1), $arguments);
        $f32 = static fn (float $value): float => unpack('G', pack('G', $value))[1];
        $sin = static fn (float $value): float => $f32(sin((((int) $f32($value * 10430.378)) & 65535) * M_PI * 2 / 65536));
        $cos = static fn (float $value): float => $f32(sin((((int) $f32($f32($value * 10430.378) + 16384.0)) & 65535) * M_PI * 2 / 65536));
        $radians = $f32(M_PI / 180.0);
        $yaw = $f32($f32($sender->yaw + 90.0) * $radians);
        $pitch = $f32(-$f32($sender->pitch) * $radians);
        $upPitch = $f32($f32(-$f32($sender->pitch) + 90.0) * $radians);
        $forward = [$f32($cos($yaw) * $cos($pitch)), $sin($pitch), $f32($sin($yaw) * $cos($pitch))];
        $upward = [$f32($cos($yaw) * $cos($upPitch)), $sin($upPitch), $f32($sin($yaw) * $cos($upPitch))];
        $leftward = [
            -($forward[1] * $upward[2] - $forward[2] * $upward[1]),
            -($forward[2] * $upward[0] - $forward[0] * $upward[2]),
            -($forward[0] * $upward[1] - $forward[1] * $upward[0]),
        ];
        $origin = [$sender->x, $sender->y, $sender->z];
        return array_map(static fn (int $axis): float => $origin[$axis] + ($forward[$axis] * $forwards + $upward[$axis] * $up + $leftward[$axis] * $left), [0, 1, 2]);
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
        if (!is_finite($value)) {
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
     * BlockPosArgument.getLoadedBlockPos: the block coordinates, which must be in a loaded chunk
     * and inside the build height and the horizontal world bounds.
     *
     * @return array{int, int, int}
     */
    private function loadedBlockPosition(array $arguments, Client $client, ?string $dimension = null): array
    {
        if (count($arguments) !== 3) {
            throw new \InvalidArgumentException('Expected x y z coordinates');
        }
        $position = array_map(static fn (float $value): int => (int) floor($value), $this->localPosition($arguments, $client) ?? [$this->coordinate($arguments[0], $client->x), $this->coordinate($arguments[1], $client->y), $this->coordinate($arguments[2], $client->z)]);
        // A chunk is loaded while it is within a player's view in the dimension, as vanilla loads a
        // player's view as soon as they are placed, before the chunks reach the client.
        [$chunkX, $chunkZ] = [$position[0] >> 4, $position[2] >> 4];
        $dimension ??= $client->dimension;
        if (array_filter($this->players(), fn (Client $player): bool => $player->dimension === $dimension && $this->chunkInView($player, $chunkX, $chunkZ)) === []) {
            throw new CommandModules\CommandFailure(['translate' => 'argument.pos.unloaded']);
        }
        $world = $this->worlds[$dimension];
        if ($position[1] < $world->minY || $position[1] >= $world->minY + $world->height || abs($position[0] + 0.5) > 30000000 || abs($position[2] + 0.5) > 30000000) {
            throw new CommandModules\CommandFailure(['translate' => 'argument.pos.outofworld']);
        }
        return $position;
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
        $local = $this->localPosition($arguments, $sender);
        $center = static fn (string $text): float => !str_starts_with($text, '~') && !str_contains($text, '.') ? 0.5 : 0.0;
        $position = $local ?? [
            $this->coordinate($arguments[0], $sender->x) + $center($arguments[0]),
            $this->coordinate($arguments[1], $sender->y),
            $this->coordinate($arguments[2], $sender->z) + $center($arguments[2]),
        ];
        // Level.isInSpawnableBounds for the block containing the position.
        [$blockX, $blockY, $blockZ] = array_map(static fn (float $value): float => floor($value), $position);
        if ($blockY < -20000000 || $blockY >= 20000000 || $blockX < -30000000 || $blockX >= 30000000 || $blockZ < -30000000 || $blockZ >= 30000000) {
            throw new CommandModules\CommandFailure(['translate' => 'commands.teleport.invalidPosition']);
        }
        return $position;
    }

    /**
     * ItemStack.getDisplayName: the bracketed hover name in the rarity colour (raised for enchanted
     * items, as ItemStack.getRarity does), hovering the item with its component patch.
     */
    private function itemComponent(string $item, array $components = [], array $removed = []): array
    {
        $display = $this->version->catalog->displayNames['items']['minecraft:' . $item];
        $color = ($components['minecraft:enchantments'] ?? []) === [] ? $display['color'] : match ($display['color']) {
            'white', 'yellow' => 'aqua',
            'aqua' => 'light_purple',
            default => $display['color'],
        };
        $hover = ['action' => 'show_item', 'id' => 'minecraft:' . $item];
        $patch = $components + array_fill_keys(array_map(static fn (string $name): string => '!' . $name, $removed), new \stdClass());
        if ($patch !== []) {
            $hover['components'] = $patch;
        }
        return [
            'translate' => 'chat.square_brackets',
            'with' => [['text' => '', 'extra' => [$display['name']]]],
            'color' => $color,
            'hover_event' => $hover,
        ];
    }

    /** Entity.getDisplayName for an unnamed non-player entity. */
    /**
     * Entity.getDisplayName for a non-player entity: its custom name (keeping that name's style)
     * or its type's name, hovering the entity and inserting its UUID.
     */
    private function entityComponent(string $kind, string $uuid, ?array $customName = null): array
    {
        $name = $customName ?? $this->version->catalog->displayNames['entities']['minecraft:' . $kind];
        $holder = Binary::formatUuid($uuid);
        // Entity.getDisplayName: PlayerTeam.formatNameForTeam, as for players.
        $team = $this->teamFormat($holder);
        $formatted = $team === null ? $name : ['text' => '', 'extra' => [$team['prefix'], $name, $team['suffix']]] + ($team['color'] === null ? [] : ['color' => $team['color']]);
        return [...$formatted, 'insertion' => $holder,
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
            // Commands.finishParsing: syntax errors are reported with the input around the cursor.
            $error = $this->commandTree($sender)->parse($text, new CommandModules\ArgumentReader(CommandModules\ArgumentReader::registries($this->version, array_keys(self::CLOCKS)), $this->operator($sender)));
            if ($error !== null) {
                $this->sendFailure($sender, $error->component);
                if ($error->cursor >= 0) {
                    $this->sendFailure($sender, $error->context());
                }
                return;
            }
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

    /** Clock name => total, paused, rate and partial-tick fields. */
    private const CLOCKS = [
        'minecraft:overworld' => ['dayTime', 'clockPaused', 'clockRate', 'clockPartial'],
        'minecraft:the_end' => ['endClockTicks', 'endClockPaused', 'endClockRate', 'endClockPartial'],
    ];

    /** The periodic time sync: game time only, as clients extrapolate the clocks. */
    private function sendClock(Client $client): void
    {
        $client->send($this->version, 'set_time', $this->clockPacket([]));
    }

    /**
     * set_time with the named clocks' network state. The effective rate is zero while a clock is
     * paused or time does not advance.
     */
    private function clockPacket(array $names): string
    {
        $clocks = [];
        foreach ($names as $name) {
            [$total, $paused, $rate, $partial] = self::CLOCKS[$name];
            $clocks[] = [substr($name, 10), $this->$total, $this->$partial, $this->daylightCycle && !$this->$paused ? $this->$rate : 0.0];
        }
        return Packets::clockTime($this->version, $this->worldAge, $clocks);
    }

    /** ServerClockManager.modifyClock: everyone receives the changed clock. */
    private function broadcastClock(string $name): void
    {
        foreach ($this->players() as $player) {
            $player->send($this->version, 'set_time', $this->clockPacket([$name]));
        }
    }

    /** sendLevelInfo: weather is only sent to a joining player while it rains. */
    private function sendJoinWeather(Client $client): void
    {
        if ($client->dimension === 'overworld' && $this->weather !== 'clear') {
            $client->send($this->version, 'game_event', "\1" . pack('G', 0));
            $client->send($this->version, 'game_event', "\7" . pack('G', 1.0));
            $client->send($this->version, 'game_event', "\x08" . pack('G', $this->weather === 'thunder' ? 1.0 : 0.0));
        }
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
