<?php
declare(strict_types=1);
if (PHP_VERSION_ID < 80512) {
    throw new RuntimeException('PHP 8.5.12 or newer is required; update to the latest stable security release. Running: '.PHP_VERSION);
}
if (PHP_INT_SIZE !== 8) {
    throw new RuntimeException('A 64-bit PHP runtime is required.');
}
spl_autoload_register(static function (string $class): void {
    $prefix = 'PhpMinecraftJavaServer\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) { require $path; }
    }
});
