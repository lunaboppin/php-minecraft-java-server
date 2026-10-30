<?php

declare(strict_types=1);

/*
 * /locate's search, run apart from the server's main loop: the nearest structure of the named
 * types or biome of the named biomes in one dimension, printed as JSON [x, y, z, name] or null.
 * Stronghold ring positions it resolves are saved to the world, as generation saves them.
 */

require __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Vanilla\ChunkStore;

if ($argc !== 10 || !in_array($argv[5], ['structure', 'biome'], true)) {
    fwrite(STDERR, "usage: locate.php WORLD.sqlite SEED DIMENSION HEIGHT structure|biome NAMES_JSON X Y Z\n");
    exit(2);
}
$db = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=30000');
@proc_nice(10);
$store = new ChunkStore($db, new Version(), (int) $argv[2], $argv[3], (int) $argv[4]);
$names = json_decode($argv[6], true, 512, JSON_THROW_ON_ERROR);
[$x, $y, $z] = [(int) $argv[7], (int) $argv[8], (int) $argv[9]];
echo json_encode($argv[5] === 'structure' ? $store->locateStructure($names, $x, $y, $z) : $store->locateBiome($names, $x, $y, $z)), "\n";
