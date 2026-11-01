<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;

/**
 * Burning mobs, as Entity.baseTick, Mob.burnUndead and Entity.applyEffectsFromBlocks keep it: a
 * fire countdown that hurts every 20 ticks out of lava, the on-fire flag clients draw flames for,
 * the burn_in_daylight types catching fire under the open sky while monsters_burn holds (a
 * head item takes the sun instead), and water and rain putting fire out.
 */
trait MobFire
{
    /** Entity.baseTick's fire countdown, then the shared on-fire flag. */
    private function tickMobFire(int $id, array &$mob): void
    {
        $fire = $mob['fire'] ?? 0;
        if ($fire > 0) {
            if ($this->version->catalog->mobs['entities'][$mob['kind']]['fire_immune'] ?? false) {
                $fire = 0;
            } else {
                $mob['fire'] = $fire - 1;
                if ($fire % 20 === 0 && !$this->mobInFluid($mob, 'lava')) {
                    $this->hurtMob($id, 1.0, 'minecraft:on_fire');
                }
                $fire = $mob['fire'] ?? 0;
            }
        }
        $mob['fire'] = $fire;
        $this->syncMobFire($mob);
    }

    /** Entity.setSharedFlagOnFire: the flags byte's first bit, sent when it changes. */
    private function syncMobFire(array &$mob): void
    {
        $burning = ($mob['fire'] ?? 0) > 0;
        if ($burning === ($mob['onFire'] ?? false)) {
            return;
        }
        $mob['onFire'] = $burning;
        foreach ($this->localPlayers() as $player) {
            if (isset($player->entities[$mob['id']])) {
                $player->send($this->version, 'set_entity_data', Binary::writeVarInt($mob['id']) . "\0" . Binary::writeVarInt(0) . ($burning ? "\1" : "\0") . "\xff");
            }
        }
    }

    /**
     * Mob.burnUndead in aiStep, then applyEffectsFromBlocks: rain or water puts the fire out, and
     * a mob neither burning nor just lit has none left (Entity.getFireImmuneTicks is 0).
     */
    private function burnAndExtinguishMob(array &$mob): void
    {
        $before = $mob['fire'] ?? 0;
        if (in_array($mob['kind'], $this->version->registryTags('minecraft:entity_type')['minecraft:burn_in_daylight'] ?? [], true) && $this->sunBurnTick($mob)) {
            if (isset($mob['equipment'][5])) {
                // ItemStack.hurtAndBreak(random.nextInt(2)) on the head item.
                $this->wearMobEquipment($mob, 5, random_int(0, 1));
            } elseif (($mob['fire'] ?? 0) < 160) {
                // Entity.igniteForSeconds(8)
                $mob['fire'] = 160;
            }
        }
        [$x, $y, $z] = [(int) floor($mob['x']), (int) floor($mob['y']), (int) floor($mob['z'])];
        $height = $this->version->catalog->mobs['entities'][$mob['kind']]['height'] ?? 1.8;
        if ($this->mobInFluid($mob, 'water') || $this->rainingAt($x, $y, $z) || $this->rainingAt($x, (int) floor($mob['y'] + $height), $z)) {
            $mob['fire'] = min(0, $mob['fire'] ?? 0);
        }
        if (($mob['fire'] ?? 0) <= 0 && ($mob['fire'] ?? 0) <= $before) {
            $mob['fire'] = 0;
        }
    }

    /** Mob.isSunBurnTick: daylight bright enough at the eyes, not wet, under the open sky, by chance. */
    private function sunBurnTick(array $mob): bool
    {
        // The day timeline's gameplay/monsters_burn: from 23460 to 12542.
        $time = (($this->dayTime % 24000) + 24000) % 24000;
        if ($this->world->dimension !== 'overworld' || ($time >= 12542 && $time < 23460)) {
            return false;
        }
        $size = $this->version->catalog->mobs['entities'][$mob['kind']];
        [$x, $eye, $z] = [(int) floor($mob['x']), (int) floor($mob['y'] + $size['eye_height'] * (($mob['baby'] ?? false) ? 0.5 : 1.0)), (int) floor($mob['z'])];
        // LevelReader.getLightLevelDependentMagicValue, with the overworld's ambient light of 0.
        $f = static fn (float $value): float => unpack('g', pack('g', $value))[1];
        $light = $f($this->world->rawBrightness($x, $eye, $z, false, $this->skyDarken()) / 15.0);
        $brightness = $f($light / $f(4.0 - 3.0 * $light));
        if ($brightness <= 0.5 || $f(self::randomUnit() * 30.0) >= $f(($brightness - 0.4) * 2.0)) {
            return false;
        }
        $height = $size['height'] * (($mob['baby'] ?? false) ? 0.5 : 1.0);
        if ($this->mobInFluid($mob, 'water') || $this->rainingAt($x, (int) floor($mob['y']), $z) || $this->rainingAt($x, (int) floor($mob['y'] + $height), $z)) {
            return false;
        }
        return $this->world->skyLight($x, $eye, $z) >= 15;
    }

    /** ItemStack.hurtAndBreak for a mob's item: worn by the amount, gone once fully worn. */
    private function wearMobEquipment(array &$mob, int $slot, int $amount): void
    {
        $stack = $mob['equipment'][$slot] ?? null;
        $durability = $stack === null ? 0 : ($this->version->catalog->item($stack['item'])['durability'] ?? 0);
        if ($amount <= 0 || $durability <= 0) {
            return;
        }
        $stack['damage'] = ($stack['damage'] ?? 0) + $amount;
        if ($stack['damage'] >= $durability) {
            unset($mob['equipment'][$slot]);
        } else {
            $mob['equipment'][$slot] = $stack;
        }
        $mob['equipmentChanged'][$slot] = true;
    }

    /** Whether the mob's feet are in the fluid. */
    private function mobInFluid(array $mob, string $fluid): bool
    {
        return $this->version->catalog->blockName($this->world->get((int) floor($mob['x']), (int) floor($mob['y']), (int) floor($mob['z']))) === $fluid;
    }
}
