<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\OverworldGenerator;
use PhpMinecraftJavaServer\World\Vanilla\Structure\BlockTransform;
use PhpMinecraftJavaServer\World\Vanilla\Structure\Piece;
use PhpMinecraftJavaServer\World\Vanilla\Structure\Structures;

// Structure starts the official 26.3 server generates for seed 42 (tools/parity/worldgen_regions.py
// --structures), and the block transforms structure pieces apply.
$version = new Version();
$generator = new OverworldGenerator(42, $version->directory);
$structures = new Structures($generator->settings, $generator->biomes, $version->directory . '/worldgen');
$checks = 0;
foreach ([
    [-17, 22, 'swamp_hut', [-272, 64, 352, -264, 70, 358], 'east'],
    [-51, -23, 'swamp_hut', [-816, 64, -368, -808, 70, -362], 'east'],
    [-86, -110, 'desert_pyramid', [-1376, 64, -1760, -1356, 78, -1740], 'east'],
    [-85, 180, 'jungle_pyramid', [-1360, 64, 2880, -1346, 73, 2891], 'east'],
    // The last piece of an igloo is its top; this one has a ladder shaft and laboratory below.
    [208, -305, 'igloo', [3328, 90, -4880, 3334, 94, -4873], 'north'],
    [12, 28, 'shipwreck', [184, 90, 459, 211, 98, 467], 'north'],
    [41, -19, 'village_plains', [647, 79, -313, 656, 85, -304], 186],
    [4, 0, 'mineshaft', [66, 5, 2, 76, 11, 14], 135],
    // The first stronghold ring chunk, after its biome search.
    [-25, -141, 'stronghold', [-398, -27, -2254, -394, -17, -2250], 97],
    // A cold ruin's three layers; the last is its mossy layer, before settling on the floor.
    [3, 10, 'ocean_ruin_cold', [43, 90, 154, 48, 96, 160], 'north'],
    [-19, -38, 'ruined_portal', [-304, 70, -615, -296, 78, -608], 'north'],
    [-17, 21, null, null, null],
    [0, 0, null, null, null],
] as [$cx, $cz, $structure, $box, $orientation]) {
    $starts = $structures->create($cx, $cz);
    $last = $starts === [] ? null : $starts[0]['pieces'][count($starts[0]['pieces']) - 1];
    $actual = $starts === [] ? [null, null, null] : [$starts[0]['structure'], $last->box, $last->orientation];
    if (in_array($structure, ['village_plains', 'mineshaft', 'stronghold'], true)) {
        // Official starts: the village's 186 pieces, the mineshaft's 135 and the stronghold's 97, from their first piece.
        $actual = [$starts[0]['structure'], $starts[0]['pieces'][0]->box, count($starts[0]['pieces'])];
    }
    if ($actual !== [$structure, $box, $orientation]) {
        throw new RuntimeException("Structure start at $cx,$cz: " . json_encode($actual));
    }
    ++$checks;
}

// /locate from 0 70 0, as the official server answers: the nearest mineshaft and monument, and
// the nearest plains sampled in the biome spiral.
$actual = [$structures->locate(['mineshaft', 'mineshaft_mesa'], 0, 70, 0), $structures->locate(['monument'], 0, 70, 0), $generator->biomes->findClosest3d(['plains'], 0, 70, 0, 6400, 32, 64, -64, 319)];
if ($actual !== [[64, 0, 0, 'mineshaft'], [784, 0, -176, 'monument'], [352, 70, -320, 'plains']]) {
    throw new RuntimeException('Locate from 0 70 0: ' . json_encode($actual));
}
++$checks;

// The ocean monument at 49,-11: the official building facing east, and its rooms after saving.
$monument = $structures->create(49, -11);
$building = $monument[0]['pieces'][0] ?? null;
$restored = $building === null ? null : Piece::restore(json_decode(json_encode(['type' => $building->type(), 'box' => $building->box, 'orientation' => $building->orientation] + $building->data()), true));
$actual = [$monument[0]['structure'] ?? null, $building?->box, $building?->orientation, count($building->children ?? []), $restored?->data() === $building?->data()];
if ($actual !== ['monument', [755, 39, -205, 812, 61, -148], 'east', 26, true]) {
    throw new RuntimeException('Ocean monument start at 49,-11: ' . json_encode($actual));
}
++$checks;

// The woodland mansion at 531,-274: the official start's 598 pieces, from its entrance.
$mansion = $structures->create(531, -274);
$entrance = $mansion[0]['pieces'][0] ?? null;
$actual = [$mansion[0]['structure'] ?? null, count($mansion[0]['pieces'] ?? []), $entrance?->template, $entrance?->position, $entrance?->rotation];
if ($actual !== ['mansion', 598, 'woodland_mansion/entrance', [8503, 63, -4368], 3]) {
    throw new RuntimeException('Woodland mansion start at 531,-274: ' . json_encode($actual));
}
++$checks;

// The Nether fortress at the origin: the official start's 90 pieces.
$nether = new OverworldGenerator(42, $version->directory, 'nether');
$fortress = (new Structures($nether->settings, $nether->biomes, $version->directory . '/worldgen'))->create(0, 0);
$actual = [$fortress[0]['structure'] ?? null, count($fortress[0]['pieces'] ?? []), $fortress[0]['pieces'][0]->box ?? null];
if ($actual !== ['fortress', 90, [2, 69, 2, 20, 78, 20]]) {
    throw new RuntimeException('Nether fortress start at 0,0: ' . json_encode($actual));
}
++$checks;

// A Nether fossil at -12,0: the official template, rotation and position.
$fossil = (new Structures($nether->settings, $nether->biomes, $version->directory . '/worldgen'))->create(-12, 0);
$piece = $fossil[0]['pieces'][0] ?? null;
$actual = [$fossil[0]['structure'] ?? null, $piece?->template, $piece?->rotation, $piece?->position, $piece?->box];
if ($actual !== ['nether_fossil', 'nether_fossils/fossil_3', 3, [-192, 55, 1], [-192, 55, -1, -191, 58, 1]]) {
    throw new RuntimeException('Nether fossil start at -12,0: ' . json_encode($actual));
}
++$checks;

// An End city: the official start's 104 pieces.
$end = new OverworldGenerator(42, $version->directory, 'end');
$city = (new Structures($end->settings, $end->biomes, $version->directory . '/worldgen'))->create(66, -38);
$actual = [$city[0]['structure'] ?? null, count($city[0]['pieces'] ?? [])];
if ($actual !== ['end_city', 104]) {
    throw new RuntimeException('End city start at 66,-38: ' . json_encode($actual));
}
++$checks;

$blocks = new Blocks($version, $version->directory . '/worldgen/block-behaviour.json');
$transform = new BlockTransform($blocks);
$name = static function (int $state) use ($blocks): string {
    $properties = $blocks->properties($state);
    ksort($properties);
    return $blocks->name($state) . json_encode($properties);
};
foreach ([
    // StructurePiece orientation south mirrors LEFT_RIGHT; west also turns a quarter.
    [['spruce_stairs', ['facing' => 'north', 'shape' => 'outer_right']], BlockTransform::LEFT_RIGHT, 0, ['spruce_stairs', ['facing' => 'south', 'shape' => 'outer_left']]],
    [['spruce_stairs', ['facing' => 'east', 'shape' => 'outer_right']], BlockTransform::LEFT_RIGHT, 0, ['spruce_stairs', ['facing' => 'east', 'shape' => 'outer_right']]],
    [['spruce_stairs', ['facing' => 'east', 'shape' => 'inner_left']], BlockTransform::FRONT_BACK, 0, ['spruce_stairs', ['facing' => 'west', 'shape' => 'inner_left']]],
    [['spruce_stairs', ['facing' => 'north']], BlockTransform::LEFT_RIGHT, 1, ['spruce_stairs', ['facing' => 'west']]],
    [['oak_log', ['axis' => 'x']], BlockTransform::NONE, 1, ['oak_log', ['axis' => 'z']]],
    [['oak_door', ['facing' => 'north', 'hinge' => 'left']], BlockTransform::LEFT_RIGHT, 0, ['oak_door', ['facing' => 'south', 'hinge' => 'right']]],
    [['oak_fence', ['north' => 'true']], BlockTransform::NONE, 1, ['oak_fence', ['east' => 'true']]],
    [['oak_sign', ['rotation' => '3']], BlockTransform::LEFT_RIGHT, 0, ['oak_sign', ['rotation' => '13']]],
    // RailBlock.rotate and mirror turn each side of the shape.
    [['rail', ['shape' => 'north_south']], BlockTransform::NONE, 1, ['rail', ['shape' => 'east_west']]],
    [['rail', ['shape' => 'north_east']], BlockTransform::LEFT_RIGHT, 1, ['rail', ['shape' => 'south_west']]],
    [['powered_rail', ['shape' => 'ascending_north']], BlockTransform::NONE, 3, ['powered_rail', ['shape' => 'ascending_west']]],
] as [[$block, $properties], $mirror, $rotation, [$expectedBlock, $expectedProperties]]) {
    $actual = $name($transform->apply($blocks->state($block, $properties), $mirror, $rotation));
    $expected = $name($blocks->state($expectedBlock, $expectedProperties));
    if ($actual !== $expected) {
        throw new RuntimeException("Transform of $block: $actual, expected $expected");
    }
    ++$checks;
}
echo "PASS: $checks structure start and block transform checks\n";
