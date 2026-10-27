<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\EntityPushEligibility;
use PhpMinecraftJavaServer\World\Pushing\EligibilityRegistry;
use PhpMinecraftJavaServer\World\Pushing\SharedPushDispatch;

$eligibilityPath = getenv('PHP_MC_PUSH_ELIGIBILITY_REFERENCE') ?: __DIR__ . '/../reference/26.3/push-eligibility.json';
$eligibilityReference = json_decode(file_get_contents($eligibilityPath), true, 512, JSON_THROW_ON_ERROR);
$eligibilityRules = json_decode(file_get_contents(dirname($eligibilityPath) . '/push-eligibility-rules.json'), true, 512, JSON_THROW_ON_ERROR);
$eligibilityProfiles = json_decode(file_get_contents(__DIR__ . '/../reference/26.3/entity-pushing-rules.json'), true, 512, JSON_THROW_ON_ERROR)['profiles'];
if ($eligibilityReference['version'] !== Version::NAME || $eligibilityReference['protocol'] !== Version::PROTOCOL
    || $eligibilityReference['block_state_count'] !== 35723 || count($eligibilityReference['cases']) !== 87574
    || count($eligibilityReference['virtual_cases']) !== 960 || count($eligibilityReference['virtual_pushes']) !== 96) {
    throw new RuntimeException('The complete pinned native push eligibility fixture is required');
}
$eligibility = new EntityPushEligibility($eligibilityRules);
$eligibilityRegistry = new EligibilityRegistry($eligibility);
$eligibilityChecks = 0;
$eligibilityAssert = static function (mixed $actual, mixed $expected, string $context) use (&$eligibilityChecks): void {
    if ($actual !== $expected) {
        throw new RuntimeException($context . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    ++$eligibilityChecks;
};
foreach ($eligibilityReference['cases'] as $index => $case) {
    $mask = $case['mask'];
    $state = ['position' => [0.0, 64.0, 0.0], 'alive' => ($mask & 1) === 0, 'spectator' => ($mask & 2) !== 0,
        'fallFlying' => ($mask & 4) !== 0, 'ticking' => ($mask & 8) === 0, 'alwaysTicking' => ($mask & 16) !== 0,
        'lastClimbablePos' => ($mask & 32) === 0 ? null : [9, 8, 7]];
    $expected = $eligibilityReference['results'][$case['result']];
    $climbingState = $state;
    $actual = $eligibility->onClimbable($climbingState, $case['feet'], $case['below']);
    $eligibilityAssert($actual, $expected['on_climbable'], 'Native climbing ' . $index);
    $eligibilityAssert($climbingState['lastClimbablePos'], $expected['climbing_last'], 'Native climbing side effect ' . $index);
    $actual = $eligibility->pushable($state, $case['feet'], $case['below']);
    $eligibilityAssert($actual, $expected['pushable'], 'Native shared pushability ' . $index);
    $eligibilityAssert($state['lastClimbablePos'], $expected['pushable_last'], 'Native eligibility short-circuit side effect ' . $index);
}
foreach ($eligibilityReference['virtual_cases'] as $index => $case) {
    $state = ['position' => [0.0, 64.0, 0.0], 'alive' => $case['alive'], 'spectator' => false,
        'fallFlying' => $case['fall_flying'], 'ticking' => $case['ticking'], 'alwaysTicking' => false,
        'lastClimbablePos' => [9, 8, 7], 'vehicle' => $case['vehicle'], 'pose' => $case['pose'], 'canMove' => $case['can_move']];
    $actual = $eligibilityRegistry->test($eligibilityProfiles[$case['kind']]['pushable'], $state, $case['feet'], $case['below']);
    $eligibilityAssert($actual, $case['pushable'], 'Native virtual pushability ' . $case['kind'] . '/' . $index);
    $eligibilityAssert($state['lastClimbablePos'], $case['last'], 'Native virtual climbing side effect ' . $case['kind'] . '/' . $index);
}
$eligibilityDecodeMotion = static function (array $state, string $kind) use ($eligibilityProfiles): array {
    $vector = static fn (array $values): array => array_map(static fn (string $value): float => unpack('E', hex2bin($value))[1], $values);
    return ['kind' => $kind, 'position' => $vector($state['position_bits']), 'velocity' => $vector($state['velocity_bits']),
        'needsSync' => $state['needs_sync'], 'syncVelocity' => $state['sync_velocity'], 'pushable' => $state['pushable'],
        'noPhysics' => $state['no_physics'], 'sleeping' => $state['sleeping'], 'vehicle' => $state['vehicle'],
        'velocityMode' => $kind === 'shulker' ? 'zero' : 'normal',
        'pushOwner' => $eligibilityProfiles[$kind]['push'], 'doPushOwner' => $eligibilityProfiles[$kind]['doPush']];
};
foreach ($eligibilityReference['virtual_pushes'] as $index => $case) {
    $giant = $eligibilityDecodeMotion($case['giant'], 'giant');
    $shulker = $eligibilityDecodeMotion($case['shulker'], 'shulker');
    foreach ($case['frames'] as $repeat => $frame) {
        if ($case['action'] === 1) {
            SharedPushDispatch::contact($giant, $shulker, false);
        } else {
            SharedPushDispatch::contact($shulker, $giant, false);
        }
        foreach (['giant' => $giant, 'shulker' => $shulker] as $kind => $actual) {
            foreach (['position', 'velocity'] as $field) {
                $bits = array_map(static fn (float $value): string => bin2hex(pack('E', $value)), $actual[$field]);
                $eligibilityAssert($bits, $frame[$kind][$field . '_bits'], 'Native virtual impulse ' . $index . '/' . $repeat . '/' . $kind . '/' . $field);
            }
            foreach (['needsSync' => 'needs_sync', 'syncVelocity' => 'sync_velocity', 'pushable' => 'pushable',
                'noPhysics' => 'no_physics', 'sleeping' => 'sleeping', 'vehicle' => 'vehicle'] as $field => $referenceField) {
                $eligibilityAssert($actual[$field], $frame[$kind][$referenceField], 'Native virtual impulse flags ' . $index . '/' . $repeat . '/' . $kind . '/' . $field);
            }
        }
    }
}
echo 'PASS: ' . $eligibilityChecks . " actual Java climbing, eligibility, short-circuit and virtual-species checks\n";
unset($eligibilityReference);
