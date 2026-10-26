<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait TimeCommand
{
    private function executeTime(Client $sender, array $arguments): void
    {
        if (($arguments[0] ?? '') === 'query') {
            $this->tell($sender, 'Daytime: ' . ($this->dayTime % 24000));
            return;
        }
        if (!in_array($arguments[0] ?? '', ['set', 'add'], true) || !isset($arguments[1])) {
            throw new \InvalidArgumentException('Usage: /time set day|noon|night|midnight|ticks OR /time add ticks');
        }
        $value = match($arguments[1]) {
            'day' => 1000,'noon' => 6000,'night' => 13000,'midnight' => 18000,default => filter_var($arguments[1], FILTER_VALIDATE_INT)
        };
        if ($value === false || $value < 0 || $value > 2147483647) {
            throw new \InvalidArgumentException('Invalid time');
        }
        $this->dayTime = $arguments[0] === 'add' ? $this->dayTime + $value : $value;
        foreach ($this->players() as $p) {
            $this->sendClock($p);
        }
        $this->tell($sender, 'Time set to ' . $this->dayTime);
        return;
    }
}
