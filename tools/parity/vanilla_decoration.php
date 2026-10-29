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
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Survival;
use PhpMinecraftJavaServer\World\Vanilla\OverworldGenerator;
use PhpMinecraftJavaServer\World\Vanilla\Structure\ShapeUpdater;
use PhpMinecraftJavaServer\World\Vanilla\Structure\Structures;

if ($argc < 3) {
    fwrite(STDERR, "usage: vanilla_decoration.php REGIONS.json SEED [CX CZ]\n");
    exit(2);
}
$seed = (int) $argv[2];
$centerX = (int) ($argv[3] ?? 0);
$centerZ = (int) ($argv[4] ?? 0);
$reference = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$dimension = $reference['dimension'] ?? 'overworld';
$version = new Version();
$generator = new OverworldGenerator($seed, $version->directory, match ($dimension) { 'the_nether' => 'nether', 'the_end' => 'end', default => 'overworld' });
$blocks = new Blocks($version, $version->directory . '/worldgen/block-behaviour.json');
$decorator = new Decorator($blocks, new BiomeClimate($version->directory . '/worldgen'), $version->directory . '/worldgen', $dimension);
if (($reference['structures'] ?? false) === true) {
    $decorator->structures = new Structures($generator->settings, $generator->biomes, $version->directory . '/worldgen');
}
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
// LevelChunk.postProcessGeneration for the shapes structure pieces marked.
$shapes = new ShapeUpdater($blocks, new Survival($blocks, $version->directory . '/worldgen/block-behaviour.json'));
$get = static function (int $x, int $y, int $z) use (&$chunks, $settings): int {
    return $chunks[($x >> 4) . ',' . ($z >> 4)]->blocks[(($y - $settings->minY) << 8) | (($z & 15) << 4) | ($x & 15)];
};
foreach ($decorator->structureContext?->postProcessing ?? [] as [$x, $y, $z]) {
    $updated = $shapes->update($get($x, $y, $z), $x, $y, $z, $get);
    if ($updated !== null) {
        $chunks[($x >> 4) . ',' . ($z >> 4)]->blocks[(($y - $settings->minY) << 8) | (($z & 15) << 4) | ($x & 15)] = $updated;
    }
}
if ($decorator->skipped !== []) {
    echo 'Not ported: ' . json_encode($decorator->skipped) . "\n";
}
foreach ($reference['chunks'] as $official) {
    if (abs($official['x'] - $centerX) > 1 || abs($official['z'] - $centerZ) > 1) {
        continue;
    }
    $names = explode("\n", zlib_decode(base64_decode($official['blocks'])));
    $mine = $chunks[$official['x'] . ',' . $official['z']]->blocks;
    $kinds = [];
    $total = 0;
    // FULL=1 compares block states with their properties, which official names omit for default states.
    $full = getenv('FULL') === '1';
    $describe = static function (int $state) use ($blocks): string {
        $name = $blocks->name($state);
        if ($state === $blocks->state($name)) {
            return $name;
        }
        $properties = $blocks->properties($state);
        ksort($properties);
        return $name . '[' . implode(',', array_map(static fn ($k, $v): string => "$k=$v", array_keys($properties), $properties)) . ']';
    };
    foreach ($names as $i => $name) {
        $official_name = $full ? $name : preg_replace('/\[.*$/', '', $name);
        // Above the noise height (the End's 128 of 256) nothing is generated.
        $name = isset($mine[$i]) ? ($full ? $describe($mine[$i]) : $blocks->name($mine[$i])) : 'air';
        if ($official_name !== $name) {
            ++$total;
            $kinds["$official_name<-$name"] = ($kinds["$official_name<-$name"] ?? 0) + 1;
        }
    }
    arsort($kinds);
    printf("chunk %d,%d: %d of %d blocks differ %s\n", $official['x'], $official['z'], $total, count($names), json_encode(array_slice($kinds, 0, 8)));
}
