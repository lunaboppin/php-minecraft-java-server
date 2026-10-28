<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\DimensionGenerator;
use PhpMinecraftJavaServer\World\Noise;
use PhpMinecraftJavaServer\World\TerrainGenerator;

// Unedited chunks are regenerated from these generators on every load, so live worlds depend
// on their exact output. The hashes were recorded before the noise was optimized; any change
// here alters existing players' terrain and needs an explicit world migration.
$terrainVersion = new Version();
$terrainGolden = [
    ['terrain', 12345, true, 3, -6, '130e05b79ebdebd4a8ca6c636e29c81be23796ed66ec88d671e89d78a6924b91'],
    ['terrain', -2147483648, true, -40, 25, '76e71d12ad3ea44dbea6707de201bb20baa2e5f15b765d71fde1c9805150a1cf'],
    ['terrain', 777, false, 0, 0, '7185c174ccfe0fdc2f9ed060fe0435941f77b9393b19a96185cef00375491ff6'],
    ['the_nether', 12345, true, 15, -66, '66cfe1934b7eacd9a5fa1ed79d258673c2d54c69bbf87e1afa65087922b81d6a'],
    ['the_end', 12345, true, -1, -1, 'c531751ba7ce66a4dec037f49a808b83c8d95f5c74916bf4af380901ee9ea146'],
];
$terrainChecks = 0;
foreach ($terrainGolden as [$kind, $seed, $enhanced, $cx, $cz, $expected]) {
    $generator = $kind === 'terrain' ? new TerrainGenerator($terrainVersion, $seed, $enhanced) : new DimensionGenerator($terrainVersion, $seed, $kind);
    $hash = hash_init('sha256');
    foreach ($generator->chunk($cx, $cz) as $section) {
        hash_update($hash, pack('n*', ...$section));
    }
    if (hash_final($hash) !== $expected) {
        throw new RuntimeException("Generated $kind chunk $cx,$cz for seed $seed changed");
    }
    ++$terrainChecks;
}

// The per-column sampler must return exactly Noise::sample3, including across lattice cells,
// at negative coordinates and for integer-valued inputs.
$noise = new Noise(-918273645);
foreach ([[0.0, 0.0], [-1.5, 3.25], [1234.567, -98765.4321], [-0.0001, 7.0], [2.0, -2.0]] as [$x, $z]) {
    foreach ([8, 9, 31, 32] as $salt) {
        $column = $noise->column($x, $z, $salt);
        for ($y = -64; $y <= 320; $y += 3) {
            foreach ([$y / 14, $y / 29, $y / 23.0] as $sampleY) {
                if ($column->at($sampleY) !== $noise->sample3($x, $sampleY, $z, $salt)) {
                    throw new RuntimeException("Column noise differs from sample3 at $x,$sampleY,$z salt $salt");
                }
                ++$terrainChecks;
            }
        }
    }
}
echo 'PASS: ' . $terrainChecks . " terrain generator checks for unchanged regenerated chunks and exact column noise\n";
