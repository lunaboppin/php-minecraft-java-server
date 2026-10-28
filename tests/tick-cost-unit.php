<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\Server\Client;
use PhpMinecraftJavaServer\Server\Config;
use PhpMinecraftJavaServer\Server\Server;
use PhpMinecraftJavaServer\World\World;

// A redstone clock left running in an unvisited dimension cost about 65 ms per tick on the
// live world: every block change waited for an fsync, and every changed chunk was relit
// even though no player could receive the light update.
$tickCostProperty = static fn (string $class, string $name): ReflectionProperty => new ReflectionProperty($class, $name);
$tickCostInvoke = static fn (Server $server, string $method, mixed ...$arguments): mixed => (new ReflectionMethod(Server::class, $method))->invoke($server, ...$arguments);
$directory = sys_get_temp_dir() . '/php-tick-cost-' . bin2hex(random_bytes(8));
mkdir($directory);
$socket = tmpfile();
$checks = 0;
try {
    $version = new Version();
    $server = new Server(new Config(port: 0, worldPath: $directory . '/world.sqlite', generator: 'terrain', seed: 12345, difficulty: 2, logDirectory: $directory . '/logs'), $version);
    $world = $tickCostProperty(Server::class, 'world')->getValue($server);
    if (!$world instanceof World) {
        throw new RuntimeException('The actual server World is required');
    }

    // WAL commits reach the operating system before returning, so a process crash loses no
    // edit; only an OS crash or power loss can drop the latest commits.
    $database = $tickCostProperty(World::class, 'db')->getValue($world);
    if ((int) $database->query('PRAGMA synchronous')->fetchColumn() !== 1 || $database->query('PRAGMA journal_mode')->fetchColumn() !== 'wal') {
        throw new RuntimeException('World storage must use WAL with synchronous=NORMAL');
    }
    ++$checks;

    $cache = $tickCostProperty(World::class, 'cache');
    $stone = $version->catalog->state('stone');
    $tickCostInvoke($server, 'setBlock', 4000, 100, 4000, $stone);
    $tickCostInvoke($server, 'tick', hrtime(true) / 1e9);
    if ($tickCostProperty(Server::class, 'pendingLight')->getValue($server) !== [] || isset($cache->getValue($world)['250:250'])) {
        throw new RuntimeException('A changed chunk without viewers was relit');
    }
    if ($world->get(4000, 100, 4000) !== $stone) {
        throw new RuntimeException('The block change was not stored');
    }
    $checks += 2;

    // A player with the chunk loaded still receives the light update.
    $player = new Client($socket, 'test');
    $player->entityId = 1;
    $player->state = 'play';
    [$player->x, $player->y, $player->z] = [4000.5, 120.0, 4000.5];
    $player->loadedChunks['250:250'] = [250, 250];
    $tickCostProperty(Server::class, 'clients')->setValue($server, [(int) $socket => $player]);
    $tickCostInvoke($server, 'setBlock', 4001, 100, 4000, $stone);
    $player->output = '';
    $tickCostInvoke($server, 'tick', hrtime(true) / 1e9);
    $light = Binary::writeVarInt($version->id('play', 'light_update')) . Binary::writeVarInt(250) . Binary::writeVarInt(250) . $world->chunkLight(250, 250);
    if (!str_contains($player->output, $light)) {
        throw new RuntimeException('A viewer did not receive the relit chunk');
    }
    ++$checks;
} finally {
    unset($server, $world, $database, $player);
    fclose($socket);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $path) {
        $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    }
    rmdir($directory);
}
echo 'PASS: ' . $checks . " tick cost checks for durable WAL commits and viewer-only relighting\n";
