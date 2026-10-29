<?php

declare(strict_types=1);

/*
 * A background generation worker for vanilla Overworld chunks. The server starts these with
 * the world database path and seed; each one generates requested chunks into the world's
 * vanilla_chunks table and exits when the server closes its standard input. With a centre
 * chunk and radius, idle workers generate that area ahead of players.
 */

require __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Vanilla\ChunkStore;

if ($argc !== 3 && $argc !== 6) {
    fwrite(STDERR, "usage: vanilla-worker.php WORLD.sqlite SEED [CHUNK_X CHUNK_Z RADIUS]\n");
    exit(2);
}
$db = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=30000');
$store = new ChunkStore($db, new Version(), (int) $argv[2]);
if ($argc === 6) {
    $store->pregenerate((int) $argv[3], (int) $argv[4], (int) $argv[5]);
}
// Generation yields the CPU to the server's main loop, which keeps ticking players.
@proc_nice(10);
stream_set_blocking(STDIN, false);
while (true) {
    $line = fgets(STDIN);
    if (feof(STDIN)) {
        exit(0);
    }
    try {
        if (!$store->work()) {
            usleep(100000);
        }
    } catch (PDOException $e) {
        // Another process holds the database; try again shortly.
        usleep(200000);
    }
}
