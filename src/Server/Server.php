<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Framing;
use PhpMinecraftJavaServer\Protocol\LowPrecisionVector;
use PhpMinecraftJavaServer\Protocol\Nbt;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Protocol\ProtocolException;
use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\ShortOpenHashSet;
use PhpMinecraftJavaServer\World\World;

final class Server
{
    use BossBars;

    use Mining;

    use ExperienceOrbs;

    use PlayerSpawning;

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

    use ChunkMobs;

    use Dimensions;

    use BlockSimulation;

    use FluidSimulation;

    use RandomTicks;

    use TreeGrowth;

    use FireSimulation;

    use Explosions;

    use Sleeping;

    use WeatherCycle;

    use Lightning;

    use PoweredRails;

    use RespawnPoints;

    use FallingBlocks;

    use Speleothems;

    use Bells;

    use Tripwires;

    use Corals;

    use Chorus;

    use PressurePlates;

    use DaylightDetectors;

    use Observers;

    use Pistons;

    use TurtleEggs;

    use BlockPlacement;

    use Rails;

    use Sponges;

    use Precipitation;

    use BoneMeal;

    use SignAndPick;

    use EntityTracking;

    use SpawnEggs;

    use MobMovement;

    use Authentication;

    use Cameras;

    use Swinging;

    use TickRates;

    use CommandSources;

    use Riding;

    use ContainerOpeners;

    use VehicleSteering;

    use Waypoints;

    /** @var array<string, array<string, ShortOpenHashSet>> changed block positions per dimension and section */
    private array $changedBlocks = [];
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

    /** server.properties player-idle-timeout, in minutes; 0 never kicks. */
    private int $playerIdleTimeout = 0;

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
        if ((array) $config->initialGameRules !== [] && $this->world->setting('gameRules') === null) {
            $this->world->setting('gameRules', (array) $config->initialGameRules);
        }
        foreach (['the_nether', 'the_end'] as $dimension) {
            $path = $config->worldPath . '.' . $dimension . '.sqlite';
            $this->worlds[$dimension] = new World($version, $path, 'terrain', $config->seed, $dimension, $config->vanillaTerrain);
        }
        foreach (array_keys($this->worlds) as $dimension) {
            $this->withDimension($dimension, function (): void {
                $this->restoreAnimals();
                $this->restoreExperienceOrbs();
                $this->restoreItems();
                $this->restoreTnt();
                $this->restoreMovingPistons();
                $this->restoreDaylightDetectors();
                $this->restoreFallingBlocks();
                $this->restoreBlockSimulation();
            });
        }
        $this->crafting = new \PhpMinecraftJavaServer\World\Crafting($version->directory);
        foreach (['worldAge', 'dayTime', 'daylightCycle', 'weather', 'rainLevel', 'thunderLevel', 'keepInventory', 'naturalRegeneration', 'spawnMobs', 'clockPaused', 'clockRate', 'clockPartial', 'endClockTicks', 'endClockPaused', 'endClockRate', 'endClockPartial'] as $key) {
            $saved = $this->world->setting($key);
            if ($saved !== null) {
                $this->$key = $saved;
            }
        }
        $this->restoreWeather($this->world->setting('weatherData'));
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
        foreach (['the_nether', 'the_end'] as $dimension) {
            $pregenerate[] = ($this->worlds[$dimension] ?? null)?->vanilla !== null ? $this->config->worldPath . '.' . $dimension . '.sqlite' : '-';
        }
        $this->generationWorkerCommand = [PHP_BINARY, '-d', 'memory_limit=768M', __DIR__ . '/../../bin/vanilla-worker.php', $this->config->worldPath, (string) $this->world->seed, ...$pregenerate];
        for ($i = 0; $i < $this->config->generationWorkers; ++$i) {
            $worker = $this->spawnGenerationWorker();
            if ($worker !== null) {
                $this->generationWorkers[] = $worker;
            }
        }
        $this->log('Vanilla terrain: ' . count($this->generationWorkers) . ' generation workers');
        // ChunkGeneratorStructureState.ensureStructuresGenerated: stronghold rings are resolved
        // (and saved) in the background at startup, so /locate and eyes of ender find them quickly.
        $this->startLocate(null, 'overworld', 'structure', ['stronghold'], [0, 0, 0], 'minecraft:stronghold', false);
        // Spawn chunks first, kept for ten minutes so the first player to join finds them ready.
        [$x, , $z] = $this->world->spawn();
        for ($dz = -3; $dz <= 3; ++$dz) {
            for ($dx = -3; $dx <= 3; ++$dx) {
                $this->world->requestGeneration(((int) floor($x) >> 4) + $dx, ((int) floor($z) >> 4) + $dz, 100 + max(abs($dx), abs($dz)), 600.0);
                // The End's arrival platform, so players coming through a portal land on generated islands.
                ($this->worlds['the_end'] ?? null)?->requestGeneration(6 + $dx, $dz, 100 + max(abs($dx), abs($dz)), 600.0);
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
                $this->pollProfileFetches();
                $now = self::now();
                if ($now >= $nextTick) {
                    // MinecraftServer.runServer: a sprint runs the next tick at once.
                    $sprint = $this->sprinting() && $this->checkShouldSprintThisTick();
                    $started = hrtime(true);
                    $this->tick($now);
                    $this->tickTimes[] = hrtime(true) - $started;
                    $this->recordPerfTick(end($this->tickTimes));
                    if (count($this->tickTimes) > 100) {
                        array_shift($this->tickTimes);
                    }
                    if ($sprint) {
                        $this->sprintTimeSpent += hrtime(true) - $this->sprintTickStart;
                    }
                    ++$this->ticks;
                    $nextTick = $sprint ? $now : max($nextTick + $this->nanosecondsPerTick / 1e9, $now);
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
                'play' => $this->withDimension($c->dimension, function () use ($c, $name, $r): void {
                    // ServerPlayer.resetLastActionTime: the packets that show a player is not idle.
                    if (in_array($name, ['player_input', 'player_action', 'use_item_on', 'use_item', 'set_carried_item', 'chat_command', 'player_command', 'attack',
                        'interact', 'teleport_to_entity', 'client_command', 'container_click', 'place_recipe', 'container_button_click', 'sign_update'], true)) {
                        $c->lastActionTime = self::now();
                    }
                    $this->play($c, $name, $r);
                }),
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
            $c->lastActionTime = self::now();
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
        // The light engine settles light apart from chunk sending, so the light the batch's chunks
        // need is worked out before the batch's time budget starts.
        foreach ($batch as [$x, $z]) {
            $this->world->warmLight($x, $z);
        }
        $c->send($this->version, 'chunk_batch_start');
        ++$c->unacknowledgedBatches;
        $count = 0;
        $started = hrtime(true);
        foreach ($batch as $key => [$x, $z]) {
            $c->send($this->version, 'level_chunk_with_light', $this->world->chunk($x, $z));
            $c->loadedChunks[$key] = [$x, $z];
            // Neighbours lit before this chunk was generated take in the light it lets across.
            foreach ($this->world->neighbourArrived($x, $z) as [$nx, $nz]) {
                $this->pendingLight[$nx . ':' . $nz] = [$nx, $nz];
            }
            $this->loadChunkMobs($x, $z);
            if ($this->world->vanilla !== null) {
                $this->generatedEntities($x, $z);
            } else {
                $this->naturalAnimals($x, $z);
            }
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
        $this->sendTickingState($c);
        // CustomBossEvents.onPlayerConnect.
        $this->bossBarsConnect($c);
        $this->scheduleChunks($c);
        // The player's ServerEntity starts with them, counting ticks from here.
        $this->playerMotionTracker($c);
        $this->broadcastPlayerPosition($c);
        foreach ($others as $other) {
            $this->spawnPlayer($c, $other);
            $this->spawnPlayer($other, $c);
        }
        // ServerPlayer's first tick sends its saved post effects.
        $this->sendPostEffects($c);
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
        // ServerEntity.sendPairingData: the spawn from the last synced position, then the
        // non-default entity data, the synchronized attributes and any equipment.
        $recipient->send($this->version, 'add_entity', $this->playerMotionTracker($player)->spawnPacket($player->uuid, $this->version->blocks['player_entity_type']));
        $data = $this->playerPairingData($player);
        if ($data !== null) {
            $recipient->send($this->version, 'set_entity_data', $data);
        }
        $attributes = $this->pairingAttributes($player);
        if ($attributes !== null) {
            $recipient->send($this->version, 'update_attributes', $attributes);
        }
        $equipment = array_filter($this->playerEquipment($player), static fn (?array $stack): bool => $stack !== null);
        if ($equipment !== []) {
            $recipient->send($this->version, 'set_equipment', Packets::equipment($player->entityId, $equipment, $this->version->blocks['component_damage'], $this->version->catalog->itemStacks));
        }
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

    private function teleport(Client $c, ?array $position = null, int $relative = 0, ?array $from = null): void
    {
        if ($c->dimension !== $this->world->dimension) {
            $this->withDimension($c->dimension, fn () => $this->teleport($c, $position, $relative, $from));
            return;
        }
        $this->sendPosition($c, $position ?? $this->world->spawn(), $relative, $from);
        $this->scheduleChunks($c);
        // Trackers follow in the player's tick, as ChunkMap.tick does, after a command's reply.
        $c->teleportBroadcastPending = true;
    }

    /** ServerGamePacketListenerImpl.teleport: move the player and await their confirmation. */
    /** ServerGamePacketListenerImpl.ackBlockChangesUpTo: acknowledged together in the player's next tick. */
    private function acknowledge(Client $c, int $sequence): void
    {
        if ($sequence < 0) {
            throw new ProtocolException('Expected packet sequence nr >= 0');
        }
        $c->ackUpTo = max($c->ackUpTo, $sequence);
    }

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

    /**
     * Relative flags (Relative.pack) send each flagged coordinate or angle as its change from the
     * pose before, which defaults to the player's current one; the client adds it to its own.
     */
    private function sendPosition(Client $c, array $position, int $relative = 0, ?array $from = null): void
    {
        $from ??= [$c->x, $c->y, $c->z, $c->yaw, $c->pitch];
        [$c->x, $c->y, $c->z] = $position;
        // ServerPlayer.teleport resets the connection's movement check to the destination.
        $c->tickPosition = [$c->x, $c->y, $c->z];
        $this->trackPushingPlayer($c);
        $c->fallDistance = 0;
        $c->velocityY = 0.0;
        $c->teleport = ++$c->teleportCounter;
        $c->teleportTick = $this->ticks;
        $pose = [$c->x, $c->y, $c->z, $c->yaw, $c->pitch];
        foreach ($pose as $i => $value) {
            if (($relative >> $i & 1) === 1) {
                $pose[$i] = $value - $from[$i];
            }
        }
        $c->send($this->version, 'player_position', Packets::position($c->teleport, ...$pose, relative: $relative));
    }

    /** ChunkTrackingView.isWithinDistance: the cylinder of chunks a player's view keeps, with its border. */
    private function chunkInView(Client $c, int $x, int $z): bool
    {
        $view = $this->viewDistance($c);
        return max(0, abs($x - (int) floor($c->x / 16)) - 2) ** 2 + max(0, abs($z - (int) floor($c->z / 16)) - 2) ** 2 < $view * $view;
    }

    /** ChunkMap.isChunkOnTrackedBorder: a chunk in view with a neighbour outside it. */
    private function chunkOnViewBorder(Client $c, int $x, int $z): bool
    {
        for ($dx = -1; $dx <= 1; ++$dx) {
            for ($dz = -1; $dz <= 1; ++$dz) {
                if (!$this->chunkInView($c, $x + $dx, $z + $dz)) {
                    return true;
                }
            }
        }
        return false;
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
                // handleAcceptTeleportPacket then moves the player to the confirmed pose, rotation
                // included, as a packet that is not on the ground.
                $c->ground = false;
                $this->playerPositionChange($c, $confirmation['x'], $confirmation['y'], $confirmation['z'], $confirmation['yaw'], $confirmation['pitch'], 0);
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
                // ServerGamePacketListenerImpl.handleChat: illegal characters disconnect.
                if (preg_match('/[\x00-\x1f\x7f\x{00a7}]/u', $message)) {
                    $this->disconnect($c, ['translate' => 'multiplayer.disconnect.illegal_characters']);
                    return;
                }
                // tryHandleChat: a player who hides chat is told so instead.
                if (($c->information['chatVisibility'] ?? 0) === 2) {
                    $c->sendUnfiltered($this->version, 'system_chat', Packets::systemChat(['translate' => 'chat.disabled.options', 'color' => 'red']));
                    return;
                }
                $this->broadcastPlayerChat($c, $message);
                // chatSpamThrottler: twenty a message, one less each tick; operators are never kicked.
                $c->chatSpam += 20;
                if ($c->chatSpam >= 200 && !$this->operator($c)) {
                    $this->disconnect($c, ['translate' => 'disconnect.spam']);
                }
                $c->lastActionTime = self::now();
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
                    $this->acknowledge($c, $sequence);
                    return;
                }
                if (!is_finite($yaw) || !is_finite($pitch)) {
                    $this->acknowledge($c, $sequence);
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
                $this->acknowledge($c, $sequence);
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
                    // handleContainerClose sends nothing back unless the cursor or crafting grid returned items.
                    $before = [$c->inventory, $c->cursor];
                    $this->closeWindow($c);
                    if ([$c->inventory, $c->cursor] !== $before) {
                        $this->syncInventory($c);
                    }
                }return;
            case 'player_loaded':$r->end();
                $c->playerLoaded = true;
                return;
            case 'punch':
                $r->end();
                // ServerGamePacketListenerImpl.handlePunch: the main hand's attack swing, for trackers only.
                if (!$c->dead) {
                    [$type, $duration] = $this->attackAnimation($c->inventory[36 + $c->heldSlot] ?? null);
                    $this->swing($c->entityId, 0, $type, $duration, $c);
                }
                return;
            case 'move_vehicle': $this->moveVehicle($c, $r);
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
                }
                // ServerboundPlayerCommandPacket.Action.STOP_SLEEPING: leaving the bed.
                if ($action === 0 && $c->sleepingPos !== null) {
                    $this->withDimension($c->dimension, fn () => $this->stopSleeping($c, false, true));
                }
                return;
            case 'attack': $id = $r->varInt();
                $r->end();
                $this->attackEntity($c, $id);
                return;
            case 'interact':
                $id = $r->varInt();
                $hand = $r->varInt();
                LowPrecisionVector::decode($r);
                $secondary = $r->bool();
                $r->end();
                if ($c->playerLoaded) {
                    $c->lastActionTime = self::now();
                    $this->interactEntity($c, $id, $hand, $secondary);
                }
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
        $this->playerPositionChange($c, $x, $y, $z, $yaw, $pitch, $flags);
    }

    /** ServerGamePacketListenerImpl.handlePlayerPositionChange, for movement packets and confirmed teleports. */
    private function playerPositionChange(Client $c, float $x, float $y, float $z, float $yaw, float $pitch, int $flags): void
    {
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
        // handlePlayerPositionChange: a passenger only turns; its vehicle places it.
        if ($c->vehicle !== null) {
            $c->yaw = $yaw;
            $c->pitch = $pitch;
            $this->broadcastPlayerPosition($c);
            return;
        }
        // handlePlayerPositionChange: more than ten blocks from where the tick began, allowing more
        // for each movement packet since then (as one when there are more than five), moved too quickly.
        $packets = ++$c->movesThisTick > 5 ? 1 : $c->movesThisTick;
        [$fromX, $fromY, $fromZ] = $c->tickPosition ?? [$c->x, $c->y, $c->z];
        // The allowance is beyond the player's own server-side movement, of which only the vertical part is simulated here.
        if (($x - $fromX) ** 2 + ($y - $fromY) ** 2 + ($z - $fromZ) ** 2 - $c->velocityY ** 2 > 100.0 * $packets && $this->gameRule('player_movement_check') === 'true') {
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
        // handlePlayerKnownMovement: real movement shows the player is not idle.
        if (($x - $c->x) ** 2 + ($y - $c->y) ** 2 + ($z - $c->z) ** 2 > 1.0E-5) {
            $c->lastActionTime = self::now();
        }
        $c->x = $x;
        $c->y = $y;
        $c->z = $z;
        $c->yaw = $yaw;
        $c->pitch = $pitch;
        $ground = ($flags & 1) !== 0;
        // handlePlayerPositionChange: leaving the ground upwards is a jump in the server's own simulation.
        if ($c->ground && !$ground && $y > $oldY) {
            $c->velocityY = max(0.42, $c->velocityY);
        }
        $c->ground = $ground;
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
        if ($action <= 3) {
            // ServerGamePacketListenerImpl.handlePlayerAction: block actions, acknowledged next tick.
            $this->blockBreakAction($c, $action, $x, $y, $z, $face);
            $this->acknowledge($c, $sequence);
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
        }
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
        // Player.isWithinBlockInteractionRange with a block of slack: farther clicks are only acknowledged.
        if (!$this->withinBlockReach($c, $x, $y, $z, 1.0)) {
            $this->acknowledge($c, $sequence);
            return;
        }
        // Vanilla measures the hit against the block centre, with a strict 1.0000001 bound.
        // Valid client edge hits can lie outside [0,1]. Invalid hits are acknowledged and ignored.
        foreach ($cursor as $axis => $value) {
            $block = [$x, $y, $z][$axis];
            if (!is_finite($value) || !(abs(($block + $value) - ($block + 0.5)) < 1.0000001)) {
                $this->acknowledge($c, $sequence);
                return;
            }
        }
        $slot = $hand === 0 ? 36 + $c->heldSlot : 45;
        $stack = $c->inventory[$slot] ?? null;
        $clicked = $this->world->get($x, $y, $z);
        $name = $this->version->catalog->blockName($clicked);
        $within = !$c->dead && ($x + 0.5 - $c->x) ** 2 + ($y + 0.5 - $c->y - 1.62) ** 2 + ($z + 0.5 - $c->z) ** 2 <= 64;
        // ServerGamePacketListenerImpl.handleUseItemOn: spawn protection stops any use on the block.
        // ServerPlayer.sendBuildLimitMessage, as an overlay: clicks above or below the world.
        $maxY = $this->world->minY + $this->world->height - 1;
        $buildLimit = fn (bool $high) => $c->send($this->version, 'system_chat', Nbt::component(['translate' => $high ? 'build.tooHigh' : 'build.tooLow', 'with' => [$high ? $maxY : $this->world->minY], 'color' => 'red']) . "\1");
        if ($y > $maxY || $y < $this->world->minY) {
            $buildLimit($y > $maxY);
            $this->acknowledge($c, $sequence);
            return;
        }
        if ($within && $this->underSpawnProtection($c, $x, $z)) {
            $this->spawnProtectionMessage($c, $x, $y, $z);
            $this->acknowledge($c, $sequence);
            return;
        }
        // handleUseItemOn ignores the click while a teleport awaits its confirmation, answering
        // with the too-high message and the two blocks as they are.
        if ($c->teleport !== null) {
            $buildLimit(true);
            $this->blockUpdate($c, $x, $y, $z);
            $this->blockUpdate($c, $x + [0, 0, 0, 0, -1, 1][$face], $y + [-1, 1, 0, 0, 0, 0][$face], $z + [0, 0, -1, 1, 0, 0][$face]);
            $this->acknowledge($c, $sequence);
            return;
        }
        if ($within && $name === 'end_portal_frame' && $stack !== null && $this->version->catalog->itemName($stack['item']) === 'ender_eye' && $this->insertEndEye($c, $x, $y, $z, $slot)) {
            $this->useSwing($c, $hand);
            // handleUseItemOn resends the used block and the one on its clicked face after any use.
            $this->resendUsedBlocks($c, $x, $y, $z, $face);
            $this->acknowledge($c, $sequence);
            return;
        }
        if ($within && !$c->sneaking && $name === 'bell' && $c->gameMode !== 3 && $this->useBell($x, $y, $z, $face, $cursor[1])) {
            $this->useSwing($c, $hand);
            // handleUseItemOn resends the used block and the one on its clicked face after any use.
            $this->resendUsedBlocks($c, $x, $y, $z, $face);
            $this->acknowledge($c, $sequence);
            return;
        }
        if ($within && !$c->sneaking && $this->useSpecialBlock($c, $x, $y, $z, $slot)) {
            $this->useSwing($c, $hand);
            // handleUseItemOn resends the used block and the one on its clicked face after any use.
            $this->resendUsedBlocks($c, $x, $y, $z, $face);
            $this->acknowledge($c, $sequence);
            return;
        }
        if ($within && !$c->sneaking && $c->gameMode !== 3) {
            $type = match ($name) {
                'crafting_table' => 'crafting','furnace' => 'furnace','chest','trapped_chest','barrel' => 'chest','ender_chest' => 'ender',default => null
            };
            // EnderChestBlock and ChestBlock.isChestBlockedAt: a redstone conductor above keeps it shut,
            // above either half of a double chest.
            $blockedAbove = fn (int $bx, int $bz): bool => $this->version->catalog->collision['shapes'][$this->version->catalog->collision['states'][$this->world->get($bx, $y + 1, $bz)]] === [[0.0, 0.0, 0.0, 1.0, 1.0, 1.0]];
            $chest = $name === 'chest' || $name === 'trapped_chest';
            $partner = $chest ? $this->chestPartner([$x, $y, $z]) : null;
            if (($type === 'ender' || $chest) && ($blockedAbove($x, $z) || ($partner !== null && $blockedAbove($partner[0], $partner[2])))) {
                $this->useSwing($c, $hand);
                $this->acknowledge($c, $sequence);
                $this->resendUsedBlocks($c, $x, $y, $z, $face);
                return;
            }
            if ($type !== null) {
                $this->openWindow($c, $type, [$x, $y, $z], $partner);
                $this->useSwing($c, $hand);
                $this->acknowledge($c, $sequence);
                $this->resendUsedBlocks($c, $x, $y, $z, $face);
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
                $this->useSwing($c, $hand);
                $this->acknowledge($c, $sequence);
                return;
            }
            // Sneaking with an item in hand skips the block's own use.
            $sneakSkips = $c->sneaking && ($stack !== null || isset($c->inventory[$hand === 0 ? 45 : 36 + $c->heldSlot]));
            if ($name === 'respawn_anchor' && !$sneakSkips && $this->useRespawnAnchor($c, $x, $y, $z, $slot)) {
                $this->useSwing($c, $hand, true);
                $this->acknowledge($c, $sequence);
                return;
            }
            if (str_ends_with($name, '_bed') && !($c->sneaking && ($stack !== null || isset($c->inventory[$hand === 0 ? 45 : 36 + $c->heldSlot]))) && $this->useBed($c, $x, $y, $z)) {
                // BedBlock answers SUCCESS_SERVER, which the sleeper sees too.
                $this->useSwing($c, $hand, true);
                $this->acknowledge($c, $sequence);
                return;
            }
        }
        [$dx, $dy, $dz] = [[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]][$face];
        $heldName = $stack === null ? null : $this->version->catalog->itemName($stack['item']);
        $replace = $this->canBeReplacedBy($clicked, $heldName, true, $face, $cursor[1], $c->sneaking);
        [$tx, $ty, $tz] = $replace ? [$x, $y, $z] : [$x + $dx, $y + $dy, $z + $dz];
        if ($heldName === 'scaffolding' && $name === 'scaffolding') {
            // ScaffoldingBlockItem.updatePlacementContext: up the column, or outward from its top,
            // to the first free space within seven blocks sideways.
            $toward = $c->sneaking ? $face : ($face === 1 ? [3, 4, 2, 5][((int) floor($c->yaw / 90 + 0.5)) & 3] : 1);
            [$sx, $sy, $sz] = [[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]][$toward];
            [$tx, $ty, $tz] = [$x, $y, $z];
            for ($sideways = 0; $sideways < 7;) {
                [$tx, $ty, $tz] = [$tx + $sx, $ty + $sy, $tz + $sz];
                $there = $ty >= $this->world->minY && $ty < $this->world->minY + $this->world->height ? $this->world->get($tx, $ty, $tz) : null;
                if ($there === null || $this->version->catalog->blockName($there) !== 'scaffolding') {
                    $replace = $there !== null && $this->canBeReplacedBy($there, $heldName, true, $toward, $cursor[1], $c->sneaking);
                    break;
                }
                $sideways += $sy === 0 ? 1 : 0;
            }
            if (!$replace) {
                [$tx, $ty, $tz] = [$x, $y, $z];
            }
        }
        if ($this->useSpawnEggOn($c, $slot, $x, $y, $z, $face)) {
            $this->useSwing($c, $hand);
            $this->acknowledge($c, $sequence);
            return;
        }
        $itemName = $stack === null ? null : $this->version->catalog->itemName($stack['item']);
        // BoneMealItem.useOn: a valid target grows, and the bone meal is used.
        if ($within && $itemName === 'bone_meal' && $this->editable($c, $x, $y, $z) && $this->useBoneMeal($c, $x, $y, $z, $slot)) {
            $this->useSwing($c, $hand);
            $this->blockUpdate($c, $x, $y, $z);
            $this->blockUpdate($c, $x + $dx, $y + $dy, $z + $dz);
            $this->acknowledge($c, $sequence);
            return;
        }
        // TntBlock.useItemOn: flint and steel or a fire charge primes TNT, wearing or using up the item.
        if ($within && in_array($itemName, ['flint_and_steel', 'fire_charge'], true) && $name === 'tnt' && $this->editable($c, $x, $y, $z) && $this->gameRule('tnt_explodes') === 'true') {
            $this->primeTnt($x, $y, $z, $c);
            $this->setBlock($x, $y, $z, $this->version->catalog->state('air'));
            if ($c->gameMode !== 1) {
                if ($itemName === 'fire_charge') {
                    \PhpMinecraftJavaServer\World\Inventory::consume($c->inventory, $slot);
                } else {
                    $c->inventory[$slot]['damage'] = ($c->inventory[$slot]['damage'] ?? 0) + 1;
                    if ($c->inventory[$slot]['damage'] >= $this->version->catalog->item($c->inventory[$slot]['item'])['durability']) {
                        unset($c->inventory[$slot]);
                    }
                }
                $this->syncInventory($c);
            }
            $this->useSwing($c, $hand);
            $this->blockUpdate($c, $x, $y, $z);
            $this->blockUpdate($c, $x + $dx, $y + $dy, $z + $dz);
            $this->acknowledge($c, $sequence);
            return;
        }
        if ($itemName === 'flint_and_steel' && $this->ignitePortal($c, $tx, $ty, $tz, $slot)) {
            $this->useSwing($c, $hand);
            $this->acknowledge($c, $sequence);
            return;
        }
        if ($itemName === 'water_bucket' && $c->dimension === 'the_nether') {
            $this->tell($c, 'Water evaporates in the Nether.');
            $this->acknowledge($c, $sequence);
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
        if ($block !== null && $stack !== null && $this->editable($c, $tx, $ty, $tz)
            && ($replace || $this->canBeReplacedBy($this->world->get($tx, $ty, $tz), $itemName, false, $face, $cursor[1], $c->sneaking))) {
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
            if ($blockName === 'chest' || $blockName === 'trapped_chest') {
                [$properties['facing'], $properties['type']] = $this->chestPlacement($blockName, [$tx, $ty, $tz], $facing, $face, $c->sneaking);
            }
            $placed = $this->placementState($c, $blockName, $properties, [$tx, $ty, $tz], $face, $cursor, [$tx, $ty, $tz] === [$x, $y, $z]);
            $blocked = $placed === null;
            if ($placed !== null) {
                [$blockName, $properties] = $placed;
                $block = $this->version->catalog->blocks[$blockName];
                $blocked = !$this->placementSurvives($this->version->catalog->state($blockName, $properties), $tx, $ty, $tz);
            }
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
                $this->setBlock($tx, $ty, $tz, $this->connectedState($this->version->catalog->state($blockName, $properties), $tx, $ty, $tz));
                $this->updateNeighbourShapes($tx, $ty, $tz);
                if ($blockName === 'tripwire_hook') {
                    // TripWireHookBlock.setPlacedBy.
                    $this->hookCalculate([$tx, $ty, $tz], $this->world->get($tx, $ty, $tz), false, false, -1, null);
                }
                if (($properties['type'] ?? 'single') !== 'single' && ($blockName === 'chest' || $blockName === 'trapped_chest')) {
                    $this->joinChest([$tx, $ty, $tz]);
                }
                if (!str_ends_with($itemName, '_bucket')) {
                    $this->placeSound($c, $tx, $ty, $tz, $blockName);
                }
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
                $this->useSwing($c, $hand);
            }
        }
        $this->acknowledge($c, $sequence);
        $this->resendUsedBlocks($c, $x, $y, $z, $face);
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
        $this->unjoinChest([$x, $y, $z], $previous, $state);
        $this->changedSimulation($x, $y, $z);
        $this->blockChanged($x, $y, $z);
        // Block.onPlace and affectNeighborsAfterRemoval run only when the block itself changes.
        if ($this->version->catalog->blockName($previous) !== $this->version->catalog->blockName($state)) {
            $this->tripwireBlockSwapped($x, $y, $z, $previous, $state);
        }
        // BaseRailBlock.onPlace: a rail set where it was not joins the rails beside it.
        if ($this->isRail($state) && $this->version->catalog->blockName($previous) !== $this->version->catalog->blockName($state)) {
            $this->placeRail($x, $y, $z);
        }
    }

    /** ChunkHolder.blockChanged: clients hear of a changed block with the tick's other changes, and its light follows. */
    private function blockChanged(int $x, int $y, int $z): void
    {
        $this->relightAround($x, $z);
        $this->changedBlocks[$this->world->dimension][($x >> 4) . ':' . ($z >> 4) . ':' . ($y >> 4)] ??= new ShortOpenHashSet();
        $this->changedBlocks[$this->world->dimension][($x >> 4) . ':' . ($z >> 4) . ':' . ($y >> 4)]->add((($x & 15) << 8) | (($z & 15) << 4) | ($y & 15));
    }

    /**
     * ChunkHolder.broadcastChanges: each section's changed blocks reach the players the chunk is
     * sent to, one as a block update and more as a section update, each followed by any block
     * entity data.
     */
    private function broadcastChangedBlocks(): void
    {
        $changes = $this->changedBlocks[$this->world->dimension] ?? [];
        unset($this->changedBlocks[$this->world->dimension]);
        // ServerChunkCache.broadcastChangedChunks visits the chunks in the order they first changed,
        // and each ChunkHolder its sections from the bottom up.
        $chunks = [];
        foreach (array_keys($changes) as $key) {
            [$cx, $cz, $sy] = explode(':', $key);
            $chunks[$cx . ':' . $cz][(int) $sy] = $key;
        }
        $ordered = [];
        foreach ($chunks as $sections) {
            ksort($sections);
            foreach ($sections as $key) {
                $ordered[$key] = $changes[$key];
            }
        }
        foreach ($ordered as $key => $set) {
            [$cx, $cz, $sy] = array_map('intval', explode(':', $key));
            $players = array_filter($this->localPlayers(), static fn (Client $player): bool => isset($player->loadedChunks[$cx . ':' . $cz]));
            if ($players === []) {
                continue;
            }
            $positions = array_map(static fn (int $packed): array => [($cx << 4) | (($packed >> 8) & 15), ($sy << 4) | ($packed & 15), ($cz << 4) | (($packed >> 4) & 15)], $set->values());
            if (count($positions) === 1) {
                foreach ($players as $player) {
                    $this->blockUpdate($player, ...$positions[0]);
                }
                continue;
            }
            $section = pack('J', (($cx & 0x3fffff) << 42) | (($cz & 0x3fffff) << 20) | ($sy & 0xfffff));
            $payload = $section . Binary::writeVarInt(count($positions));
            foreach ($positions as [$x, $y, $z]) {
                $payload .= Binary::writeVarLong(($this->world->get($x, $y, $z) << 12) | (($x & 15) << 8) | (($z & 15) << 4) | ($y & 15));
            }
            foreach ($players as $player) {
                $player->send($this->version, 'section_blocks_update', $payload);
                foreach ($positions as [$x, $y, $z]) {
                    $type = $this->world->blockEntityUpdateType($this->world->get($x, $y, $z));
                    if ($type !== null) {
                        $player->send($this->version, 'block_entity_data', Binary::writePosition($x, $y, $z) . Binary::writeVarInt($type) . $this->world->blockEntityNbt($x, $y, $z));
                    }
                }
            }
        }
    }

    /**
     * BlockItem.place: the block's place sound, at its SoundType's volume eased toward full and
     * 80% of its pitch, for the players near it but not the placer, who plays it themself.
     */
    private function placeSound(Client $placer, int $x, int $y, int $z, string $block): void
    {
        [$sound, $volume, $pitch] = $this->version->catalog->simulation['place_sounds'][$block];
        $volume = Float32::round(($volume + 1.0) / 2.0);
        $payload = Packets::sound($sound, 4, $x + 0.5, $y + 0.5, $z + 0.5, $volume, Float32::round($pitch * Float32::round(0.8)), unpack('J', random_bytes(8))[1]);
        $range = $volume > 1.0 ? 16.0 * $volume : 16.0;
        foreach ($this->localPlayers() as $player) {
            if ($player !== $placer && ($player->x - $x - 0.5) ** 2 + ($player->y - $y - 0.5) ** 2 + ($player->z - $z - 0.5) ** 2 < $range ** 2) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    /** handleUseItemOn resends the used block and the one on its clicked face. */
    private function resendUsedBlocks(Client $c, int $x, int $y, int $z, int $face): void
    {
        [$dx, $dy, $dz] = [[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]][$face];
        foreach ([[$x, $y, $z], [$x + $dx, $y + $dy, $z + $dz]] as [$bx, $by, $bz]) {
            $c->send($this->version, 'block_update', Binary::writePosition($bx, $by, $bz) . Binary::writeVarInt($this->world->get($bx, $by, $bz)));
        }
    }

    /** Queue light updates for a changed block's chunk and its neighbours, which light crosses into. */
    private function relightAround(int $x, int $z): void
    {
        for ($dx = -1; $dx <= 1; ++$dx) {
            for ($dz = -1; $dz <= 1; ++$dz) {
                $this->pendingLight[(($x >> 4) + $dx) . ':' . (($z >> 4) + $dz)] = [($x >> 4) + $dx, ($z >> 4) + $dz];
            }
        }
    }

    /** ServerPlayer.sendPostEffects. */
    private function sendPostEffects(Client $c): void
    {
        $c->postEffectsDirty = false;
        $c->send($this->version, 'post_effects', Binary::writeVarInt(count($c->postEffects)) . implode('', array_map(Binary::writeString(...), $c->postEffects)));
    }

    private function blockUpdate(Client $c, int $x, int $y, int $z): void
    {
        $c->send($this->version, 'block_update', Binary::writePosition($x, $y, $z) . Binary::writeVarInt($this->world->get($x, $y, $z)));
        $type = $this->world->blockEntityUpdateType($this->world->get($x, $y, $z));
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
        $this->tickRateManagerTick();
        // ServerLevel.tick: game time and clocks stand still while the game is frozen.
        if ($this->runGameElements) {
            $this->tickTime();
            // ServerLevel.tickTime: the overworld's scheduled events come due with game time.
            $this->tickScheduledFunctions();
        }
        $this->tickSwings();
        $this->tickDimensions();
        if ($this->locating !== []) {
            $this->pollLocates();
        }
        $this->tickClients($now);
        $this->tickWaypoints();
    }

    private function tickTime(): void
    {
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
    }

    /** Generation workers, then each connection and its player. */
    private function tickClients(float $now): void
    {
        if ($this->generationWorkers !== [] && $now - $this->generationWorkersCheckedAt >= 5) {
            $this->generationWorkersCheckedAt = $now;
            $this->checkGenerationWorkers();
            $this->placeEndExitPortal();
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
                // ServerGamePacketListenerImpl.tick: idle longer than the timeout is kicked.
                if ($this->playerIdleTimeout > 0 && $c->lastActionTime > 0 && $now - $c->lastActionTime > $this->playerIdleTimeout * 60) {
                    $this->disconnect($c, ['translate' => 'multiplayer.disconnect.idling']);
                    continue;
                }
                // ServerGamePacketListenerImpl.tick: the chat spam count falls by one a tick.
                if ($c->chatSpam > 0) {
                    --$c->chatSpam;
                }
                if ($c->keepAlive === null && $now - $c->lastKeepAlive >= 15) {
                    $c->keepAlive = random_int(1, PHP_INT_MAX);
                    $c->lastKeepAlive = $now;
                    $c->send($this->version, 'keep_alive', pack('J', $c->keepAlive));
                }
                // ServerGamePacketListenerImpl.tick: one acknowledgement of the highest block change sequence,
                // before this tick's block changes, which vanilla broadcasts at the start of the next.
                if ($c->ackUpTo >= 0) {
                    $c->send($this->version, 'block_changed_ack', Binary::writeVarInt($c->ackUpTo));
                    $c->ackUpTo = -1;
                }
                // ServerGamePacketListenerImpl.tickPlayer: movement checks start again from here.
                $c->tickPosition = [$c->x, $c->y, $c->z];
                $c->movesThisTick = 0;
                $this->withDimension($c->dimension, function () use ($c, $now): void {
                    $this->tickPortals($c);
                    if ($c->dimension !== $this->world->dimension) {
                        return;
                    }
                    $this->tickControlledVehicle($c);
                    $this->tickSurvival($c);
                    $this->sendKnockbackMotion($c);
                    $this->tickCamera($c);
                    $this->tickPlayerPushing($c);
                    // ServerPlayer.tick ends by sending changed post effects, the empty list included.
                    if ($c->postEffectsDirty) {
                        $this->sendPostEffects($c);
                    }
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
                    $this->synchronizePlayerMotion($c);
                    $this->simulatePlayerGround($c);
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

    /** ComponentSerialization's JSON: a component with only text is written as a bare string, at any depth. */
    private static function compactComponent(mixed $component): mixed
    {
        if (!is_array($component)) {
            return $component;
        }
        if (array_keys($component) === ['text'] && is_string($component['text'])) {
            return $component['text'];
        }
        foreach (['with', 'extra'] as $list) {
            if (isset($component[$list]) && is_array($component[$list])) {
                $component[$list] = array_map(self::compactComponent(...), $component[$list]);
            }
        }
        return $component;
    }

    /** @param string|array $reason plain text, or a text component */
    private function disconnect(Client $c, string|array $reason): void
    {
        if ($c->closing) {
            return;
        }
        if (in_array($c->state, ['login', 'configuration', 'play'], true)) {
            $component = is_array($reason) ? $reason : ['text' => $reason];
            $payload = $c->state === 'login' ? Binary::writeString(json_encode(self::compactComponent($component), JSON_THROW_ON_ERROR)) : Nbt::component($component);
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
        $this->leaveVehicle($c);
        // ServerPlayer.disconnect leaves the bed; the level then recounts its sleepers.
        if ($c->state === 'play' && $c->sleepingPos !== null) {
            $this->withDimension($c->dimension, fn () => $this->stopSleeping($c, true, false));
        }
        unset($this->clients[$id]);
        if ($c->state === 'play') {
            $this->withDimension($c->dimension, fn () => $this->updateSleepingPlayers());
        }
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
