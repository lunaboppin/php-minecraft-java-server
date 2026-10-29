<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Framing;
use PhpMinecraftJavaServer\Protocol\Nbt;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Protocol\ProtocolException;
use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\World;

final class Server
{
    use InventoryManagement;

    use Entities;

    use Survival;

    use Experience;

    use Borders;

    use PlayerLists;

    use Scoreboards;

    use Effects;

    use Attributes;

    use Commands;

    use Animals;

    use Dimensions;

    use BlockSimulation;

    use SignAndPick;

    use EntityTracking;

    use SpawnEggs;

    use MobMovement;

    use Authentication;
    private const INVALID_MOVEMENT = ['translate' => 'multiplayer.disconnect.invalid_player_movement'];

    private mixed $listener = null;

    /** @var array<int,Client> */
    private array $clients = [];

    private bool $running = true;

    /** Periodic autosave, toggled by /save-on and /save-off. */
    private bool $autoSave = true;

    /** The disconnect reason for a /stop shutdown; deployments may still override it. */
    private string|array|null $stopReason = null;

    private int $nextEntity = 1;

    private int $ticks = 0;

    private array $pendingLight = [];

    private World $world;

    private readonly DailyLog $logger;

    private readonly Announcements $announcements;

    public function __construct(private Config $config, private readonly Version $version = new Version(), ?\PhpMinecraftJavaServer\Authentication\SessionVerifier $sessions = null, private readonly ?string $configPath = null)
    {
        $this->logger = new DailyLog($config->logDirectory, $config->logRetentionDays);
        $this->announcements = new Announcements($config->worldPath);
        $this->initializeAuthentication($sessions);
        $this->world = new World($version, $config->worldPath, $config->generator, $config->seed, 'overworld', $config->vanillaTerrain);
        $this->worlds['overworld'] = $this->world;
        foreach (['the_nether', 'the_end'] as $dimension) {
            $path = $config->worldPath . '.' . $dimension . '.sqlite';
            $this->worlds[$dimension] = new World($version, $path, 'terrain', $config->seed, $dimension, $dimension === 'the_nether' && $config->vanillaTerrain);
        }
        foreach (array_keys($this->worlds) as $dimension) {
            $this->withDimension($dimension, function (): void {
                $this->restoreAnimals();
                $this->restoreBlockSimulation();
            });
        }
        $this->crafting = new \PhpMinecraftJavaServer\World\Crafting($version->directory);
        foreach (['worldAge', 'dayTime', 'daylightCycle', 'weather', 'keepInventory', 'naturalRegeneration', 'spawnMobs', 'clockPaused', 'clockRate', 'clockPartial', 'endClockTicks', 'endClockPaused', 'endClockRate', 'endClockPartial'] as $key) {
            $saved = $this->world->setting($key);
            if ($saved !== null) {
                $this->$key = $saved;
            }
        }
        // Both clocks start with the world; a world saved before the End clock existed takes its age.
        $this->endClockTicks ??= $this->worldAge;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /** @var list<array{0: resource, 1: array}> */
    private array $generationWorkers = [];

    /** @var list<string> */
    private array $generationWorkerCommand = [];

    private float $generationWorkersCheckedAt = 0.0;

    /** Start the background processes that generate vanilla chunks. */
    private function startGenerationWorkers(): void
    {
        if ($this->world->vanilla === null) {
            return;
        }
        [$spawnX, , $spawnZ] = $this->world->spawn();
        $pregenerate = [(string) ((int) floor($spawnX) >> 4), (string) ((int) floor($spawnZ) >> 4), (string) $this->config->pregenerateRadius];
        if (($this->worlds['the_nether'] ?? null)?->vanilla !== null) {
            $pregenerate[] = $this->config->worldPath . '.the_nether.sqlite';
        }
        $this->generationWorkerCommand = [PHP_BINARY, '-d', 'memory_limit=768M', __DIR__ . '/../../bin/vanilla-worker.php', $this->config->worldPath, (string) $this->world->seed, ...$pregenerate];
        for ($i = 0; $i < $this->config->generationWorkers; ++$i) {
            $worker = $this->spawnGenerationWorker();
            if ($worker !== null) {
                $this->generationWorkers[] = $worker;
            }
        }
        $this->log('Vanilla terrain: ' . count($this->generationWorkers) . ' generation workers');
        // Spawn chunks first, kept for ten minutes so the first player to join finds them ready.
        [$x, , $z] = $this->world->spawn();
        for ($dz = -3; $dz <= 3; ++$dz) {
            for ($dx = -3; $dx <= 3; ++$dx) {
                $this->world->requestGeneration(((int) floor($x) >> 4) + $dx, ((int) floor($z) >> 4) + $dz, 100 + max(abs($dx), abs($dz)), 600.0);
            }
        }
    }

    /** @return array{0: resource, 1: array}|null */
    private function spawnGenerationWorker(): ?array
    {
        $process = proc_open($this->generationWorkerCommand, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        return is_resource($process) ? [$process, $pipes] : null;
    }

    /** Replace generation workers that have exited, so chunk generation never silently stops. */
    private function checkGenerationWorkers(): void
    {
        foreach ($this->generationWorkers as $index => [$process, $pipes]) {
            $status = proc_get_status($process);
            if ($status['running']) {
                continue;
            }
            @fclose($pipes[0]);
            proc_close($process);
            $how = $status['signaled'] ? 'was killed by signal ' . $status['termsig'] : 'exited with code ' . $status['exitcode'];
            $this->log('Vanilla terrain: generation worker ' . $how . '; restarting it');
            $worker = $this->spawnGenerationWorker();
            if ($worker === null) {
                unset($this->generationWorkers[$index]);
            } else {
                $this->generationWorkers[$index] = $worker;
            }
        }
    }

    private function stopGenerationWorkers(): void
    {
        foreach ($this->generationWorkers as [$process, $pipes]) {
            @fclose($pipes[0]);
            proc_close($process);
        }
        $this->generationWorkers = [];
    }

    public function run(?int $maxTicks = null): void
    {
        $host = str_contains($this->config->host, ':') ? '[' . $this->config->host . ']' : $this->config->host;
        $this->listener = @stream_socket_server('tcp://' . $host . ':' . $this->config->port, $errno, $error);
        if ($this->listener === false) {
            throw new \RuntimeException('Cannot listen: ' . $error);
        }
        stream_set_blocking($this->listener, false);
        $this->log('Listening on ' . stream_socket_get_name($this->listener, false) . ' (Minecraft ' . Version::NAME . ', protocol ' . Version::PROTOCOL . ')');
        $this->log('PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ', ' . (PHP_INT_SIZE * 8) . '-bit)');
        $this->log(($this->config->onlineMode ? 'Online authentication: verified Mojang accounts. World: ' : 'Offline authentication: usernames are unverified. World: ') . $this->config->worldPath);
        $this->startGenerationWorkers();
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop());
            pcntl_signal(SIGINT, fn () => $this->stop());
        }
        $nextTick = self::now();
        try {
            while ($this->running && ($maxTicks === null || $this->ticks < $maxTicks)) {
                $read = [$this->listener];
                $write = [];
                foreach ($this->clients as $client) {
                    if (!$client->closing) {
                        $read[] = $client->socket;
                    }
                    if ($client->output !== '') {
                        $write[] = $client->socket;
                    }
                }
                $except = null;
                $wait = min(50000, max(0, (int) (($nextTick - self::now()) * 1000000)));
                foreach ($this->clients as $client) {
                    if ($client->bufferedWork && !$client->closing) {
                        $wait = min($wait, 1000);
                    }
                }
                $ready = @stream_select($read, $write, $except, 0, $wait);
                if ($ready !== false) {
                    foreach ($read as $socket) {
                        if ($socket === $this->listener) {
                            $this->accept();
                            continue;
                        }
                        $id = (int) $socket;
                        if (!isset($this->clients[$id])) {
                            continue;
                        }
                        $client = $this->clients[$id];
                        $bytes = @fread($socket, 65536);
                        if ($bytes === false || ($bytes === '' && feof($socket))) {
                            $this->remove($id);
                            continue;
                        }
                        $client->input .= $client->decryptor?->transform($bytes) ?? $bytes;
                        if ($bytes !== '') {
                            $client->lastRead = self::now();
                        }
                        if (strlen($client->input) > Framing::MAX_PACKET + 65536) {
                            $this->remove($id);
                        }
                    }
                    foreach ($write as $socket) {
                        if (isset($this->clients[(int) $socket])) {
                            $this->flush($this->clients[(int) $socket]);
                        }
                    }
                }
                foreach ($this->clients as $id => $client) {
                    if ($client->closing) {
                        continue;
                    }
                    try {
                        for ($i = 0; $i < 128; ++$i) {
                            $frame = Framing::extract($client->input, $client->compression);
                            if ($frame === null) {
                                break;
                            }
                            $now = self::now();
                            if ($now - $client->packetWindow >= 1) {
                                $client->packetWindow = $now;
                                $client->packetsThisSecond = 0;
                            }
                            if (++$client->packetsThisSecond > 1000) {
                                throw new ProtocolException('Packet rate exceeded');
                            }
                            $this->packet($client, new Binary($frame));
                            if ($client->closing) {
                                break;
                            }
                        }
                        $client->bufferedWork = $i === 128 && $client->input !== '';
                    } catch (ProtocolException|\InvalidArgumentException $e) {
                        $this->log('Protocol error from ' . $client->peer . ': ' . $e->getMessage());
                        $this->disconnect($client, 'Invalid packet: ' . $e->getMessage());
                    }
                }
                $this->pollAuthentication();
                $now = self::now();
                if ($now >= $nextTick) {
                    $this->tick($now);
                    ++$this->ticks;
                    $nextTick = max($nextTick + 0.05, $now);
                }
            }
        } finally {
            $this->stopGenerationWorkers();
            $restartReason = RestartNotice::consume($this->config->worldPath, $this->stopReason ?? RestartNotice::FALLBACK);
            foreach ($this->clients as $client) {
                if ($client->state === 'play') {
                    $this->closeWindow($client);
                    $this->savePlayer($client);
                    $this->log($client->name . ' disconnected (server shutdown)');
                }
                $this->disconnect($client, $restartReason);
            }
            // Drain queued frames and the restart reason before closing healthy connections.
            $deadline = self::now() + 2;
            do {
                $pending = false;
                foreach ($this->clients as $client) {
                    if (is_resource($client->socket) && $client->output !== '') {
                        $this->flush($client);
                        $pending = $pending || $client->output !== '';
                    }
                }
                if ($pending) {
                    usleep(1000);
                }
            } while ($pending && self::now() < $deadline);
            foreach ($this->clients as $client) {
                if (is_resource($client->socket)) {
                    fclose($client->socket);
                }
            }
            $this->clients = [];
            $this->saveWorld();
            fclose($this->listener);
            $this->log('Server stopped');
        }
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    private function log(string $text): void
    {
        $this->logger->write($text);
        fwrite(STDOUT, '[' . gmdate('H:i:s') . '] ' . $text . PHP_EOL);
    }

    private function accept(): void
    {
        for ($i = 0; $i < 16; ++$i) {
            $socket = @stream_socket_accept($this->listener, 0, $peer);
            if ($socket === false) {
                break;
            }
            if (count($this->clients) >= max(128, $this->config->maxPlayers * 2)) {
                fclose($socket);
                continue;
            }
            stream_set_blocking($socket, false);
            stream_set_read_buffer($socket, 0);
            stream_set_write_buffer($socket, 0);
            $this->clients[(int) $socket] = new Client($socket, $peer);
        }
    }

    private function flush(Client $client): void
    {
        if ($client->output === '') {
            return;
        }
        $written = @fwrite($client->socket, substr($client->output, 0, 65536));
        if ($written === false) {
            $this->remove((int) $client->socket);
            return;
        }
        $client->output = substr($client->output, $written);
    }

    private function packet(Client $c, Binary $r): void
    {
        $id = $r->varInt();
        $name = $this->version->name($c->state, $id);
        $state = $c->state;
        try {
            match ($state) {
                'handshake' => $this->handshake($c, $name, $r),
                'status' => $this->status($c, $name, $r),
                'login' => $this->login($c, $name, $r),
                'configuration' => $this->configuration($c, $name, $r),
                'play' => $this->withDimension($c->dimension, fn () => $this->play($c, $name, $r)),
                default => throw new ProtocolException('Invalid connection state'),
            };
        } catch (ProtocolException $e) {
            throw new ProtocolException($state . '/' . $name . ': ' . $e->getMessage(), 0, $e);
        }
    }

    private function handshake(Client $c, string $name, Binary $r): void
    {
        if ($name !== 'intention') {
            throw new ProtocolException('Expected handshake');
        }
        $protocol = $r->varInt();
        $r->string(255);
        $r->ushort();
        $next = $r->varInt();
        $r->end();
        if ($next !== 1 && $next !== 2) {
            throw new ProtocolException('Unsupported handshake intention');
        }
        $c->state = $next === 1 ? 'status' : 'login';
        if ($next === 2 && $protocol !== Version::PROTOCOL) {
            $this->disconnect($c, 'Use Minecraft Java ' . Version::NAME . ' (protocol ' . Version::PROTOCOL . ')');
        }
    }

    private function status(Client $c, string $name, Binary $r): void
    {
        if ($name === 'status_request') {
            $r->end();
            $sample = [];
            foreach ($this->players() as $player) {
                $sample[] = ($player->information['allowsListing'] ?? false) ? ['name' => $player->name, 'id' => Binary::formatUuid($player->uuid)] : ['name' => 'Anonymous Player', 'id' => '00000000-0000-0000-0000-000000000000'];
            }
            // ServerStatus codec order. A plain-text description is a bare string, and the empty
            // sample and false enforcesSecureChat are omitted as lenient optional defaults.
            $players = ['max' => $this->config->maxPlayers, 'online' => count($this->players())];
            if ($sample !== []) {
                $players['sample'] = array_slice($sample, 0, 12);
            }
            $json = json_encode(['description' => $this->config->motd, 'players' => $players, 'version' => ['name' => Version::NAME, 'protocol' => Version::PROTOCOL]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $c->send($this->version, 'status_response', Binary::writeString($json));
        } elseif ($name === 'ping_request') {
            $payload = $r->bytes(8);
            $r->end();
            $c->send($this->version, 'pong_response', $payload);
            $this->closeAfterWrite($c);
        } else {
            throw new ProtocolException('Unexpected status packet');
        }
    }

    private function login(Client $c, string $name, Binary $r): void
    {
        if ($name === 'hello' && !$c->loginStarted) {
            $username = $r->string(16);
            $r->bytes(16);
            $r->end();
            if (preg_match('/\A[A-Za-z0-9_]{1,16}\z/D', $username) !== 1) {
                throw new ProtocolException('Invalid username');
            }
            $count = 0;
            foreach ($this->clients as $other) {
                if ($other !== $c && !$other->closing && $other->name !== '') {
                    ++$count;
                    if (!$this->config->onlineMode && strcasecmp($other->name, $username) === 0) {
                        $this->disconnect($c, 'That username is already connected');
                        return;
                    }
                }
            }
            $c->name = $username;
            $c->loginStarted = true;
            if ($this->config->onlineMode) {
                $c->challengeNonce = random_bytes(4);
                $c->authenticationPhase = 'key';
                $c->send($this->version, 'hello', $this->challenge->packet($c->challengeNonce));
            } else {
                $c->uuid = Binary::offlineUuid($username);
                $rejection = $this->loginRejection($c);
                if ($rejection !== null) {
                    $this->disconnect($c, $rejection);
                    return;
                }
                $this->finishLogin($c);
            }
        } elseif ($name === 'key' && $c->authenticationPhase === 'key') {
            $secret = $this->challenge->secret($r, $c->challengeNonce);
            $c->challengeNonce = '';
            $c->encrypt($secret);
            $c->authenticationPhase = 'verifying';
            $this->sessionVerifier->start((int) $c->socket, $c->name, $this->challenge->hash($secret));
        } elseif ($name === 'login_acknowledged' && $c->authenticationPhase === 'ready') {
            $r->end();
            $c->state = 'configuration';
            $c->send($this->version, 'custom_payload', Binary::writeString('minecraft:brand') . Binary::writeString('PHP Minecraft Java Server'));
            $c->send($this->version, 'update_enabled_features', "\1" . Binary::writeString('minecraft:vanilla'));
            // No shared-pack assumption: always send complete NBT from the official codecs.
            $c->send($this->version, 'select_known_packs', "\0");
            $c->knownPacksRequested = true;
        } else {
            throw new ProtocolException('Unexpected login packet');
        }
    }

    private function configuration(Client $c, string $name, Binary $r): void
    {
        if ($name === 'client_information') {
            $this->clientInformation($c, $r);
            return;
        }
        if ($name === 'select_known_packs') {
            if (!$c->knownPacksRequested || $c->registriesSent || $r->varInt() !== 0) {
                throw new ProtocolException('Unexpected known packs response');
            }
            $r->end();
            foreach ($this->version->registryPackets() as $payload) {
                $c->send($this->version, 'registry_data', $payload);
            }
            $c->send($this->version, 'update_tags', $this->version->tags());
            $c->send($this->version, 'finish_configuration');
            $c->registriesSent = true;
        } elseif ($name === 'finish_configuration' && $c->registriesSent) {
            $r->end();
            $c->state = 'play';
            $this->join($c);
        } elseif (!in_array($name, ['custom_payload', 'resource_pack'], true)) {
            throw new ProtocolException('Unexpected configuration packet');
        }
    }

    /** @return array<int,Client> */
    private function players(): array
    {
        return array_filter($this->clients, static fn (Client $c): bool => $c->state === 'play' && !$c->closing);
    }

    private function join(Client $c): void
    {
        $c->entityId = $this->nextEntity++;
        $this->restorePlayer($c);
        $this->withDimension($c->dimension, fn () => $this->joinWorld($c));
    }

    /**
     * PlayerChunkSender.sendNextChunks: the quota grows by the client's desired rate, capped at
     * that rate, and the nearest pending chunks fill one batch. Vanilla only sends chunks that
     * are already loaded; generation here is synchronous, so a batch also stops after 25 ms.
     */
    private function sendNextChunks(Client $c, float $now): void
    {
        if ($c->unacknowledgedBatches >= $c->maxUnacknowledgedBatches || strlen($c->output) >= 1048576) {
            return;
        }
        $float = static fn (float $value): float => unpack('G', pack('G', $value))[1];
        $c->batchQuota = $float(min($c->batchQuota + $c->desiredChunksPerTick, max(1.0, $c->desiredChunksPerTick)));
        if ($c->batchQuota < 1.0 || $c->queuedChunks === []) {
            return;
        }
        $pending = $c->queuedChunks;
        uasort($pending, static fn (array $a, array $b): int => (($a[0] - $c->chunkX) ** 2 + ($a[1] - $c->chunkZ) ** 2) <=> (($b[0] - $c->chunkX) ** 2 + ($b[1] - $c->chunkZ) ** 2));
        // Vanilla chunks are sent once the generation workers have finished them.
        $batch = [];
        foreach ($pending as $key => [$x, $z]) {
            if (count($batch) >= (int) floor($c->batchQuota)) {
                break;
            }
            if ($this->world->ready($x, $z)) {
                $batch[$key] = [$x, $z];
            } else {
                $this->world->requestGeneration($x, $z, ($x - $c->chunkX) ** 2 + ($z - $c->chunkZ) ** 2);
            }
        }
        if ($batch === []) {
            return;
        }
        $c->send($this->version, 'chunk_batch_start');
        ++$c->unacknowledgedBatches;
        $count = 0;
        $started = hrtime(true);
        foreach ($batch as $key => [$x, $z]) {
            $c->send($this->version, 'level_chunk_with_light', $this->world->chunk($x, $z));
            $c->loadedChunks[$key] = [$x, $z];
            $this->naturalAnimals($x, $z);
            unset($c->queuedChunks[$key]);
            ++$count;
            $c->trackingDirty = true;
            if ((hrtime(true) - $started) / 1e6 > 25) {
                break;
            }
        }
        $c->send($this->version, 'chunk_batch_finished', Binary::writeVarInt($count));
        $c->batchQuota = $float($c->batchQuota - $count);
        $c->batchAt = $now;
    }

    /** PlayerList.placeNewPlayer and sendLevelInfo, in vanilla's packet order. */
    private function joinWorld(Client $c): void
    {
        $c->send($this->version, 'login', Packets::playLogin($this->version, $c->entityId, $this->config->maxPlayers, $this->viewDistance($c), $c->gameMode, $this->world->generator === 'flat' && $c->dimension === 'overworld', $this->world->hashedSeed(), $c->dimension, array_keys($this->worlds), $this->config->viewDistance, $this->gameRule('reduced_debug_info') === 'true', $this->gameRule('immediate_respawn') === 'true', $this->gameRule('limited_crafting') === 'true'));
        $c->send($this->version, 'change_difficulty', Packets::difficulty($this->config->difficulty));
        $this->abilities($c);
        $c->send($this->version, 'set_held_slot', Binary::writeVarInt($c->heldSlot));
        $this->sendPermissionLevel($c);
        // The recipe book (all filters closed) has no recipes here yet (#24).
        $c->send($this->version, 'recipe_book_settings', str_repeat("\0", 8));
        // PlayerList.updateEntireScoreboard: displayed objectives and their scores.
        $this->sendScoreboard($c);
        // The join message reaches players already online, not the one joining.
        $this->broadcastComponent(['translate' => 'multiplayer.player.joined', 'with' => [$this->displayName($c)], 'color' => 'yellow'], $c->name . ' joined the game', $c);
        $this->sendPosition($c, [$c->x, $c->y, $c->z]);
        $c->send($this->version, 'server_data', Packets::serverData($this->config->motd));
        $info = static fn (Client $player): array => ['uuid' => $player->uuid, 'name' => $player->name, 'gameMode' => $player->gameMode, 'hat' => (($player->information['skinParts'] ?? 0) & 64) !== 0, 'properties' => $player->profileProperties];
        $others = array_values(array_filter($this->players(), static fn (Client $player): bool => $player !== $c));
        $c->send($this->version, 'player_info_update', Packets::playerInfoInitializing(array_map($info, $others)));
        foreach ($this->players() as $player) {
            $player->send($this->version, 'player_info_update', Packets::playerInfoInitializing([$info($c)]));
        }
        $this->sendBorder($c);
        $c->send($this->version, 'set_time', $this->clockPacket(array_keys(self::CLOCKS)));
        $c->send($this->version, 'set_default_spawn_position', $this->worldSpawnPacket());
        $this->sendJoinWeather($c);
        $c->send($this->version, 'game_event', "\x0d" . pack('G', 0));
        // The server ticks at 20 per second, unfrozen and without pending steps.
        $c->send($this->version, 'ticking_state', pack('G', 20.0) . "\0");
        $c->send($this->version, 'ticking_step', Binary::writeVarInt(0));
        $this->scheduleChunks($c);
        $this->broadcastPlayerPosition($c);
        foreach ($others as $other) {
            $this->spawnPlayer($c, $other);
            $this->spawnPlayer($other, $c);
        }
        $c->send($this->version, 'post_effects', Binary::writeVarInt(0));
        $this->syncInventory($c);
        // PlayerList.sendActivePlayerEffects: saved effects, without blending in.
        foreach (array_keys($c->effects) as $effect) {
            $c->send($this->version, 'update_mob_effect', $this->effectPacket($c, $effect, false));
            $this->syncEffectAttributes($c, $effect);
        }
        if ($c->absorption > 0.0) {
            $this->setAbsorption($c, $c->absorption);
        }
        $c->send($this->version, 'set_entity_data', Packets::playerAppearance($this->version->catalog, $c->entityId, $c->information['skinParts'] ?? 0, $c->information['mainHand'] ?? 1));
        $this->health($c);
        if ($c->dead) {
            $c->send($this->version, 'player_combat_kill', Binary::writeVarInt($c->entityId) . Nbt::text($c->name . ' died'));
        }
        $this->log($c->name . ' joined from ' . $c->peer);
    }

    private function spawnPlayer(Client $recipient, Client $player): void
    {
        if (isset($recipient->entities[$player->entityId]) || !$this->tracksPlayer($recipient, $player)) {
            return;
        }
        $recipient->send($this->version, 'add_entity', Packets::addPlayer($player->entityId, $player->uuid, $this->version->blocks['player_entity_type'], $player->x, $player->y, $player->z, $player->yaw, $player->pitch));
        $recipient->send($this->version, 'rotate_head', Packets::headRotation($player->entityId, $player->yaw));
        $recipient->send($this->version, 'set_entity_data', Packets::playerAppearance($this->version->catalog, $player->entityId, $player->information['skinParts'] ?? 0, $player->information['mainHand'] ?? 1));
        $attributes = $this->pairingAttributes($player);
        if ($attributes !== null) {
            $recipient->send($this->version, 'update_attributes', $attributes);
        }
        $recipient->send($this->version, 'set_equipment', Packets::equipment($player->entityId, $this->playerEquipment($player), $this->version->blocks['component_damage'], $this->version->catalog->itemStacks));
        $recipient->send($this->version, 'set_entity_data', $this->playerMetadata($player));
        $recipient->entities[$player->entityId] = true;
    }

    private function viewDistance(Client $c): int
    {
        return min($this->config->viewDistance, max(2, min(32, $c->information['viewDistance'] ?? $this->config->viewDistance)));
    }

    private function clientInformation(Client $c, Binary $r): void
    {
        $oldView = $this->viewDistance($c);
        $previous = $c->information;
        $c->information = Packets::readClientInformation($r);
        if ($c->state !== 'play') {
            return;
        }
        $appearance = Packets::playerAppearance($this->version->catalog, $c->entityId, $c->information['skinParts'], $c->information['mainHand']);
        foreach ($this->players() as $player) {
            if ((($previous['skinParts'] ?? 0) & 64) !== ($c->information['skinParts'] & 64)) {
                $player->send($this->version, 'player_info_update', "\x80\1" . $c->uuid . chr((int) (($c->information['skinParts'] & 64) !== 0)));
            }
            if (($player === $c || isset($player->entities[$c->entityId])) && (($previous['skinParts'] ?? 0) !== $c->information['skinParts'] || ($previous['mainHand'] ?? 1) !== $c->information['mainHand'])) {
                $player->send($this->version, 'set_entity_data', $appearance);
            }
        }
        if ($oldView !== $this->viewDistance($c)) {
            $c->send($this->version, 'set_chunk_cache_radius', Binary::writeVarInt($this->viewDistance($c)));
            $this->scheduleChunks($c);
        }
    }

    private function teleport(Client $c, ?array $position = null): void
    {
        if ($c->dimension !== $this->world->dimension) {
            $this->withDimension($c->dimension, fn () => $this->teleport($c, $position));
            return;
        }
        $this->sendPosition($c, $position ?? $this->world->spawn());
        $this->scheduleChunks($c);
        // Trackers follow in the player's tick, as ChunkMap.tick does, after a command's reply.
        $c->teleportBroadcastPending = true;
    }

    /** ServerGamePacketListenerImpl.teleport: move the player and await their confirmation. */
    private static function wrapDegrees(float $degrees): float
    {
        $wrapped = Float32::round(fmod(Float32::round($degrees), 360.0));
        if ($wrapped >= 180.0) {
            $wrapped = Float32::round($wrapped - 360.0);
        }
        if ($wrapped < -180.0) {
            $wrapped = Float32::round($wrapped + 360.0);
        }
        return $wrapped;
    }

    private function sendPosition(Client $c, array $position): void
    {
        [$c->x, $c->y, $c->z] = $position;
        // ServerPlayer.teleport resets the connection's movement check to the destination.
        $c->tickPosition = [$c->x, $c->y, $c->z];
        $this->trackPushingPlayer($c);
        $c->fallDistance = 0;
        $c->mining = null;
        $c->teleport = ++$c->teleportCounter;
        $c->teleportTick = $this->ticks;
        $c->send($this->version, 'player_position', Packets::position($c->teleport, $c->x, $c->y, $c->z, $c->yaw, $c->pitch));
    }

    private function scheduleChunks(Client $c): void
    {
        $cx = (int) floor($c->x / 16);
        $cz = (int) floor($c->z / 16);
        $c->chunkX = $cx;
        $c->chunkZ = $cz;
        $wanted = [];
        $queue = [];
        $view = $this->viewDistance($c);
        // ChunkTrackingView.isWithinDistance with the chunks adjacent to the view border: a
        // cylinder two chunks wider than the view distance.
        for ($z = $cz - $view - 2; $z <= $cz + $view + 2; ++$z) {
            for ($x = $cx - $view - 2; $x <= $cx + $view + 2; ++$x) {
                if (max(0, abs($x - $cx) - 2) ** 2 + max(0, abs($z - $cz) - 2) ** 2 >= $view * $view) {
                    continue;
                }
                $key = $x . ':' . $z;
                $wanted[$key] = true;
                if (!isset($c->loadedChunks[$key])) {
                    $queue[$key] = [$x, $z];
                }
            }
        }
        uasort($queue, static fn (array $a, array $b): int => (($a[0] - $cx) ** 2 + ($a[1] - $cz) ** 2) <=> (($b[0] - $cx) ** 2 + ($b[1] - $cz) ** 2));
        foreach ($c->loadedChunks as $key => [$x, $z]) {
            if (!isset($wanted[$key])) {
                $c->send($this->version, 'forget_level_chunk', pack('NN', $z, $x));
                unset($c->loadedChunks[$key]);
            }
        }
        $c->queuedChunks = $queue;
        $c->trackingDirty = true;
        $this->refreshTracking($c);
        $c->send($this->version, 'set_chunk_cache_center', Binary::writeVarInt($cx) . Binary::writeVarInt($cz));
    }

    private function play(Client $c, string $name, Binary $r): void
    {
        switch ($name) {
            case 'sign_update':$this->updateSign($c, $r);
                return;
            case 'pick_item_from_block':$this->pickBlock($c, $r);
                return;
            case 'client_information':$this->clientInformation($c, $r);
                return;
            case 'accept_teleportation':
                $confirmation = Packets::readTeleportConfirmation($r);
                // Like vanilla, only the latest teleport is confirmed; a repeated confirmation of it,
                // or one with NaN or infinite values, is invalid movement.
                if ($confirmation['id'] !== $c->teleportCounter) {
                    return;
                }
                if ($c->teleport === null || !$confirmation['valid']) {
                    $this->disconnect($c, self::INVALID_MOVEMENT);
                    return;
                }
                $c->teleport = null;
                return;
            case 'client_tick_end':
                $r->end();
                $c->positionThisTick = false;
                return;
            case 'keep_alive':
                $id = $r->long();
                $r->end();
                if ($c->keepAlive === null || $id !== $c->keepAlive) {
                    throw new ProtocolException('Invalid keep-alive');
                }
                $c->keepAlive = null;
                return;
            case 'chunk_batch_received':
                $rate = $r->float();
                $r->end();
                // PlayerChunkSender.onChunkBatchReceivedByClient: a NaN rate means the minimum.
                $c->unacknowledgedBatches = max(0, $c->unacknowledgedBatches - 1);
                $c->desiredChunksPerTick = is_nan($rate) ? 0.01 : max(0.01, min(64.0, $rate));
                if ($c->unacknowledgedBatches === 0) {
                    $c->batchQuota = 1.0;
                }
                $c->maxUnacknowledgedBatches = 10;
                return;
            case 'move_player_pos': case 'move_player_pos_rot': case 'move_player_rot': case 'move_player_status_only':
                $this->movement($c, $name, $r);
                return;
            case 'chat':
                $message = $r->string(256);
                // Signed chat is received but relayed as unsigned system chat.
                $r->long();
                $r->long();
                if ($r->bool()) {
                    $r->bytes(256);
                }
                $r->varInt();
                $r->bytes(3);
                $r->byte();
                $r->end();
                if (self::now() - $c->chatAt < 0.5) {
                    return;
                } $c->chatAt = self::now();
                if (preg_match('/[\x00-\x1f\x7f\x{00a7}]/u', $message)) {
                    throw new ProtocolException('Invalid chat characters');
                }
                $this->broadcast('<' . $c->name . '> ' . $message);
                return;
            case 'command_suggestion':
                $id = $r->varInt();
                $command = $r->string(32500);
                $r->end();
                $this->commandSuggestions($c, $id, $command);
                return;
            case 'chat_command':
                $command = $r->string(256);
                $r->end();
                $this->command($c, $command);
                return;
            case 'player_action': $this->dig($c, $r);
                return;
            case 'use_item_on': $this->place($c, $r);
                return;
            case 'use_item':
                $hand = $r->varInt();
                $sequence = $r->varInt();
                $yaw = $r->float();
                $pitch = $r->float();
                $r->end();
                if ($hand < 0 || $hand > 1 || $sequence < 0) {
                    throw new ProtocolException('Invalid use item');
                }
                if (!isset($c->inventory[$hand === 0 ? 36 + $c->heldSlot : 45])) {
                    $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
                    return;
                }
                if (!is_finite($yaw) || !is_finite($pitch)) {
                    $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
                    return;
                }
                $oldYaw = $c->yaw;
                $oldPitch = $c->pitch;
                $c->yaw = fmod(fmod($yaw + 180, 360) + 360, 360) - 180;
                $c->pitch = max(-90, min(90, fmod(fmod($pitch + 180, 360) + 360, 360) - 180));
                if ($oldYaw !== $c->yaw || $oldPitch !== $c->pitch) {
                    $this->broadcastPlayerPosition($c);
                }
                $this->useHeldItem($c, $hand);
                $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
                return;
            case 'set_carried_item':
                $slot = $r->short();
                $r->end();
                if ($slot < 0 || $slot > 8) {
                    throw new ProtocolException('Invalid hotbar slot');
                }$c->heldSlot = $slot;
                $c->eating = null;
                return;
            case 'set_creative_mode_slot': $this->creativeSlot($c, $r);
                return;
            case 'ping_request': $payload = $r->bytes(8);
                $r->end();
                $c->send($this->version, 'pong_response', $payload);
                return;
            case 'player_abilities': $flags = $r->byte();
                $r->end();
                $c->flying = $c->gameMode === 3 || ($c->gameMode === 1 && ($flags & 2) !== 0);
                $c->fallDistance = 0;
                $this->abilities($c);
                return;
            case 'client_command': $action = $r->varInt();
                $r->end();
                if ($action === 0) {
                    $this->respawnPlayer($c);
                }return;
            case 'container_click': $this->inventoryClick($c, $r);
                return;
            case 'container_close': $window = $r->varInt();
                $r->end();
                if ($window === $c->windowId) {
                    $this->closeWindow($c);
                    $this->syncInventory($c);
                }return;
            case 'player_loaded':$r->end();
                $c->playerLoaded = true;
                return;
            case 'player_input': $flags = $r->byte();
                $r->end();
                if ($c->playerLoaded) {
                    $c->sneaking = ($flags & 32) !== 0;
                }return;
            case 'player_command':
                $r->varInt();
                $action = $r->varInt();
                $r->varInt();
                $r->end();
                if ($action < 0 || $action > 6) {
                    throw new ProtocolException('Invalid player command');
                }
                if ($c->playerLoaded && in_array($action, [1, 2], true)) {
                    $c->sprinting = $action === 1;
                }return;
            case 'attack': $id = $r->varInt();
                $r->end();
                $this->attackEntity($c, $id);
                return;
            default:
                // Optional client telemetry and unsupported interactions have no world effects.
                return;
        }
    }

    private function movement(Client $c, string $name, Binary $r): void
    {
        $x = $c->x;
        $y = $c->y;
        $z = $c->z;
        $yaw = $c->yaw;
        $pitch = $c->pitch;
        if ($name === 'move_player_pos' || $name === 'move_player_pos_rot') {
            $x = $r->double();
            $y = $r->double();
            $z = $r->double();
        }
        if ($name === 'move_player_rot' || $name === 'move_player_pos_rot') {
            $yaw = $r->float();
            $pitch = $r->float();
        }
        $flags = $r->byte();
        $r->end();
        // ServerGamePacketListenerImpl.handleMovePlayer: NaN coordinates, non-finite rotations and a
        // second position in one client tick disconnect; other coordinates are clamped.
        if (is_nan($x) || is_nan($y) || is_nan($z) || !is_finite($yaw) || !is_finite($pitch)) {
            $this->disconnect($c, self::INVALID_MOVEMENT);
            return;
        }
        if ($name === 'move_player_pos' || $name === 'move_player_pos_rot') {
            if ($c->positionThisTick) {
                $this->disconnect($c, self::INVALID_MOVEMENT);
                return;
            }
            $c->positionThisTick = true;
        }
        $x = max(-3.0E7, min(3.0E7, $x));
        $y = max(-2.0E7, min(2.0E7, $y));
        $z = max(-3.0E7, min(3.0E7, $z));
        // Mth.wrapDegrees on the float rotations; Entity.setXRot keeps the pitch within ±90.
        $yaw = self::wrapDegrees($yaw);
        $pitch = max(-90.0, min(90.0, self::wrapDegrees($pitch)));
        if ($c->teleport !== null) {
            // updateAwaitingTeleport: after twenty ticks unconfirmed, a movement packet sends the
            // teleport again; meanwhile only the player's rotation follows the client.
            if ($this->ticks - $c->teleportTick > 20) {
                $this->sendPosition($c, [$c->x, $c->y, $c->z]);
            }
            $c->yaw = $yaw;
            $c->pitch = $pitch;
            return;
        }
        // handlePlayerPositionChange: more than ten blocks from where the tick began, allowing more
        // for each movement packet since then (as one when there are more than five), moved too quickly.
        $packets = ++$c->movesThisTick > 5 ? 1 : $c->movesThisTick;
        [$fromX, $fromY, $fromZ] = $c->tickPosition ?? [$c->x, $c->y, $c->z];
        if (($x - $fromX) ** 2 + ($y - $fromY) ** 2 + ($z - $fromZ) ** 2 > 100.0 * $packets && $this->gameRule('player_movement_check') === 'true') {
            $this->log($c->name . ' moved too quickly! ' . ($x - $fromX) . ',' . ($y - $fromY) . ',' . ($z - $fromZ));
            $this->teleport($c, [$c->x, $c->y, $c->z]);
            return;
        }
        if ($c->dead) {
            return;
        }
        $oldYaw = $c->yaw;
        $oldY = $c->y;
        $distance = sqrt(($x - $c->x) ** 2 + ($z - $c->z) ** 2);
        $c->x = $x;
        $c->y = $y;
        $c->z = $z;
        $c->yaw = $yaw;
        $c->pitch = $pitch;
        $c->ground = ($flags & 1) !== 0;
        $this->survivalMovement($c, $oldY, $distance);
        $this->trackPushingPlayer($c);
        if ($y < -80 && in_array($c->gameMode, [1, 3], true)) {
            $this->teleport($c);
        }
        if ((int) floor($c->x / 16) !== $c->chunkX || (int) floor($c->z / 16) !== $c->chunkZ) {
            $this->scheduleChunks($c);
        }
        $this->broadcastPlayerPosition($c, $oldYaw !== $c->yaw);
    }

    private function editable(Client $c, int $x, int $y, int $z): bool
    {
        return !$c->dead && in_array($c->gameMode, [0, 1], true) && $c->teleport === null && $y >= $this->world->minY && $y < $this->world->minY + $this->world->height && isset($c->loadedChunks[($x >> 4) . ':' . ($z >> 4)])
            && ($x + 0.5 - $c->x) ** 2 + ($y + 0.5 - ($c->y + 1.62)) ** 2 + ($z + 0.5 - $c->z) ** 2 <= 64;
    }

    /**
     * DedicatedServer.isUnderSpawnProtection: in the spawn's dimension, while any operator exists,
     * non-operators may not change blocks whose x or z is within the radius of the world spawn.
     */
    private function underSpawnProtection(Client $c, int $x, int $z): bool
    {
        if ($c->dimension !== 'overworld' || $this->config->operators === [] || $this->operator($c) || $this->config->spawnProtection <= 0) {
            return false;
        }
        [$spawnX, , $spawnZ] = $this->worlds['overworld']->spawn();
        return max(abs($x - (int) floor($spawnX)), abs($z - (int) floor($spawnZ))) <= $this->config->spawnProtection;
    }

    /** ServerPlayer.sendSpawnProtectionMessage: a red action-bar notice naming the block. */
    private function spawnProtectionMessage(Client $c, int $x, int $y, int $z): void
    {
        $c->send($this->version, 'system_chat', Nbt::component(['translate' => 'build.spawn_protection', 'with' => [['text' => $x . ', ' . $y . ', ' . $z]], 'color' => 'red']) . "\1");
    }

    private function dig(Client $c, Binary $r): void
    {
        $action = $r->varInt();
        [$x, $y, $z] = $r->position();
        $face = $r->byte();
        $sequence = $r->varInt();
        $r->end();
        if ($action < 0 || $action > 8 || $face > 5 || $sequence < 0) {
            throw new ProtocolException('Invalid digging action');
        }
        if ($action === 2) {
            $c->mining = null;
        } elseif ($action === 6) {
            $c->eating = null;
        } elseif ($action === 4 || $action === 5) {
            $slot = 36 + $c->heldSlot;
            if (isset($c->inventory[$slot]) && $c->gameMode !== 3) {
                $stack = $c->inventory[$slot];
                $count = $action === 4 ? $stack['count'] : 1;
                $this->playerDrop($c, array_replace($stack, ['count' => $count]), false);
                \PhpMinecraftJavaServer\World\Inventory::consume($c->inventory, $slot, $count);
                $this->syncInventory($c);
            }
        } elseif ($action === 7) {
            [$main, $off] = [$c->inventory[36 + $c->heldSlot] ?? null, $c->inventory[45] ?? null];
            if ($off === null) {
                unset($c->inventory[36 + $c->heldSlot]);
            } else {
                $c->inventory[36 + $c->heldSlot] = $off;
            }if ($main === null) {
                unset($c->inventory[45]);
            } else {
                $c->inventory[45] = $main;
            }$this->syncInventory($c);
        } elseif ($action === 0 && $this->editable($c, $x, $y, $z) && $this->underSpawnProtection($c, $x, $z)) {
            // ServerPlayerGameMode.handleBlockBreakAction: the notice alone, without a block update.
            $this->spawnProtectionMessage($c, $x, $y, $z);
            $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
            return;
        } elseif ($action === 0 && $this->editable($c, $x, $y, $z)) {
            $state = $this->world->get($x, $y, $z);
            if ($this->version->catalog->blockName($state) === 'note_block') {
                $this->playNote($x, $y, $z);
            }
            if ($c->gameMode === 1) {
                $this->breakBlock($c, $x, $y, $z);
            } elseif (!$this->version->catalog->air($state)) {
                $c->mining = ['position' => [$x, $y, $z], 'state' => $state, 'started' => $this->ticks, 'duration' => $this->miningTicks($c, $state)];
            }
        } elseif ($action === 3 && $c->mining !== null && $c->mining['position'] === [$x, $y, $z] && $this->ticks - $c->mining['started'] >= $c->mining['duration'] - 1) {
            $this->breakBlock($c, $x, $y, $z);
            $c->mining = null;
        }
        if ($action <= 3) {
            $this->blockUpdate($c, $x, $y, $z);
        }
        $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
    }

    private function place(Client $c, Binary $r): void
    {
        $hand = $r->varInt();
        [$x, $y, $z] = $r->position();
        $face = $r->varInt();
        $cursor = [$r->float(), $r->float(), $r->float()];
        $r->bool();
        $r->bool();
        $sequence = $r->varInt();
        $r->end();
        if ($face < 0 || $face > 5 || $hand < 0 || $hand > 1 || $sequence < 0) {
            throw new ProtocolException('Invalid placement');
        }
        // Vanilla measures the hit against the block centre, with a strict 1.0000001 bound.
        // Valid client edge hits can lie outside [0,1]. Invalid hits are acknowledged and ignored.
        foreach ($cursor as $axis => $value) {
            $block = [$x, $y, $z][$axis];
            if (!is_finite($value) || !(abs(($block + $value) - ($block + 0.5)) < 1.0000001)) {
                $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
                return;
            }
        }
        $slot = $hand === 0 ? 36 + $c->heldSlot : 45;
        $stack = $c->inventory[$slot] ?? null;
        $clicked = $this->world->get($x, $y, $z);
        $name = $this->version->catalog->blockName($clicked);
        $within = !$c->dead && ($x + 0.5 - $c->x) ** 2 + ($y + 0.5 - $c->y - 1.62) ** 2 + ($z + 0.5 - $c->z) ** 2 <= 64;
        // ServerGamePacketListenerImpl.handleUseItemOn: spawn protection stops any use on the block.
        if ($within && $y >= $this->world->minY && $y < $this->world->minY + $this->world->height && $this->underSpawnProtection($c, $x, $z)) {
            $this->spawnProtectionMessage($c, $x, $y, $z);
            $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
            return;
        }
        if ($within && $name === 'end_portal_frame' && $stack !== null && $this->version->catalog->itemName($stack['item']) === 'ender_eye' && $this->insertEndEye($c, $x, $y, $z, $slot)) {
            $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
            return;
        }
        if ($within && !$c->sneaking && $this->useSpecialBlock($c, $x, $y, $z, $slot)) {
            $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
            return;
        }
        if ($within && !$c->sneaking && $c->gameMode !== 3) {
            $type = match ($name) {
                'crafting_table' => 'crafting','furnace' => 'furnace','chest','barrel' => 'chest',default => null
            };
            if ($type !== null) {
                $this->openWindow($c, $type, [$x, $y, $z]);
                $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
                return;
            }
            $properties = $this->version->catalog->properties($clicked);
            if (isset($properties['open'])) {
                $properties['open'] = $properties['open'] === 'true' ? 'false' : 'true';
                $this->setBlock($x, $y, $z, $this->version->catalog->state($name, $properties));
                if (str_ends_with($name, '_door')) {
                    $otherY = $y + (($properties['half'] ?? 'lower') === 'lower' ? 1 : -1);
                    $other = $this->version->catalog->properties($this->world->get($x, $otherY, $z));
                    if ($this->version->catalog->blockName($this->world->get($x, $otherY, $z)) === $name) {
                        $other['open'] = $properties['open'];
                        $this->setBlock($x, $otherY, $z, $this->version->catalog->state($name, $other));
                    }
                }
                $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
                return;
            }
            if (str_ends_with($name, '_bed') && $c->dimension === 'overworld') {
                $c->personalSpawn = [$x + 0.5, (float) $y + 1, $z + 0.5];
                $this->savePlayer($c);
                $this->tell($c, 'Respawn point set');
                $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
                return;
            }
        }
        [$dx, $dy, $dz] = [[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]][$face];
        $replace = $this->version->catalog->blocks[$name]['replaceable'];
        [$tx, $ty, $tz] = $replace ? [$x, $y, $z] : [$x + $dx, $y + $dy, $z + $dz];
        if ($this->useSpawnEggOn($c, $slot, $x, $y, $z, $face)) {
            $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
            return;
        }
        $itemName = $stack === null ? null : $this->version->catalog->itemName($stack['item']);
        if ($itemName === 'flint_and_steel' && $this->ignitePortal($c, $tx, $ty, $tz, $slot)) {
            $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
            return;
        }
        if ($itemName === 'water_bucket' && $c->dimension === 'the_nether') {
            $this->tell($c, 'Water evaporates in the Nether.');
            $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
            return;
        }
        $blockName = match ($itemName) {
            'redstone' => 'redstone_wire','water_bucket' => 'water','lava_bucket' => 'lava',default => $itemName
        };
        if ($blockName !== null && str_ends_with($blockName, '_sign') && $face >= 2) {
            $wall = str_replace('_sign', '_wall_sign', $blockName);
            if (isset($this->version->catalog->blocks[$wall])) {
                $blockName = $wall;
            }
        }
        $block = $this->version->catalog->blocks[$blockName ?? ''] ?? null;
        if ($block !== null && $stack !== null && $this->editable($c, $tx, $ty, $tz) && $this->version->catalog->blocks[$this->version->catalog->blockName($this->world->get($tx, $ty, $tz))]['replaceable']) {
            $properties = [];
            $facing = ['south', 'west', 'north', 'east'][((int) floor($c->yaw / 90 + 0.5)) & 3];
            if (str_ends_with($blockName, '_wall_sign')) {
                $facing = [2 => 'north', 3 => 'south', 4 => 'west', 5 => 'east'][$face];
            }
            if (isset($block['properties']['rotation'])) {
                $properties['rotation'] = (string) (((int) floor(($c->yaw + 180) * 16 / 360 + .5)) & 15);
            }
            if (isset($block['properties']['axis'])) {
                $properties['axis'] = $face < 2 ? 'y' : ($face < 4 ? 'z' : 'x');
            }
            if (isset($block['properties']['facing']) && in_array($facing, $block['properties']['facing']['values'], true)) {
                $properties['facing'] = $facing;
            }
            if (isset($block['properties']['half']) && in_array('top', $block['properties']['half']['values'], true)) {
                $properties['half'] = $face === 0 || ($face > 1 && $cursor[1] > 0.5) ? 'top' : 'bottom';
            }
            if (str_ends_with($blockName, '_slab')) {
                $properties['type'] = $face === 0 || ($face > 1 && $cursor[1] > 0.5) ? 'top' : 'bottom';
            }
            if (isset($block['properties']['waterlogged'])) {
                $properties['waterlogged'] = $this->version->catalog->blockName($this->world->get($tx, $ty, $tz)) === 'water' ? 'true' : 'false';
            }
            $blocked = false;
            if ($block['full_cube']) {
                foreach ($this->localPlayers() as $player) {
                    if ($player->gameMode !== 3 && !$player->dead && $tx + 1 > $player->x - 0.3 && $tx < $player->x + 0.3 && $tz + 1 > $player->z - 0.3 && $tz < $player->z + 0.3 && $ty + 1 > $player->y && $ty < $player->y + 1.8) {
                        $blocked = true;
                        break;
                    }
                }
            }
            $bed = str_ends_with($blockName, '_bed');
            $bedOffset = null;
            if ($bed) {
                $properties['part'] = 'foot';
                $bedOffset = ['north' => [0, -1], 'south' => [0, 1], 'west' => [-1, 0], 'east' => [1, 0]][$facing];
                if (!$this->editable($c, $tx + $bedOffset[0], $ty, $tz + $bedOffset[1]) || !$this->version->catalog->air($this->world->get($tx + $bedOffset[0], $ty, $tz + $bedOffset[1]))) {
                    $blocked = true;
                }
            }
            $door = str_ends_with($blockName, '_door');
            if ($door && ($ty >= $this->world->minY + $this->world->height - 1 || !$this->version->catalog->air($this->world->get($tx, $ty + 1, $tz)))) {
                $blocked = true;
            }
            if (!$blocked) {
                $this->setBlock($tx, $ty, $tz, $this->version->catalog->state($blockName, $properties));
                if (str_ends_with($blockName, '_sign')) {
                    $this->openSign($c, $tx, $ty, $tz, 1);
                }
                if ($bed) {
                    $head = $properties;
                    $head['part'] = 'head';
                    $this->setBlock($tx + $bedOffset[0], $ty, $tz + $bedOffset[1], $this->version->catalog->state($blockName, $head));
                }
                if ($door) {
                    $properties['half'] = 'upper';
                    $this->setBlock($tx, $ty + 1, $tz, $this->version->catalog->state($blockName, $properties));
                }
                if ($c->gameMode === 0) {
                    \PhpMinecraftJavaServer\World\Inventory::consume($c->inventory, $slot);
                    if (str_ends_with($itemName, '_bucket')) {
                        $this->giveStack($c, ['item' => $this->version->blocks['items']['bucket'], 'count' => 1]);
                    }$this->syncInventory($c);
                }
            }
        }
        $this->blockUpdate($c, $tx, $ty, $tz);
        $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($sequence));
    }

    private function creativeSlot(Client $c, Binary $r): void
    {
        $slot = $r->short();
        $count = $r->varInt();
        if ($c->gameMode !== 1) {
            $this->syncInventory($c);
            return;
        }
        if ($slot === -1) {
            return;
        }if ($slot < 1 || $slot > 45 || $count < 0 || $count > 99) {
            throw new ProtocolException('Invalid creative slot');
        }
        $item = -1;
        $accepted = true;
        if ($count > 0) {
            $item = $r->varInt();
            $added = $r->varInt();
            $removed = $r->varInt();
            $info = $this->version->catalog->item($item);
            $accepted = $info !== null && $count <= $info['max_stack'] && $added === 0 && $removed === 0;
            if ($added === 0 && $removed === 0) {
                $r->end();
            }
        } else {
            $r->end();
        }
        if ($accepted && $count > 0) {
            $c->inventory[$slot] = ['item' => $item, 'count' => $count];
        } else {
            unset($c->inventory[$slot]);
        }
        $c->send($this->version, 'container_set_slot', "\0" . Binary::writeVarInt(++$c->inventoryRevision) . pack('n', $slot) . Packets::stack($c->inventory[$slot] ?? null, $this->version->blocks['component_damage'], $this->version->catalog->itemStacks));
        if (!$accepted) {
            $this->tell($c, 'Custom component patches are not supported in creative inventory.');
        }
    }

    private function setBlock(int $x, int $y, int $z, int $state): void
    {
        $previous = $this->world->get($x, $y, $z);
        if ($previous === $state) {
            return;
        }
        if ($this->version->catalog->blockName($previous) === 'jukebox' && $this->version->catalog->blockName($state) !== 'jukebox') {
            $this->ejectJukebox([$x, $y, $z], false);
        }
        $this->world->set($x, $y, $z, $state);
        $this->changedSimulation($x, $y, $z);
        if ($this->world->generator === 'terrain') {
            $this->pendingLight[($x >> 4) . ':' . ($z >> 4)] = [$x >> 4, $z >> 4];
        }
        foreach ($this->localPlayers() as $player) {
            if (isset($player->loadedChunks[($x >> 4) . ':' . ($z >> 4)])) {
                $this->blockUpdate($player, $x, $y, $z);
            }
        }
    }

    private function blockUpdate(Client $c, int $x, int $y, int $z): void
    {
        $c->send($this->version, 'block_update', Binary::writePosition($x, $y, $z) . Binary::writeVarInt($this->world->get($x, $y, $z)));
        $type = $this->world->blockEntityType($this->world->get($x, $y, $z));
        if ($type !== null) {
            $c->send($this->version, 'block_entity_data', Binary::writePosition($x, $y, $z) . Binary::writeVarInt($type) . $this->world->blockEntityNbt($x, $y, $z));
        }
    }

    /** Log the server-side English text, as vanilla's console does, and send the component. */
    private function broadcastComponent(array $message, string $logText, ?Client $except = null): void
    {
        $this->log('[CHAT] ' . $logText);
        foreach ($this->players() as $player) {
            if ($player !== $except) {
                $player->send($this->version, 'system_chat', Packets::systemChat($message));
            }
        }
    }

    private function broadcast(string $message): void
    {
        $this->log('[CHAT] ' . $message);
        foreach ($this->players() as $player) {
            $player->send($this->version, 'system_chat', Packets::chat($message));
        }
    }

    private function tick(float $now): void
    {
        if (($announcement = $this->announcements->poll($this->players() !== [])) !== null) {
            $this->broadcast($announcement);
        }
        ++$this->worldAge;
        // ServerClockManager.tick: while advance_time is on, each unpaused clock adds its rate to a
        // float partial tick and moves on by the whole ticks.
        if ($this->daylightCycle) {
            foreach (self::CLOCKS as [$total, $paused, $rate, $partial]) {
                if ($this->$paused) {
                    continue;
                }
                $this->$partial = unpack('G', pack('G', $this->$partial + $this->$rate))[1];
                $whole = (int) floor($this->$partial);
                $this->$partial = unpack('G', pack('G', $this->$partial - $whole))[1];
                $this->$total += $whole;
            }
        }
        $this->tickDimensions();
        if ($this->generationWorkers !== [] && $now - $this->generationWorkersCheckedAt >= 5) {
            $this->generationWorkersCheckedAt = $now;
            $this->checkGenerationWorkers();
        }
        foreach ($this->clients as $id => $c) {
            if ($c->closing) {
                if ($c->output === '' || $now - $c->closingAt > 2) {
                    $this->remove($id);
                }
                continue;
            }
            if (($c->state !== 'play' && $now - $c->connectedAt > 30) || $now - $c->lastRead > 60 || ($c->keepAlive !== null && $now - $c->lastKeepAlive > 30) || ($c->unacknowledgedBatches > 0 && $now - $c->batchAt > 30)) {
                $this->disconnect($c, 'Connection timed out');
                continue;
            }
            if ($c->state !== 'play') {
                continue;
            }
            try {
                if ($c->keepAlive === null && $now - $c->lastKeepAlive >= 15) {
                    $c->keepAlive = random_int(1, PHP_INT_MAX);
                    $c->lastKeepAlive = $now;
                    $c->send($this->version, 'keep_alive', pack('J', $c->keepAlive));
                }
                // ServerGamePacketListenerImpl.tickPlayer: movement checks start again from here.
                $c->tickPosition = [$c->x, $c->y, $c->z];
                $c->movesThisTick = 0;
                $this->withDimension($c->dimension, function () use ($c, $now): void {
                    $this->tickPortals($c);
                    if ($c->dimension !== $this->world->dimension) {
                        return;
                    }
                    $this->tickSurvival($c);
                    $this->tickPlayerPushing($c);
                    $this->syncEquipment($c);
                    $this->syncPlayerMetadata($c);
                    if ($this->ticks % 20 === 0) {
                        $this->sendClock($c);
                    }
                    $this->sendNextChunks($c, $now);
                    if ($c->teleportBroadcastPending) {
                        $c->teleportBroadcastPending = false;
                        $this->broadcastPlayerPosition($c);
                    }
                    if (!$c->attributesSent) {
                        $c->send($this->version, 'update_attributes', $this->joinAttributes($c));
                        $c->attributesSent = true;
                    }
                    $this->refreshTracking($c);
                });
            } catch (ProtocolException $e) {
                $this->disconnect($c, $e->getMessage());
            }
        }
        // Native autosave follows world and connection/player simulation.
        if ($this->autoSave && $this->ticks % 200 === 0) {
            $this->saveWorld();
        }
    }

    private function closeAfterWrite(Client $c): void
    {
        $c->closing = true;
        $c->closingAt = self::now();
    }

    /** @param string|array $reason plain text, or a text component */
    private function disconnect(Client $c, string|array $reason): void
    {
        if ($c->closing) {
            return;
        }
        if (in_array($c->state, ['login', 'configuration', 'play'], true)) {
            $component = is_array($reason) ? $reason : ['text' => $reason];
            $payload = $c->state === 'login' ? Binary::writeString(json_encode($component, JSON_THROW_ON_ERROR)) : Nbt::component($component);
            try {
                $c->send($this->version, $c->state === 'login' ? 'login_disconnect' : 'disconnect', $payload);
            } catch (ProtocolException) {
                $c->output = '';
            }
        }
        $this->closeAfterWrite($c);
    }

    private function remove(int $id): void
    {
        $c = $this->clients[$id] ?? null;
        if ($c === null) {
            return;
        }
        $this->sessionVerifier?->cancel($id);
        $this->forgetPushingEntity($c->entityId, $c->dimension);
        unset($this->clients[$id]);
        if ($c->state === 'play') {
            $this->closeWindow($c);
            $this->savePlayer($c);
        }
        fclose($c->socket);
        if ($c->state === 'play') {
            foreach ($this->players() as $other) {
                try {
                    $this->forgetEntity($other, $c->entityId);
                    $other->send($this->version, 'player_info_remove', "\1" . $c->uuid);
                } catch (ProtocolException) {
                    $this->closeAfterWrite($other);
                }
            }
            $this->broadcastComponent(['translate' => 'multiplayer.player.left', 'with' => [$this->displayName($c)], 'color' => 'yellow'], $c->name . ' left the game');
            $this->log($c->name . ' disconnected');
        }
    }
}
