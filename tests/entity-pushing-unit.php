<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\EntityPushing;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;

$pushingPath = getenv('PHP_MC_PUSHING_REFERENCE') ?: __DIR__ . '/../reference/26.3/entity-pushing.json';
$pushingReference = json_decode(file_get_contents($pushingPath), true, 512, JSON_THROW_ON_ERROR);
if ($pushingReference['version'] !== Version::NAME || $pushingReference['protocol'] !== Version::PROTOCOL
    || count($pushingReference['pushes']) !== 40960 || count($pushingReference['filters']) !== 152
    || count($pushingReference['boxes']) !== 405 || count($pushingReference['do_pushes']) !== 2048
    || count($pushingReference['profiles']) !== 91) {
    throw new RuntimeException('The pinned native entity pushing reference is required');
}
$pushingChecks = 0;
$pushingAssert = static function (mixed $actual, mixed $expected, string $context) use (&$pushingChecks): void {
    if ($actual !== $expected) {
        throw new RuntimeException($context . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    ++$pushingChecks;
};
$pushingVector = static fn (array $values): array => array_map(static fn (string $bits): float => unpack('E', hex2bin($bits))[1], $values);
$pushingDecode = static function (array $state) use ($pushingVector): array {
    return [
        'position' => $pushingVector($state['position_bits']),
        'velocity' => $pushingVector($state['velocity_bits']),
        'needsSync' => $state['needs_sync'],
        'syncVelocity' => $state['sync_velocity'],
        'pushable' => $state['pushable'],
        'vehicle' => $state['vehicle'],
        'noPhysics' => $state['no_physics'],
        'sleeping' => $state['sleeping'],
    ];
};
$pushingCompare = static function (array $actual, array $expected, string $context) use ($pushingAssert): void {
    foreach (['position', 'velocity'] as $field) {
        $bits = array_map(static fn (float $component): string => bin2hex(pack('E', $component)), $actual[$field]);
        $pushingAssert($bits, $expected[$field . '_bits'], $context . '/' . $field);
    }
    foreach (['needsSync' => 'needs_sync', 'syncVelocity' => 'sync_velocity', 'noPhysics' => 'no_physics',
        'pushable' => 'pushable', 'vehicle' => 'vehicle', 'sleeping' => 'sleeping'] as $field => $referenceField) {
        $pushingAssert($actual[$field], $expected[$referenceField], $context . '/' . $field);
    }
};
foreach (['pushes' => 'push', 'do_pushes' => 'doPush'] as $collection => $method) {
    foreach ($pushingReference[$collection] as $index => $case) {
        $firstState = $pushingReference['states'][$case['first']];
        $secondState = $pushingReference['states'][$case['second']];
        $first = $pushingDecode($firstState);
        $second = $pushingDecode($secondState);
        foreach ($case['frames'] as $repeat => $frame) {
            EntityPushing::$method($first, $second, $firstState['same_vehicle']);
            $pushingCompare($first, $pushingReference['states'][$frame['first']], 'Push ' . $index . '/' . $repeat . '/first');
            $pushingCompare($second, $pushingReference['states'][$frame['second']], 'Push ' . $index . '/' . $repeat . '/second');
        }
        if (isset($case['random_next'])) {
            $random = new LegacyRandom(771);
            $pushingAssert((string) $random->nextLong(), $case['random_next'], 'Native push does not consume randomness ' . $index);
        }
    }
}
foreach ($pushingReference['filters'] as $index => $case) {
    $accepted = EntityPushing::eligible(
        $case['pushable'],
        $case['spectator'],
        strtoupper($case['first_rule']),
        strtoupper($case['second_rule']),
        $case['allied']
    );
    $pushingAssert($accepted, $case['accepted'], 'Actual team push predicate ' . $index);
}
foreach ($pushingReference['boxes'] as $index => $case) {
    $intersects = EntityPushing::intersects($pushingVector($case['first_bits']), $pushingVector($case['second_bits']));
    $pushingAssert($intersects, $case['intersects'], 'Native AABB intersection ' . $index);
}
echo 'PASS: ' . $pushingChecks . " actual Java push, synchronization, team-filter and intersection checks\n";
unset($pushingReference, $pushingCompare);
