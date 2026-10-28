#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use PhpMinecraftJavaServer\Server\{Config,Server};
$options=getopt('', ['config:','host:','port:','max-ticks:','help']);
if (isset($options['help'])) {
    fwrite(STDOUT,"Usage: php bin/server.php [--config=server.json] [--host=127.0.0.1] [--port=25565]\n");exit(0);
}
try {
    $overrides=[];
    if (isset($options['host'])) { $overrides['host']=$options['host']; }
    foreach (['port','max-ticks'] as $key) {
        if (isset($options[$key]) && (filter_var($options[$key],FILTER_VALIDATE_INT)===false || (int)$options[$key]<0)) { throw new InvalidArgumentException('Invalid --'.$key); }
    }
    if (isset($options['port'])) { $overrides['port']=(int)$options['port']; }
    $config=Config::load($options['config']??null,$overrides);
    (new Server($config, configPath: $options['config'] ?? null))->run(isset($options['max-ticks'])?(int)$options['max-ticks']:null);
} catch (Throwable $e) {
    fwrite(STDERR,'Server error: '.$e->getMessage().PHP_EOL);exit(1);
}
