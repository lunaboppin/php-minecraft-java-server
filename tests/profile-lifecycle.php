<?php

declare(strict_types=1);

// Verified skin/cape profile properties must reach observers across the player lifecycle.
require_once __DIR__ . '/support/AuthProbe.php';

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Version;

$version = new Version();
$directory = sys_get_temp_dir() . '/php-minecraft-profile-lifecycle-' . bin2hex(random_bytes(8));
mkdir($directory);
file_put_contents($directory . '/server.json', json_encode([
    'onlineMode' => true, 'host' => '127.0.0.1', 'port' => 0, 'generator' => 'flat',
    'worldPath' => $directory . '/world.sqlite', 'logDirectory' => $directory . '/logs', 'operators' => ['ParityProbe'],
]));
$process = null;
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    authNetworkCheck($condition, $message);
    ++$checks;
};
$start = static function (int $run) use ($directory, $check, &$process): int {
    $log = $directory . '/server-' . $run . '.log';
    $process = proc_open([PHP_BINARY, __DIR__ . '/support/auth-server.php', $directory . '/server.json', 'verified'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
    $deadline = microtime(true) + 15;
    do {
        if (preg_match('/Listening on 127\.0\.0\.1:(\d+)/', @file_get_contents($log) ?: '', $match)) {
            return (int) $match[1];
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    $check(false, 'Fixture server startup: ' . @file_get_contents($log));
};
$stop = static function () use (&$process): void {
    proc_terminate($process);
    proc_close($process);
    $process = null;
};
$profiles = [
    'ParityProbe' => ['112233445566778899aabbccddeeff00', 'fixture-value', 'fixture-signature'],
    'SkinObserver' => [md5('fixture:SkinObserver'), 'fixture-value-SkinObserver', 'fixture-signature-SkinObserver'],
];
$join = static function (int $port, string $name): array {
    $probe = new AuthProbe($port);
    $probe->challenge(name: $name);
    $probe->until('login_finished');
    $entityId = unpack('N', substr($probe->configure(), 0, 4))[1];
    return [$probe, $entityId];
};
// Read packets until $match accepts one, keeping everything seen for later absence checks.
$await = static function (AuthProbe $probe, string $name, callable $match, array &$seen) use ($version, &$checks): string {
    for ($i = 0; $i < 2000; ++$i) {
        [$id, $payload] = $probe->receive();
        $seen[] = [$id, $payload];
        if ($id === $version->id('play', $name) && $match($payload)) {
            ++$checks;
            return $payload;
        }
    }
    throw new RuntimeException('Missing ' . $name);
};
$withProfile = static function (string $player) use ($profiles): callable {
    [$uuid, $value, $signature] = $profiles[$player];
    $property = Binary::writeVarInt(1) . Binary::writeString('textures') . Binary::writeString($value) . "\1" . Binary::writeString($signature);
    // ADD_PLAYER carries the entry's UUID, name and signed properties together.
    return static fn (string $payload): bool => (ord($payload[0]) & 1) === 1
        && str_contains($payload, hex2bin($uuid) . Binary::writeString($player) . $property);
};
$spawns = static function (string $player) use ($profiles): callable {
    // add_entity: VarInt id, then the 16-byte UUID.
    return static function (string $payload) use ($profiles, $player): bool {
        $reader = new Binary($payload);
        $reader->varInt();
        return bin2hex($reader->bytes(16)) === $profiles[$player][0];
    };
};
$removed = static fn (int $entityId): callable => static fn (string $payload): bool => str_contains($payload, Binary::writeVarInt($entityId));
$neverRemovedFromList = static function (array $seen, string $player, string $context) use ($version, $profiles, $check): void {
    foreach ($seen as [$id, $payload]) {
        $check(!($id === $version->id('play', 'player_info_remove') && str_contains($payload, hex2bin($profiles[$player][0]))), $context . ' keeps the tab-list profile');
    }
};
$command = static fn (AuthProbe $probe, string $text) => $probe->send('chat_command', Binary::writeString($text));

try {
    $port = $start(1);
    // Existing player sees a later joiner, and the late joiner sees the existing player.
    [$actor, $actorId] = $join($port, 'ParityProbe');
    [$observer, $observerId] = $join($port, 'SkinObserver');
    $seen = [];
    $await($observer, 'player_info_update', $withProfile('ParityProbe'), $seen);
    $await($observer, 'add_entity', $spawns('ParityProbe'), $seen);
    $actorSeen = [];
    $await($actor, 'player_info_update', $withProfile('SkinObserver'), $actorSeen);

    // Leaving and re-entering tracking range respawns the entity without dropping its profile.
    $seen = [];
    $command($actor, 'tp @s 4000 64 4000');
    $await($observer, 'remove_entities', $removed($actorId), $seen);
    $command($actor, 'tp @s 0 64 0');
    $await($observer, 'add_entity', $spawns('ParityProbe'), $seen);
    $neverRemovedFromList($seen, 'ParityProbe', 'Tracking re-entry');

    // Dimension travel hides then restores the entity; the profile entry remains listed.
    $seen = [];
    $command($actor, 'dimension minecraft:the_nether');
    $await($observer, 'remove_entities', $removed($actorId), $seen);
    $command($actor, 'dimension minecraft:overworld');
    $await($observer, 'add_entity', $spawns('ParityProbe'), $seen);
    $neverRemovedFromList($seen, 'ParityProbe', 'Dimension travel');

    // Reconnecting removes the entry, then re-adds the same verified identity and signed textures.
    $actor->close();
    $seen = [];
    $await($observer, 'player_info_remove', static fn (string $payload): bool => str_contains($payload, hex2bin($profiles['ParityProbe'][0])), $seen);
    [$actor, $actorId] = $join($port, 'ParityProbe');
    $await($observer, 'player_info_update', $withProfile('ParityProbe'), $seen);
    $await($observer, 'add_entity', $spawns('ParityProbe'), $seen);
    file_put_contents(sys_get_temp_dir() . '/php-minecraft-profile-lifecycle-packets.json', json_encode($observer->capture, JSON_PRETTY_PRINT));
    $actor->close();
    $observer->close();
    usleep(200000);
    $stop();

    // After a restart, both players reconnect with their own verified profiles.
    $port = $start(2);
    [$observer] = $join($port, 'SkinObserver');
    [$actor] = $join($port, 'ParityProbe');
    $seen = [];
    $await($observer, 'player_info_update', $withProfile('ParityProbe'), $seen);
    $await($observer, 'add_entity', $spawns('ParityProbe'), $seen);
    $actorSeen = [];
    $await($actor, 'player_info_update', $withProfile('SkinObserver'), $actorSeen);
    file_put_contents(sys_get_temp_dir() . '/php-minecraft-profile-lifecycle-restart-packets.json', json_encode($observer->capture, JSON_PRETTY_PRINT));
    $actor->close();
    $observer->close();
    usleep(200000);
    $stop();

    $database = new PDO('sqlite:' . $directory . '/world.sqlite');
    $stored = $database->query('SELECT uuid FROM players ORDER BY uuid')->fetchAll(PDO::FETCH_COLUMN);
    $check($stored === ['online:' . $profiles['ParityProbe'][0], 'online:' . $profiles['SkinObserver'][0]], 'Only verified identities are persisted');
    echo "PASS: {$checks} verified profile checks across join order, tracking re-entry, dimension travel, reconnect and restart\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
}
