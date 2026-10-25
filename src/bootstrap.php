<?php
declare(strict_types=1);
if (PHP_INT_SIZE !== 8) {
    throw new RuntimeException('A 64-bit PHP runtime is required.');
}
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Jva\\')) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) { require $path; }
    }
});
