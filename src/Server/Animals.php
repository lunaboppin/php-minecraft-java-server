<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Nbt;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\Noise;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Combat;
use PhpMinecraftJavaServer\World\Generation\Float32;

/** Persistent mob state; species-specific AI and progression remain separate parity work. */
trait Animals
{
    use MobSounds;

    private array $animals = [];

    private array $naturalSpawns = [];

    /** Guaranteed main-hand items in the pinned skeleton/illager populateDefaultEquipmentSlots methods. */
    private function mobEquipment(string $kind): array
    {
        $name = match ($kind) {
            'skeleton','stray','bogged','parched' => 'bow','wither_skeleton' => 'stone_sword','pillager' => 'crossbow','vindicator' => 'iron_axe',default => null
        };
        return $name === null ? [] : [0 => ['item' => $this->version->blocks['items'][$name], 'count' => 1]];
    }

    private function restoreAnimals(): void
    {
        $this->naturalSpawns = $this->world->setting('naturalSpawns') ?? [];
        foreach ($this->world->setting('animals') ?? [] as $animal) {
            if (isset($animal['deadAt']) || !isset($this->version->catalog->mobs['entities'][$animal['kind'] ?? '']['attributes']['max_health'])) {
                continue;
            }
            if ($this->config->difficulty === 0 && !$this->version->catalog->mobs['entities'][$animal['kind']]['peaceful']) {
                continue;
            }
            $id = $this->nextEntity++;
            if ($animal['kind'] === 'ender_dragon') {
                $this->nextEntity += 8;
            }
            foreach (['x', 'y', 'z', 'yaw'] as $field) {
                $animal[$field] = (float) $animal[$field];
            }
            unset($animal['lastHurtTick'], $animal['lastHurtAmount'], $animal['needsSync'], $animal['syncVelocity'], $animal['syncPosition']);
            $animal['equipment'] ??= $this->mobEquipment($animal['kind']);
            if (isset($this->version->catalog->mobSounds['mobs'][$animal['kind']]['variants'])) {
                $animal['soundVariant'] ??= 'minecraft:classic';
            }
            $animal['id'] = $id;
            $animal['nextWander'] = 0;
            $animal['velocity'] ??= [0.0, 0.0, 0.0];
            $this->animals[$id] = $animal;
            $this->trackPushingMob($this->animals[$id]);
        }
    }

    private function spawnAnimal(string $kind, float $x, float $y, float $z): ?int
    {
        $info = $this->version->catalog->mobs['entities'][$kind] ?? null;
        if ($info === null || in_array($kind, ['player', 'armor_stand', 'mannequin'], true) || !isset($info['attributes']['max_health']) || !$info['summonable'] || !$info['enabled'] || ($this->config->difficulty === 0 && !$info['peaceful']) || count($this->animals) >= 256) {
            return null;
        }
        $id = $this->nextEntity++;
        if ($kind === 'ender_dragon') {
            $this->nextEntity += 8;
        }
        $this->animals[$id] = ['id' => $id, 'uuid' => bin2hex(random_bytes(16)), 'kind' => $kind, 'health' => $info['attributes']['max_health'], 'x' => $x, 'y' => $y, 'z' => $z, 'yaw' => 0.0, 'targetX' => $x, 'targetY' => $y, 'targetZ' => $z, 'nextWander' => 0, 'velocity' => [0.0, 0.0, 0.0]];
        $this->animals[$id]['equipment'] = $this->mobEquipment($kind);
        $this->trackPushingMob($this->animals[$id]);
        $variant = $this->chooseMobSoundVariant($kind);
        if ($variant !== null) {
            $this->animals[$id]['soundVariant'] = $variant;
        }
        foreach ($this->localPlayers() as $p) {
            $this->showAnimal($p, $this->animals[$id]);
        }
        return $id;
    }

    private function naturalAnimals(int $cx, int $cz): void
    {
        if (!$this->spawnMobs || $this->world->generator !== 'terrain') {
            return;
        }
        $key = $cx . ':' . $cz;
        if (isset($this->naturalSpawns[$key])) {
            return;
        }
        $this->naturalSpawns[$key] = true;
        $noise = new Noise($this->world->seed);
        $hash = $noise->hash($cx, 0, $cz, 120);
        if ($hash % 6 !== 0) {
            return;
        }
        $x = $cx * 16 + 4 + $hash % 8;
        $z = $cz * 16 + 4 + intdiv($hash, 11) % 8;
        $column = $this->world->column($x, $z);
        if (!in_array($column['biome'], ['plains', 'forest', 'taiga', 'meadow'], true) || $column['height'] < 64 || !$this->version->catalog->blocks[$this->version->catalog->blockName($this->world->get($x, $column['height'] + 1, $z))]['replaceable']) {
            return;
        }
        $kind = ['cow', 'pig', 'sheep', 'chicken'][intdiv($hash, 19) % 4];
        $this->spawnAnimal($kind, $x + 0.5, $column['height'] + 1, $z + 0.5);
        $this->spawnAnimal($kind, $x + 1.5, $column['height'] + 1, $z + 0.5);
    }

    /** Types whose entity data index 16 is DATA_BABY_ID: AgeableMob subclasses and the zombie family. */
    private const BABY_INDEX_TYPES = ['cow', 'pig', 'sheep', 'chicken', 'rabbit', 'mooshroom', 'goat', 'wolf', 'cat', 'ocelot', 'fox', 'panda', 'polar_bear', 'turtle', 'llama', 'trader_llama', 'horse', 'donkey', 'mule', 'skeleton_horse', 'zombie_horse', 'camel', 'sniffer', 'armadillo', 'bee', 'axolotl', 'strider', 'hoglin', 'parrot', 'frog', 'villager', 'wandering_trader', 'zombie', 'husk', 'drowned', 'zombie_villager'];

    /**
     * SynchedEntityData for the state summon NBT can set: the custom name (2) and its visibility
     * (3), silence (4), the mob flags' no-AI bit (15) and the baby flag (16).
     */
    private function mobState(array $animal): string
    {
        $entries = '';
        if (isset($animal['customName'])) {
            $entries .= "\x02" . Binary::writeVarInt(6) . "\1" . Nbt::component($animal['customName']);
        }
        foreach ([3 => 'customNameVisible', 4 => 'silent'] as $index => $field) {
            if (isset($animal[$field])) {
                $entries .= chr($index) . Binary::writeVarInt(8) . ($animal[$field] ? "\1" : "\0");
            }
        }
        if (isset($animal['noAi'])) {
            $entries .= "\x0f" . Binary::writeVarInt(0) . ($animal['noAi'] ? "\1" : "\0");
        }
        if (($animal['baby'] ?? false) && in_array($animal['kind'], self::BABY_INDEX_TYPES, true)) {
            $entries .= "\x10" . Binary::writeVarInt(8) . "\1";
        }
        return Binary::writeVarInt($animal['id']) . $entries . "\xff";
    }

    private function showAnimal(Client $c, array $animal): void
    {
        $id = $animal['id'];
        if (isset($c->entities[$id]) || !$this->tracksPosition($c, $animal['x'], $animal['z'], $animal['kind'])) {
            return;
        }
        $type = $this->version->blocks['entity_types'][$animal['kind']];
        $spawn = $this->ordinaryMobTravel($animal)
            ? $this->trackedMobSpawnPacket($animal, $type)
            : Packets::addPlayer($id, hex2bin($animal['uuid']), $type, $animal['x'], $animal['y'], $animal['z'], $animal['yaw'], 0, $animal['velocity'] ?? [0.0, 0.0, 0.0]);
        $c->send($this->version, 'add_entity', $spawn);
        $c->entities[$id] = true;
        $c->send($this->version, 'set_entity_data', Packets::mobHealth($this->version->catalog, $id, $animal['health']));
        if (array_intersect_key($animal, array_flip(['customName', 'customNameVisible', 'silent', 'noAi', 'baby'])) !== []) {
            $c->send($this->version, 'set_entity_data', $this->mobState($animal));
        }
        if (isset($animal['soundVariant'])) {
            $c->send($this->version, 'set_entity_data', Packets::mobSoundVariant($this->version, $id, $animal['kind'], $animal['soundVariant']));
        }
        $attributes = array_intersect_key($this->version->catalog->mobs['entities'][$animal['kind']]['attributes'], array_flip($this->version->catalog->mobs['sync_attributes']));
        $c->send($this->version, 'update_attributes', Packets::attributes($this->version->catalog, $id, $attributes));
        if (($animal['equipment'] ?? []) !== []) {
            $c->send($this->version, 'set_equipment', Packets::equipment($id, $animal['equipment'], $this->version->blocks['component_damage'], $this->version->catalog->itemStacks));
        }
    }

    private function removeAnimal(int $id): void
    {
        unset($this->mobRandomSources[$id], $this->mobMovementTrackers[$id]);
        $this->forgetPushingEntity($id);
        unset($this->animals[$id]);
        foreach ($this->localPlayers() as $p) {
            if (isset($p->entities[$id])) {
                $p->send($this->version, 'remove_entities', "\1" . Binary::writeVarInt($id));
                unset($p->entities[$id]);
            }
        }
    }

    private function attackAnimal(Client $c, int $id): bool
    {
        if (!isset($this->animals[$id])) {
            return false;
        }
        $animal = &$this->animals[$id];
        if (isset($animal['deadAt']) || ($c->x - $animal['x']) ** 2 + ($c->y - $animal['y']) ** 2 + ($c->z - $animal['z']) ** 2 > 4.5 ** 2) {
            return true;
        }
        $slot = 36 + $c->heldSlot;
        $stack = $c->inventory[$slot] ?? null;
        $tool = $this->version->catalog->itemName($stack['item'] ?? -1) ?? '';
        $weapon = $this->version->catalog->combat['items'][$tool] ?? $this->version->catalog->combat['default_item'];
        $elapsed = $this->ticks - $c->lastAttackTick;
        if (Combat::strength($weapon['speed'], $elapsed, 1.0) < $weapon['minimum_charge']) {
            return true;
        }
        // Strength and weakness modify the attack_damage attribute before the cooldown scales it.
        $damage = Combat::attack($this->effectAttackDamage($c, (float) $weapon['damage']), $weapon['speed'], $elapsed);
        $c->lastAttackTick = $this->ticks;
        $newWindow = $this->ticks - ($animal['lastHurtTick'] ?? -100) >= 10;
        if (!$newWindow && $damage <= ($animal['lastHurtAmount'] ?? 0)) {
            return true;
        }
        $incoming = $newWindow ? $damage : Float32::round($damage - $animal['lastHurtAmount']);
        $animal['lastHurtAmount'] = $damage;
        if ($newWindow) {
            $animal['lastHurtTick'] = $this->ticks;
        }
        $attributes = $this->version->catalog->mobs['entities'][$animal['kind']]['attributes'];
        $applied = Combat::afterArmor($incoming, $attributes['armor'] ?? 0, $attributes['armor_toughness'] ?? 0);
        $animal['health'] = max(0.0, Float32::round($animal['health'] - $applied));
        if ($newWindow) {
            $this->knockbackMob($animal, $c);
            $this->mobDamageSound($animal, $animal['health'] <= 0);
        }
        foreach ($this->localPlayers() as $p) {
            if (isset($p->entities[$id])) {
                $p->send($this->version, 'set_entity_data', Packets::mobHealth($this->version->catalog, $id, $animal['health']));
                if ($newWindow) {
                    $p->send($this->version, 'damage_event', Packets::damageEvent($this->version, $id, $c->entityId));
                }
                if ($animal['health'] <= 0) {
                    $p->send($this->version, 'entity_event', pack('N', $id) . "\3");
                }
            }
        }
        if (in_array($c->gameMode, [0, 2], true)) {
            $c->exhaustion += 0.1;
            $cost = $weapon['durability_cost'];
            $maximum = $this->version->catalog->item($stack['item'] ?? -1)['durability'] ?? 0;
            if ($cost > 0 && $maximum > 0) {
                $stack['damage'] = ($stack['damage'] ?? 0) + $cost;
                if ($stack['damage'] >= $maximum) {
                    unset($c->inventory[$slot]);
                } else {
                    $c->inventory[$slot] = $stack;
                }
                $this->syncInventory($c);
            }
        }
        if ($animal['health'] <= 0) {
            $this->incrementCriteria('totalKillCount', $c->name);
            $this->animalDeathLoot($animal, $c);
            if (in_array($animal['kind'], ['cow', 'pig', 'sheep', 'chicken'], true)) {
                $this->giveExperiencePoints($c, random_int(1, 3));
            }
        }
        return true;
    }

    /** Mark a mob dead and drop its loot; only kills by a player also give experience. */
    private function animalDeathLoot(array &$animal, ?Client $killer = null): void
    {
        $animal['deadAt'] = $this->ticks + 20;
        // LivingEntity.shouldDropLoot: not for babies, and only while mob_drops is on.
        if (($animal['baby'] ?? false) || $this->gameRule('mob_drops') !== 'true') {
            return;
        }
        $attacker = $killer === null ? null : $this->lootEntity($killer);
        $context = [
            'this' => [
                'type' => 'minecraft:' . $animal['kind'],
                'flags' => ['is_baby' => false, 'is_on_fire' => false],
                'components' => ['minecraft:sheep/color' => $animal['color'] ?? 'white'],
                'type_specific' => ['sheep' => ['sheared' => $animal['sheared'] ?? false]],
            ],
            'attacker' => $attacker,
            'direct_attacker' => $attacker,
            'killed_by_player' => $killer !== null,
            'damage' => $killer === null ? ['type' => 'minecraft:generic_kill'] : ['type' => 'minecraft:player_attack', 'source_entity' => $attacker, 'direct_entity' => $attacker],
        ];
        foreach ($this->loot()->drops('minecraft:entities/' . $animal['kind'], $context) as [$drop, $count]) {
            $item = $this->version->blocks['items'][substr($drop, 10)] ?? null;
            if ($item !== null) {
                $this->dropStack($animal['x'], $animal['y'] + 0.4, $animal['z'], ['item' => $item, 'count' => $count]);
            }
        }
    }

    /** A player as a loot context entity: flags and the held item. */
    private function lootEntity(Client $player): array
    {
        $held = $player->inventory[36 + $player->heldSlot] ?? null;
        $stack = $held === null ? null : ['item' => 'minecraft:' . $this->version->catalog->itemName($held['item']), 'enchantments' => $held['enchantments'] ?? []];
        return ['type' => 'minecraft:player', 'flags' => ['is_on_fire' => $player->fireTicks > 0, 'is_sneaking' => $player->sneaking, 'is_sprinting' => $player->sprinting, 'is_baby' => false], 'equipment' => ['mainhand' => $stack]];
    }

    /** Entity.kill for a mob: generic_kill bypasses invulnerability and drops its loot. */
    private function killAnimal(int $id): void
    {
        if (!isset($this->animals[$id]) || isset($this->animals[$id]['deadAt'])) {
            return;
        }
        $animal = &$this->animals[$id];
        $animal['health'] = 0.0;
        $this->mobDamageSound($animal, true);
        foreach ($this->localPlayers() as $p) {
            if (isset($p->entities[$id])) {
                $p->send($this->version, 'set_entity_data', Packets::mobHealth($this->version->catalog, $id, 0.0));
                $p->send($this->version, 'entity_event', pack('N', $id) . "\3");
            }
        }
        $this->animalDeathLoot($animal);
    }

    /** Entity.teleportTo for a mob: move it and resynchronize its position with viewers. */
    private function teleportAnimal(int $id, array $position): void
    {
        $animal = &$this->animals[$id];
        [$animal['x'], $animal['y'], $animal['z']] = $position;
        [$animal['targetX'], $animal['targetY'], $animal['targetZ']] = $position;
        $animal['velocity'] = [0.0, 0.0, 0.0];
        $animal['needsSync'] = true;
        $animal['syncPosition'] = true;
    }

    private function tickAnimals(): void
    {
        $this->preparePushingWorld();
        foreach ($this->animals as &$tracked) {
            if (!isset($tracked['deadAt']) && $this->mobIsTicking($tracked) && $this->ordinaryMobTravel($tracked)) {
                $this->synchronizeMobMotion($tracked);
            }
        }
        unset($tracked);
        $noise = new Noise($this->world->seed);
        foreach ($this->animals as $id => &$animal) {
            if (isset($animal['deadAt'])) {
                if ($this->ticks >= $animal['deadAt']) {
                    $this->removeAnimal($id);
                }
                continue;
            }
            // Entity.checkBelowWorld: a mob 64 blocks below the world is discarded, without loot.
            if ($animal['y'] < $this->world->minY - 64) {
                $this->removeAnimal($id);
                continue;
            }
            $near = false;
            foreach ($this->localPlayers() as $p) {
                if ($this->ticks % 4 === 0) {
                    $this->showAnimal($p, $animal);
                }
                if (($p->x - $animal['x']) ** 2 + ($p->z - $animal['z']) ** 2 < 80 ** 2) {
                    $near = true;
                }
            }
            // Mob.isNoAi: no goals and no travel, so a NoAI mob stays where it is.
            if (!$near || ($animal['noAi'] ?? false)) {
                continue;
            }
            $this->moveMob($animal, $noise);
        }
        unset($animal);
    }
}
