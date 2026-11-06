<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Server\Tracking\EntityMovementTracker;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * ArmorStand: a living entity without AI that falls like one, wears what players put on it,
 * wobbles at the first hit and breaks at a second within five ticks.
 */
trait ArmorStands
{
    /** @var array<int, array> armor stands by entity ID */
    private array $armorStands = [];

    /** @var array<int, EntityMovementTracker> */
    private array $armorStandTrackers = [];

    /** EquipmentSlot ordinals by the names an item's equippable component gives. */
    private const ARMOR_STAND_SLOTS = ['mainhand' => 0, 'offhand' => 1, 'feet' => 2, 'legs' => 3, 'chest' => 4, 'head' => 5, 'body' => 6, 'saddle' => 7];

    private function addArmorStand(float $x, float $y, float $z, float $yaw, array $state = []): int
    {
        $id = $this->nextEntity++;
        $this->armorStands[$id] = $state + ['id' => $id, 'uuid' => random_bytes(16), 'kind' => 'armor_stand', 'x' => $x, 'y' => $y, 'z' => $z, 'yaw' => $yaw,
            'velocity' => [0.0, 0.0, 0.0], 'ground' => false, 'health' => 20.0, 'equipment' => [], 'lastHit' => 0,
            'small' => false, 'showArms' => false, 'noBasePlate' => false, 'marker' => false, 'invisible' => false, 'noGravity' => false, 'disabledSlots' => 0];
        $this->armorStands[$id]['id'] = $id;
        return $id;
    }

    /** Armor stands saved with the level stand where they were. */
    private function restoreArmorStands(): void
    {
        foreach ($this->world->setting('armorStands') ?? [] as $saved) {
            if (!isset($saved['uuid'], $saved['x'], $saved['y'], $saved['z'])) {
                continue;
            }
            $saved['uuid'] = hex2bin($saved['uuid']);
            $saved['equipment'] = array_map(static fn (array $stack): array => $stack, (array) ($saved['equipment'] ?? []));
            $this->addArmorStand((float) $saved['x'], (float) $saved['y'], (float) $saved['z'], (float) ($saved['yaw'] ?? 0.0), $saved);
        }
    }

    private function savedArmorStands(): array
    {
        return array_values(array_map(static fn (array $stand): array => ['uuid' => bin2hex($stand['uuid'])]
            + array_intersect_key($stand, array_flip(['x', 'y', 'z', 'yaw', 'head', 'velocity', 'health', 'equipment', 'small', 'showArms', 'noBasePlate', 'marker', 'invisible', 'noGravity', 'disabledSlots', 'customName'])), $this->armorStands));
    }

    private function removeArmorStand(int $id): void
    {
        unset($this->armorStands[$id], $this->armorStandTrackers[$id]);
        foreach ($this->localPlayers() as $player) {
            $this->forgetEntity($player, $id);
        }
    }

    /** The selector-shaped reference a command names an armor stand by. */
    private function armorStandReference(int $id): array
    {
        $stand = $this->armorStands[$id];
        [$width, $height] = $this->armorStandSize($stand);
        return ['armorStand' => $id, 'x' => $stand['x'], 'y' => $stand['y'], 'z' => $stand['z'], 'yaw' => $stand['yaw'], 'pitch' => 0.0,
            'type' => 'minecraft:armor_stand', 'width' => $width, 'height' => $height, 'id' => $id, 'uuid' => $stand['uuid']];
    }

    /** ArmorStand.getDefaultDimensions: none for a marker, half size when small. */
    private function armorStandSize(array $stand): array
    {
        if ($stand['marker']) {
            return [0.0, 0.0];
        }
        return $stand['small'] ? [0.25, 0.9875000119209290] : [0.5, 1.975000023841858];
    }

    private function armorStandBox(array $stand): array
    {
        [$width, $height] = $this->armorStandSize($stand);
        return Collision::bounds($stand['x'], $stand['y'], $stand['z'], $width, $height);
    }

    /**
     * ArmorStandItem.useOn: on any face but the bottom, a stand at the bottom centre of the block
     * placed against, if nothing collides with or stands in its box, turned to the nearest 45
     * degrees from the player; its placing sound, and the item is used up outside creative.
     */
    private function placeArmorStand(Client $c, int $slot, int $x, int $y, int $z): bool
    {
        $box = Collision::bounds($x + 0.5, (float) $y, $z + 0.5, 0.5, 1.975000023841858);
        $collision = new Collision($this->version->catalog);
        foreach ($collision->boxes($this->world, $box) as $other) {
            if ($other[0] < $box[3] && $other[3] > $box[0] && $other[1] < $box[4] && $other[4] > $box[1] && $other[2] < $box[5] && $other[5] > $box[2]) {
                return false;
            }
        }
        foreach ($this->entityBoxes() as $other) {
            if ($other[0] < $box[3] && $other[3] > $box[0] && $other[1] < $box[4] && $other[4] > $box[1] && $other[2] < $box[5] && $other[5] > $box[2]) {
                return false;
            }
        }
        $wrapped = fmod(Float32::round($c->yaw - 180.0), 360.0);
        $wrapped = $wrapped >= 180.0 ? $wrapped - 360.0 : ($wrapped < -180.0 ? $wrapped + 360.0 : $wrapped);
        $yaw = (float) (floor(Float32::round(Float32::round($wrapped + 22.5) / 45.0)) * 45.0);
        // LivingEntity's constructor turned it a random 0-2pi degrees, head too, and only its body
        // is turned after; mt_rand stands in for the entity's own random.
        $id = $this->addArmorStand($x + 0.5, (float) $y, $z + 0.5, $yaw, ['head' => Float32::round(mt_rand() / (mt_getrandmax() + 1) * 6.2831855)]);
        $this->armorStandSound($this->armorStands[$id], 'place', 4, 0.75, 0.8);
        foreach ($this->localPlayers() as $player) {
            $this->showArmorStand($player, $id);
        }
        if ($c->gameMode !== 1) {
            \PhpMinecraftJavaServer\World\Inventory::consume($c->inventory, $slot);
            $this->syncInventory($c);
        }
        return true;
    }

    /** The boxes of every entity Level.getEntities would find, for placement checks. */
    private function entityBoxes(): array
    {
        $boxes = [];
        foreach ($this->localPlayers() as $player) {
            if ($player->gameMode !== 3 && !$player->dead) {
                $boxes[] = Collision::bounds($player->x, $player->y, $player->z, 0.6, $player->sneaking ? 1.5 : 1.8);
            }
        }
        foreach ($this->animals as $mob) {
            $size = $this->version->catalog->mobs['entities'][$mob['kind']];
            $boxes[] = Collision::bounds($mob['x'], $mob['y'], $mob['z'], $size['width'], $size['height']);
        }
        foreach ($this->armorStands as $stand) {
            $boxes[] = $this->armorStandBox($stand);
        }
        foreach ($this->itemEntities as $item) {
            $boxes[] = Collision::bounds($item['x'], $item['y'], $item['z'], 0.25, 0.25);
        }
        return $boxes;
    }

    /** Level.playSound from the stand, heard within 16 blocks. */
    private function armorStandSound(array $stand, string $event, int $category = 6, float $volume = 1.0, float $pitch = 1.0): void
    {
        $payload = Packets::sound($this->version->blocks['sounds']['entity.armor_stand.' . $event], $category, $stand['x'], $stand['y'], $stand['z'], $volume, $pitch, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $stand['x']) ** 2 + ($player->y - $stand['y']) ** 2 + ($player->z - $stand['z']) ** 2 < (16.0 * max(1.0, $volume)) ** 2) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    /**
     * ArmorStand.interact: an empty hand takes what is worn where the stand was clicked; an item
     * goes to its equipment slot, swapping with what is there (hands only when arms show).
     */
    private function interactArmorStand(Client $player, int $id, int $hand, array $location): void
    {
        $stand = $this->armorStands[$id];
        if ($stand['marker'] || $player->dead || $hand < 0 || $hand > 1) {
            return;
        }
        $handSlot = $hand === 0 ? 36 + $player->heldSlot : 45;
        $held = $player->inventory[$handSlot] ?? null;
        if ($player->gameMode === 3 || ($held !== null && $this->version->catalog->itemName($held['item']) === 'name_tag')) {
            return;
        }
        $itemSlot = $held === null ? 0 : (self::ARMOR_STAND_SLOTS[$this->version->catalog->item($held['item'])['equip_slot'] ?? 'mainhand'] ?? 0);
        if ($itemSlot > 5) {
            $itemSlot = 0;
        }
        if ($held === null) {
            $clicked = $this->armorStandClickedSlot($stand, $location[1]);
            $target = $this->armorStandSlotDisabled($stand, $clicked) ? $itemSlot : $clicked;
            if (isset($stand['equipment'][$target])) {
                $this->swapArmorStandItem($player, $id, $target, $handSlot);
            }
            return;
        }
        if ($this->armorStandSlotDisabled($stand, $itemSlot) || ($itemSlot < 2 && !$stand['showArms'])) {
            return;
        }
        $this->swapArmorStandItem($player, $id, $itemSlot, $handSlot);
    }

    /** ArmorStand.getClickedSlot: the worn piece at the height clicked, else a hand. */
    private function armorStandClickedSlot(array $stand, float $y): int
    {
        $small = $stand['small'];
        $y /= $small ? 0.5 : 1.0;
        $has = static fn (int $slot): bool => isset($stand['equipment'][$slot]);
        if ($y >= 0.1 && $y < 0.1 + ($small ? 0.8 : 0.45) && $has(2)) {
            return 2;
        }
        if ($y >= 0.9 + ($small ? 0.3 : 0.0) && $y < 0.9 + ($small ? 1.0 : 0.7) && $has(4)) {
            return 4;
        }
        if ($y >= 0.4 && $y < 0.4 + ($small ? 1.0 : 0.8) && $has(3)) {
            return 3;
        }
        if ($y >= 1.6 && $has(5)) {
            return 5;
        }
        return !$has(0) && $has(1) ? 1 : 0;
    }

    /** EquipmentSlot.getFilterBit: hands 0-1, then feet to head 2-5. */
    private function armorStandSlotDisabled(array $stand, int $slot): bool
    {
        return ($stand['disabledSlots'] & (1 << $slot)) !== 0 || ($slot < 2 && !$stand['showArms']);
    }

    /** ArmorStand.swapItem. */
    private function swapArmorStandItem(Client $player, int $id, int $slot, int $handSlot): void
    {
        $stand = &$this->armorStands[$id];
        $worn = $stand['equipment'][$slot] ?? null;
        $held = $player->inventory[$handSlot] ?? null;
        if ($worn !== null && ($stand['disabledSlots'] & (1 << ($slot + 8))) !== 0) {
            return;
        }
        if ($worn === null && ($stand['disabledSlots'] & (1 << ($slot + 16))) !== 0) {
            return;
        }
        if ($player->gameMode === 1 && $worn === null && $held !== null) {
            $stand['equipment'][$slot] = array_replace($held, ['count' => 1]);
        } elseif ($held === null || $held['count'] <= 1) {
            if ($held === null) {
                unset($stand['equipment'][$slot]);
            } else {
                $stand['equipment'][$slot] = $held;
            }
            if ($worn === null) {
                unset($player->inventory[$handSlot]);
            } else {
                $player->inventory[$handSlot] = $worn;
            }
        } elseif ($worn !== null) {
            return;
        } else {
            $stand['equipment'][$slot] = array_replace($held, ['count' => 1]);
            $player->inventory[$handSlot]['count'] = $held['count'] - 1;
        }
        // LivingEntity.onEquipItem: the equip sound of what was put on, unless it was taken off.
        $stand['equipmentChanged'][$slot] = true;
        unset($stand);
        $this->syncInventory($player);
    }

    /**
     * ArmorStand.hurtServer for a player's hit: a creative player breaks it at once; otherwise
     * the first hit wobbles it (entity event 32) and another within five ticks breaks it,
     * dropping an armor stand and everything worn. Adventure players cannot.
     */
    private function attackArmorStand(Client $c, int $id): bool
    {
        if (!isset($this->armorStands[$id])) {
            return false;
        }
        $stand = $this->armorStands[$id];
        if ($stand['invisible'] || $stand['marker'] || $c->gameMode === 2) {
            return true;
        }
        if ($c->gameMode === 1) {
            $this->armorStandSound($stand, 'break');
            $this->armorStandParticles($stand);
            $this->removeArmorStand($id);
            return true;
        }
        if ($this->ticks - $stand['lastHit'] > 5) {
            foreach ($this->localPlayers() as $player) {
                if (isset($player->entities[$id])) {
                    $player->send($this->version, 'entity_event', pack('N', $id) . chr(32));
                }
            }
            $this->armorStands[$id]['lastHit'] = $this->ticks;
            return true;
        }
        $this->breakArmorStand($id, true);
        return true;
    }

    /**
     * ArmorStand.brokenByPlayer then brokenByAnything: an armor stand popped at its block (when
     * a player broke it), the breaking sound, and every worn item popped a block above.
     */
    private function breakArmorStand(int $id, bool $byPlayer): void
    {
        $stand = $this->armorStands[$id];
        $block = [(int) floor($stand['x']), (int) floor($stand['y']), (int) floor($stand['z'])];
        if ($byPlayer) {
            $this->popResource(...[...$block, ['item' => $this->version->blocks['items']['armor_stand'], 'count' => 1]]);
        }
        $this->armorStandSound($stand, 'break');
        foreach ($stand['equipment'] as $stack) {
            $this->popResource($block[0], $block[1] + 1, $block[2], $stack);
        }
        $this->armorStands[$id]['equipment'] = [];
        if ($byPlayer) {
            $this->armorStandParticles($stand);
        }
        $this->removeArmorStand($id);
    }

    /** ArmorStand.showBreakingParticles: ten oak plank particles about two thirds up. */
    private function armorStandParticles(array $stand): void
    {
        [$width, $height] = $this->armorStandSize($stand);
        $state = $this->version->catalog->state('oak_planks');
        $y = $stand['y'] + $height * 0.6666666666666666;
        // Laid out as ParticleCommand sends it: the options, the two flags, position, spread, speed, count.
        $payload = Binary::writeVarInt($this->version->blocks['particle_types']['block']) . Binary::writeVarInt($state) . "\0\0" . pack('E3', $stand['x'], $y, $stand['z'])
            . pack('G3', $width / 4.0, $height / 4.0, $width / 4.0) . pack('G3', 0.05, 0.05, 0.05) . Binary::writeVarInt(10) . Binary::writeVarInt(0);
        foreach ($this->localPlayers() as $player) {
            if ((floor($player->x) + 0.5 - $stand['x']) ** 2 + (floor($player->y) + 0.5 - $y) ** 2 + (floor($player->z) + 0.5 - $stand['z']) ** 2 < 32.0 ** 2) {
                $player->send($this->version, 'level_particles', $payload);
            }
        }
    }

    /**
     * LivingEntity.aiStep and travel without input for a stand with physics: small motion is
     * dropped, it moves, then gravity, air drag and ground friction apply.
     */
    private function tickArmorStands(): void
    {
        $collision = new Collision($this->version->catalog);
        foreach (array_keys($this->armorStands) as $id) {
            $stand = &$this->armorStands[$id];
            // An entity added this tick is first ticked on the next.
            if (!($stand['ticked'] ?? false)) {
                $stand['ticked'] = true;
            } elseif (!$stand['marker'] && !$stand['noGravity']) {
                $velocity = array_map(static fn (float $v): float => abs($v) < 0.003 ? 0.0 : $v, $stand['velocity']);
                $below = $this->world->get((int) floor($stand['x']), (int) floor($stand['y'] - 0.5000010132789612), (int) floor($stand['z']));
                $friction = $stand['ground'] ? $this->blockFriction($below) : 1.0;
                $moved = $collision->move($this->world, $this->armorStandBox($stand), $velocity);
                $stand['x'] += $moved[0];
                $stand['y'] += $moved[1];
                $stand['z'] += $moved[2];
                $stand['ground'] = $velocity[1] < 0.0 && $moved[1] !== $velocity[1];
                foreach ([0, 1, 2] as $axis) {
                    if ($moved[$axis] !== $velocity[$axis]) {
                        $velocity[$axis] = 0.0;
                    }
                }
                $horizontal = Float32::round($friction * 0.91);
                $stand['velocity'] = [$velocity[0] * $horizontal, ($velocity[1] - 0.08) * 0.9800000190734863, $velocity[2] * $horizontal];
            }
            unset($stand);
            $this->synchronizeArmorStand($id);
        }
    }

    /** ServerEntity.sendChanges for an armor stand: its movement, then changed equipment. */
    private function synchronizeArmorStand(int $id): void
    {
        $stand = $this->armorStands[$id];
        foreach ($this->localPlayers() as $player) {
            if (!isset($player->entities[$id])) {
                $this->showArmorStand($player, $id);
            } elseif (!$this->tracksPosition($player, $stand['x'], $stand['z'], 'armor_stand')) {
                $this->forgetEntity($player, $id);
            }
        }
        $state = $this->armorStandTrackerState($stand);
        $packets = $this->armorStandTracker($stand)->update($state);
        foreach ($packets as $packet) {
            foreach ($this->localPlayers() as $player) {
                if (isset($player->entities[$id])) {
                    $player->send($this->version, $packet['name'], $packet['payload']);
                }
            }
        }
        $this->synchronizeEquipment($this->armorStands[$id]);
    }

    private function armorStandTrackerState(array $stand): array
    {
        return ['position' => [$stand['x'], $stand['y'], $stand['z']], 'velocity' => $stand['velocity'], 'yaw' => $stand['yaw'], 'pitch' => 0.0, 'head' => $stand['head'] ?? $stand['yaw'],
            'ground' => $stand['ground'], 'needs_sync' => false, 'sync_velocity' => false, 'sync_position' => false, 'precise' => false];
    }

    private function armorStandTracker(array $stand): EntityMovementTracker
    {
        return $this->armorStandTrackers[$stand['id']] ??= new EntityMovementTracker($stand['id'], 3, true, $this->armorStandTrackerState($stand));
    }

    /** DATA_CLIENT_FLAGS: small 1, arms 4, no base plate 8, marker 16. */
    private function armorStandFlags(array $stand): int
    {
        return ($stand['small'] ? 1 : 0) | ($stand['showArms'] ? 4 : 0) | ($stand['noBasePlate'] ? 8 : 0) | ($stand['marker'] ? 16 : 0);
    }

    /**
     * ServerEntity.sendPairingData: the spawn, every non-default data value (invisibility,
     * health, the stand's flags), its synced attributes and what it wears.
     */
    private function showArmorStand(Client $player, int $id): bool
    {
        $stand = $this->armorStands[$id];
        if (isset($player->entities[$id]) || !$this->tracksPosition($player, $stand['x'], $stand['z'], 'armor_stand')) {
            return isset($player->entities[$id]);
        }
        $player->send($this->version, 'bundle_delimiter', '');
        $player->send($this->version, 'add_entity', $this->armorStandTracker($stand)->spawnPacket($stand['uuid'], $this->version->blocks['entity_types']['armor_stand']));
        $data = $stand['invisible'] ? "\0" . Binary::writeVarInt(0) . "\x20" : '';
        if (isset($stand['customName'])) {
            $data .= "\x02" . Binary::writeVarInt(6) . "\1" . \PhpMinecraftJavaServer\Protocol\Nbt::component($stand['customName']);
        }
        $data .= substr(Packets::mobHealth($this->version->catalog, $id, $stand['health']), strlen(Binary::writeVarInt($id)), -1);
        if ($this->armorStandFlags($stand) !== 0) {
            $data .= "\x0f" . Binary::writeVarInt(0) . chr($this->armorStandFlags($stand));
        }
        $player->send($this->version, 'set_entity_data', Binary::writeVarInt($id) . $data . "\xff");
        $player->send($this->version, 'update_attributes', Packets::attributeModifiers($this->version->catalog, $id, $this->mobAttributeEntries($stand)));
        if ($stand['equipment'] !== []) {
            ksort($stand['equipment']);
            $player->send($this->version, 'set_equipment', Packets::equipment($id, $stand['equipment'], $this->version->blocks['component_damage'], $this->version->catalog->itemStacks));
        }
        $player->send($this->version, 'bundle_delimiter', '');
        $player->entities[$id] = true;
        return true;
    }
}
