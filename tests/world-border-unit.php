<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\World\WorldBorder;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    ++$checks;
};
// The default border is the initialize_border payload vanilla sends to a joining player.
$default = pack('EEEE', 0.0, 0.0, 59999968.0, 59999968.0) . Binary::writeVarLong(0) . Binary::writeVarInt(29999984) . Binary::writeVarInt(5) . Binary::writeVarInt(300);
$check(WorldBorder::fromArray(null)->initializePacket(1234) === $default, 'default border packet');
$border = WorldBorder::fromArray(null);
$border->lerpSizeBetween(100.0, 50.0, 20, 1000);
$check($border->size(1000) === 100.0 && $border->size(1010) === 75.0 && $border->size(1020) === 50.0 && $border->size(5000) === 50.0, 'moving size over game ticks');
$check($border->initializePacket(1010) === pack('EEEE', 0.0, 0.0, 75.0, 50.0) . Binary::writeVarLong(10) . Binary::writeVarInt(29999984) . Binary::writeVarInt(5) . Binary::writeVarInt(300), 'a joining player sees the remaining lerp');
$border->setSize(20.0);
[$border->centerX, $border->centerZ] = [10.5, -4.5];
$check($border->distanceToBorder(10.5, -4.5, 0) === 10.0 && $border->distanceToBorder(25.5, -4.5, 0) === -5.0, 'distance to the nearest edge');
$saved = WorldBorder::fromArray(json_decode(json_encode($border->toArray()), true));
$check($saved->toArray() == $border->toArray() && $saved->warningTime === 300 && $saved->centerX === 10.5, 'settings survive a save');
echo "PASS: {$checks} world border checks\n";
