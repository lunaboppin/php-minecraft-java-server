<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\World;

// Chunk packets (sections, palettes, heightmaps, block entities and light) are built on every
// chunk send. This hash was recorded before the packet builder was optimized and again for light
// propagation, then for light crossing chunk borders and shape occlusion; any change means different bytes reach clients. It is computed without the JIT:
// PHP 8.5.12's tracing JIT changes the legacy terrain and End generators' output.
$chunkVersion = new Version();
$chunkDirectory = sys_get_temp_dir() . '/php-chunk-packets-' . bin2hex(random_bytes(8));
mkdir($chunkDirectory);
try {
    $chunkHash = hash_init('sha256');
    $states = $chunkVersion->blocks['states'];
    foreach ([['flat', 'overworld'], ['terrain', 'overworld'], ['terrain', 'the_nether'], ['terrain', 'the_end']] as [$generator, $dimension]) {
        $world = new World($chunkVersion, "$chunkDirectory/$generator-$dimension.sqlite", $generator, 424242, $dimension);
        // Light sources, block entities, fluids, transparent and leaf blocks in edited chunks.
        foreach ([['torch', 3, 70, 3], ['glowstone', 18, 40, 5], ['chest', 5, 66, 7], ['water', 9, 80, 9], ['lava', 20, 30, 20], ['glass', 4, 90, 4], ['oak_leaves', 6, 95, 6], ['furnace', 1, 10, 1], ['sea_lantern', -5, 120, -5]] as [$block, $x, $y, $z]) {
            $world->set($x, $y, $z, $states[$block]);
        }
        foreach ([[0, 0], [1, 0], [-1, -1], [0, 1], [3, -2], [-20, 17]] as [$cx, $cz]) {
            hash_update($chunkHash, $world->chunk($cx, $cz));
            hash_update($chunkHash, $world->chunkLight($cx, $cz));
        }
        unset($world);
    }
    if (hash_final($chunkHash) !== 'fa79981b531a6b91a108be142a817bd57443ad538f902e3971a345c9451ae5ce') {
        throw new RuntimeException('Chunk packet bytes changed');
    }
} finally {
    foreach (glob($chunkDirectory . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($chunkDirectory);
}
echo "PASS: 24 chunk packets across flat, terrain, Nether and End with edits match the recorded bytes\n";
