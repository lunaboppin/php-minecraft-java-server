<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;

final class Config
{
    public function __construct(
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 25565,
        public readonly string $motd = 'PHP Minecraft Java Server 26.3 — terrain and survival',
        public readonly int $maxPlayers = 20,
        public readonly int $viewDistance = 2,
        public readonly int $compressionThreshold = 256,
        public readonly string $worldPath = __DIR__ . '/../../data/world-terrain.sqlite',
        public readonly string $generator = 'terrain',
        public readonly int $seed = 12345,
        public readonly int $defaultGameMode = 0,
        public readonly int $difficulty = 2,
        public readonly array $operators = [],
        public readonly bool $pvp = true,
        public readonly string $logDirectory = 'logs',
        public readonly int $logRetentionDays = 90,
    ) {
        if($logDirectory==='' || $logRetentionDays<1 || $logRetentionDays>90){throw new \InvalidArgumentException('Log retention must be between 1 and 90 days');}
        if (!in_array($generator,['flat','terrain'],true) || $seed < -2147483648 || $seed > 2147483647 || $defaultGameMode<0 || $defaultGameMode>3 || $difficulty<0 || $difficulty>3) { throw new \InvalidArgumentException('Invalid world settings'); }
        foreach($operators as $operator){if(!is_string($operator) || preg_match('/\A[A-Za-z0-9_]{1,16}\z/D',$operator)!==1){throw new \InvalidArgumentException('Invalid operator username');}}
        if (filter_var($host,FILTER_VALIDATE_IP) === false || $port < 0 || $port > 65535 || $maxPlayers < 1 || $maxPlayers > 100 || $viewDistance < 2 || $viewDistance > 4 || $compressionThreshold < -1 || $compressionThreshold > 1048576 || strlen($motd) > 4096 || preg_match('//u',$motd) !== 1 || $worldPath === '') { throw new \InvalidArgumentException('Invalid server configuration'); }
    }
    public static function load(?string $path, array $overrides = []): self {
        $values = [];
        if ($path !== null) {
            $bytes = @file_get_contents($path);
            if ($bytes === false) { throw new \RuntimeException('Cannot read configuration: ' . $path); }
            $object = json_decode($bytes,false,512,JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) { throw new \InvalidArgumentException('Configuration must be a JSON object'); }
            $values = get_object_vars($object);
        }
        $allowed = ['host','port','motd','maxPlayers','viewDistance','compressionThreshold','worldPath','generator','seed','defaultGameMode','difficulty','operators','pvp','logDirectory','logRetentionDays'];
        foreach ($values as $key => $_) { if (!in_array($key,$allowed,true)) { throw new \InvalidArgumentException('Unknown configuration option: ' . $key); } }
        return new self(...array_replace($values,$overrides));
    }
}
