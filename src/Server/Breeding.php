<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;

/**
 * AgeableMob and Animal: babies grow up over 24000 ticks, parents rest for 6000, and an adult
 * fed its food falls in love; two in love find each other (BreedGoal) and have a baby, with a
 * little experience.
 */
trait Breeding
{
    /** Animals that breed by being fed, with the food tag Animal.isFood reads. */
    private const BREEDING_FOOD = ['cow' => 'cow_food', 'mooshroom' => 'cow_food', 'pig' => 'pig_food', 'sheep' => 'sheep_food', 'chicken' => 'chicken_food',
        'rabbit' => 'rabbit_food', 'goat' => 'goat_food', 'hoglin' => 'hoglin_food', 'strider' => 'strider_food', 'armadillo' => 'armadillo_food', 'sniffer' => 'sniffer_food'];

    /** AgeableMob.getAge: below zero a baby, above zero an adult that cannot breed yet. */
    private function mobAge(array $mob): int
    {
        return $mob['age'] ?? (($mob['baby'] ?? false) ? -24000 : 0);
    }

    /** AgeableMob.setAge: crossing zero changes DATA_BABY_ID, which trackers see. */
    private function setMobAge(array &$mob, int $age): void
    {
        $baby = $age < 0;
        $mob['age'] = $age;
        if ($baby === ($mob['baby'] ?? false)) {
            return;
        }
        $mob['baby'] = $baby;
        $packet = Binary::writeVarInt($mob['id']) . "\x10" . Binary::writeVarInt(8) . ($baby ? "\1" : "\0") . "\xff";
        foreach ($this->localPlayers() as $player) {
            if (isset($player->entities[$mob['id']])) {
                $player->send($this->version, 'set_entity_data', $packet);
            }
        }
    }

    /**
     * Animal.mobInteract with its food: an adult that may breed falls in love, a baby grows a tenth
     * of the way faster; either way one is used and the hand swings.
     */
    private function feedAnimal(Client $player, int $id, int $hand): bool
    {
        $mob = &$this->animals[$id];
        $tag = self::BREEDING_FOOD[$mob['kind']] ?? null;
        $slot = $hand === 0 ? 36 + $player->heldSlot : 45;
        $held = $player->inventory[$slot] ?? null;
        if ($tag === null || $held === null || !in_array($this->version->catalog->itemName($held['item']), $this->version->registryTags('minecraft:item')['minecraft:' . $tag] ?? [], true)) {
            return false;
        }
        $age = $this->mobAge($mob);
        if ($age === 0 && ($mob['inLove'] ?? 0) <= 0) {
            // Animal.setInLove: 600 ticks, remembering who fed it, with hearts for trackers.
            $mob['inLove'] = 600;
            $mob['loveCause'] = bin2hex($player->uuid);
            $this->mobEvent($id, 18);
        } elseif ($age < 0) {
            // AgeableMob.ageUp by getSpeedUpSecondsWhenFeeding, a tenth of the whole seconds left; the
            // growth is also kept as ForcedAge, which a baby that grows up this way starts adult life with.
            $grown = min(0, $age + (int) (intdiv(-$age, 20) * 0.1) * 20);
            $mob['forcedAge'] = ($mob['forcedAge'] ?? 0) + $grown - $age;
            $this->setMobAge($mob, $grown);
            if ($grown === 0) {
                $this->setMobAge($mob, $mob['forcedAge']);
            }
        } else {
            return false;
        }
        if ($player->gameMode !== 1) {
            if (--$player->inventory[$slot]['count'] <= 0) {
                unset($player->inventory[$slot]);
            }
            $this->syncInventory($player);
        }
        $this->useSwing($player, $hand, true);
        return true;
    }

    /**
     * AgeableMob.aiStep and Animal.aiStep for a ticking mob: its age moves a tick toward zero,
     * and love lasts while it is an adult ready to breed.
     */
    private function tickAge(array &$mob): void
    {
        if (!in_array($mob['kind'], self::BABY_INDEX_TYPES, true) || in_array($mob['kind'], ['zombie', 'husk', 'drowned', 'zombie_villager'], true)) {
            return;
        }
        $age = $this->mobAge($mob);
        if ($age !== 0) {
            $this->setMobAge($mob, $age < 0 ? $age + 1 : $age - 1);
        }
        if ($this->mobAge($mob) !== 0) {
            $mob['inLove'] = 0;
        } elseif (($mob['inLove'] ?? 0) > 0) {
            --$mob['inLove'];
        }
    }

    /**
     * BreedGoal: a mob in love heads for the nearest other of its kind in love within 8 blocks;
     * after 60 ticks together, closer than 3 blocks, they breed. Returns the partner it heads for.
     */
    private function breedingPartner(array &$mob): ?array
    {
        if (($mob['inLove'] ?? 0) <= 0 || !isset(self::BREEDING_FOOD[$mob['kind']])) {
            unset($mob['loveTime']);
            return null;
        }
        $partner = null;
        $nearest = PHP_FLOAT_MAX;
        foreach ($this->animals as $id => $other) {
            if ($id === $mob['id'] || $other['kind'] !== $mob['kind'] || isset($other['deadAt']) || ($other['inLove'] ?? 0) <= 0
                || abs($other['x'] - $mob['x']) > 8.0 + 0.5 || abs($other['y'] - $mob['y']) > 8.0 + 1.0 || abs($other['z'] - $mob['z']) > 8.0 + 0.5) {
                continue;
            }
            $distance = ($other['x'] - $mob['x']) ** 2 + ($other['y'] - $mob['y']) ** 2 + ($other['z'] - $mob['z']) ** 2;
            if ($distance < $nearest && $distance <= 64.0) {
                [$partner, $nearest] = [$other, $distance];
            }
        }
        if ($partner === null) {
            unset($mob['loveTime']);
            return null;
        }
        $mob['loveTime'] = ($mob['loveTime'] ?? 0) + 1;
        if ($mob['loveTime'] >= 60 && $nearest < 9.0) {
            $this->breed($mob, $partner['id']);
        }
        return $partner;
    }

    /**
     * Animal.spawnChildFromBreeding: both parents rest for 6000 ticks and lose their love, hearts
     * show over the first, it drops 1 to 7 experience, and the baby appears at its feet.
     */
    private function breed(array &$mob, int $partnerId): void
    {
        $partner = &$this->animals[$partnerId];
        $this->setMobAge($mob, 6000);
        $this->setMobAge($partner, 6000);
        $mob['inLove'] = $partner['inLove'] = 0;
        unset($mob['loveTime'], $partner['loveTime'], $mob['loveCause'], $partner['loveCause']);
        unset($partner);
        $this->mobEvent($mob['id'], 18);
        if ($this->gameRule('mob_drops') === 'true') {
            $this->awardExperience($mob['x'], $mob['y'], $mob['z'], $this->mobRandom($mob['id'])->nextInt(7) + 1);
        }
        // getBreedOffspring: the baby takes one parent's variant, as most animals' offspring do.
        $state = ['baby' => true, 'age' => -24000];
        $parent = random_int(0, 1) === 0 ? $mob : $this->animals[$partnerId];
        foreach (['variant', 'color'] as $field) {
            if (isset($parent[$field])) {
                $state[$field] = $parent[$field];
            }
        }
        $this->spawnAnimal($mob['kind'], $mob['x'], $mob['y'], $mob['z'], $state);
    }

    /** Level.broadcastEntityEvent for a mob. */
    private function mobEvent(int $id, int $event): void
    {
        foreach ($this->localPlayers() as $player) {
            if (isset($player->entities[$id])) {
                $player->send($this->version, 'entity_event', pack('N', $id) . chr($event));
            }
        }
    }
}
