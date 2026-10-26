<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\Server\Tracking\EntityMovementTracker;

$trackingVersion = new Version();
$trackingReference = json_decode(file_get_contents(__DIR__ . '/../reference/26.3/mob-tracking.json'), true, 512, JSON_THROW_ON_ERROR);
if ($trackingReference['version'] !== Version::NAME || $trackingReference['protocol'] !== Version::PROTOCOL || count($trackingReference['cases']) !== 14) {
    throw new RuntimeException('The complete pinned entity tracking reference is required');
}
$trackingState = static function (array $row): array {
    $state = $row;
    foreach (['position', 'velocity', 'predicted'] as $name) {
        $state[$name] = array_map(static fn (string $bits): float => unpack('E', hex2bin($bits))[1], $row[$name . '_bits']);
    }
    foreach (['yaw', 'pitch', 'head'] as $name) {
        $state[$name] = unpack('G', hex2bin($row[$name . '_bits']))[1];
    }
    return $state;
};
$trackingFrames = 0;
$trackingPackets = 0;
foreach ($trackingReference['cases'] as $case) {
    $tracker = new EntityMovementTracker(17, $case['interval'], $case['track_delta'], $trackingState($case['initial']));
    foreach ($case['frames'] as $tick => $frame) {
        $state = $trackingState($frame['state']);
        $packets = $tracker->update($state);
        $actual = array_map(static fn (array $packet): array => [
            'type' => 'minecraft:' . $packet['name'],
            'delivery' => $packet['delivery'],
            'hex' => bin2hex(Binary::writeVarInt($trackingVersion->id('play', $packet['name'])) . $packet['payload']),
        ], $packets);
        if ($actual !== $frame['packets']) {
            throw new RuntimeException('Official tracking packets differ in ' . $case['name'] . ' tick ' . $tick . ': '
                . json_encode(['expected' => $frame['packets'], 'actual' => $actual], JSON_THROW_ON_ERROR));
        }
        foreach ($frame['after_flags'] as $flag => $expected) {
            if ($state[$flag] !== $expected) {
                throw new RuntimeException('Official tracking flag differs: ' . $case['name'] . '/' . $tick . '/' . $flag);
            }
        }
        $pairing = Binary::writeVarInt($trackingVersion->id('play', 'add_entity')) . $tracker->spawnPacket(
            hex2bin('000102030405060708090a0b0c0d0e0f'),
            $trackingVersion->catalog->mobs['entities']['giant']['id']
        );
        if (bin2hex($pairing) !== $frame['pairing_hex']) {
            throw new RuntimeException('Official tracked-entity pairing differs: ' . $case['name'] . '/' . $tick);
        }
        ++$trackingFrames;
        $trackingPackets += count($packets);
    }
}
if ($trackingFrames !== 4078 || $trackingPackets !== 3417) {
    throw new RuntimeException('Official tracking fixture frame or packet coverage changed');
}
echo 'PASS: ' . $trackingFrames . ' actual Java entity tracker frames and ' . $trackingPackets . " complete ordered packets/flags\n";
