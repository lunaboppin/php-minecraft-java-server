<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait WeatherCommand
{
    private function executeWeather(Client $sender, array $arguments): void
    {
        $weather = $arguments[0] ?? '';
        if (!in_array($weather, ['clear', 'rain', 'thunder'], true)) {
            throw new \InvalidArgumentException('Usage: /weather clear|rain|thunder');
        }
        $this->weather = $weather;
        foreach ($this->players() as $p) {
            $this->sendWeather($p);
        }
        $this->tell($sender, 'Weather changed to ' . $weather);
        return;
    }
}
