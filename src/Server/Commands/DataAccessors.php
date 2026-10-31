<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Protocol\Nbt;
use PhpMinecraftJavaServer\Server\Client;

/**
 * EntityDataAccessor and BlockDataAccessor for /data: the saved NBT of a player, a mob or a block
 * entity, built from the server's state with keys put in the order the game saves them, and the
 * fields a modification may change written back.
 */
trait DataAccessors
{
    /** BlockEntity types with data here, by block. */
    private const DATA_BLOCK_ENTITIES = ['chest' => 'chest', 'trapped_chest' => 'trapped_chest', 'barrel' => 'barrel', 'furnace' => 'furnace'];

    private static function nbtList(string $type, array $values): NbtTag
    {
        return new NbtTag('list', array_map(static fn (mixed $value): NbtTag => new NbtTag($type, $value), $values));
    }

    /** @param list<array{string, NbtTag}> $pairs */
    private static function nbtCompound(array $pairs): NbtTag
    {
        $compound = NbtTag::compound();
        foreach ($pairs as [$key, $value]) {
            $compound->put($key, $value);
        }
        return $compound;
    }

    /** ItemStack.CODEC: the item's ID, its count and its component changes. */
    private function stackNbt(array $stack, ?int $slot = null): NbtTag
    {
        $name = 'minecraft:' . $this->version->catalog->itemName($stack['item']);
        $components = [];
        foreach (['enchantments' => 'minecraft:enchantments', 'storedEnchantments' => 'minecraft:stored_enchantments'] as $field => $component) {
            if (($stack[$field] ?? []) !== []) {
                $components[] = [$component, self::nbtCompound(array_map(static fn (string $id, int $level): array => [$id, new NbtTag('int', $level)], array_keys($stack[$field]), $stack[$field]))];
            }
        }
        if (($stack['damage'] ?? 0) > 0) {
            $components[] = ['minecraft:damage', new NbtTag('int', $stack['damage'])];
        }
        $pairs = [['id', new NbtTag('string', $name)], ['count', new NbtTag('int', $stack['count'])]];
        if ($components !== []) {
            $pairs[] = ['components', self::nbtCompound($components)];
        }
        if ($slot !== null) {
            array_unshift($pairs, ['Slot', new NbtTag('byte', $slot)]);
        }
        return self::nbtCompound($pairs);
    }

    private static function uuidNbt(string $uuid): NbtTag
    {
        $bytes = strlen($uuid) === 16 ? $uuid : (string) hex2bin(str_replace('-', '', $uuid));
        return new NbtTag('int_array', array_map(static fn (int $word): int => $word >= 0x80000000 ? $word - 0x100000000 : $word, array_values(unpack('N4', $bytes))));
    }

    /** Entity.saveWithoutId's own fields, for a player or a mob. */
    private function entityBaseNbt(array $position, array $motion, array $rotation, float $fallDistance, int $fire, int $air, bool $ground, bool $invulnerable, int $portalCooldown, string $uuid): array
    {
        return [
            ['Pos', self::nbtList('double', $position)],
            ['Motion', self::nbtList('double', $motion)],
            ['Rotation', self::nbtList('float', $rotation)],
            ['fall_distance', new NbtTag('double', $fallDistance)],
            ['Fire', new NbtTag('short', $fire)],
            ['Air', new NbtTag('short', $air)],
            ['OnGround', new NbtTag('byte', (int) $ground)],
            ['Invulnerable', new NbtTag('byte', (int) $invulnerable)],
            ['PortalCooldown', new NbtTag('int', $portalCooldown)],
            ['UUID', self::uuidNbt($uuid)],
        ];
    }

    /** ServerPlayer.saveWithoutId: Entity, LivingEntity, Player and ServerPlayer data, after the data version. */
    private function playerNbt(Client $player): NbtTag
    {
        $creative = $player->gameMode === 1;
        $spectator = $player->gameMode === 3;
        $pairs = [['DataVersion', new NbtTag('int', 5023)]];
        array_push($pairs, ...$this->entityBaseNbt(
            [$player->x, $player->y, $player->z],
            [0.0, $player->ground ? -0.0784000015258789 : $player->velocityY, 0.0],
            [$player->yaw, $player->pitch],
            (float) $player->fallDistance,
            $player->fireTicks > 0 ? $player->fireTicks : -20,
            $player->air,
            $player->ground,
            false,
            $player->portalCooldown,
            $player->uuid
        ));
        if ($player->tags !== []) {
            $pairs[] = ['Tags', self::nbtList('string', array_values($player->tags))];
        }
        $inventory = [];
        foreach ([...range(36, 44), ...range(9, 35)] as $window) {
            if (isset($player->inventory[$window])) {
                $inventory[] = $this->stackNbt($player->inventory[$window], $window >= 36 ? $window - 36 : $window);
            }
        }
        $equipment = [];
        foreach ([45 => 'offhand', 5 => 'head', 6 => 'chest', 7 => 'legs', 8 => 'feet'] as $window => $slot) {
            if (isset($player->inventory[$window])) {
                $equipment[] = [$slot, $this->stackNbt($player->inventory[$window])];
            }
        }
        array_push(
            $pairs,
            ['Health', new NbtTag('float', $player->health)],
            ['HurtTime', new NbtTag('short', 0)],
            ['DeathTime', new NbtTag('short', 0)],
            ['AbsorptionAmount', new NbtTag('float', $player->absorption)],
            ['current_impulse_context_reset_grace_time', new NbtTag('int', 0)],
            ['FallFlying', new NbtTag('byte', 0)],
            ['Brain', self::nbtCompound([['memories', NbtTag::compound()]])],
        );
        if ($equipment !== []) {
            $pairs[] = ['equipment', self::nbtCompound($equipment)];
        }
        array_push(
            $pairs,
            ['Inventory', new NbtTag('list', $inventory)],
            ['SelectedItemSlot', new NbtTag('int', $player->heldSlot)],
            ['SleepTimer', new NbtTag('short', 0)],
            ['XpP', new NbtTag('float', $player->experienceProgress)],
            ['XpLevel', new NbtTag('int', $player->experienceLevel)],
            ['XpTotal', new NbtTag('int', $player->experience)],
            ['XpSeed', new NbtTag('int', 0)],
            ['Score', new NbtTag('int', 0)],
            ['foodLevel', new NbtTag('int', $player->food)],
            ['foodTickTimer', new NbtTag('int', $player->foodTimer)],
            ['foodSaturationLevel', new NbtTag('float', $player->saturation)],
            ['foodExhaustionLevel', new NbtTag('float', $player->exhaustion)],
            // Abilities.addSaveData for the game mode.
            ['abilities', self::nbtCompound([
                ['invulnerable', new NbtTag('byte', (int) ($creative || $spectator))],
                ['flying', new NbtTag('byte', (int) ($player->flying || $spectator))],
                ['mayfly', new NbtTag('byte', (int) ($creative || $spectator))],
                ['instabuild', new NbtTag('byte', (int) $creative)],
                ['mayBuild', new NbtTag('byte', (int) !in_array($player->gameMode, [2, 3], true))],
                ['flySpeed', new NbtTag('float', 0.05000000074505806)],
                ['walkSpeed', new NbtTag('float', 0.10000000149011612)],
            ])],
            ['EnderItems', new NbtTag('list', array_map(fn (int $slot): NbtTag => $this->stackNbt($player->enderItems[$slot], $slot), array_keys(array_filter($player->enderItems))))],
            ['seenCredits', new NbtTag('byte', 0)],
            ['Dimension', new NbtTag('string', 'minecraft:' . $player->dimension)],
            ['spawn_extra_particles_on_fall', new NbtTag('byte', 0)],
            ['post_effects', self::nbtList('string', $player->postEffects)],
            ['playerGameType', new NbtTag('int', $player->gameMode)],
        );
        $selected = $player->inventory[36 + $player->heldSlot] ?? null;
        if ($selected !== null) {
            // NbtPredicate.getEntityTagWithItem: a player's selected item is added for commands.
            $pairs[] = ['SelectedItem', $this->stackNbt($selected)];
        }
        return self::nbtCompound($pairs);
    }

    /** Mob.saveWithoutId: Entity, LivingEntity and Mob data, then AgeableMob and Animal for animals. */
    private function mobNbt(array $mob): NbtTag
    {
        $pairs = $this->entityBaseNbt(
            [$mob['x'], $mob['y'], $mob['z']],
            $mob['velocity'] ?? [0.0, 0.0, 0.0],
            [(float) $mob['yaw'], (float) ($mob['pitch'] ?? 0.0)],
            (float) ($mob['fallDistance'] ?? 0.0),
            0,
            300,
            (bool) ($mob['ground'] ?? false),
            (bool) ($mob['invulnerable'] ?? false),
            0,
            hex2bin($mob['uuid'])
        );
        if (isset($mob['customName'])) {
            $pairs[] = ['CustomName', self::componentNbt($mob['customName'])];
        }
        if ($mob['customNameVisible'] ?? false) {
            $pairs[] = ['CustomNameVisible', new NbtTag('byte', 1)];
        }
        if ($mob['silent'] ?? false) {
            $pairs[] = ['Silent', new NbtTag('byte', 1)];
        }
        if (($mob['tags'] ?? []) !== []) {
            $pairs[] = ['Tags', self::nbtList('string', self::hashSetOrder(array_values($mob['tags'])))];
        }
        array_push(
            $pairs,
            ['Health', new NbtTag('float', (float) $mob['health'])],
            ['HurtTime', new NbtTag('short', 0)],
            ['DeathTime', new NbtTag('short', 0)],
            ['AbsorptionAmount', new NbtTag('float', 0.0)],
            ['current_impulse_context_reset_grace_time', new NbtTag('int', 0)],
            ['FallFlying', new NbtTag('byte', 0)],
            ['Brain', self::nbtCompound([['memories', NbtTag::compound()]])],
            ['CanPickUpLoot', new NbtTag('byte', 0)],
            ['PersistenceRequired', new NbtTag('byte', 0)],
            ['LeftHanded', new NbtTag('byte', 0)],
        );
        if ($mob['noAi'] ?? false) {
            $pairs[] = ['NoAI', new NbtTag('byte', 1)];
        }
        if (($this->experienceReference()['mobs'][$mob['kind']]['reward_class'] ?? '') === 'Animal') {
            array_push(
                $pairs,
                ['Age', new NbtTag('int', ($mob['baby'] ?? false) ? -24000 : 0)],
                ['ForcedAge', new NbtTag('int', 0)],
                ['AgeLocked', new NbtTag('byte', 0)],
                ['InLove', new NbtTag('int', 0)],
            );
        }
        return self::nbtCompound($pairs);
    }

    /** A text component as ComponentSerialization's NBT: plain text as a string. */
    private static function componentNbt(array|string $component): NbtTag
    {
        if (is_string($component) || array_keys($component) === ['text']) {
            return new NbtTag('string', is_string($component) ? $component : (string) $component['text']);
        }
        $pairs = [];
        foreach ($component as $key => $value) {
            $pairs[] = [(string) $key, match (true) {
                is_bool($value) => new NbtTag('byte', (int) $value),
                is_int($value) => new NbtTag('int', $value),
                is_array($value) && array_is_list($value) => new NbtTag('list', array_map(self::componentNbt(...), $value)),
                is_array($value) => self::componentNbt($value),
                default => new NbtTag('string', (string) $value),
            }];
        }
        return self::nbtCompound($pairs);
    }

    /** NBT back into a text component, as CustomName is read. */
    private static function nbtComponent(NbtTag $tag): ?array
    {
        if ($tag->type === 'string') {
            return ['text' => $tag->value];
        }
        if ($tag->type !== 'compound') {
            return null;
        }
        $component = [];
        foreach ($tag->keys() as $key) {
            $value = $tag->get($key);
            $component[$key] = match ($value->type) {
                'compound' => self::nbtComponent($value),
                'list' => array_map(static fn (NbtTag $element): ?array => self::nbtComponent($element), $value->value),
                'byte' => (bool) $value->value,
                default => $value->value,
            };
        }
        return $component;
    }

    /** HashSet<String> iteration: a HashMap's key order, as the entity's tags are kept. */
    private static function hashSetOrder(array $tags): array
    {
        $set = NbtTag::compound();
        foreach ($tags as $tag) {
            $set->put((string) $tag, new NbtTag('byte', 1));
        }
        return $set->keys();
    }

    /** BlockEntity.saveWithFullMetadata for a container: its items, then the components, ID and position. */
    private function blockNbt(string $dimension, array $position): ?NbtTag
    {
        $world = $this->worlds[$dimension];
        $name = $this->version->catalog->blockName($world->get(...$position));
        $type = self::DATA_BLOCK_ENTITIES[$name] ?? (str_ends_with($name, '_hanging_sign') ? 'hanging_sign' : (str_ends_with($name, '_sign') ? 'sign' : null));
        if ($type === null) {
            return null;
        }
        $data = $world->container($position);
        $pairs = [];
        if ($type === 'sign' || $type === 'hanging_sign') {
            // SignBlockEntity.saveAdditional: both sides' text, then whether it is waxed.
            $side = static fn (array $lines): NbtTag => self::nbtCompound([
                ['messages', self::nbtList('string', array_map('strval', $lines))],
                ['color', new NbtTag('string', 'black')],
                ['has_glowing_text', new NbtTag('byte', 0)],
            ]);
            array_push(
                $pairs,
                ['front_text', $side($data['front'] ?? ['', '', '', ''])],
                ['back_text', $side($data['back'] ?? ['', '', '', ''])],
                ['is_waxed', new NbtTag('byte', 0)],
                ['components', NbtTag::compound()],
                ['id', new NbtTag('string', 'minecraft:' . $type)],
                ['x', new NbtTag('int', $position[0])],
                ['y', new NbtTag('int', $position[1])],
                ['z', new NbtTag('int', $position[2])],
            );
            return self::nbtCompound($pairs);
        }
        if ($type === 'furnace') {
            array_push(
                $pairs,
                ['lit_time_remaining', new NbtTag('int', 0)],
                ['cooking_time_spent', new NbtTag('int', 0)],
                ['cooking_total_time', new NbtTag('int', 0)],
                ['lit_total_time', new NbtTag('int', 0)],
            );
        }
        if (isset($data['customName'])) {
            $pairs[] = ['CustomName', self::componentNbt($data['customName'])];
        }
        $items = [];
        $slots = $data['slots'] ?? [];
        ksort($slots);
        foreach ($slots as $slot => $stack) {
            if ($stack !== null) {
                $items[] = $this->stackNbt($stack, (int) $slot);
            }
        }
        $pairs[] = ['Items', new NbtTag('list', $items)];
        if ($type === 'furnace') {
            array_push($pairs, ['RecipesUsed', NbtTag::compound()], ['speed_multiplier', new NbtTag('float', 1.0)]);
        }
        array_push(
            $pairs,
            ['components', NbtTag::compound()],
            ['id', new NbtTag('string', 'minecraft:' . $type)],
            ['x', new NbtTag('int', $position[0])],
            ['y', new NbtTag('int', $position[1])],
            ['z', new NbtTag('int', $position[2])],
        );
        return self::nbtCompound($pairs);
    }

    /** BlockEntity.loadWithComponents for a container: its items and name; other changes, and signs, are ignored. */
    private function setBlockNbt(string $dimension, array $position, NbtTag $tag): void
    {
        $world = $this->worlds[$dimension];
        if (str_ends_with($this->version->catalog->blockName($world->get(...$position)), '_sign')) {
            return;
        }
        $data = $world->container($position);
        $slots = [];
        foreach ($tag->get('Items')?->value ?? [] as $entry) {
            $stack = $this->nbtStack($entry);
            $slot = $entry->get('Slot');
            if ($stack !== null && $slot !== null && $slot->isNumeric()) {
                $slots[(int) $slot->value & 0xff] = $stack;
            }
        }
        $data['slots'] = $slots;
        $name = $tag->get('CustomName');
        $data['customName'] = $name === null ? null : self::nbtComponent($name);
        if ($data['customName'] === null) {
            unset($data['customName']);
        }
        $world->container($position, $data);
        $this->syncContainer($position);
    }

    /** ItemStack.CODEC read back: a known item with a positive count, its damage and enchantments. */
    private function nbtStack(NbtTag $entry): ?array
    {
        if ($entry->type !== 'compound' || $entry->get('id')?->type !== 'string') {
            return null;
        }
        $name = preg_replace('/^minecraft:/', '', $entry->get('id')->value);
        $item = $this->version->blocks['items'][$name] ?? null;
        $count = $entry->get('count');
        $count = $count === null ? 1 : ($count->isNumeric() ? (int) $count->value : 0);
        if ($item === null || $name === 'air' || $count <= 0) {
            return null;
        }
        $stack = ['item' => $item, 'count' => $count];
        $components = $entry->get('components');
        if ($components?->type === 'compound') {
            $damage = $components->get('minecraft:damage');
            if ($damage !== null && $damage->isNumeric() && (int) $damage->value > 0) {
                $stack['damage'] = (int) $damage->value;
            }
            foreach (['minecraft:enchantments' => 'enchantments', 'minecraft:stored_enchantments' => 'storedEnchantments'] as $component => $field) {
                $levels = $components->get($component);
                if ($levels?->type === 'compound' && $levels->size() > 0) {
                    foreach ($levels->keys() as $id) {
                        $stack[$field][$id] = (int) $levels->get($id)->value;
                    }
                }
            }
        }
        return $stack;
    }

    /**
     * Entity.load for a mob from a modified snapshot: the name, its visibility, silence, NoAI,
     * invulnerability, tags, health and position; other fields are not written back.
     */
    private function setMobNbt(int $id, NbtTag $tag): void
    {
        $mob = &$this->animals[$id];
        $name = $tag->get('CustomName');
        if ($name === null) {
            unset($mob['customName']);
        } else {
            $mob['customName'] = self::nbtComponent($name);
        }
        foreach (['CustomNameVisible' => 'customNameVisible', 'Silent' => 'silent', 'NoAI' => 'noAi', 'Invulnerable' => 'invulnerable'] as $key => $field) {
            $value = $tag->get($key);
            $mob[$field] = $value !== null && $value->isNumeric() && $value->value != 0;
        }
        $tags = $tag->get('Tags');
        $mob['tags'] = $tags?->type === 'list' ? array_values(array_unique(array_map(static fn (NbtTag $element): string => (string) $element->value, $tags->value))) : [];
        $health = $tag->get('Health');
        if ($health !== null && $health->isNumeric()) {
            $mob['health'] = max(0.0, min((float) $health->value, (float) ($this->mobAttributes($mob)['max_health'] ?? $mob['health'])));
        }
        $position = $tag->get('Pos');
        if ($position?->type === 'list' && $position->size() === 3 && array_filter($position->value, static fn (NbtTag $axis): bool => $axis->isNumeric()) === $position->value) {
            [$mob['x'], $mob['y'], $mob['z']] = array_map(static fn (NbtTag $axis): float => (float) $axis->value, $position->value);
            [$mob['targetX'], $mob['targetY'], $mob['targetZ']] = [$mob['x'], $mob['y'], $mob['z']];
            $mob['needsSync'] = true;
        }
        $snapshot = $mob;
        unset($mob);
        foreach ($this->localPlayers() as $viewer) {
            if (isset($viewer->entities[$id])) {
                $viewer->send($this->version, 'set_entity_data', $this->mobState($snapshot));
                $viewer->send($this->version, 'set_entity_data', \PhpMinecraftJavaServer\Protocol\Packets::mobHealth($this->version->catalog, $id, $snapshot['health']));
            }
        }
    }
}
