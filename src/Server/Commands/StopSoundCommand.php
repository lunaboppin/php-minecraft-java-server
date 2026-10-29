<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Server\Client;

trait StopSoundCommand
{
    /** StopSoundCommand: a source ("*" for any) and an optional sound. */
    private function executeStopSound(Client $sender, array $arguments): void
    {
        $targets = $this->selectPlayers($arguments[0] ?? '', $sender);
        $source = isset($arguments[1]) && $arguments[1] !== '*' ? $arguments[1] : null;
        $sound = isset($arguments[2]) ? (str_contains($arguments[2], ':') ? $arguments[2] : 'minecraft:' . $arguments[2]) : null;
        // ClientboundStopSoundPacket: bit 0 marks a source, bit 1 a sound.
        $payload = chr(($source !== null ? 1 : 0) | ($sound !== null ? 2 : 0))
            . ($source !== null ? Binary::writeVarInt(self::SOUND_SOURCES[$source]) : '')
            . ($sound !== null ? Binary::writeString($sound) : '');
        foreach ($targets as $target) {
            $target->send($this->version, 'stop_sound', $payload);
        }
        $this->sendSuccess($sender, match (true) {
            $source === null && $sound === null => ['translate' => 'commands.stopsound.success.sourceless.any'],
            $sound === null => ['translate' => 'commands.stopsound.success.source.any', 'with' => [['text' => $source]]],
            $source === null => ['translate' => 'commands.stopsound.success.sourceless.sound', 'with' => [['text' => $sound]]],
            default => ['translate' => 'commands.stopsound.success.source.sound', 'with' => [['text' => $sound], ['text' => $source]]],
        }, true);
    }
}
