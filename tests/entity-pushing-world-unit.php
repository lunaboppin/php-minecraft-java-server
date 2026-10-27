<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\AirTravel;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\EntityPhysics;
use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\LivingEntityPushPhase;
use PhpMinecraftJavaServer\World\World;

$pushWorldPath = getenv('PHP_MC_PUSHING_WORLD_REFERENCE') ?: __DIR__ . '/../reference/26.3/entity-pushing-trajectories.json';
$pushWorldCases = json_decode(file_get_contents($pushWorldPath), true, 512, JSON_THROW_ON_ERROR);
if (array_column($pushWorldCases, 'name') !== ['player-outside', 'player-overlap', 'spectator-overlap', 'two-giants']) {
    throw new RuntimeException('The complete controlled native pushing trajectory roster is required');
}
$pushWorldVersion = new Version();
$pushWorldCatalog = $pushWorldVersion->catalog;
$pushWorldTravel = new AirTravel(
    $pushWorldCatalog,
    new EntityPhysics(file_get_contents($pushWorldVersion->directory . '/entity-sine.bin')),
    $pushWorldCatalog->movement
);
$pushWorldVector = static fn (array $bits): array => array_map(static fn (string $value): float => unpack('E', hex2bin($value))[1], $bits);
$pushWorldState = static function (array $mob, float $width, float $height, bool $spectator = false): array {
    return [
        'position' => [$mob['x'], $mob['y'], $mob['z']],
        'bounds' => Collision::bounds($mob['x'], $mob['y'], $mob['z'], $width, $height),
        'velocity' => $mob['velocity'],
        'needsSync' => $mob['needsSync'] ?? false,
        'pushable' => !$spectator,
        'spectator' => $spectator,
        'noPhysics' => $spectator,
        'sleeping' => false,
        'vehicle' => false,
    ];
};
$pushWorldSnapshot = static fn (array $mob): array => [
    'position_bits' => array_map(static fn (float $value): string => bin2hex(pack('E', $value)), [$mob['x'], $mob['y'], $mob['z']]),
    'velocity_bits' => array_map(static fn (float $value): string => bin2hex(pack('E', $value)), $mob['velocity']),
    'ground' => $mob['ground'],
];
$pushWorldChecks = 0;
foreach ($pushWorldCases as $case) {
    if (count($case['steps']) !== 24) {
        throw new RuntimeException('Every controlled native pushing case requires 24 complete ticks');
    }
    $world = new World($pushWorldVersion, ':memory:');
    for ($x = -12; $x <= 12; ++$x) {
        for ($z = -12; $z <= 12; ++$z) {
            $world->set($x, 63, $z, $pushWorldCatalog->state('stone'));
        }
    }
    $phase = new LivingEntityPushPhase();
    [$x, $y, $z] = $pushWorldVector($case['initial']['player']['position_bits']);
    $player = ['x' => $x, 'y' => $y, 'z' => $z, 'velocity' => $pushWorldVector($case['initial']['player']['velocity_bits'])];
    $phase->upsert(1, $pushWorldState($player, Float32::round(0.6), Float32::round(1.8), $case['mode'] === 'spectator'));
    $definition = $pushWorldCatalog->mobs['entities'][$case['kind']];
    $mobs = [];
    foreach ($case['initial']['mobs'] as $index => $initial) {
        [$x, $y, $z] = $pushWorldVector($initial['position_bits']);
        $mobs[$index] = ['x' => $x, 'y' => $y, 'z' => $z, 'velocity' => $pushWorldVector($initial['velocity_bits']),
            'ground' => $initial['ground'], 'yaw' => 0.0];
        $phase->upsert($index + 100, $pushWorldState($mobs[$index], $definition['width'], $definition['height']));
    }
    foreach ($case['steps'] as $tick => $frame) {
        foreach ($mobs as $index => &$mob) {
            $mob['velocity'] = $phase->state($index + 100)['velocity'];
            $pushWorldTravel->step($world, $mob, $definition, [0.0, 0.0, 0.0], 0.0);
            $phase->upsert($index + 100, $pushWorldState($mob, $definition['width'], $definition['height']));
            $phase->pushNeighbors($index + 100);
        }
        unset($mob);
        // ServerPlayer's normal-tick contact pass follows the world mob ticks.
        // Its client-owned motion/reset lifecycle is outside this mob-state comparison.
        $phase->pushNeighbors(1);
        foreach ($mobs as $index => &$mob) {
            $mob['velocity'] = $phase->state($index + 100)['velocity'];
            $actual = $pushWorldSnapshot($mob);
            $expected = $frame['mobs'][$index];
            if ($actual !== $expected) {
                throw new RuntimeException('Controlled native pushing differs: ' . $case['name'] . '/' . ($tick + 1) . '/' . $index
                    . ': ' . json_encode(['actual' => $actual, 'expected' => $expected], JSON_THROW_ON_ERROR));
            }
            $pushWorldChecks += 7;
        }
        unset($mob);
    }
}
echo 'PASS: ' . $pushWorldChecks . " exact mob position/velocity/ground checks across 96 controlled native collision world ticks\n";
unset($pushWorldCases);
