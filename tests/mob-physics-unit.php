<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\Protocol\LowPrecisionVector;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Protocol\ProtocolException;
use PhpMinecraftJavaServer\World\EntityPhysics;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;

$mobPhysicsReference = json_decode(
    file_get_contents(__DIR__ . '/../reference/26.3/mob-physics.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$mobPhysicsChecks = 0;

foreach ($mobPhysicsReference['vectors'] as $sample) {
    $vector = array_map(
        static fn (string $bits): float => unpack('E', hex2bin($bits))[1],
        $sample['input_bits']
    );
    if (bin2hex(LowPrecisionVector::encode(...$vector)) !== $sample['hex']) {
        throw new RuntimeException('Official low-precision vector encoding differs: ' . json_encode($sample));
    }
    ++$mobPhysicsChecks;

    $input = new Binary(hex2bin($sample['hex']));
    $decoded = LowPrecisionVector::decode($input);
    $input->end();
    foreach ($decoded as $axis => $component) {
        if (bin2hex(pack('E', $component)) !== $sample['decoded_bits'][$axis]) {
            throw new RuntimeException('Official low-precision vector decoding differs: ' . json_encode($sample));
        }
        ++$mobPhysicsChecks;
    }

    if (bin2hex(Packets::entityMotion(17, ...$vector)) !== $sample['motion_hex']) {
        throw new RuntimeException('Official entity-motion packet bytes differ');
    }
    ++$mobPhysicsChecks;
}

// The component decoder must leave subsequent packet fields untouched.
$vectorWithTail = new Binary(LowPrecisionVector::encode(0.4, 0.2, -0.8) . "\x7b");
LowPrecisionVector::decode($vectorWithTail);
if ($vectorWithTail->byte() !== 123) {
    throw new RuntimeException('Vector decoder consumed a subsequent packet field');
}
$vectorWithTail->end();

foreach (["\1", "\1\0\0", "\4\0\0\0\0\0\x80", "\4\0\0\0\0\0\xff\xff\xff\xff\x10"] as $malformedVector) {
    try {
        LowPrecisionVector::decode(new Binary($malformedVector));
    } catch (ProtocolException) {
        continue;
    }
    throw new RuntimeException('Malformed low-precision vector was accepted');
}

$entityPhysics = new EntityPhysics(file_get_contents(__DIR__ . '/../reference/26.3/entity-sine.bin'));
$readPhysicsVector = static fn (array $bits): array => array_map(
    static fn (string $value): float => unpack('E', hex2bin($value))[1],
    $bits
);
foreach ($mobPhysicsReference['knockbacks'] as $sample) {
    $direction = $readPhysicsVector($sample['direction_bits']);
    $actual = EntityPhysics::knockback(
        $readPhysicsVector($sample['velocity_bits']),
        $sample['strength'],
        $sample['resistance'],
        $sample['ground'],
        $direction[0],
        $direction[1],
        new LegacyRandom((int) $sample['seed'])
    );
    foreach ($actual['velocity'] as $axis => $component) {
        if (bin2hex(pack('E', $component)) !== $sample['expected_bits'][$axis]) {
            throw new RuntimeException('Official knockback vector differs: ' . json_encode($sample));
        }
        ++$mobPhysicsChecks;
    }
    if ($actual['synchronize'] !== $sample['synchronize']) {
        throw new RuntimeException('Official knockback synchronization flag differs');
    }
    ++$mobPhysicsChecks;
}
foreach ($mobPhysicsReference['inputs'] as $sample) {
    $actual = $entityPhysics->inputVector(
        $readPhysicsVector($sample['input_bits']),
        unpack('G', hex2bin($sample['speed_bits']))[1],
        unpack('G', hex2bin($sample['yaw_bits']))[1]
    );
    foreach ($actual as $axis => $component) {
        if (bin2hex(pack('E', $component)) !== $sample['expected_bits'][$axis]) {
            throw new RuntimeException('Official entity input rotation differs: ' . json_encode($sample));
        }
        ++$mobPhysicsChecks;
    }
}
foreach ($mobPhysicsReference['frictions'] as $sample) {
    if (bin2hex(pack('G', EntityPhysics::modifiedFriction($sample['base'], $sample['modifier']))) !== $sample['bits']) {
        throw new RuntimeException('Official modified friction differs');
    }
    ++$mobPhysicsChecks;
}
foreach ($mobPhysicsReference['speeds'] as $sample) {
    if (bin2hex(pack('G', EntityPhysics::movementAcceleration($sample['speed'], $sample['friction'], $sample['ground']))) !== $sample['bits']) {
        throw new RuntimeException('Official friction-influenced speed differs');
    }
    ++$mobPhysicsChecks;
}

$physicsVersion = new Version();
foreach ($mobPhysicsReference['bounds'] as $sample) {
    $position = $readPhysicsVector($sample['position_bits']);
    $definition = $physicsVersion->catalog->mobs['entities'][$sample['kind']];
    $bounds = Collision::bounds(...[...$position, $definition['width'], $definition['height']]);
    foreach ($bounds as $axis => $component) {
        if (bin2hex(pack('E', $component)) !== $sample['expected_bits'][$axis]) {
            throw new RuntimeException('Official entity bounding box differs: ' . json_encode($sample));
        }
        ++$mobPhysicsChecks;
    }
}

echo 'PASS: ' . $mobPhysicsChecks . " official entity movement, knockback and motion codec checks\n";
