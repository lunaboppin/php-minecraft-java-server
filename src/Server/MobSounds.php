<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\MobAudio;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;

/** Default adult primary damage voices. Special states and ambient/step sounds remain in #88. */
trait MobSounds
{
    use MobRandomness;

    private array $mobVariantRandom = [];

    private function chooseMobSoundVariant(string $kind): ?string
    {
        $random = $this->mobVariantRandom[$this->world->dimension] ??= new LegacyRandom(unpack('J', random_bytes(8))[1]);
        return MobAudio::variant($this->version->catalog->mobSounds['mobs'][$kind], $random);
    }

    private function mobDamageSound(array $mob, bool $death): void
    {
        // The dragon has a separate damage/death lifecycle, not LivingEntity's
        // ordinary primary-sound path. Its fight remains tracked in #52/#88.
        if ($mob['kind'] === 'ender_dragon' || ($mob['silent'] ?? false)) {
            return;
        }
        $info = $this->version->catalog->mobSounds['mobs'][$mob['kind']];
        $voice = $info['variants'][$mob['soundVariant'] ?? 'minecraft:classic'] ?? $info;
        $sound = $voice[$death ? 'death' : 'hurt'];
        if ($sound === null) {
            return;
        }
        $random = $this->mobRandom($mob['id']);
        $pitch = MobAudio::pitch($random, $info['pitch_method'], $mob['baby'] ?? false);
        $radius = MobAudio::range($sound, $info['volume']);
        $payload = Packets::sound(
            $sound['id'],
            $info['category'],
            $mob['x'],
            $mob['y'],
            $mob['z'],
            $info['volume'],
            $pitch,
            unpack('J', random_bytes(8))[1]
        );
        // Java's level broadcast uses sound range and dimension, independently
        // of whether the receiving player currently tracks the emitting entity.
        foreach ($this->localPlayers() as $viewer) {
            if (($viewer->x - $mob['x']) ** 2 + ($viewer->y - $mob['y']) ** 2 + ($viewer->z - $mob['z']) ** 2 < $radius ** 2) {
                $viewer->send($this->version, 'sound', $payload);
            }
        }
    }
}
