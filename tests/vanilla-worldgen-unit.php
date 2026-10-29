<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\World\Vanilla\OverworldGenerator;

// Complete undecorated chunks from the official generator (reference/26.3/worldgen-chunks.json),
// stage by stage: biomes, noise fill with aquifers, material rules and carvers, for Overworld and
// Nether chunks.
$fixture = json_decode((string) file_get_contents(__DIR__ . '/../reference/26.3/worldgen-chunks.json'), true, 512, JSON_THROW_ON_ERROR);
$generators = [];
$failures = [];
$checks = 0;
foreach ($fixture['chunks'] as $expected) {
    $dimension = $expected['dimension'] ?? 'overworld';
    $generator = $generators[$expected['seed'] . $dimension] ??= new OverworldGenerator((int) $expected['seed'], __DIR__ . '/../reference/26.3', $dimension === 'the_nether' ? 'nether' : 'overworld');
    $chunk = $generator->chunk($expected['x'], $expected['z'], true);
    $actual = [
        'biomes' => $chunk['biomes'],
        'noise' => $chunk['stages']['noise'],
        'surface' => $chunk['stages']['surface'],
        'carvers' => $chunk['blocks'],
    ];
    foreach ($actual as $stage => $names) {
        ++$checks;
        if (hash('sha256', implode("\n", $names)) !== $expected[$stage]) {
            $failures[] = "$dimension seed {$expected['seed']} chunk {$expected['x']},{$expected['z']} differs from vanilla after $stage";
        }
    }
}
if ($failures !== []) {
    throw new RuntimeException(implode("\n", $failures));
}
echo 'PASS: ' . $checks . ' official Overworld and Nether chunk stages across ' . count($fixture['chunks']) . " chunks and two seeds\n";
