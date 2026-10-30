<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\World\Generation\Float32;

/** LivingEntity.SwingState: arm swings, sent as ClientboundSwingAnimationPacket to trackers. */
trait Swinging
{
    /** @var array<int, array{hand: int, type: int, duration: int, effective: int, ticks: int}> swings by entity network ID */
    private array $swings = [];

    // SwingAnimationType IDs.
    private const SWING_TYPES = ['none' => 0, 'whack' => 1, 'stab' => 2];

    /** ItemStack.getAttackAnimation: spears stab for their attack duration, all else whacks for 6 ticks. */
    private function attackAnimation(?array $stack): array
    {
        static $spears = ['wooden_spear' => 0.65, 'stone_spear' => 0.75, 'copper_spear' => 0.85, 'iron_spear' => 0.95, 'golden_spear' => 0.95, 'diamond_spear' => 1.05, 'netherite_spear' => 1.15];
        $duration = $spears[$stack === null ? '' : $this->version->catalog->itemName($stack['item'])] ?? null;
        // Item.Properties.spear: (int) (attackDuration * 20.0F).
        return $duration === null ? ['whack', 6] : ['stab', (int) Float32::round(Float32::round($duration) * 20.0)];
    }

    /**
     * LivingEntity.swing: start a swing unless one is in its first half, and tell the trackers,
     * and the swinging player too when asked. Returns whether the swing started.
     */
    private function swing(int $entityId, int $hand, string $type, int $duration, ?Client $player = null, bool $toSelf = false): bool
    {
        $current = $this->swings[$entityId] ?? null;
        if ($current !== null && $current['ticks'] <= intdiv($current['effective'], 2) && $current['ticks'] > 0) {
            return false;
        }
        // LivingEntity.getModifiedSwingDuration: haste or conduit power shortens it, mining fatigue lengthens it.
        $effects = $player?->effects ?? [];
        $dig = max($effects['minecraft:haste']['amplifier'] ?? -1, $effects['minecraft:conduit_power']['amplifier'] ?? -1);
        $effective = $dig >= 0 ? $duration - (1 + $dig) : (isset($effects['minecraft:mining_fatigue']) ? $duration + (1 + $effects['minecraft:mining_fatigue']['amplifier']) * 2 : $duration);
        $this->swings[$entityId] = ['hand' => $hand, 'type' => self::SWING_TYPES[$type], 'duration' => $duration, 'effective' => $effective, 'ticks' => 0];
        $payload = Binary::writeVarInt($entityId) . Binary::writeVarInt($hand) . Binary::writeVarInt(self::SWING_TYPES[$type]) . Binary::writeVarInt($duration);
        foreach ($this->players() as $viewer) {
            if (isset($viewer->entities[$entityId]) || ($toSelf && $viewer === $player)) {
                $viewer->send($this->version, 'swing_animation', $payload);
            }
        }
        return true;
    }

    /** A player's swing with the held item's interaction animation, for a successful use. */
    private function useSwing(Client $c, int $hand, bool $toSelf = false): void
    {
        // ItemStack.getInteractAnimation: no default item changes it.
        $this->swing($c->entityId, $hand, 'whack', 6, $c, $toSelf);
    }

    /** SwingState.tick for every swinging entity. */
    private function tickSwings(): void
    {
        foreach ($this->swings as $id => &$swing) {
            if ($swing['ticks']++ > $swing['effective']) {
                unset($this->swings[$id]);
            }
        }
        unset($swing);
    }
}
