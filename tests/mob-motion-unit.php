<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\AirTravel;
use PhpMinecraftJavaServer\World\EntityPhysics;
use PhpMinecraftJavaServer\World\World;

$motionVersion = new Version();
$motionCatalog = $motionVersion->catalog;
$motionDirectory = __DIR__ . '/../reference/26.3/';
$motionTravel = new AirTravel(
    $motionCatalog,
    new EntityPhysics(file_get_contents($motionDirectory . 'entity-sine.bin')),
    json_decode(file_get_contents($motionDirectory . 'movement.json'), true, 512, JSON_THROW_ON_ERROR)
);
$motionCases = json_decode(
    file_get_contents($motionDirectory . 'mob-motion-trajectories.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$expectedMotionCases = ['resting-stone', 'stone-hop', 'stone-wall', 'bottom-slab', 'ice-drag', 'slime-bounce'];
if (array_column($motionCases, 'name') !== $expectedMotionCases) {
    throw new RuntimeException('The complete official movement trajectory roster is required');
}
$motionSnapshot = static fn (array $mob): array => [
    'position_bits' => array_map(static fn (float $value): string => bin2hex(pack('E', $value)), [$mob['x'], $mob['y'], $mob['z']]),
    'velocity_bits' => array_map(static fn (float $value): string => bin2hex(pack('E', $value)), $mob['velocity']),
    'ground' => $mob['ground'],
];
$motionChecks = 0;
foreach ($motionCases as $case) {
    // Every case has its own actual World/SQLite instance with no entities that
    // could push the actor. Match the official fixture's block edits exactly.
    $motionWorld = new World($motionVersion, ':memory:');
    for ($x = -12; $x <= 12; ++$x) {
        for ($z = -12; $z <= 12; ++$z) {
            $motionWorld->set($x, 63, $z, $motionCatalog->state($case['floor']));
        }
    }
    if (isset($case['obstacle'])) {
        $maximum = $case['obstacle'] === 'stone' ? 82 : 64;
        $state = $case['obstacle'] === 'stone'
            ? $motionCatalog->state('stone')
            : $motionCatalog->state('stone_slab', ['type' => 'bottom']);
        for ($z = -12; $z <= 12; ++$z) {
            for ($y = 64; $y <= $maximum; ++$y) {
                $motionWorld->set(3, $y, $z, $state);
            }
        }
    }
    $motionMob = [
        'x' => $case['position'][0],
        'y' => $case['position'][1],
        'z' => $case['position'][2],
        'velocity' => $case['velocity'],
        'ground' => $case['ground'],
        'yaw' => 0.0,
    ];
    if ($motionSnapshot($motionMob) !== $case['initial'] || count($case['steps']) !== 24) {
        throw new RuntimeException('Invalid official movement fixture initial state or tick count');
    }
    foreach ($case['steps'] as $tick => $expected) {
        $motionTravel->step($motionWorld, $motionMob, $motionCatalog->mobs['entities'][$case['kind']], [0.0, 0.0, 0.0], 0.0);
        $actual = $motionSnapshot($motionMob);
        if ($actual !== $expected) {
            throw new RuntimeException('Official movement differs for ' . $case['name'] . ' tick ' . ($tick + 1)
                . ': ' . json_encode(['expected' => $expected, 'actual' => $actual], JSON_THROW_ON_ERROR));
        }
        $motionChecks += 7;
    }
}
echo 'PASS: ' . $motionChecks . " exact position, velocity and ground checks across 144 official world ticks\n";
