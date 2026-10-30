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
$buildHeight = $dimension === 'overworld' ? $generator->settings->height : 256;
$chunks = [];
for ($dz = -3; $dz <= 3; ++$dz) {
    for ($dx = -3; $dx <= 3; ++$dx) {
        $chunk = $generator->chunk($centerX + $dx, $centerZ + $dz, false, $decorator->structures?->beardifier($centerX + $dx, $centerZ + $dz));
        // The Nether and the End decorate up to their 256-block build height.
        $ids = array_pad(array_map($blocks->state(...), $chunk['blocks']), $buildHeight * 256, 0);
        $heights = ProtoChunk::worldgenHeights($blocks, $ids, $generator->settings->minY, $buildHeight);
        $chunks[($centerX + $dx) . ',' . ($centerZ + $dz)] = new ProtoChunk($centerX + $dx, $centerZ + $dz, $ids, $chunk['biomes'], $heights);
    }
}
$settings = $generator->settings;
for ($dz = -2; $dz <= 2; ++$dz) {
    for ($dx = -2; $dx <= 2; ++$dx) {
        $decorator->decorate(new Region($blocks, $centerX + $dx, $centerZ + $dz, $chunks, $settings->minY, $buildHeight, $settings->seaLevel, BiomeClimate::zoomSeed($seed), $seed, $settings->height));
    }
}
// LevelChunk.postProcessGeneration for the shapes structure pieces marked.
$shapes = new ShapeUpdater($blocks, new Survival($blocks, $version->directory . '/worldgen/block-behaviour.json'));
$get = static function (int $x, int $y, int $z) use (&$chunks, $settings): int {
    return $chunks[($x >> 4) . ',' . ($z >> 4)]->blocks[(($y - $settings->minY) << 8) | (($z & 15) << 4) | ($x & 15)];
};
if (getenv('MARKORDER')) {
    echo 'mark order: ', implode(' ', array_keys(array_filter($decorator->structureContext?->postProcessing ?? [], static fn ($p) => in_array(implode(',', $p), explode(';', getenv('MARKORDER')), true)))), "\n";
}
$shapes->updateAll(array_values($decorator->structureContext?->postProcessing ?? []), $get, static function (int $x, int $y, int $z, int $state) use (&$chunks, $settings): void {
    $chunks[($x >> 4) . ',' . ($z >> 4)]->blocks[(($y - $settings->minY) << 8) | (($z & 15) << 4) | ($x & 15)] = $state;
});
if (getenv('NEIGHBOURS')) {
    // NEIGHBOURS=block prints each such block in the centre chunk with its horizontal neighbours.
    $c = $chunks[$centerX . ',' . $centerZ];
    foreach ($c->blocks as $i => $state) {
        if ($blocks->name($state) === getenv('NEIGHBOURS')) {
            [$x, $y, $z] = [$centerX * 16 + ($i & 15), ($i >> 8) + $settings->minY, $centerZ * 16 + (($i >> 4) & 15)];
            echo "$x,$y,$z ", json_encode($blocks->properties($state)), ' N=', $blocks->name($get($x, $y, $z - 1)), ' S=', $blocks->name($get($x, $y, $z + 1)), ' W=', $blocks->name($get($x - 1, $y, $z)), ' E=', $blocks->name($get($x + 1, $y, $z)), "\n";
        }
    }
}
if ($decorator->skipped !== []) {
    echo 'Not ported: ' . json_encode($decorator->skipped) . "\n";
}
// ENTITIES=1 compares the entities generation added to the middle chunks instead of blocks:
// type, position and yaw, then the tags official and PHP both saved.
if (getenv('ENTITIES') === '1') {
    // Shulkers take a random yaw from the entity's own unseeded random, so only their position counts.
    $key = static fn (array $nbt): string => sprintf('%s %.3f %.3f %.3f', preg_replace('/^minecraft:/', '', $nbt['id']), $nbt['Pos'][0], $nbt['Pos'][1], $nbt['Pos'][2]) . ($nbt['id'] === 'minecraft:shulker' ? '' : sprintf(' %.2f', fmod($nbt['Rotation'][0] + 720.0, 360.0)));
    $inMiddle = static fn (array $nbt): bool => abs(((int) floor($nbt['Pos'][0]) >> 4) - $centerX) <= 1 && abs(((int) floor($nbt['Pos'][2]) >> 4) - $centerZ) <= 1;
    $mine = [];
    foreach ($decorator->structureContext?->entities ?? [] as $entity) {
        if ($inMiddle($entity['nbt'])) {
            $mine[$key($entity['nbt'])][] = $entity['nbt'];
        }
    }
    $official = [];
    foreach ($reference['chunks'] as $chunk) {
        foreach ($chunk['entities'] ?? [] as $nbt) {
            if ($inMiddle($nbt)) {
                $official[$key($nbt)][] = $nbt;
            }
        }
    }
    $canonical = static function (mixed $value) use (&$canonical): string {
        if (is_array($value) && !array_is_list($value)) {
            ksort($value);
        }
        return is_array($value) ? '{' . implode(',', array_map(static fn ($k, $v) => $k . ':' . $canonical($v), array_keys($value), $value)) . '}' : json_encode($value);
    };
    $matched = 0;
    foreach ($official as $k => $list) {
        foreach ($list as $i => $nbt) {
            if (!isset($mine[$k][$i])) {
                echo "official only: $k\n";
                continue;
            }
            ++$matched;
            foreach ($mine[$k][$i] as $tag => $value) {
                if (!in_array($tag, ['Pos', 'Rotation', 'UUID'], true) && array_key_exists($tag, $nbt) && $canonical($nbt[$tag]) !== $canonical($value)) {
                    echo "  $k: $tag official " . json_encode($nbt[$tag]) . ' php ' . json_encode($value) . "\n";
                }
            }
        }
    }
    foreach ($mine as $k => $list) {
        foreach ($list as $i => $nbt) {
            if (!isset($official[$k][$i])) {
                echo "php only: $k\n";
            }
        }
    }
    printf("entities: %d matched, %d official, %d php\n", $matched, array_sum(array_map('count', $official)), array_sum(array_map('count', $mine)));
    exit(0);
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
    // BOX=x0,z0,x1,z1 compares only those columns.
    $area = getenv('BOX') ? array_map('intval', explode(',', getenv('BOX'))) : null;
    foreach ($names as $i => $name) {
        if ($area !== null) {
            $bx = $official['x'] * 16 + ($i & 15);
            $bz = $official['z'] * 16 + (($i >> 4) & 15);
            if ($bx < $area[0] || $bx > $area[2] || $bz < $area[1] || $bz > $area[3]) {
                continue;
            }
        }
        $official_name = $full ? $name : preg_replace('/\[.*$/', '', $name);
        // Above the noise height (the End's 128 of 256) nothing is generated.
        $name = isset($mine[$i]) ? ($full ? $describe($mine[$i]) : $blocks->name($mine[$i])) : 'air';
        if ($official_name !== $name) {
            // DIFFKIND=official<-php prints where that difference is.
            if (getenv('DIFFKIND') === "$official_name<-$name" && ($shown = ($shown ?? 0) + 1) <= 8) {
                echo '  ', $official['x'] * 16 + ($i & 15), ',', ($i >> 8) + $settings->minY, ',', $official['z'] * 16 + (($i >> 4) & 15), "\n";
            }
            ++$total;
            $kinds["$official_name<-$name"] = ($kinds["$official_name<-$name"] ?? 0) + 1;
        }
    }
    arsort($kinds);
    printf("chunk %d,%d: %d of %d blocks differ %s\n", $official['x'], $official['z'], $total, count($names), json_encode(array_slice($kinds, 0, (int) (getenv("TOP") ?: 8))));
}
