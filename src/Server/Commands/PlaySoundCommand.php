<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Server\Client;

trait PlaySoundCommand
{
    /** SoundSource names and ids. */
    private const SOUND_SOURCES = ['master' => 0, 'music' => 1, 'record' => 2, 'weather' => 3, 'block' => 4, 'hostile' => 5, 'neutral' => 6, 'player' => 7, 'ambient' => 8, 'voice' => 9, 'ui' => 10];

    /**
     * PlaySoundCommand: the sound plays for each target in range; one out of range hears it
     * two blocks away at the minimum volume, or not at all without one.
     */
    private function executePlaySound(Client $sender, array $arguments): void
    {
        $sound = str_contains($arguments[0], ':') ? $arguments[0] : 'minecraft:' . $arguments[0];
        $source = $arguments[1] ?? 'master';
        $targets = $this->selectPlayers($arguments[2] ?? '@s', $sender);
        $position = isset($arguments[5]) ? $this->positionArguments(array_slice($arguments, 3, 3), $sender) : [$sender->x, $sender->y, $sender->z];
        $f32 = static fn (float $value): float => unpack('G', pack('G', $value))[1];
        $volume = $f32((float) ($arguments[6] ?? 1.0));
        $pitch = $f32((float) ($arguments[7] ?? 1.0));
        $minimum = $f32((float) ($arguments[8] ?? 0.0));
        $range = ($volume > 1.0 ? $f32($volume * 16.0) : 16.0) ** 2;
        $seed = unpack('J', random_bytes(8))[1];
        $played = 0;
        foreach ($targets as $target) {
            [$x, $y, $z] = $position;
            $loudness = $volume;
            $distance = ($x - $target->x) ** 2 + ($y - $target->y) ** 2 + ($z - $target->z) ** 2;
            if ($distance > $range) {
                if ($minimum <= 0.0) {
                    continue;
                }
                $length = sqrt($distance);
                [$x, $y, $z] = [$target->x + ($x - $target->x) / $length * 2.0, $target->y + ($y - $target->y) / $length * 2.0, $target->z + ($z - $target->z) / $length * 2.0];
                $loudness = $minimum;
            }
            // A direct sound event (by name), as the command does not look sounds up.
            $target->send($this->version, 'sound', Binary::writeVarInt(0) . Binary::writeString($sound) . "\0" . Binary::writeVarInt(self::SOUND_SOURCES[$source])
                . pack('NNNGGJ', ((int) ($x * 8)) & 0xffffffff, ((int) ($y * 8)) & 0xffffffff, ((int) ($z * 8)) & 0xffffffff, $loudness, $pitch, $seed));
            ++$played;
        }
        if ($played === 0) {
            throw new CommandFailure(['translate' => 'commands.playsound.failed']);
        }
        $this->sendSuccess($sender, count($targets) === 1
            ? ['translate' => 'commands.playsound.success.single', 'with' => [['text' => $sound], $this->displayName($targets[0])]]
            : ['translate' => 'commands.playsound.success.multiple', 'with' => [['text' => $sound], count($targets)]], true);
    }
}
