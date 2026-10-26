<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\LowPrecisionVector;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Protocol\ProtocolException;

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

echo 'PASS: ' . $mobPhysicsChecks . " official low-precision motion codec checks\n";
