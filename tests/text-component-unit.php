<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Nbt;
use PhpMinecraftJavaServer\Protocol\Packets;

// A join message captured from the official 26.3 server (tools/.cache/parity-equipment-2/reference-packets.json),
// without its packet ID. Field order there is Java HashMap order, not insertion or alphabetical order.
$vanillaJoin = '0a090004776974680a000000010a000b636c69636b5f6576656e74080006616374696f6e000f737567676573745f636f6d6d616e64080007'
    . '636f6d6d616e6400152f74656c6c205061726974794f627365727665722000080009696e73657274696f6e000e5061726974794f62736572'
    . '76657208000474657874000e5061726974794f627365727665720a000b686f7665725f6576656e740800046e616d65000e5061726974794f'
    . '62736572766572080006616374696f6e000b73686f775f656e74697479080002696400106d696e6563726166743a706c617965720b000475'
    . '756964000000044199648926563ab3a81a984528f77a530000080005636f6c6f72000679656c6c6f770800097472616e736c61746500196d'
    . '756c7469706c617965722e706c617965722e6a6f696e65640000';
$textChecks = 0;
$textCheck = static function (bool $condition, string $message) use (&$textChecks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    ++$textChecks;
};
$uuid = hex2bin('4199648926563ab3a81a984528f77a53');
// Deliberately supply fields in a different order from the wire.
$join = ['color' => 'yellow', 'translate' => 'multiplayer.player.joined', 'with' => [Packets::playerName('ParityObserver', $uuid)]];
$textCheck(bin2hex(Packets::systemChat($join)) === $vanillaJoin, 'join message is byte-identical to the official server');
$textCheck(bin2hex(Nbt::component(['text' => 'plain'])) === bin2hex(Nbt::text('plain')), 'text-only component stays a bare string');
$textCheck(bin2hex(Nbt::component(['translate' => 'a', 'with' => [['text' => 'x'], ['text' => 'y']]])) === '0a0900047769746808000000020001780001790800097472616e736c61746500016100', 'plain arguments form a string list');
foreach ([['with' => [['text' => 'x'], ['translate' => 'y']]], ['uuid' => 'short'], ['size' => 1]] as $invalid) {
    try {
        Nbt::component(['translate' => 'k', ...$invalid]);
        $textCheck(false, 'rejected unsupported component ' . json_encode(array_keys($invalid)));
    } catch (InvalidArgumentException) {
        $textCheck(true, 'rejected unsupported component');
    }
}
echo "PASS: {$textChecks} text component checks\n";
