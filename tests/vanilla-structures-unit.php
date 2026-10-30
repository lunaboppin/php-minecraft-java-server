<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\OverworldGenerator;
use PhpMinecraftJavaServer\World\Vanilla\Structure\BlockTransform;
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
    [-17, 21, null, null, null],
    [0, 0, null, null, null],
] as [$cx, $cz, $structure, $box, $orientation]) {
    $starts = $structures->create($cx, $cz);
    $last = $starts === [] ? null : $starts[0]['pieces'][count($starts[0]['pieces']) - 1];
    $actual = $starts === [] ? [null, null, null] : [$starts[0]['structure'], $last->box, $last->orientation];
    if ($structure === 'village_plains' || $structure === 'mineshaft') {
        // Official starts: the village's 186 pieces from its meeting point, the mineshaft's 135 from its room.
        $actual = [$starts[0]['structure'], $starts[0]['pieces'][0]->box, count($starts[0]['pieces'])];
    }
    if ($actual !== [$structure, $box, $orientation]) {
        throw new RuntimeException("Structure start at $cx,$cz: " . json_encode($actual));
    }
    ++$checks;
}

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
] as [[$block, $properties], $mirror, $rotation, [$expectedBlock, $expectedProperties]]) {
    $actual = $name($transform->apply($blocks->state($block, $properties), $mirror, $rotation));
    $expected = $name($blocks->state($expectedBlock, $expectedProperties));
    if ($actual !== $expected) {
        throw new RuntimeException("Transform of $block: $actual, expected $expected");
    }
    ++$checks;
}
echo "PASS: $checks structure start and block transform checks\n";
