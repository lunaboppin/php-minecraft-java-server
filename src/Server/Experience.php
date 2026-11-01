<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;

/**
 * Player experience as vanilla keeps it: a level, the float progress toward the next level and
 * the total points ever collected (Player.giveExperiencePoints and giveExperienceLevels).
 */
trait Experience
{
    /** Player.getXpNeededForNextLevel */
    private static function xpNeededForNextLevel(int $level): int
    {
        return $level >= 30 ? 112 + ($level - 30) * 9 : ($level >= 15 ? 37 + ($level - 15) * 5 : 7 + $level * 2);
    }

    private static function f32(float $value): float
    {
        return unpack('G', pack('G', $value))[1];
    }

    /** Player.giveExperiencePoints: progress in floats, levels gained or lost on the way. */
    private function giveExperiencePoints(Client $c, int $points): void
    {
        // Player.increaseScore wraps as Java ints do.
        $c->score = (($c->score + $points + 2147483648) & 0xFFFFFFFF) - 2147483648;
        $c->experienceProgress = self::f32($c->experienceProgress + self::f32($points / self::xpNeededForNextLevel($c->experienceLevel)));
        $c->experience = max(0, min(2147483647, $c->experience + $points));
        while ($c->experienceProgress < 0.0) {
            $remaining = self::f32($c->experienceProgress * self::xpNeededForNextLevel($c->experienceLevel));
            if ($c->experienceLevel > 0) {
                $this->giveExperienceLevels($c, -1);
                $c->experienceProgress = self::f32(1.0 + self::f32($remaining / self::xpNeededForNextLevel($c->experienceLevel)));
            } else {
                $this->giveExperienceLevels($c, -1);
                $c->experienceProgress = 0.0;
            }
        }
        while ($c->experienceProgress >= 1.0) {
            $c->experienceProgress = self::f32(self::f32($c->experienceProgress - 1.0) * self::xpNeededForNextLevel($c->experienceLevel));
            $this->giveExperienceLevels($c, 1);
            $c->experienceProgress = self::f32($c->experienceProgress / self::xpNeededForNextLevel($c->experienceLevel));
        }
        $this->syncExperience($c);
    }

    /** Player.giveExperienceLevels: a negative level resets everything; every fifth level plays the level-up sound. */
    private function giveExperienceLevels(Client $c, int $levels): void
    {
        $c->experienceLevel = (int) max(-2147483648, min(2147483647, $c->experienceLevel + $levels));
        if ($c->experienceLevel < 0) {
            $c->experienceLevel = 0;
            $c->experienceProgress = 0.0;
            $c->experience = 0;
        }
        if ($levels > 0 && $c->experienceLevel % 5 === 0 && $c->lastLevelUpTick < $this->ticks - 100) {
            $volume = $c->experienceLevel > 30 ? 1.0 : self::f32($c->experienceLevel / 30.0);
            $payload = Packets::sound($this->version->blocks['sounds']['entity.player.levelup'], 7, $c->x, $c->y, $c->z, self::f32($volume * 0.75), 1.0, unpack('J', random_bytes(8))[1]);
            foreach ($this->localPlayers() as $listener) {
                if ($listener->dimension === $c->dimension && ($listener->x - $c->x) ** 2 + ($listener->y - $c->y) ** 2 + ($listener->z - $c->z) ** 2 < (16 * max(1.0, $volume * 0.75)) ** 2) {
                    $listener->send($this->version, 'sound', $payload);
                }
            }
            $c->lastLevelUpTick = $this->ticks;
        }
        $this->syncExperience($c);
    }

    /** ServerPlayer.setExperiencePoints: the progress within the current level, kept below a full level. */
    private function setExperiencePoints(Client $c, int $points): void
    {
        $needed = (float) self::xpNeededForNextLevel($c->experienceLevel);
        $c->experienceProgress = max(0.0, min(self::f32(($needed - 1.0) / $needed), self::f32($points / $needed)));
        $this->syncExperience($c);
    }

    private function setExperienceLevels(Client $c, int $level): void
    {
        $c->experienceLevel = $level;
        $this->syncExperience($c);
    }

    /** The progress as points, as /experience query reports it. */
    private function experiencePoints(Client $c): int
    {
        return (int) floor(self::f32($c->experienceProgress * self::xpNeededForNextLevel($c->experienceLevel)));
    }

    private function syncExperience(Client $c): void
    {
        $c->send($this->version, 'set_experience', pack('G', $c->experienceProgress) . Binary::writeVarInt($c->experienceLevel) . Binary::writeVarInt($c->experience));
    }

    /** Older saves kept only the total; the level and progress follow from it. */
    private static function experienceFromTotal(int $total): array
    {
        $level = 0;
        while ($total >= self::xpNeededForNextLevel($level)) {
            $total -= self::xpNeededForNextLevel($level);
            ++$level;
        }
        return [$level, self::f32($total / self::xpNeededForNextLevel($level))];
    }
}
