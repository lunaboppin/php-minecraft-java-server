<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;

/**
 * Players' status effects (reference/26.3/mob-effects.json): MobEffectInstance durations and
 * updates, the effect and attribute packets, and the ticking effects that change health, food
 * and damage.
 */
trait Effects
{
    /** @return array<string, array{id: int, category: string, color: int, instant: bool, description: string, attributes: array}> */
    private function effectDefinitions(): array
    {
        static $effects = null;
        return $effects ??= json_decode((string) file_get_contents($this->version->directory . '/mob-effects.json'), true, 512, JSON_THROW_ON_ERROR)['effects'];
    }

    /**
     * LivingEntity.addEffect with MobEffectInstance.update: a stronger effect replaces the
     * current one, an equal one only lengthens it, and visibility changes count. Returns whether
     * anything changed.
     */
    private function giveEffect(Client $c, string $effect, int $duration, int $amplifier, bool $ambient = false, bool $particles = true, bool $icon = true): bool
    {
        $new = ['amplifier' => $amplifier, 'duration' => $duration, 'ambient' => $ambient, 'particles' => $particles, 'icon' => $icon];
        $current = $c->effects[$effect] ?? null;
        if ($current !== null) {
            $shorter = static fn (array $a, array $b): bool => $a['duration'] !== -1 && ($a['duration'] < $b['duration'] || $b['duration'] === -1);
            $changed = false;
            if ($amplifier > $current['amplifier']) {
                [$current['amplifier'], $current['duration'], $changed] = [$amplifier, $duration, true];
            } elseif ($shorter($current, $new) && $amplifier === $current['amplifier']) {
                [$current['duration'], $changed] = [$duration, true];
            }
            foreach (['ambient', 'particles', 'icon'] as $flag) {
                if ($current[$flag] !== $new[$flag]) {
                    [$current[$flag], $changed] = [$new[$flag], true];
                }
            }
            if (!$changed) {
                return false;
            }
            $new = $current;
        }
        $upgraded = $current === null || $amplifier > ($c->effects[$effect]['amplifier'] ?? -1);
        $c->effects[$effect] = $new;
        // onEffectAdded blends the new effect in; onEffectUpdated does not.
        $c->send($this->version, 'update_mob_effect', $this->effectPacket($c, $effect, $current === null));
        $this->syncEffectAttributes($c, $effect);
        if ($upgraded && $effect === 'minecraft:absorption') {
            // AbsorptionMobEffect.onEffectStarted: at least four hearts' worth per level.
            $this->setAbsorption($c, max($c->absorption, 4.0 * ($new['amplifier'] + 1)));
        }
        return true;
    }

    private function removeEffect(Client $c, string $effect): bool
    {
        if (!isset($c->effects[$effect])) {
            return false;
        }
        unset($c->effects[$effect]);
        $c->send($this->version, 'remove_mob_effect', Binary::writeVarInt($c->entityId) . Binary::writeVarInt($this->effectDefinitions()[$effect]['id']));
        $this->syncEffectAttributes($c, $effect);
        // LivingEntity.refreshDirtyAttributes clamps absorption and health to their new maximums.
        if ($effect === 'minecraft:absorption' && $c->absorption > 0.0) {
            $this->setAbsorption($c, 0.0);
        }
        return true;
    }

    /** ClientboundUpdateMobEffectPacket: the flags mark ambient, visible, icon and blending. */
    private function effectPacket(Client $c, string $effect, bool $blend): string
    {
        $instance = $c->effects[$effect];
        $flags = ($instance['ambient'] ? 1 : 0) | ($instance['particles'] ? 2 : 0) | ($instance['icon'] ? 4 : 0) | ($blend ? 8 : 0);
        return Binary::writeVarInt($c->entityId) . Binary::writeVarInt($this->effectDefinitions()[$effect]['id'])
            . Binary::writeVarInt($instance['amplifier']) . Binary::writeVarInt($instance['duration']) . chr($flags);
    }

    /**
     * MobEffect.removeAttributeModifiers and addAttributeModifiers: the effect's modifiers are
     * removed and, while it is active, added again for its level, then the synchronized ones
     * are sent.
     */
    private function syncEffectAttributes(Client $c, string $effect): void
    {
        $names = [];
        foreach ($this->effectDefinitions()[$effect]['attributes'] as $attribute => $modifier) {
            $name = substr($attribute, 10);
            $c->attributeInstances[$name] = true;
            unset($c->attributeModifiers[$name][$modifier['id']]);
            if (isset($c->effects[$effect])) {
                // AttributeTemplate.create: the amount per level, times the level.
                $amount = $modifier['amounts'][0] * ($c->effects[$effect]['amplifier'] + 1);
                $c->attributeModifiers[$name][$modifier['id']] = [$amount, ['add_value' => 0, 'add_multiplied_base' => 1, 'add_multiplied_total' => 2][$modifier['operation']], false];
            }
            $names[] = $name;
        }
        $this->syncPlayerAttributes($c, $names);
    }

    /**
     * LivingEntity.tickEffects: each effect applies when its period comes round, counts down
     * and is removed when it runs out (infinite effects never do).
     */
    private function tickEffects(Client $c): void
    {
        foreach ($c->effects as $effect => $instance) {
            if ($this->effectTickDue($effect, $instance)) {
                $this->applyEffectTick($c, $effect, $instance['amplifier']);
                if ($c->dead) {
                    return;
                }
            }
            if ($instance['duration'] === -1) {
                continue;
            }
            if (--$c->effects[$effect]['duration'] <= 0) {
                $this->removeEffect($c, $effect);
            } elseif ($c->effects[$effect]['duration'] % 600 === 0) {
                // LivingEntity.tickEffects resynchronizes the remaining time every 30 seconds.
                $c->send($this->version, 'update_mob_effect', $this->effectPacket($c, $effect, false));
            }
        }
    }

    /** MobEffect.shouldApplyEffectTickThisTick */
    private function effectTickDue(string $effect, array $instance): bool
    {
        $period = match ($effect) {
            'minecraft:regeneration' => 50,
            'minecraft:poison' => 25,
            'minecraft:wither' => 40,
            'minecraft:hunger', 'minecraft:instant_health', 'minecraft:instant_damage', 'minecraft:saturation' => 1,
            default => null,
        };
        if ($period === null) {
            return false;
        }
        $interval = $period >> $instance['amplifier'];
        return $interval <= 1 || $instance['duration'] % $interval === 0;
    }

    private function applyEffectTick(Client $c, string $effect, int $amplifier): void
    {
        switch ($effect) {
            case 'minecraft:regeneration':
                if ($c->health < $this->maxHealth($c)) {
                    $c->health = min($this->maxHealth($c), $c->health + 1.0);
                    $this->health($c);
                }
                return;
            case 'minecraft:poison':
                // Poison never kills.
                if ($c->health > 1.0) {
                    $this->damage($c, 1.0, 'magic');
                }
                return;
            case 'minecraft:wither':
                $this->damage($c, 1.0, 'wither');
                return;
            case 'minecraft:hunger':
                $c->exhaustion += 0.005 * ($amplifier + 1);
                return;
            case 'minecraft:instant_health':
                $c->health = min($this->maxHealth($c), $c->health + max(4 << $amplifier, 0));
                $this->health($c);
                return;
            case 'minecraft:instant_damage':
                $this->damage($c, (float) (6 << $amplifier), 'magic');
                return;
            case 'minecraft:saturation':
                // FoodData.eat(amplifier + 1, 1.0)
                $c->food = min(20, $c->food + $amplifier + 1);
                $c->saturation = min((float) $c->food, $c->saturation + ($amplifier + 1) * 2.0);
                $this->health($c);
                return;
        }
    }

    /** LivingEntity.getMaxHealth: the max_health attribute as a float. */
    private function maxHealth(Client $c): float
    {
        return (float) unpack('G', pack('G', $this->playerAttribute($c, 'max_health')))[1];
    }

    /** Player.setAbsorptionAmount, shown to the player and those tracking them. */
    private function setAbsorption(Client $c, float $amount): void
    {
        $c->absorption = max(0.0, $amount);
        $payload = Binary::writeVarInt($c->entityId) . chr($this->consumables()['absorption_index']) . Binary::writeVarInt(3) . pack('G', $c->absorption) . "\xff";
        foreach ($this->players() as $player) {
            if ($player === $c || isset($player->entities[$c->entityId])) {
                $player->send($this->version, 'set_entity_data', $payload);
            }
        }
    }

    /** @return array{items: array<string, array>, absorption_index: int} */
    private function consumables(): array
    {
        static $consumables = null;
        return $consumables ??= json_decode((string) file_get_contents($this->version->directory . '/consumables.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Consumable.onConsume: the item's consume effects. Random teleports and sounds are not
     * modelled.
     */
    private function applyConsumeEffects(Client $c, string $item): void
    {
        foreach ($this->consumables()['items'][$item]['on_consume_effects'] ?? [] as $effect) {
            switch ($effect['type']) {
                case 'minecraft:apply_effects':
                    if (mt_rand() / (mt_getrandmax() + 1) >= ($effect['probability'] ?? 1.0)) {
                        break;
                    }
                    foreach ($effect['effects'] as $instance) {
                        $this->giveEffect($c, $instance['id'], (int) ($instance['duration'] ?? 0), (int) ($instance['amplifier'] ?? 0), (bool) ($instance['ambient'] ?? false), (bool) ($instance['show_particles'] ?? true), (bool) ($instance['show_icon'] ?? true));
                    }
                    break;
                case 'minecraft:remove_effects':
                    $ids = $effect['effects'];
                    foreach (is_array($ids) ? $ids : [$ids] as $id) {
                        foreach (str_starts_with($id, '#') ? array_map(static fn (string $name): string => 'minecraft:' . $name, $this->version->registryTags('minecraft:mob_effect')[substr($id, 1)] ?? []) : [$id] as $remove) {
                            $this->removeEffect($c, $remove);
                        }
                    }
                    break;
                case 'minecraft:clear_all_effects':
                    foreach (array_keys($c->effects) as $active) {
                        $this->removeEffect($c, $active);
                    }
                    break;
            }
        }
    }

    /** Damage after resistance's 20% per level, unless the type bypasses it. */
    private function effectDamage(Client $c, float $amount, string $type): float
    {
        if (isset($c->effects['minecraft:resistance']) && !in_array($type, $this->version->catalog->damageTypes['tags']['minecraft:bypasses_resistance'] ?? [], true)) {
            $amount = max(0.0, $amount * (25 - ($c->effects['minecraft:resistance']['amplifier'] + 1) * 5) / 25.0);
        }
        return $amount;
    }

    /** Strength and weakness on a player's melee damage (the attack_damage modifiers). */
    private function effectAttackDamage(Client $c, float $damage): float
    {
        foreach (['minecraft:strength' => 3.0, 'minecraft:weakness' => -4.0] as $effect => $step) {
            if (isset($c->effects[$effect])) {
                $damage += $step * ($c->effects[$effect]['amplifier'] + 1);
            }
        }
        return max(0.0, $damage);
    }
}
