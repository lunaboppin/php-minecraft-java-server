<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * ServerLevel.advanceWeatherCycle: clear weather, rain and thunder last random spans of ticks
 * while advance_weather is on, and the rain and thunder levels fade by 0.01 a tick towards them,
 * with each change sent to the Overworld's players.
 */
trait WeatherCycle
{
    /** @var array{clear: int, rain: int, thunder: int, raining: bool, thundering: bool} WeatherData */
    private array $weatherData = ['clear' => 0, 'rain' => 0, 'thunder' => 0, 'raining' => false, 'thundering' => false];

    private float $rainLevel = 0.0;

    private float $thunderLevel = 0.0;

    /** A world saved before the weather cycle keeps its weather at full strength. */
    private function restoreWeather(?array $data): void
    {
        if ($data !== null) {
            $this->weatherData = $data + $this->weatherData;
            return;
        }
        $this->weatherData['raining'] = $this->weather !== 'clear';
        $this->weatherData['thundering'] = $this->weather === 'thunder';
        $this->rainLevel = $this->weatherData['raining'] ? 1.0 : 0.0;
        $this->thunderLevel = $this->weatherData['thundering'] ? 1.0 : 0.0;
    }

    /** Level.isRaining: the rain level above 0.2. */
    private function raining(): bool
    {
        return $this->rainLevel > 0.2;
    }

    /** Level.getThunderLevel: thunder scaled by the rain. */
    private function thunderStrength(): float
    {
        return Float32::round($this->thunderLevel * $this->rainLevel);
    }

    /** ServerLevel.setWeatherParameters, as /weather and waking from sleep set it. */
    private function setWeatherParameters(int $clear, int $time, bool $raining, bool $thundering): void
    {
        $this->weatherData = ['clear' => $clear, 'rain' => $time, 'thunder' => $time, 'raining' => $raining, 'thundering' => $thundering];
        $this->weather = $thundering ? 'thunder' : ($raining ? 'rain' : 'clear');
    }

    /** ServerLevel.resetWeatherCycle */
    private function resetWeatherCycle(): void
    {
        $this->weatherData['rain'] = 0;
        $this->weatherData['raining'] = false;
        $this->weatherData['thunder'] = 0;
        $this->weatherData['thundering'] = false;
        $this->weather = 'clear';
    }

    private function tickWeather(): void
    {
        if ($this->world->dimension !== 'overworld') {
            return;
        }
        $wasRaining = $this->raining();
        $data = &$this->weatherData;
        if ($this->gameRule('advance_weather') === 'true') {
            if ($data['clear'] > 0) {
                --$data['clear'];
                $data['thunder'] = $data['thundering'] ? 0 : 1;
                $data['rain'] = $data['raining'] ? 0 : 1;
                $data['thundering'] = false;
                $data['raining'] = false;
            } else {
                if ($data['thunder'] > 0) {
                    if (--$data['thunder'] === 0) {
                        $data['thundering'] = !$data['thundering'];
                    }
                } else {
                    // THUNDER_DURATION and THUNDER_DELAY
                    $data['thunder'] = $data['thundering'] ? random_int(3600, 15600) : random_int(12000, 180000);
                }
                if ($data['rain'] > 0) {
                    if (--$data['rain'] === 0) {
                        $data['raining'] = !$data['raining'];
                    }
                } else {
                    // RAIN_DURATION and RAIN_DELAY
                    $data['rain'] = $data['raining'] ? random_int(12000, 24000) : random_int(12000, 180000);
                }
            }
            $this->weather = $data['thundering'] ? 'thunder' : ($data['raining'] ? 'rain' : 'clear');
        }
        $oldThunder = $this->thunderLevel;
        $this->thunderLevel = max(0.0, min(1.0, Float32::round($this->thunderLevel + ($data['thundering'] ? 0.01 : -0.01))));
        $oldRain = $this->rainLevel;
        $this->rainLevel = max(0.0, min(1.0, Float32::round($this->rainLevel + ($data['raining'] ? 0.01 : -0.01))));
        unset($data);
        $send = function (string $event, float $value, bool $everywhere = false): void {
            foreach ($this->players() as $player) {
                if ($everywhere || $player->dimension === 'overworld') {
                    $player->send($this->version, 'game_event', $event . pack('G', $value));
                }
            }
        };
        if ($oldRain !== $this->rainLevel) {
            $send("\7", $this->rainLevel);
        }
        if ($oldThunder !== $this->thunderLevel) {
            $send("\x08", $this->thunderLevel);
        }
        if ($wasRaining !== $this->raining()) {
            // The start and end of rain, and then both levels, reach players in every dimension.
            $send($wasRaining ? "\2" : "\1", 0.0, true);
            $send("\7", $this->rainLevel, true);
            $send("\x08", $this->thunderLevel, true);
        }
    }
}
