<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Decorator;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\ProtoChunk;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Survival;
use PhpMinecraftJavaServer\World\Vanilla\Structure\ShapeUpdater;
use PhpMinecraftJavaServer\World\Vanilla\Structure\Structures;

/**
 * Vanilla Overworld or Nether chunks stored in the world database while they move through
 * vanilla's stages: carved (status 1), then decorated (status 2). Decoration also writes into the eight
 * neighbours, so a chunk is final, and may be sent, once every chunk around it is decorated.
 * Several processes may share the store: carving is idempotent and each decoration runs in
 * an immediate transaction.
 */
final class ChunkStore
{
    public const CARVED = 1;
    public const DECORATED = 2;

    private ?OverworldGenerator $generator = null;

    private ?Decorator $decorator = null;

    private ?ShapeUpdater $shapes = null;

    private ?Blocks $blocks = null;

    /** @var array<string, array{0: list<int>, 1: list<string>}> */
    private array $final = [];

    /** @var array<string, true> chunks known to be final */
    private array $readyChunks = [];

    /** @var array<string, float> when a not-ready chunk may be checked or requested again */
    private array $recheck = [];

    /** When work() next deletes expired requests. */
    private float $expireAt = 0.0;

    /** @var ?array{0: int, 1: int, 2: int} centre chunk and radius generated while idle */
    private ?array $pregenerate = null;

    private int $pregenerateRing = 0;

    /** @var array<int, array{0: int, 1: int}> chunks of the current ring not known to be final */
    private array $pregenerateQueue = [];

    private const STEP_READY = 0;

    private const STEP_CARVED = 1;

    private const STEP_DECORATED = 2;

    private const STEP_BLOCKED = 3;

    /** @param int $worldHeight the dimension's build height, which final() pads its output to */
    public function __construct(
        private readonly \PDO $db,
        private readonly Version $version,
        private readonly int $seed,
        private readonly string $dimension = 'overworld',
        private readonly int $worldHeight = 384,
    ) {
        $db->exec('CREATE TABLE IF NOT EXISTS vanilla_chunks(cx INTEGER NOT NULL,cz INTEGER NOT NULL,status INTEGER NOT NULL,blocks BLOB NOT NULL,biomes BLOB NOT NULL,PRIMARY KEY(cx,cz));'
            . 'CREATE TABLE IF NOT EXISTS vanilla_requests(cx INTEGER NOT NULL,cz INTEGER NOT NULL,priority INTEGER NOT NULL,PRIMARY KEY(cx,cz));'
            . 'CREATE TABLE IF NOT EXISTS vanilla_claims(cx INTEGER NOT NULL,cz INTEGER NOT NULL,claimed REAL NOT NULL,PRIMARY KEY(cx,cz));'
            // Structure blocks whose shapes are updated once the chunk and its neighbours are decorated.
            . 'CREATE TABLE IF NOT EXISTS vanilla_post_processing(cx INTEGER NOT NULL,cz INTEGER NOT NULL,x INTEGER NOT NULL,y INTEGER NOT NULL,z INTEGER NOT NULL,PRIMARY KEY(x,y,z));'
            // Block entities generation placed: containers' loot tables and seeds.
            . 'CREATE TABLE IF NOT EXISTS vanilla_block_entities(cx INTEGER NOT NULL,cz INTEGER NOT NULL,x INTEGER NOT NULL,y INTEGER NOT NULL,z INTEGER NOT NULL,data TEXT NOT NULL,PRIMARY KEY(x,y,z))');
        // Worldgen heightmaps of the carved chunk, kept while neighbours decorate into it.
        $columns = array_column($db->query('PRAGMA table_info(vanilla_chunks)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        if (!in_array('heights', $columns, true)) {
            $db->exec('ALTER TABLE vanilla_chunks ADD COLUMN heights BLOB');
        }
        // Requests nobody renews (a player moved away or left) expire instead of keeping the workers busy.
        $columns = array_column($db->query('PRAGMA table_info(vanilla_requests)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        if (!in_array('expires', $columns, true)) {
            $db->exec('ALTER TABLE vanilla_requests ADD COLUMN expires REAL NOT NULL DEFAULT 0');
        }
    }

    public function blocks(): Blocks
    {
        return $this->blocks ??= new Blocks($this->version, $this->version->directory . '/worldgen/block-behaviour.json');
    }

    private function generator(): OverworldGenerator
    {
        return $this->generator ??= new OverworldGenerator($this->seed, $this->version->directory, match ($this->dimension) { 'the_nether' => 'nether', 'the_end' => 'end', default => 'overworld' });
    }

    private function decorator(): Decorator
    {
        if ($this->decorator === null) {
            $this->decorator = new Decorator($this->blocks(), new BiomeClimate($this->version->directory . '/worldgen'), $this->version->directory . '/worldgen', $this->dimension);
            $generator = $this->generator();
            $this->decorator->structures = new Structures($generator->settings, $generator->biomes, $this->version->directory . '/worldgen', $this->db);
        }
        return $this->decorator;
    }

    /**
     * The block entities generation placed in a chunk, by "x,y,z".
     *
     * @return array<string, array>
     */
    public function blockEntities(int $cx, int $cz): array
    {
        $q = $this->db->prepare('SELECT x,y,z,data FROM vanilla_block_entities WHERE cx=? AND cz=?');
        $q->execute([$cx, $cz]);
        $out = [];
        foreach ($q->fetchAll(\PDO::FETCH_NUM) as [$x, $y, $z, $data]) {
            $out["$x,$y,$z"] = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        }
        return $out;
    }

    /** LevelChunk.postProcessGeneration: update the shapes structure pieces marked in a chunk. */
    private function postProcess(int $cx, int $cz, array $blocks): array
    {
        $q = $this->db->prepare('SELECT x,y,z FROM vanilla_post_processing WHERE cx=? AND cz=? ORDER BY rowid');
        $q->execute([$cx, $cz]);
        $marks = $q->fetchAll(\PDO::FETCH_NUM);
        if ($marks === []) {
            return $blocks;
        }
        $minY = $this->generator()->settings->minY;
        $neighbours = ["$cx,$cz" => &$blocks];
        $get = function (int $x, int $y, int $z) use (&$neighbours, $minY): int {
            $key = ($x >> 4) . ',' . ($z >> 4);
            if (!array_key_exists($key, $neighbours)) {
                $q = $this->db->prepare('SELECT blocks FROM vanilla_chunks WHERE cx=? AND cz=?');
                $q->execute([$x >> 4, $z >> 4]);
                $blob = $q->fetchColumn();
                $neighbours[$key] = $blob === false ? null : self::decode($blob);
            }
            return $neighbours[$key][(($y - $minY) << 8) | (($z & 15) << 4) | ($x & 15)] ?? 0;
        };
        $this->shapes ??= new ShapeUpdater($this->blocks(), new Survival($this->blocks(), $this->version->directory . '/worldgen/block-behaviour.json'));
        $this->shapes->updateAll($marks, $get, static function (int $x, int $y, int $z, int $state) use (&$blocks, $minY): void {
            $blocks[(($y - $minY) << 8) | (($z & 15) << 4) | ($x & 15)] = $state;
        });
        $update = $this->db->prepare('UPDATE vanilla_chunks SET blocks=? WHERE cx=? AND cz=?');
        $update->bindValue(1, self::encode($blocks), \PDO::PARAM_LOB);
        $update->bindValue(2, $cx, \PDO::PARAM_INT);
        $update->bindValue(3, $cz, \PDO::PARAM_INT);
        $update->execute();
        $this->db->prepare('DELETE FROM vanilla_post_processing WHERE cx=? AND cz=?')->execute([$cx, $cz]);
        return $blocks;
    }

    private static function encode(array $blocks): string
    {
        return gzcompress(pack('v*', ...$blocks), 6);
    }

    /** @return list<int> */
    private static function decode(string $blob): array
    {
        return array_values(unpack('v*', gzuncompress($blob)));
    }

    /** @return array<string, int> status by "x,z" for a square of chunks */
    private function statuses(int $cx, int $cz, int $radius): array
    {
        $q = $this->db->prepare('SELECT cx,cz,status FROM vanilla_chunks WHERE cx BETWEEN ? AND ? AND cz BETWEEN ? AND ?');
        $q->execute([$cx - $radius, $cx + $radius, $cz - $radius, $cz + $radius]);
        $out = [];
        foreach ($q->fetchAll(\PDO::FETCH_NUM) as [$x, $z, $status]) {
            $out["$x,$z"] = (int) $status;
        }
        return $out;
    }

    /**
     * The nearest dry land to the origin on an 8-block spiral, as a spawn point standing on
     * the column's surface.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function findSpawn(): array
    {
        $settings = $this->generator()->settings;
        for ($radius = 0; $radius <= 64; ++$radius) {
            for ($dz = -$radius; $dz <= $radius; ++$dz) {
                for ($dx = -$radius; $dx <= $radius; ++$dx) {
                    if (max(abs($dx), abs($dz)) !== $radius) {
                        continue;
                    }
                    [$height, $fluid] = NoiseChunk::column($settings, $dx * 8, $dz * 8);
                    if (!$fluid && $height >= $settings->seaLevel) {
                        return [$dx * 8 + 0.5, (float) ($height + 1), $dz * 8 + 0.5];
                    }
                }
            }
        }
        return [0.5, (float) ($settings->seaLevel + 1), 0.5];
    }

    /** A chunk is final once it and its eight neighbours are decorated. */
    public function ready(int $cx, int $cz): bool
    {
        $key = "$cx,$cz";
        if (isset($this->readyChunks[$key]) || isset($this->final[$key])) {
            return true;
        }
        if (($this->recheck[$key] ?? 0.0) > microtime(true)) {
            return false;
        }
        $q = $this->db->prepare('SELECT COUNT(*) FROM vanilla_chunks WHERE cx BETWEEN ? AND ? AND cz BETWEEN ? AND ? AND status=2');
        $q->execute([$cx - 1, $cx + 1, $cz - 1, $cz + 1]);
        if ((int) $q->fetchColumn() === 9) {
            $this->readyChunks[$key] = true;
            unset($this->recheck[$key]);
            return true;
        }
        $this->recheck[$key] = microtime(true) + 0.25;
        return false;
    }

    /**
     * Ask the generation workers for a chunk; a lower priority is generated sooner. Callers
     * that still want the chunk renew the request at most every 5 seconds, so it is kept for
     * 15 seconds by default.
     */
    public function request(int $cx, int $cz, int $priority, float $keepFor = 15.0): void
    {
        $key = "r$cx,$cz";
        $now = microtime(true);
        if (($this->recheck[$key] ?? 0.0) > $now) {
            return;
        }
        $this->recheck[$key] = $now + 5.0;
        $q = $this->db->prepare('INSERT INTO vanilla_requests(cx,cz,priority,expires) VALUES(?,?,?,?) ON CONFLICT(cx,cz) DO UPDATE SET priority=excluded.priority,expires=MAX(expires,excluded.expires)');
        $q->execute([$cx, $cz, $priority, $now + $keepFor]);
    }

    /**
     * The final blocks (state ids in y, z, x order) and quart biomes of a chunk, generating
     * whatever is missing in this process.
     *
     * @return array{0: list<int>, 1: list<string>}
     */
    public function final(int $cx, int $cz): array
    {
        if (isset($this->final["$cx,$cz"])) {
            return $this->final["$cx,$cz"];
        }
        if (!$this->ready($cx, $cz)) {
            for ($dz = -1; $dz <= 1; ++$dz) {
                for ($dx = -1; $dx <= 1; ++$dx) {
                    $this->decorate($cx + $dx, $cz + $dz);
                }
            }
        }
        $q = $this->db->prepare('SELECT blocks,biomes FROM vanilla_chunks WHERE cx=? AND cz=?');
        $q->execute([$cx, $cz]);
        [$blocks, $biomes] = $q->fetch(\PDO::FETCH_NUM);
        if (count($this->final) >= 64) {
            array_shift($this->final);
        }
        $blocks = $this->postProcess($cx, $cz, self::decode($blocks));
        $biomes = explode("\n", $biomes);
        // The Nether generates its lower 128 blocks; above them is air, and its biomes do not vary with height.
        if (count($blocks) < $this->worldHeight * 256) {
            $blocks = array_pad($blocks, $this->worldHeight * 256, 0);
            $biomes = array_merge($biomes, ...array_fill(0, intdiv($this->worldHeight * 16 - count($biomes), 16), array_slice($biomes, -16)));
        }
        return $this->final["$cx,$cz"] = [$blocks, $biomes];
    }

    /** Carve a chunk unless it is stored already. */
    public function carve(int $cx, int $cz): void
    {
        $q = $this->db->prepare('SELECT 1 FROM vanilla_chunks WHERE cx=? AND cz=?');
        $q->execute([$cx, $cz]);
        if ($q->fetchColumn() !== false) {
            return;
        }
        $chunk = $this->generator()->chunk($cx, $cz);
        $blocks = $this->blocks();
        $ids = array_map(static fn (string $name): int => $blocks->state($name), $chunk['blocks']);
        $settings = $this->generator()->settings;
        $heights = ProtoChunk::worldgenHeights($blocks, $ids, $settings->minY, $settings->height);
        $q = $this->db->prepare('INSERT OR IGNORE INTO vanilla_chunks(cx,cz,status,blocks,biomes,heights) VALUES(?,?,1,?,?,?)');
        $q->bindValue(1, $cx, \PDO::PARAM_INT);
        $q->bindValue(2, $cz, \PDO::PARAM_INT);
        $q->bindValue(3, self::encode($ids), \PDO::PARAM_LOB);
        $q->bindValue(4, implode("\n", $chunk['biomes']), \PDO::PARAM_LOB);
        $q->bindValue(5, pack('s*', ...$heights), \PDO::PARAM_LOB);
        $q->execute();
    }

    /** Decorate a chunk once its 3×3 neighbourhood is carved. */
    public function decorate(int $cx, int $cz): void
    {
        $statuses = $this->statuses($cx, $cz, 1);
        if (($statuses["$cx,$cz"] ?? 0) >= self::DECORATED) {
            return;
        }
        for ($dz = -1; $dz <= 1; ++$dz) {
            for ($dx = -1; $dx <= 1; ++$dx) {
                if (!isset($statuses[($cx + $dx) . ',' . ($cz + $dz)])) {
                    $this->carve($cx + $dx, $cz + $dz);
                }
            }
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q = $this->db->prepare('SELECT cx,cz,status,blocks,biomes,heights FROM vanilla_chunks WHERE cx BETWEEN ? AND ? AND cz BETWEEN ? AND ?');
            $q->execute([$cx - 1, $cx + 1, $cz - 1, $cz + 1]);
            $chunks = [];
            $done = false;
            $settings = $this->generator()->settings;
            foreach ($q->fetchAll(\PDO::FETCH_NUM) as [$x, $z, $status, $blocks, $biomes, $heights]) {
                $ids = self::decode($blocks);
                // Chunks stored before heightmaps were kept use their current blocks.
                $heights = $heights === null ? ProtoChunk::worldgenHeights($this->blocks(), $ids, $settings->minY, $settings->height) : array_values(unpack('s*', $heights));
                $chunks["$x,$z"] = new ProtoChunk((int) $x, (int) $z, $ids, explode("\n", $biomes), $heights);
                if ((int) $x === $cx && (int) $z === $cz && (int) $status >= self::DECORATED) {
                    $done = true;
                }
            }
            if ($done) {
                $this->db->exec('COMMIT');
                return;
            }
            $region = new Region($this->blocks(), $cx, $cz, $chunks, $settings->minY, $settings->height, $settings->seaLevel, BiomeClimate::zoomSeed($this->seed), $this->seed);
            $decorator = $this->decorator();
            $decorator->decorate($region);
            $marks = $this->db->prepare('INSERT OR IGNORE INTO vanilla_post_processing(cx,cz,x,y,z) VALUES(?,?,?,?,?)');
            foreach ($decorator->structureContext?->postProcessing ?? [] as [$x, $y, $z]) {
                $marks->execute([$x >> 4, $z >> 4, $x, $y, $z]);
            }
            $entities = $this->db->prepare('INSERT OR REPLACE INTO vanilla_block_entities(cx,cz,x,y,z,data) VALUES(?,?,?,?,?,?)');
            foreach ($decorator->structureContext?->blockEntities ?? [] as $position => $data) {
                [$x, $y, $z] = array_map('intval', explode(',', $position));
                $entities->execute([$x >> 4, $z >> 4, $x, $y, $z, json_encode($data, JSON_THROW_ON_ERROR)]);
            }
            if ($decorator->structureContext !== null) {
                $decorator->structureContext->postProcessing = [];
                $decorator->structureContext->blockEntities = [];
            }
            $update = $this->db->prepare('UPDATE vanilla_chunks SET blocks=?,status=MAX(status,?) WHERE cx=? AND cz=?');
            foreach ($chunks as $chunk) {
                $update->bindValue(1, self::encode($chunk->blocks), \PDO::PARAM_LOB);
                $update->bindValue(2, $chunk->x === $cx && $chunk->z === $cz ? self::DECORATED : self::CARVED, \PDO::PARAM_INT);
                $update->bindValue(3, $chunk->x, \PDO::PARAM_INT);
                $update->bindValue(4, $chunk->z, \PDO::PARAM_INT);
                $update->execute();
                unset($this->final[$chunk->x . ',' . $chunk->z]);
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * One unit of background work: the most urgent request's missing carving (claimed so
     * workers do not duplicate it), then its decorations. Without requests, the area set by
     * pregenerate() is generated ring by ring from its centre. Returns false when idle.
     */
    public function work(bool $pregenerate = true): bool
    {
        $now = microtime(true);
        if ($now >= $this->expireAt) {
            $this->db->prepare('DELETE FROM vanilla_requests WHERE expires<?')->execute([$now]);
            $this->expireAt = $now + 5.0;
        }
        $q = $this->db->prepare('SELECT cx,cz FROM vanilla_requests WHERE expires>=? ORDER BY priority LIMIT 8');
        $q->execute([$now]);
        foreach ($q->fetchAll(\PDO::FETCH_NUM) as [$cx, $cz]) {
            $step = $this->advance((int) $cx, (int) $cz);
            if ($step === self::STEP_READY || $step === self::STEP_DECORATED) {
                $this->db->prepare('DELETE FROM vanilla_requests WHERE cx=? AND cz=?')->execute([$cx, $cz]);
            }
            if ($step === self::STEP_CARVED || $step === self::STEP_DECORATED) {
                return true;
            }
        }
        return $pregenerate && $this->pregenerateStep();
    }

    /** Generate the chunks within a radius of a centre chunk whenever no chunk is requested. */
    public function pregenerate(int $cx, int $cz, int $radius): void
    {
        $this->pregenerate = [$cx, $cz, $radius];
        $this->pregenerateRing = 0;
        $this->pregenerateQueue = [];
    }

    private function pregenerateStep(): bool
    {
        if ($this->pregenerate === null) {
            return false;
        }
        [$centerX, $centerZ, $radius] = $this->pregenerate;
        while ($this->pregenerateQueue === []) {
            if ($this->pregenerateRing > $radius) {
                return false;
            }
            $ring = $this->pregenerateRing++;
            for ($dz = -$ring; $dz <= $ring; ++$dz) {
                for ($dx = -$ring; $dx <= $ring; ++$dx) {
                    if (max(abs($dx), abs($dz)) === $ring) {
                        $this->pregenerateQueue[] = [$centerX + $dx, $centerZ + $dz];
                    }
                }
            }
        }
        foreach ($this->pregenerateQueue as $index => [$cx, $cz]) {
            $step = $this->advance($cx, $cz);
            if ($step === self::STEP_READY || $step === self::STEP_DECORATED) {
                unset($this->pregenerateQueue[$index]);
            }
            if ($step !== self::STEP_BLOCKED && $step !== self::STEP_READY) {
                return true;
            }
        }
        // Everything left in this ring waits on other workers' carving.
        return $this->pregenerateQueue === [] && $this->pregenerateRing <= $radius;
    }

    /** Take one step towards a chunk being final: carve one missing chunk, or decorate around it. */
    private function advance(int $cx, int $cz): int
    {
        if ($this->ready($cx, $cz)) {
            return self::STEP_READY;
        }
        $statuses = $this->statuses($cx, $cz, 2);
        for ($radius = 0; $radius <= 2; ++$radius) {
            for ($dz = -$radius; $dz <= $radius; ++$dz) {
                for ($dx = -$radius; $dx <= $radius; ++$dx) {
                    if (max(abs($dx), abs($dz)) !== $radius || isset($statuses[($cx + $dx) . ',' . ($cz + $dz)])) {
                        continue;
                    }
                    $claim = $this->db->prepare('INSERT INTO vanilla_claims(cx,cz,claimed) VALUES(?,?,?) ON CONFLICT(cx,cz) DO UPDATE SET claimed=excluded.claimed WHERE claimed<?');
                    $now = microtime(true);
                    $claim->execute([$cx + $dx, $cz + $dz, $now, $now - 120]);
                    if ($claim->rowCount() === 0) {
                        continue;
                    }
                    try {
                        $this->carve($cx + $dx, $cz + $dz);
                    } finally {
                        $this->db->prepare('DELETE FROM vanilla_claims WHERE cx=? AND cz=?')->execute([$cx + $dx, $cz + $dz]);
                    }
                    return self::STEP_CARVED;
                }
            }
        }
        if (count($this->statuses($cx, $cz, 2)) < 25) {
            return self::STEP_BLOCKED;
        }
        for ($dz = -1; $dz <= 1; ++$dz) {
            for ($dx = -1; $dx <= 1; ++$dx) {
                $this->decorate($cx + $dx, $cz + $dz);
            }
        }
        return self::STEP_DECORATED;
    }
}
