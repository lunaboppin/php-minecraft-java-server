<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\Server\Client;
use PhpMinecraftJavaServer\Server\Config;
use PhpMinecraftJavaServer\Server\Server;
use PhpMinecraftJavaServer\World\World;

$backendCases = json_decode(file_get_contents(__DIR__ . '/../reference/26.3/entity-pushing-trajectories.json'), true, 512, JSON_THROW_ON_ERROR);
if (array_column($backendCases, 'name') !== ['player-outside', 'player-overlap', 'spectator-overlap', 'two-giants']) {
    throw new RuntimeException('The complete native collision world roster is required');
}
$backendVector = static fn (array $bits): array => array_map(static fn (string $value): float => unpack('E', hex2bin($value))[1], $bits);
$backendSnapshot = static fn (array $mob): array => [
    'position_bits' => array_map(static fn (float $value): string => bin2hex(pack('E', $value)), [$mob['x'], $mob['y'], $mob['z']]),
    'velocity_bits' => array_map(static fn (float $value): string => bin2hex(pack('E', $value)), $mob['velocity']),
    'ground' => $mob['ground'],
];
$backendProperty = static fn (string $name): ReflectionProperty => new ReflectionProperty(Server::class, $name);
$backendInvoke = static function (Server $server, string $method, mixed ...$arguments): mixed {
    return (new ReflectionMethod(Server::class, $method))->invoke($server, ...$arguments);
};
$backendChecks = 0;
foreach ($backendCases as $case) {
    $directory = sys_get_temp_dir() . '/php-native-pushing-' . bin2hex(random_bytes(8));
    mkdir($directory);
    $socket = tmpfile();
    $server = null;
    try {
        $version = new Version();
        $server = new Server(new Config(port: 0, worldPath: $directory . '/world.sqlite', generator: 'flat', difficulty: 2, logDirectory: $directory . '/logs'), $version);
        $world = $backendProperty('world')->getValue($server);
        if (!$world instanceof World) {
            throw new RuntimeException('The actual server World is required');
        }
        for ($x = -12; $x <= 12; ++$x) {
            for ($z = -12; $z <= 12; ++$z) {
                $world->set($x, 63, $z, $version->catalog->state('stone'));
            }
        }
        $player = new Client($socket, 'test');
        $player->entityId = 1;
        $player->state = 'play';
        $player->gameMode = $case['mode'] === 'spectator' ? 3 : 1;
        [$player->x, $player->y, $player->z] = $backendVector($case['initial']['player']['position_bits']);
        $backendProperty('clients')->setValue($server, [(int) $socket => $player]);
        $backendProperty('nextEntity')->setValue($server, 2);
        $backendInvoke($server, 'trackPushingPlayer', $player);
        $ids = [];
        foreach ($case['initial']['mobs'] as $initial) {
            $position = $backendVector($initial['position_bits']);
            $id = $backendInvoke($server, 'spawnAnimal', 'giant', ...$position);
            if (!is_int($id)) {
                throw new RuntimeException('The actual server must create the fixture Giant');
            }
            // Match the explicit native summon inputs, including OnGround:1b.
            $animals = $backendProperty('animals')->getValue($server);
            $animals[$id]['velocity'] = $backendVector($initial['velocity_bits']);
            $animals[$id]['ground'] = $initial['ground'];
            $backendProperty('animals')->setValue($server, $animals);
            $ids[] = $id;
        }
        foreach ($case['steps'] as $tick => $frame) {
            $backendProperty('ticks')->setValue($server, $tick + 1);
            $backendInvoke($server, 'tickAnimals');
            $backendInvoke($server, 'tickPlayerPushing', $player);
            $animals = $backendProperty('animals')->getValue($server);
            foreach ($ids as $index => $id) {
                $actual = $backendSnapshot($animals[$id]);
                if ($actual !== $frame['mobs'][$index]) {
                    throw new RuntimeException('Live backend differs from native world: ' . $case['name'] . '/' . ($tick + 1) . '/' . $index
                        . ': ' . json_encode(['actual' => $actual, 'expected' => $frame['mobs'][$index]], JSON_THROW_ON_ERROR));
                }
                $backendChecks += 7;
            }
        }
        // An autosave must retain the completed player contact pass, not the
        // intermediate world-only velocity from earlier in the same tick.
        $backendProperty('ticks')->setValue($server, 200);
        $backendInvoke($server, 'tick', hrtime(true) / 1e9);
        $animals = $backendProperty('animals')->getValue($server);
        $persisted = $world->setting('animals');
        foreach ($ids as $id) {
            if ($backendSnapshot($persisted[$id]) !== $backendSnapshot($animals[$id])) {
                throw new RuntimeException('Autosave captured an intermediate collision tick');
            }
            $backendChecks += 7;
        }
    } finally {
        unset($server, $world, $player, $animals);
        fclose($socket);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $path) {
            if ($path->isDir()) {
                rmdir($path->getPathname());
            } else {
                unlink($path->getPathname());
            }
        }
        rmdir($directory);
    }
}
echo 'PASS: ' . $backendChecks . " exact native world fields through actual server spawn, ticking, eligibility, queries and contact adapters\n";
