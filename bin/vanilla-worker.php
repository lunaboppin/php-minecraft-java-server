<?php

declare(strict_types=1);

/*
 * A background generation worker for vanilla chunks. The server starts these with the world
 * database path and seed; each one generates requested chunks into the world's vanilla_chunks
 * table and exits when the server closes its standard input. With a centre chunk and radius,
 * idle workers generate that area ahead of players. Nether and End database paths ("-" for
 * none) add those dimensions' requests, which come before Overworld pregeneration.
 */

require __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Vanilla\ChunkStore;

if (!in_array($argc, [3, 6, 7, 8], true)) {
    fwrite(STDERR, "usage: vanilla-worker.php WORLD.sqlite SEED [CHUNK_X CHUNK_Z RADIUS [NETHER.sqlite|- [END.sqlite|-]]]\n");
    exit(2);
}
$open = static function (string $path): PDO {
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=30000');
    return $db;
};
$version = new Version();
$databases = [$open($argv[1])];
$store = new ChunkStore($databases[0], $version, (int) $argv[2]);
if ($argc >= 6) {
    $store->pregenerate((int) $argv[3], (int) $argv[4], (int) $argv[5]);
}
$dimension = static function (int $index, string $name) use ($argv, $open, $version, &$databases): ?ChunkStore {
    return ($argv[$index] ?? '-') === '-' ? null : new ChunkStore($databases[] = $open($argv[$index]), $version, (int) $argv[2], $name, 256);
};
$nether = $dimension(6, 'the_nether');
$end = $dimension(7, 'the_end');
// Generation yields the CPU to the server's main loop, which keeps ticking players.
@proc_nice(10);
stream_set_blocking(STDIN, false);
while (true) {
    $line = fgets(STDIN);
    if (feof(STDIN)) {
        exit(0);
    }
    try {
        if (!($nether?->work(false) ?? false) && !($end?->work(false) ?? false) && !$store->work()) {
            usleep(100000);
        }
    } catch (PDOException $e) {
        // Another process holds the database; try again shortly.
        usleep(200000);
    } catch (Throwable $e) {
        // A generation bug fails only this unit of work: its claim expires and it is retried later.
        fwrite(STDERR, sprintf("[%s] vanilla worker: %s: %s at %s:%d\n", date('Y-m-d H:i:s'), $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
        foreach ($databases as $db) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
        usleep(1000000);
    }
}
