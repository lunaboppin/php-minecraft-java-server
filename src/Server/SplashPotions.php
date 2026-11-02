<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * Splash potions (ThrownSplashPotion): where a thrown potion lands, every player within four
 * blocks takes its effects, weaker the further away; a water potion puts out fires around the
 * block it hits. Mobs take its instant effects only, since they carry no other effects yet.
 */
trait SplashPotions
{
    /** PotionContents.getAllEffects: the potion's own effects, then the custom ones. */
    private function potionEffects(array $contents): array
    {
        static $potions = null;
        $potions ??= json_decode((string) file_get_contents($this->version->directory . '/mob-effects.json'), true, 512, JSON_THROW_ON_ERROR)['potions'];
        return [...($contents['potion'] === null ? [] : $potions[$contents['potion']] ?? []), ...$contents['custom_effects']];
    }

    /** PotionContents.getColor: the custom colour, else the visible effects' colours weighted by level, else water's. */
    private function potionColor(array $contents): int
    {
        if ($contents['custom_color'] !== null) {
            return $contents['custom_color'];
        }
        [$red, $green, $blue, $total] = [0, 0, 0, 0];
        foreach ($this->potionEffects($contents) as $effect) {
            if (!$effect['show_particles']) {
                continue;
            }
            $color = $this->effectDefinitions()[$effect['id']]['color'];
            $weight = $effect['amplifier'] + 1;
            [$red, $green, $blue, $total] = [$red + $weight * ($color >> 16 & 255), $green + $weight * ($color >> 8 & 255), $blue + $weight * ($color & 255), $total + $weight];
        }
        if ($total === 0) {
            return -13083194;
        }
        return ((255 << 24) | (intdiv($red, $total) << 16) | (intdiv($green, $total) << 8) | intdiv($blue, $total)) - 0x100000000;
    }

    /**
     * AbstractThrownPotion.onHit and ThrownSplashPotion.onHitAsPotion: a water potion douses fire
     * by the block it hit; players near the splash get the effects scaled by their distance from
     * it, instant ones at once and others for long enough to matter; then the splash is shown.
     */
    private function splashPotion(array $projectile, array $hit, ?Client $owner): void
    {
        $contents = $projectile['stack']['potion'] ?? ['potion' => null, 'custom_color' => null, 'custom_effects' => [], 'custom_name' => null];
        if (isset($hit['block']) && $contents['potion'] === 'minecraft:water') {
            $this->douseAround($hit['block']['position'], $hit['block']['face']);
        }
        $effects = $this->potionEffects($contents);
        [$x, $y, $z] = $hit['location'];
        $potion = Collision::bounds($x, $y, $z, 0.25, 0.25);
        $margin = max(0.0, min(0.3, Float32::round(($projectile['ticks'] - 2) / 20.0)));
        if ($effects !== []) {
            foreach ($this->localPlayers() as $player) {
                if ($player->dead || $player->gameMode === 3) {
                    continue;
                }
                $distance = self::boxDistanceSquared($potion, self::inflate($this->playerBox($player), $margin));
                if ($distance >= 16.0) {
                    continue;
                }
                $scale = 1.0 - sqrt($distance) / 4.0;
                foreach ($effects as $effect) {
                    if ($this->effectDefinitions()[$effect['id']]['instant']) {
                        $this->splashInstant($player, null, $effect, $scale, $owner);
                        continue;
                    }
                    $duration = $effect['duration'] === -1 ? -1 : (int) ($scale * $effect['duration'] + 0.5);
                    // MobEffectInstance.endsWithin(20): too short to bother with.
                    if ($duration === -1 || $duration > 20) {
                        $this->giveEffect($player, $effect['id'], $duration, $effect['amplifier'], $effect['ambient'], $effect['show_particles'], $effect['show_particles']);
                    }
                }
            }
            foreach ($this->animals as $id => $mob) {
                if (isset($mob['deadAt'])) {
                    continue;
                }
                $size = $this->version->catalog->mobs['entities'][$mob['kind']];
                $distance = self::boxDistanceSquared($potion, self::inflate(Collision::bounds($mob['x'], $mob['y'], $mob['z'], $size['width'], $size['height']), $margin));
                if ($distance < 16.0) {
                    foreach ($effects as $effect) {
                        if ($this->effectDefinitions()[$effect['id']]['instant']) {
                            $this->splashInstant(null, $id, $effect, 1.0 - sqrt($distance) / 4.0, $owner);
                        }
                    }
                }
            }
        }
        // Instant potions burst with the instant splash and its sound, others with the plain one.
        $instant = false;
        foreach ($contents['potion'] === null ? [] : $this->potionEffects(['potion' => $contents['potion'], 'custom_effects' => []]) as $effect) {
            $instant = $instant || $this->effectDefinitions()[$effect['id']]['instant'];
        }
        $position = [(int) floor($projectile['x']), (int) floor($projectile['y']), (int) floor($projectile['z'])];
        $this->levelEvent($instant ? 2007 : 2002, $position, $this->potionColor($contents));
        $this->levelEvent($instant ? 1054 : 1053, $position, 0);
    }

    /**
     * InstantenousMobEffect.applyInstantenousEffect with the splash's scale: healing heals and
     * harming hurts by indirect magic, the other way round for the undead.
     */
    private function splashInstant(?Client $player, ?int $mob, array $effect, float $scale, ?Client $owner): void
    {
        $undead = $mob !== null && in_array($this->animals[$mob]['kind'], $this->version->registryTags('minecraft:entity_type')['minecraft:undead'] ?? [], true);
        $heals = ($effect['id'] === 'minecraft:instant_health') !== $undead;
        $amount = (int) ($scale * (($heals ? 4 : 6) << $effect['amplifier']) + 0.5);
        if ($heals) {
            if ($player !== null) {
                $this->heal($player, (float) $amount);
            } elseif (isset($this->animals[$mob])) {
                $info = $this->mobAttributes($this->animals[$mob]);
                $this->animals[$mob]['health'] = min((float) ($info['max_health'] ?? 20.0), $this->animals[$mob]['health'] + $amount);
            }
            return;
        }
        if ($player !== null) {
            $this->damage($player, (float) $amount, 'minecraft:indirect_magic', $owner);
        } else {
            $this->hurtMob($mob, (float) $amount, 'minecraft:indirect_magic', $owner);
        }
    }

    /** AbstractThrownPotion.douseFire around the face hit: fire there, in the block hit, and beside it. */
    private function douseAround(array $block, int $face): void
    {
        $normal = [[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]][$face];
        [$x, $y, $z] = [$block[0] + $normal[0], $block[1] + $normal[1], $block[2] + $normal[2]];
        foreach ([[$x, $y, $z], $block, [$x, $y, $z - 1], [$x, $y, $z + 1], [$x - 1, $y, $z], [$x + 1, $y, $z]] as $position) {
            if (in_array($this->version->catalog->blockName($this->world->get(...$position)), ['fire', 'soul_fire'], true)) {
                $this->setBlock(...[...$position, $this->version->catalog->state('air')]);
            }
        }
    }

    /** AABB.distanceToSqr: the squared gap between two boxes on each axis, none where they overlap. */
    private static function boxDistanceSquared(array $a, array $b): float
    {
        $sum = 0.0;
        for ($axis = 0; $axis < 3; ++$axis) {
            $gap = max(0.0, $a[$axis] - $b[$axis + 3], $b[$axis] - $a[$axis + 3]);
            $sum += $gap * $gap;
        }
        return $sum;
    }
}
