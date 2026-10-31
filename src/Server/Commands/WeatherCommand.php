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
        // WeatherCommand: the weather lasts the given ticks or a random span, and the levels then
        // fade towards it with the weather cycle.
        $ticks = isset($arguments[1]) ? (int) round((float) $match[1] * ['' => 1, 't' => 1, 's' => 20, 'd' => 24000][$match[2]]) : null;
        match ($weather) {
            'clear' => $this->setWeatherParameters($ticks ?? random_int(12000, 180000), 0, false, false),
            'rain' => $this->setWeatherParameters(0, $ticks ?? random_int(12000, 24000), true, false),
            'thunder' => $this->setWeatherParameters(0, $ticks ?? random_int(3600, 15600), true, true),
        };
        $this->sendSuccess($sender, ['translate' => 'commands.weather.set.' . $weather], true);
    }
}
