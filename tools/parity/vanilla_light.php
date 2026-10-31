<?php

declare(strict_types=1);

/*
 * Compare the light PHP computes for a chunk with the light the official server saved
 * (tools/parity/worldgen_regions.py --light output), lighting the official blocks so only the
 * light engine differs. The neighbouring chunks in the file light across the borders as they do
 * in the world; cells within 3 blocks of the chunk's sides are counted apart as edge cells.
 *
 * Usage: php tools/parity/vanilla_light.php REGIONS.json CX CZ
 */

require __DIR__ . '/../../src/bootstrap.php';
use PhpMinecraftJavaServer\Protocol\{Version, Binary};
use PhpMinecraftJavaServer\World\Lighting;
function vi(string $d, int $o): array { $v = 0; $sh = 0; do { $b = ord($d[$o++]); $v |= ($b & 0x7f) << $sh; $sh += 7; } while ($b & 0x80); return [$v, $o]; }
[$file, $cx, $cz] = [$argv[1], (int) $argv[2], (int) $argv[3]];
$v = new Version(); $c = $v->catalog;
$ref = json_decode(file_get_contents($file), true);
$sectionsOf = static function (array $chunk) use ($c): array {
    $states = [];
    foreach (explode("\n", zlib_decode(base64_decode($chunk['blocks']))) as $n) {
        $props = [];
        if (preg_match('/^([a-z_0-9]+)(?:\[(.*)\])?$/', $n, $m)) { foreach (array_filter(explode(',', $m[2] ?? '')) as $kv) { [$k, $val] = explode('=', $kv); $props[$k] = $val; } $states[] = $c->state($m[1], $props); } else { $states[] = 0; }
    }
    // official order y, z, x like PHP sections
    return array_chunk($states, 4096);
};
$byPos = [];
foreach ($ref['chunks'] as $chunk) { $byPos[$chunk['x'] . ':' . $chunk['z']] = $chunk; }
$ch = $byPos[$cx . ':' . $cz];
$sky = ($ref['dimension'] ?? 'overworld') === 'overworld';
$area = [];
for ($dz = -1; $dz <= 1; ++$dz) {
    for ($dx = -1; $dx <= 1; ++$dx) {
        $chunk = $byPos[($cx + $dx) . ':' . ($cz + $dz)] ?? null;
        if ($chunk === null) { $area[($dz + 1) * 3 + $dx + 1] = null; continue; }
        $sections = $sectionsOf($chunk);
        [$skyLevels, $blockLevels] = Lighting::levels($sections, $c, $sky, $v->directory);
        $area[($dz + 1) * 3 + $dx + 1] = [Lighting::dampening($sections, $c, $v->directory), $skyLevels, $blockLevels, Lighting::shapes($sections, $v->directory)];
    }
}
$payload = Lighting::buildArea($area, count($sectionsOf($ch)), $sky, $v->directory);
// decode: 4 bitsets then sky arrays then block arrays
$o = 0; $masks = [];
for ($i = 0; $i < 4; ++$i) { [$len, $o] = vi($payload, $o); $masks[] = unpack('P', str_pad(substr($payload, $o, $len), 8, "\0"))[1]; $o += $len; }
$arrays = [];
foreach (['SkyLight', 'BlockLight'] as $k => $kind) {
    [$n, $o] = vi($payload, $o);
    $list = [];
    for ($i = 0; $i < 40; ++$i) { if ($masks[$k] >> $i & 1) { [$l, $o] = vi($payload, $o); $list[$i] = substr($payload, $o, $l); $o += $l; } }
    $arrays[$kind] = $list;
}
$nib = static fn (string $b, int $i): int => strlen($b) > ($i >> 1) ? ord($b[$i >> 1]) >> (($i & 1) * 4) & 15 : 0;
foreach (['SkyLight', 'BlockLight'] as $kind) {
    $diff = ['inner' => 0, 'edge' => 0]; $cells = 0; $sum = 0;
    foreach ($ch['light'][$kind] as $sy => $b64) {
        $official = base64_decode($b64); $index = (int) $sy + 5; // PHP index 0 = section -5
        $mine = $arrays[$kind][$index] ?? str_repeat($kind === 'SkyLight' && $sy > 19 ? "\xff" : "\0", 2048);
        if ($sy < -4 || $sy > 19) continue;
        for ($i = 0; $i < 4096; ++$i) { $a = $nib($official, $i); $b = $nib($mine, $i); ++$cells; if ($a !== $b) { $x = $i & 15; $z = $i >> 4 & 15; $d = min($x, $z, 15 - $x, 15 - $z); ++$diff[$d >= 3 ? 'inner' : 'edge']; $sum += abs($a - $b); } }
    }
    printf("%s: %d cells, differ inner %d edge %d, total abs %d\n", $kind, $cells, $diff['inner'], $diff['edge'], $sum);
}
