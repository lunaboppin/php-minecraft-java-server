<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\Server\Tracking\EntityMovementTracker;
use PhpMinecraftJavaServer\Server\Server;

$trackingVersion = new Version();
$trackingReference = json_decode(file_get_contents(__DIR__ . '/../reference/26.3/mob-tracking.json'), true, 512, JSON_THROW_ON_ERROR);
if ($trackingReference['version'] !== Version::NAME || $trackingReference['protocol'] !== Version::PROTOCOL || count($trackingReference['cases']) !== 20) {
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
    $initial = $trackingState($case['initial']);
    $tracker = new EntityMovementTracker(17, $case['interval'], $case['track_delta'], $initial, $case['stepped']);
    (new ReflectionProperty(EntityMovementTracker::class, 'ticks'))->setValue($tracker, $case['initial_tick']);
    // Exercise the real server adapter, including special interpolation/spawn
    // policy, without opening a listener or creating unrelated world fixtures.
    $adapter = (new ReflectionClass(Server::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Server::class, 'version'))->setValue($adapter, $trackingVersion);
    $mob = [
        'id' => 17, 'kind' => $case['kind'], 'uuid' => '000102030405060708090a0b0c0d0e0f',
        'x' => $initial['position'][0], 'y' => $initial['position'][1], 'z' => $initial['position'][2],
        'velocity' => $initial['velocity'], 'yaw' => $initial['yaw'], 'pitch' => $initial['pitch'],
        'headYaw' => $initial['head'], 'ground' => $initial['ground'], 'pose' => $case['pose'],
    ];
    $type = $trackingVersion->catalog->mobs['entities'][$case['kind']]['id'];
    $spawn = (new ReflectionMethod(Server::class, 'trackedMobSpawnPacket'))->invoke($adapter, $mob, $type);
    if (bin2hex(Binary::writeVarInt($trackingVersion->id('play', 'add_entity')) . $spawn) !== $case['initial_pairing_hex']) {
        throw new RuntimeException('Official initial pairing through the server adapter differs: ' . $case['name']);
    }
    $profiles = $trackingVersion->catalog->movement['profiles'];
    if (($profiles[$case['kind']]['interpolation'] === 'stepped') !== $case['stepped']) {
        throw new RuntimeException('Actual interpolation policy differs from the server profile');
    }
    foreach ($case['frames'] as $tick => $frame) {
        $state = $trackingState($frame['state']);
        $mob['velocity'] = array_map(static fn (string $bits): float => unpack('E', hex2bin($bits))[1], $frame['state']['requested_velocity_bits']);
        $adapterState = (new ReflectionMethod(Server::class, 'mobMotionState'))->invoke($adapter, $mob);
        if (array_map(static fn (float $value): string => bin2hex(pack('E', $value)), $adapterState['velocity']) !== $frame['state']['velocity_bits']) {
            throw new RuntimeException('Actual velocity override differs in the server adapter: ' . $case['name'] . '/' . $tick);
        }
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
            $trackingVersion->catalog->mobs['entities'][$case['kind']]['id'],
            $case['spawn_data']
        );
        if (bin2hex($pairing) !== $frame['pairing_hex']) {
            throw new RuntimeException('Official tracked-entity pairing differs: ' . $case['name'] . '/' . $tick);
        }
        ++$trackingFrames;
        $trackingPackets += count($packets);
    }
}
if ($trackingFrames !== 5856 || $trackingPackets !== 4017) {
    throw new RuntimeException('Official tracking fixture frame or packet coverage changed');
}
echo 'PASS: ' . $trackingFrames . ' actual Java entity tracker frames and ' . $trackingPackets . " complete ordered packets/flags\n";
