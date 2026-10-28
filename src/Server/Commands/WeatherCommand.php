<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait WeatherCommand
{
    private function executeWeather(Client $sender, array $arguments): void
    {
        $weather = $arguments[0] ?? '';
        if (!in_array($weather, ['clear', 'rain', 'thunder'], true) || count($arguments) > 2) {
            throw new \InvalidArgumentException('Usage: /weather clear|rain|thunder [duration]');
        }
        // TimeArgument: a positive number of ticks, or of seconds (s) or days (d).
        if (isset($arguments[1]) && (preg_match('/\A(\d+(?:\.\d+)?|\.\d+)([dst]?)\z/D', $arguments[1], $match) !== 1
            || (int) round((float) $match[1] * ['' => 1, 't' => 1, 's' => 20, 'd' => 24000][$match[2]]) < 1)) {
            throw new \InvalidArgumentException('Invalid duration: ' . $arguments[1]);
        }
        // There is no natural weather cycle yet, so the weather persists until changed.
        $this->weather = $weather;
        foreach ($this->players() as $player) {
            $this->sendWeather($player);
        }
        $this->sendSuccess($sender, ['translate' => 'commands.weather.set.' . $weather], true);
    }
}
