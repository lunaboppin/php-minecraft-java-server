<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Decorator;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\ProtoChunk;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;

/**
 * Vanilla Overworld chunks stored in the world database while they move through vanilla's
 * stages: carved (status 1), then decorated (status 2). Decoration also writes into the eight
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
    private ?Blocks $blocks = null;
    /** @var array<string, array{0: list<int>, 1: list<string>}> */
    private array $final = [];
    /** @var array<string, true> chunks known to be final */
    private array $readyChunks = [];
    /** @var array<string, float> when a not-ready chunk may be checked or requested again */
    private array $recheck = [];

    public function __construct(private readonly \PDO $db, private readonly Version $version, private readonly int $seed)
    {
        $db->exec('CREATE TABLE IF NOT EXISTS vanilla_chunks(cx INTEGER NOT NULL,cz INTEGER NOT NULL,status INTEGER NOT NULL,blocks BLOB NOT NULL,biomes BLOB NOT NULL,PRIMARY KEY(cx,cz));'
            . 'CREATE TABLE IF NOT EXISTS vanilla_requests(cx INTEGER NOT NULL,cz INTEGER NOT NULL,priority INTEGER NOT NULL,PRIMARY KEY(cx,cz));'
            . 'CREATE TABLE IF NOT EXISTS vanilla_claims(cx INTEGER NOT NULL,cz INTEGER NOT NULL,claimed REAL NOT NULL,PRIMARY KEY(cx,cz))');
    }

    public function blocks(): Blocks
    {
        return $this->blocks ??= new Blocks($this->version, $this->version->directory . '/worldgen/block-behaviour.json');
    }

    private function generator(): OverworldGenerator
    {
        return $this->generator ??= new OverworldGenerator($this->seed, $this->version->directory);
    }

    private function decorator(): Decorator
    {
        return $this->decorator ??= new Decorator($this->blocks(), new BiomeClimate($this->version->directory . '/worldgen'), $this->version->directory . '/worldgen');
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

    /** Ask the generation workers for a chunk; a lower priority is generated sooner. */
    public function request(int $cx, int $cz, int $priority): void
    {
        $key = "r$cx,$cz";
        if (($this->recheck[$key] ?? 0.0) > microtime(true)) {
            return;
        }
        $this->recheck[$key] = microtime(true) + 5.0;
        $q = $this->db->prepare('INSERT INTO vanilla_requests(cx,cz,priority) VALUES(?,?,?) ON CONFLICT(cx,cz) DO UPDATE SET priority=MIN(priority,excluded.priority)');
        $q->execute([$cx, $cz, $priority]);
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
        return $this->final["$cx,$cz"] = [self::decode($blocks), explode("\n", $biomes)];
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
        $q = $this->db->prepare('INSERT OR IGNORE INTO vanilla_chunks(cx,cz,status,blocks,biomes) VALUES(?,?,1,?,?)');
        $q->bindValue(1, $cx, \PDO::PARAM_INT);
        $q->bindValue(2, $cz, \PDO::PARAM_INT);
        $q->bindValue(3, self::encode($ids), \PDO::PARAM_LOB);
        $q->bindValue(4, implode("\n", $chunk['biomes']), \PDO::PARAM_LOB);
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
            $q = $this->db->prepare('SELECT cx,cz,status,blocks,biomes FROM vanilla_chunks WHERE cx BETWEEN ? AND ? AND cz BETWEEN ? AND ?');
            $q->execute([$cx - 1, $cx + 1, $cz - 1, $cz + 1]);
            $chunks = [];
            $done = false;
            foreach ($q->fetchAll(\PDO::FETCH_NUM) as [$x, $z, $status, $blocks, $biomes]) {
                $chunks["$x,$z"] = new ProtoChunk((int) $x, (int) $z, self::decode($blocks), explode("\n", $biomes));
                if ((int) $x === $cx && (int) $z === $cz && (int) $status >= self::DECORATED) {
                    $done = true;
                }
            }
            if ($done) {
                $this->db->exec('COMMIT');
                return;
            }
            $settings = $this->generator()->settings;
            $region = new Region($this->blocks(), $cx, $cz, $chunks, $settings->minY, $settings->height, $settings->seaLevel, BiomeClimate::zoomSeed($this->seed), $this->seed);
            $this->decorator()->decorate($region);
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
     * workers do not duplicate it), then its decorations. Returns false when idle.
     */
    public function work(): bool
    {
        $request = $this->db->query('SELECT cx,cz FROM vanilla_requests ORDER BY priority LIMIT 8')->fetchAll(\PDO::FETCH_NUM);
        if ($request === []) {
            return false;
        }
        foreach ($request as [$cx, $cz]) {
            $cx = (int) $cx;
            $cz = (int) $cz;
            if ($this->ready($cx, $cz)) {
                $this->db->prepare('DELETE FROM vanilla_requests WHERE cx=? AND cz=?')->execute([$cx, $cz]);
                continue;
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
                        return true;
                    }
                }
            }
            if (count($this->statuses($cx, $cz, 2)) < 25) {
                continue;
            }
            for ($dz = -1; $dz <= 1; ++$dz) {
                for ($dx = -1; $dx <= 1; ++$dx) {
                    $this->decorate($cx + $dx, $cz + $dz);
                }
            }
            $this->db->prepare('DELETE FROM vanilla_requests WHERE cx=? AND cz=?')->execute([$cx, $cz]);
            return true;
        }
        return false;
    }
}
