<?php

declare(strict_types=1);

/*
 * Compare decorated vanilla chunks from PHP with chunks the official server saved
 * (tools/parity/worldgen_regions.py output). PHP carves the 7×7 chunks around the centre,
 * decorates the 5×5 inside them in row order, and compares the 3×3 in the middle by block
 * name. Vanilla decorates neighbouring chunks in a scheduling-dependent order, so two official
 * runs of one seed also differ where features overlap chunk borders; compare two official runs
 * to see that baseline.
 *
 * Usage: php tools/parity/vanilla_decoration.php REGIONS.json SEED [CX CZ]
 */

require __DIR__ . '/../../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Vanilla\BiomeClimate;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Decorator;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\ProtoChunk;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;
use PhpMinecraftJavaServer\World\Vanilla\OverworldGenerator;

if ($argc < 3) {
    fwrite(STDERR, "usage: vanilla_decoration.php REGIONS.json SEED [CX CZ]\n");
    exit(2);
}
$seed = (int) $argv[2];
$centerX = (int) ($argv[3] ?? 0);
$centerZ = (int) ($argv[4] ?? 0);
$version = new Version();
$generator = new OverworldGenerator($seed, $version->directory);
$blocks = new Blocks($version, $version->directory . '/worldgen/block-behaviour.json');
$decorator = new Decorator($blocks, new BiomeClimate($version->directory . '/worldgen'), $version->directory . '/worldgen');
$chunks = [];
for ($dz = -3; $dz <= 3; ++$dz) {
    for ($dx = -3; $dx <= 3; ++$dx) {
        $chunk = $generator->chunk($centerX + $dx, $centerZ + $dz);
        $ids = array_map($blocks->state(...), $chunk['blocks']);
        $heights = ProtoChunk::worldgenHeights($blocks, $ids, $generator->settings->minY, $generator->settings->height);
        $chunks[($centerX + $dx) . ',' . ($centerZ + $dz)] = new ProtoChunk($centerX + $dx, $centerZ + $dz, $ids, $chunk['biomes'], $heights);
    }
}
$settings = $generator->settings;
for ($dz = -2; $dz <= 2; ++$dz) {
    for ($dx = -2; $dx <= 2; ++$dx) {
        $decorator->decorate(new Region($blocks, $centerX + $dx, $centerZ + $dz, $chunks, $settings->minY, $settings->height, $settings->seaLevel, BiomeClimate::zoomSeed($seed), $seed));
    }
}
if ($decorator->skipped !== []) {
    echo 'Not ported: ' . json_encode($decorator->skipped) . "\n";
}
$reference = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
foreach ($reference['chunks'] as $official) {
    if (abs($official['x'] - $centerX) > 1 || abs($official['z'] - $centerZ) > 1) {
        continue;
    }
    $names = explode("\n", zlib_decode(base64_decode($official['blocks'])));
    $mine = $chunks[$official['x'] . ',' . $official['z']]->blocks;
    $kinds = [];
    $total = 0;
    foreach ($names as $i => $name) {
        $official_name = preg_replace('/\[.*$/', '', $name);
        $name = $blocks->name($mine[$i]);
        if ($official_name !== $name) {
            ++$total;
            $kinds["$official_name<-$name"] = ($kinds["$official_name<-$name"] ?? 0) + 1;
        }
    }
    arsort($kinds);
    printf("chunk %d,%d: %d of %d blocks differ %s\n", $official['x'], $official['z'], $total, count($names), json_encode(array_slice($kinds, 0, 8)));
}
