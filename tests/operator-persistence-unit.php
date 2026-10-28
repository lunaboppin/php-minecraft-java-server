<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Protocol\Version;
use PhpMinecraftJavaServer\Server\Config;
use PhpMinecraftJavaServer\Server\Server;

// /op and /deop persist immediately, as vanilla writes ops.json, by rewriting only the
// operators of server.json; every other setting and the file's formatting are kept.
$directory = sys_get_temp_dir() . '/php-operator-persistence-' . bin2hex(random_bytes(8));
mkdir($directory);
$operatorChecks = 0;
try {
    $path = $directory . '/server.json';
    $original = ['host' => '127.0.0.1', 'port' => 0, 'motd' => 'Café / "quoted"', 'generator' => 'flat', 'worldPath' => $directory . '/world.sqlite', 'operators' => ['Owner'], 'onlineMode' => true, 'logDirectory' => $directory . '/logs'];
    file_put_contents($path, json_encode($original, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $setOperators = new ReflectionMethod(Server::class, 'setOperators');
    $server = new Server(Config::load($path), new Version(), configPath: $path);
    $setOperators->invoke($server, ['Owner', 'Helper']);
    $saved = file_get_contents($path);
    $decoded = json_decode($saved, true, 512, JSON_THROW_ON_ERROR);
    if ($decoded !== array_replace($original, ['operators' => ['Owner', 'Helper']])) {
        throw new RuntimeException('Only the operators may change, in place');
    }
    if (!str_starts_with($saved, "{\n  \"host\"") || !str_ends_with($saved, "\n") || Config::load($path)->operators !== ['Owner', 'Helper']) {
        throw new RuntimeException('server.json must keep two-space indentation, a trailing newline and load back');
    }
    $operatorChecks += 3;
    $setOperators->invoke($server, array_filter(['Owner', 'Helper'], static fn (string $name): bool => $name !== 'Owner'));
    if (Config::load($path)->operators !== ['Helper'] || file_exists($path . '.tmp')) {
        throw new RuntimeException('Removing an operator must leave a valid list and no temporary file');
    }
    ++$operatorChecks;
    unset($server);

    // Without a configuration file (tests, embedded use) the change is runtime-only.
    $runtime = new Server(new Config(port: 0, generator: 'flat', worldPath: $directory . '/runtime.sqlite', logDirectory: $directory . '/logs'), new Version());
    $setOperators->invoke($runtime, ['Someone']);
    if ((new ReflectionProperty(Server::class, 'config'))->getValue($runtime)->operators !== ['Someone']) {
        throw new RuntimeException('Runtime operators were not applied');
    }
    ++$operatorChecks;
    unset($runtime);
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
echo 'PASS: ' . $operatorChecks . " operator persistence checks for server.json\n";
