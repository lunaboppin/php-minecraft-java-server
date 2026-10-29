<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\Protocol\Nbt;
use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Inventory;

trait Survival
{
    /** The overworld clock's total ticks; a new vanilla world's clock starts at 0. */
    private int $dayTime = 0;

    /** ServerClockInstance: a paused clock does not advance; its rate is a Java float. */
    private bool $clockPaused = false;

    private float $clockRate = 1.0;

    private float $clockPartial = 0.0;

    /** The End's own clock (minecraft:the_end), which runs alongside the Overworld's. */
    private ?int $endClockTicks = null;

    private bool $endClockPaused = false;

    private float $endClockRate = 1.0;

    private float $endClockPartial = 0.0;

    private int $worldAge = 0;

    private bool $daylightCycle = true;

    private bool $keepInventory = false;

    private bool $naturalRegeneration = true;

    private bool $spawnMobs = true;

    private string $weather = 'clear';

    private function restorePlayer(Client $c): void
    {
        $c->gameMode = $this->config->defaultGameMode;
        [$c->x, $c->y, $c->z] = $this->world->spawn();
        $data = $this->worlds['overworld']->loadPlayer($c->uuid, $c->authenticated);
        if ($data === null) {
            return;
        }
        $c->dimension = in_array($data['dimension'] ?? 'overworld', array_keys($this->worlds), true) ? ($data['dimension'] ?? 'overworld') : 'overworld';
        $c->gameMode = max(0, min(3, (int) ($data['gameMode'] ?? $c->gameMode)));
        foreach (['x', 'y', 'z', 'yaw', 'pitch'] as $field) {
            if (isset($data[$field]) && is_numeric($data[$field]) && is_finite((float) $data[$field])) {
                $c->$field = (float) $data[$field];
            }
        }
        if (abs($c->x) > 29999900 || abs($c->z) > 29999900 || $c->y < $this->worlds[$c->dimension]->minY || $c->y >= $this->worlds[$c->dimension]->minY + $this->worlds[$c->dimension]->height) {
            [$c->x, $c->y, $c->z] = $this->worlds[$c->dimension]->spawn();
        }
        $c->effects = array_filter($data['effects'] ?? [], fn (mixed $instance, mixed $effect): bool => is_array($instance) && isset($this->effectDefinitions()[$effect]), ARRAY_FILTER_USE_BOTH);
        $this->loadAttributes($c, $data['attributes'] ?? []);
        $c->health = max(0, min($this->maxHealth($c), (float) ($data['health'] ?? 20)));
        $c->absorption = max(0.0, (float) ($data['absorption'] ?? 0.0));
        $c->food = max(0, min(20, (int) ($data['food'] ?? 20)));
        $c->saturation = max(0, min($c->food, (float) ($data['saturation'] ?? 5)));
        $c->experience = max(0, (int) ($data['experience'] ?? 0));
        [$c->experienceLevel, $c->experienceProgress] = isset($data['experienceLevel']) ? [max(0, (int) $data['experienceLevel']), max(0.0, min(1.0, (float) $data['experienceProgress']))] : self::experienceFromTotal($c->experience);
        $c->heldSlot = max(0, min(8, (int) ($data['heldSlot'] ?? 0)));
        $c->dead = (bool) ($data['dead'] ?? false);
        $c->personalSpawn = $data['personalSpawn'] ?? null;
        // Persist a duration, since the process tick counter restarts from zero.
        $c->portalCooldown = $this->ticks + max(0, min(300, (int) ($data['portalCooldown'] ?? 0)));
        foreach ($data['inventory'] ?? [] as $slot => $stack) {
            $item = $this->version->catalog->item((int) ($stack['item'] ?? -1));
            if ($slot >= 1 && $slot <= 45 && $item !== null && ($stack['count'] ?? 0) > 0) {
                $stack['item'] = (int) $stack['item'];
                $stack['count'] = min($item['max_stack'], (int) $stack['count']);
                $restored = $this->version->catalog->itemStacks->canonical($stack);
                if ($restored !== null) {
                    $c->inventory[(int) $slot] = $restored;
                }
            }
        }
    }

    private function savePlayer(Client $c): void
    {
        if ($c->uuid === '' || $c->entityId === 0) {
            return;
        }
        $this->worlds['overworld']->savePlayer($c->uuid, ['dimension' => $c->dimension, 'gameMode' => $c->gameMode, 'x' => $c->x, 'y' => $c->y, 'z' => $c->z, 'yaw' => $c->yaw, 'pitch' => $c->pitch, 'health' => $c->health, 'food' => $c->food, 'saturation' => $c->saturation, 'experience' => $c->experience, 'experienceLevel' => $c->experienceLevel, 'experienceProgress' => $c->experienceProgress, 'effects' => $c->effects, 'attributes' => $this->savedAttributes($c), 'absorption' => $c->absorption, 'inventory' => $c->inventory, 'heldSlot' => $c->heldSlot, 'dead' => $c->dead, 'personalSpawn' => $c->personalSpawn, 'portalCooldown' => max(0, $c->portalCooldown - $this->ticks)], $c->authenticated);
    }

    private function saveWorld(): void
    {
        foreach ($this->players() as $player) {
            $this->savePlayer($player);
        }
        foreach (['worldAge', 'dayTime', 'daylightCycle', 'weather', 'keepInventory', 'naturalRegeneration', 'spawnMobs', 'clockPaused', 'clockRate', 'clockPartial', 'endClockTicks', 'endClockPaused', 'endClockRate', 'endClockPartial'] as $key) {
            $this->worlds['overworld']->setting($key, $this->$key);
        }
        foreach (array_keys($this->worlds) as $dimension) {
            $this->withDimension($dimension, function (): void {
                $this->saveBlockSimulation();
                $this->world->setting('animals', $this->animals);
                $this->world->setting('naturalSpawns', $this->naturalSpawns);
                $this->world->checkpoint();
            });
        }
    }

    private function health(Client $c): void
    {
        if ($c->dimension !== $this->world->dimension) {
            $this->withDimension($c->dimension, fn () => $this->health($c));
            return;
        }
        $c->send($this->version, 'set_health', pack('G', $c->health) . Binary::writeVarInt($c->food) . pack('G', $c->saturation));
        $c->lastSentVitals = [$c->health, $c->food, $c->saturation == 0.0];
        $this->syncPlayerMetadata($c);
        $this->syncExperience($c);
    }

    private function abilities(Client $c): void
    {
        $flags = match ($c->gameMode) {
            1 => 0x0d,3 => 0x07,default => 0
        };
        if ($c->gameMode === 3 || ($c->gameMode === 1 && $c->flying)) {
            $flags |= 2;
        }
        $c->send($this->version, 'player_abilities', chr($flags) . pack('GG', 0.05, 0.1));
    }

    private function setGameMode(Client $c, int $mode): void
    {
        if ($c->dimension !== $this->world->dimension) {
            $this->withDimension($c->dimension, fn () => $this->setGameMode($c, $mode));
            return;
        }
        $previous = $c->gameMode;
        $c->gameMode = $mode;
        $c->flying = $mode === 3;
        $c->fallDistance = 0;
        $c->mining = null;
        $this->abilities($c);
        $c->send($this->version, 'game_event', "\3" . pack('G', $mode));
        // Creative mode adds the interaction range modifiers, which are then sent as changed.
        if (($previous === 1) !== ($mode === 1)) {
            $c->send($this->version, 'update_attributes', Packets::attributeModifiers($this->version->catalog, $c->entityId, $this->interactionRanges($c)));
        }
        foreach ($this->players() as $p) {
            $p->send($this->version, 'player_info_update', "\4\1" . $c->uuid . Binary::writeVarInt($mode));
            $this->refreshTracking($p);
        }
        $this->syncPlayerMetadata($c);
        $this->savePlayer($c);
    }

    /**
     * Player interaction ranges: base values, with Player's creative mode modifiers. Vanilla lists
     * changed attributes in hash order; these follow the order the official server sends.
     */
    private function interactionRanges(Client $c): array
    {
        $creative = $c->gameMode === 1;
        return [
            ['entity_interaction_range', 3.0, $creative ? [['minecraft:creative_mode_entity_range', 2.0, 0]] : []],
            ['block_interaction_range', 4.5, $creative ? [['minecraft:creative_mode_block_range', 0.5, 0]] : []],
        ];
    }

    /**
     * The attributes changed while a player is created: both interaction ranges, as the game
     * mode's modifiers are applied, and the movement speed set from the abilities' walking speed.
     */
    private function joinAttributes(Client $c): string
    {
        $speed = $this->attributeEntry('player', $c->attributeBases, $c->attributeModifiers, 'movement_speed');
        $ranges = $this->interactionRanges($c);
        return Packets::attributeModifiers($this->version->catalog, $c->entityId, $c->gameMode === 1 ? [$speed, ...$ranges] : [...array_reverse($ranges), $speed]);
    }

    /**
     * LivingEntity.hurtServer for players with a vanilla damage type. Returns whether the player was hurt.
     * Armor, enchantments and effects do not reduce damage yet (#31, #23).
     */
    private function damage(Client $c, float $amount, string $type, ?Client $attacker = null): bool
    {
        if ($c->dimension !== $this->world->dimension) {
            return $this->withDimension($c->dimension, fn () => $this->damage($c, $amount, $type, $attacker));
        }
        $type = str_contains($type, ':') ? $type : 'minecraft:' . $type;
        $tags = $this->version->catalog->damageTypes['tags'];
        // Creative and spectator players are invulnerable unless the damage type bypasses it.
        if ($c->dead || (in_array($c->gameMode, [1, 3], true) && !in_array($type, $tags['minecraft:bypasses_invulnerability'], true))) {
            return false;
        }
        // Player.isInvulnerableTo: the damage-type game rules.
        foreach (['minecraft:is_drowning' => 'drowning_damage', 'minecraft:is_fall' => 'fall_damage', 'minecraft:is_fire' => 'fire_damage', 'minecraft:is_freezing' => 'freeze_damage'] as $tag => $rule) {
            if (in_array($type, $tags[$tag], true) && $this->gameRule($rule) !== 'true') {
                return false;
            }
        }
        // LivingEntity.hurtServer: fire resistance ignores fire damage entirely.
        if (isset($c->effects['minecraft:fire_resistance']) && in_array($type, $tags['minecraft:is_fire'], true)) {
            return false;
        }
        // During the invulnerability window only a stronger hit lands, for the difference.
        if ($this->ticks - $c->lastDamageTick < 10) {
            if ($amount <= $c->lastDamageAmount) {
                return false;
            }
            [$amount, $c->lastDamageAmount] = [$amount - $c->lastDamageAmount, $amount];
        } else {
            [$c->lastDamageTick, $c->lastDamageAmount] = [$this->ticks, $amount];
        }
        if ($attacker !== null) {
            [$c->lastHurtByPlayer, $c->lastHurtByPlayerTick] = [$attacker, $this->ticks];
        }
        // getDamageAfterMagicAbsorb: resistance reduces what is taken, then absorption takes its share.
        $amount = $this->effectDamage($c, $amount, $type);
        if ($c->absorption > 0.0) {
            $absorbed = min($c->absorption, $amount);
            $this->setAbsorption($c, $c->absorption - $absorbed);
            $amount -= $absorbed;
        }
        $c->health = max(0, $c->health - $amount);
        $this->health($c);
        // Level.broadcastDamageEvent; the hurt or death sound, which the player hears locally; then
        // the death event when the hit was lethal.
        $event = Packets::damageEvent($this->version, $c->entityId, $attacker?->entityId ?? -1, substr($type, 10));
        $effects = $this->version->catalog->damageTypes['types'][$type]['effects'] ?? 'hurt';
        $sound = $c->health <= 0 ? 'entity.player.death' : match ($effects) {
            'drowning' => 'entity.player.hurt_drown',
            'burning' => 'entity.player.hurt_on_fire',
            'freezing' => 'entity.player.hurt_freeze',
            'poking' => 'entity.player.hurt_sweet_berry_bush',
            default => 'entity.player.hurt',
        };
        $random = static fn (): float => mt_rand() / (mt_getrandmax() + 1);
        $pitch = unpack('G', pack('G', ($random() - $random()) * 0.2 + 1.0))[1];
        $noise = Packets::sound($this->version->blocks['sounds'][$sound], 7, $c->x, $c->y, $c->z, 1.0, $pitch, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $p) {
            if ($p === $c || isset($p->entities[$c->entityId])) {
                $p->send($this->version, 'damage_event', $event);
            }
            if ($p !== $c && ($p->x - $c->x) ** 2 + ($p->y - $c->y) ** 2 + ($p->z - $c->z) ** 2 < 16 ** 2) {
                $p->send($this->version, 'sound', $noise);
            }
            if ($c->health <= 0 && ($p === $c || isset($p->entities[$c->entityId]))) {
                $p->send($this->version, 'entity_event', pack('N', $c->entityId) . "\3");
            }
        }
        if ($c->health <= 0) {
            $c->dead = true;
            // The respawned player starts without effects or absorption.
            [$c->effects, $c->absorption] = [[], 0.0];
            // ServerPlayer.restoreFrom keeps base values and permanent modifiers only.
            $c->attributeModifiers = array_filter(array_map(static fn (array $modifiers): array => array_filter($modifiers, static fn (array $modifier): bool => $modifier[2]), $c->attributeModifiers));
            $c->mining = null;
            $c->eating = null;
            $this->closeWindow($c, true);
            if (!$this->keepInventory) {
                foreach ($c->inventory as $slot => $stack) {
                    $this->dropStack($c->x, $c->y + 0.5, $c->z, $stack);
                }$c->inventory = [];
                [$c->experience, $c->experienceLevel, $c->experienceProgress] = [0, 0, 0.0];
            }
            // ServerPlayer.die: the death screen always opens; the message is shared only if the rule allows it.
            $message = $this->deathMessage($c, $type, $attacker);
            if ($this->gameRule('show_death_messages') === 'true') {
                $c->send($this->version, 'player_combat_kill', Binary::writeVarInt($c->entityId) . Nbt::component($message));
                // The team's deathMessageVisibility decides who else sees it.
                $this->log('[CHAT] ' . $c->name . ' died: ' . $message['translate']);
                foreach ($this->deathMessageRecipients($c) as $player) {
                    $player->send($this->version, 'system_chat', Packets::systemChat($message));
                }
            } else {
                $c->send($this->version, 'player_combat_kill', Binary::writeVarInt($c->entityId) . Nbt::component(['text' => '']));
            }
            // ServerPlayer.die: the death counts, then the killer's kill counts (awardKillScore).
            $this->incrementCriteria('deathCount', $c->name);
            $killer = $c->lastHurtByPlayer !== null && $this->ticks - $c->lastHurtByPlayerTick <= 100 && in_array($c->lastHurtByPlayer, $this->players(), true) ? $c->lastHurtByPlayer : null;
            if ($killer !== null && $killer !== $c) {
                $this->incrementCriteria('totalKillCount', $killer->name);
                $this->incrementCriteria('playerKillCount', $killer->name);
            }
            $this->savePlayer($c);
        }
        return true;
    }

    /** CombatTracker.getDeathMessage for the killing blow. */
    private function deathMessage(Client $c, string $type, ?Client $attacker): array
    {
        $data = $this->version->catalog->damageTypes['types'][$type] ?? throw new \LogicException('Unknown damage type ' . $type);
        $victim = $this->displayName($c);
        // Fall variants apply to a significant fall of more than five blocks; there is no
        // ladder, vine or water fall location yet, so it is the generic accident.
        if (($data['death_message_type'] ?? 'default') === 'fall_variants' && $c->fallDistance > 5) {
            return ['translate' => 'death.fell.accident.generic', 'with' => [$victim]];
        }
        $key = 'death.attack.' . $data['message_id'];
        if ($attacker !== null) {
            return ['translate' => $key, 'with' => [$victim, $this->displayName($attacker)]];
        }
        // Without a direct attacker, a player who hurt the victim in the last 100 ticks is credited.
        $credit = $c->lastHurtByPlayer !== null && $this->ticks - $c->lastHurtByPlayerTick <= 100 && in_array($c->lastHurtByPlayer, $this->players(), true) ? $c->lastHurtByPlayer : null;
        return $credit === null
            ? ['translate' => $key, 'with' => [$victim]]
            : ['translate' => $key . '.player', 'with' => [$victim, $this->displayName($credit)]];
    }

    private function respawnPlayer(Client $c): void
    {
        if ($c->dimension !== $this->world->dimension) {
            $this->withDimension($c->dimension, fn () => $this->respawnPlayer($c));
            return;
        }
        if (!$c->dead) {
            return;
        }$c->dead = false;
        $c->health = 20;
        $c->food = 20;
        $c->saturation = 5;
        $c->fallDistance = 0;
        $c->fireTicks = 0;
        $c->air = 300;
        // PlayerList.respawn: the new player joins the end of the player list.
        $key = array_search($c, $this->clients, true);
        if ($key !== false) {
            unset($this->clients[$key]);
            $this->clients[$key] = $c;
        }
        // The respawned player applies its equipment's attribute modifiers afresh.
        $c->equipmentModifierStacks = [];
        $this->applyEquipmentAttributes($c, $this->playerEquipment($c));
        $this->changeDimension($c, 'overworld', $c->personalSpawn ?? $this->worlds['overworld']->spawn(), true);
    }

    private function miningTicks(Client $c, int $state): int
    {
        $name = $this->version->catalog->blockName($state);
        $info = $this->version->catalog->blocks[$name];
        if ($info['hardness'] < 0) {
            return PHP_INT_MAX;
        }
        $held = $c->inventory[36 + $c->heldSlot]['item'] ?? -1;
        $tool = $this->version->catalog->itemName($held) ?? '';
        $correct = $this->correctTool($tool, $name);
        $speed = 1;
        if ($correct && str_contains($tool, '_')) {
            $material = explode('_', $tool)[0];
            $speed = match ($material) {
                'wooden' => 2,'stone' => 4,'iron' => 6,'golden' => 12,'diamond' => 8,'netherite' => 9,default => 1
            };
        }
        return max(1, (int) ceil($info['hardness'] * ($info['requires_tool'] && !$correct ? 100 : 30) / $speed));
    }

    private function correctTool(string $tool, string $block): bool
    {
        if (str_contains($block, 'obsidian') || $block === 'ancient_debris') {
            return in_array($tool, ['diamond_pickaxe', 'netherite_pickaxe'], true);
        }
        if (str_contains($block, 'diamond_ore') || str_contains($block, 'gold_ore') || str_contains($block, 'redstone_ore') || str_contains($block, 'emerald_ore')) {
            return in_array($tool, ['iron_pickaxe', 'diamond_pickaxe', 'netherite_pickaxe'], true);
        }
        if (str_contains($block, 'iron_ore') || str_contains($block, 'copper_ore') || str_contains($block, 'lapis_ore')) {
            return str_ends_with($tool, '_pickaxe') && !str_starts_with($tool, 'wooden_') && !str_starts_with($tool, 'golden_');
        }
        $required = $this->version->catalog->blocks[$block]['requires_tool'];
        if ($required) {
            return str_ends_with($tool, '_pickaxe');
        }
        if (str_ends_with($block, '_log') || str_ends_with($block, '_planks') || str_contains($block, 'wood') || in_array($block, ['chest', 'crafting_table'], true)) {
            return str_ends_with($tool, '_axe');
        }
        if (in_array($block, ['dirt', 'grass_block', 'sand', 'gravel', 'snow'], true)) {
            return str_ends_with($tool, '_shovel');
        }
        return false;
    }

    private function loot(): \PhpMinecraftJavaServer\World\Loot
    {
        static $loot = null;
        if ($loot !== null) {
            return $loot;
        }
        $tag = function (string $registry, string $tag): array {
            if ($registry === 'minecraft:damage_type') {
                return $this->version->catalog->damageTypes['tags'][$tag] ?? [];
            }
            return array_map(static fn (string $name): string => 'minecraft:' . $name, $this->version->registryTags($registry)[$tag] ?? []);
        };
        // Smelting results by input item, from the official recipes.
        $items = array_flip($this->version->blocks['items']);
        $smelting = [];
        foreach (json_decode((string) file_get_contents($this->version->directory . '/recipes.json'), true, 512, JSON_THROW_ON_ERROR) as $recipe) {
            if ($recipe['type'] === 'smelting') {
                foreach ($recipe['ingredients'][0] as $input) {
                    $smelting['minecraft:' . $items[$input]] ??= 'minecraft:' . $items[$recipe['result']['item']];
                }
            }
        }
        $data = json_decode((string) file_get_contents($this->version->directory . '/loot.json'), true, 512, JSON_THROW_ON_ERROR);
        return $loot = new \PhpMinecraftJavaServer\World\Loot($data, $tag, static fn (string $item): ?string => $smelting[$item] ?? null);
    }

    private function breakBlock(Client $c, int $x, int $y, int $z): void
    {
        $state = $this->world->get($x, $y, $z);
        $name = $this->version->catalog->blockName($state);
        if ($this->version->catalog->air($state)) {
            return;
        }
        $containerData = in_array($name, ['chest', 'barrel', 'furnace'], true) ? $this->world->container([$x, $y, $z]) : null;
        $properties = $this->version->catalog->properties($state);
        $this->setBlock($x, $y, $z, 0);
        if (in_array($name, ['obsidian', 'nether_portal'], true)) {
            $this->invalidatePortal($x, $y, $z);
        }
        if (str_ends_with($name, '_door')) {
            $otherY = $y + (($properties['half'] ?? 'lower') === 'lower' ? 1 : -1);
            if ($this->version->catalog->blockName($this->world->get($x, $otherY, $z)) === $name) {
                $this->setBlock($x, $otherY, $z, 0);
            }
        }
        if (str_ends_with($name, '_bed')) {
            $offset = ['north' => [0, -1], 'south' => [0, 1], 'west' => [-1, 0], 'east' => [1, 0]][$properties['facing']];
            $sign = ($properties['part'] ?? 'foot') === 'foot' ? 1 : -1;
            $bx = $x + $offset[0] * $sign;
            $bz = $z + $offset[1] * $sign;
            if ($this->version->catalog->blockName($this->world->get($bx, $y, $bz)) === $name) {
                $this->setBlock($bx, $y, $bz, 0);
            }
        }
        if (in_array($name, ['chest', 'barrel', 'furnace'], true)) {
            foreach ($this->localPlayers() as $viewer) {
                if ($viewer->windowPosition === [$x, $y, $z]) {
                    $this->closeWindow($viewer, true);
                    $this->syncInventory($viewer);
                }
            }
            $data = $containerData;
            foreach ($data['slots'] as $stack) {
                $this->dropStack($x + 0.5, $y + 0.5, $z + 0.5, $stack);
            }$this->world->deleteContainer([$x, $y, $z]);
        }
        if ($c->gameMode !== 0) {
            return;
        }
        $tool = $this->version->catalog->itemName($c->inventory[36 + $c->heldSlot]['item'] ?? -1) ?? '';
        $required = $this->version->catalog->blocks[$name]['requires_tool'];
        if ((!$required || $this->correctTool($tool, $name)) && $this->gameRule('block_drops') === 'true') {
            // The block's official loot table, for the held tool.
            $held = $c->inventory[36 + $c->heldSlot] ?? null;
            $toolStack = $held === null ? null : ['item' => 'minecraft:' . $tool, 'enchantments' => $held['enchantments'] ?? []];
            $blockAt = function (int $offset) use ($x, $y, $z): array {
                $above = $this->world->get($x, $y + $offset, $z);
                return ['minecraft:' . $this->version->catalog->blockName($above), array_map('strval', $this->version->catalog->properties($above))];
            };
            $context = ['block' => 'minecraft:' . $name, 'state' => array_map('strval', $properties), 'tool' => $toolStack, 'blockAt' => $blockAt, 'this' => $this->lootEntity($c)];
            foreach ($this->loot()->drops('minecraft:blocks/' . $name, $context) as [$drop, $count]) {
                $item = $this->version->blocks['items'][substr($drop, 10)] ?? null;
                if ($item !== null) {
                    $this->dropStack($x + 0.5, $y + 0.5, $z + 0.5, ['item' => $item, 'count' => $count]);
                }
            }
            // DropExperienceBlock and RedStoneOreBlock experience, which silk touch prevents.
            $experience = ['coal_ore' => [0, 2], 'deepslate_coal_ore' => [0, 2], 'diamond_ore' => [3, 7], 'deepslate_diamond_ore' => [3, 7], 'emerald_ore' => [3, 7], 'deepslate_emerald_ore' => [3, 7], 'lapis_ore' => [2, 5], 'deepslate_lapis_ore' => [2, 5], 'nether_quartz_ore' => [2, 5], 'nether_gold_ore' => [0, 1], 'redstone_ore' => [1, 5], 'deepslate_redstone_ore' => [1, 5]][$name] ?? null;
            if ($experience !== null && !isset($toolStack['enchantments']['minecraft:silk_touch'])) {
                $this->giveExperiencePoints($c, random_int(...$experience));
            }
        }
        $slot = 36 + $c->heldSlot;
        $held = $c->inventory[$slot] ?? null;
        if ($held !== null && ($max = $this->version->catalog->item($held['item'])['durability']) > 0) {
            $held['damage'] = ($held['damage'] ?? 0) + 1;
            if ($held['damage'] >= $max) {
                unset($c->inventory[$slot]);
            } else {
                $c->inventory[$slot] = $held;
            }$this->syncInventory($c);
        }
        $c->exhaustion += 0.005;
    }

    private function survivalMovement(Client $c, float $oldY, float $distance): void
    {
        if ($c->gameMode !== 0 || $c->dead) {
            return;
        }$c->exhaustion += $distance * ($c->sprinting ? 0.1 : 0.01);
        $feet = $this->world->get((int) floor($c->x), (int) floor($c->y), (int) floor($c->z));
        if ($this->version->catalog->fluid($feet)) {
            $c->fallDistance = 0;
        } else {
            if ($c->y < $oldY) {
                $c->fallDistance += $oldY - $c->y;
            }if ($c->ground) {
                if ($c->fallDistance > 3) {
                    $this->damage($c, floor($c->fallDistance - 3), 'fall');
                }$c->fallDistance = 0;
            }
        }
    }

    private function tickSurvival(Client $c): void
    {
        $selected = $c->inventory[36 + $c->heldSlot]['item'] ?? -1;
        if ($selected !== $c->lastAttackItem) {
            $c->lastAttackItem = $selected;
            $c->lastAttackTick = $this->ticks;
        }
        if ($c->dead) {
            return;
        }
        $this->tickEffects($c);
        if ($c->dead) {
            return;
        }
        if (!$this->validWindow($c)) {
            $this->closeWindow($c, true);
            $this->syncInventory($c);
        }
        if ($c->mining !== null) {
            $mine = $c->mining;
            [$x, $y, $z] = $mine['position'];
            if (!$this->editable($c, $x, $y, $z) || $this->world->get($x, $y, $z) !== $mine['state']) {
                $c->mining = null;
            } elseif ($this->ticks - $mine['started'] >= $mine['duration']) {
                $this->breakBlock($c, $x, $y, $z);
                $c->mining = null;
            }
        }
        if ($c->eating !== null && $this->ticks >= $c->eating['complete']) {
            $slot = $c->eating['slot'];
            $stack = $c->inventory[$slot] ?? null;
            if ($stack !== null && $stack['item'] === $c->eating['item']) {
                $food = $this->version->catalog->item($stack['item']);
                $c->food = min(20, $c->food + $food['nutrition']);
                $c->saturation = min($c->food, $c->saturation + $food['saturation']);
                if ($c->gameMode === 0) {
                    Inventory::consume($c->inventory, $slot);
                }$this->health($c);
                $this->applyConsumeEffects($c, 'minecraft:' . $this->version->catalog->itemName($c->eating['item']));
                $this->syncInventory($c);
                $c->send($this->version, 'entity_event', pack('N', $c->entityId) . "\x09");
            }$c->eating = null;
        }
        // LivingEntity.baseTick: suffocating in a wall, otherwise the world border.
        if (!$c->dead && in_array($c->gameMode, [0, 2], true)) {
            $eye = $c->sneaking && !$c->flying ? 1.27 : 1.62;
            if ($this->inWall($c->x, $c->y + $eye, $c->z, 0.6)) {
                $this->damage($c, 1.0, 'in_wall');
            } else {
                $this->borderDamage($c);
            }
        }
        $this->tickFood($c);
        if ($c->gameMode !== 0 || $this->ticks % 20 !== 0) {
            return;
        }
        // Entity.checkBelowWorld: out_of_world damage starts 64 blocks below the world.
        if ($c->y < $this->world->minY - 64) {
            $this->damage($c, 4, 'out_of_world');
            return;
        }
        $head = $this->world->get((int) floor($c->x), (int) floor($c->y + 1.62), (int) floor($c->z));
        $feet = $this->world->get((int) floor($c->x), (int) floor($c->y), (int) floor($c->z));
        $water = str_contains($this->version->catalog->blockName($head), 'water');
        if ($water) {
            $c->air -= 20;
            if ($c->air < 0) {
                $this->damage($c, 2, 'drown');
            }
        } else {
            $c->air = min(300, $c->air + 80);
        }
        if ($this->version->catalog->blockName($feet) === 'lava') {
            $c->fireTicks = 160;
            $this->damage($c, 4, 'lava');
        } elseif ($this->version->catalog->blockName($feet) === 'water') {
            $c->fireTicks = 0;
        } elseif ($c->fireTicks > 0) {
            $c->fireTicks -= 20;
            $this->damage($c, 1, 'on_fire');
        }
    }

    /**
     * ServerPlayer.tickRegeneration (peaceful only) and FoodData.tick, every tick after the living
     * tick: exhaustion past 4 costs saturation or food, then saturated healing every 10 ticks,
     * ordinary healing every 80, or starvation. Changed health or food is then sent.
     */
    private function tickFood(Client $c): void
    {
        if ($c->dead) {
            return;
        }
        ++$c->livingTicks;
        $peaceful = $this->config->difficulty === 0;
        if ($peaceful && $this->naturalRegeneration) {
            if ($c->livingTicks % 20 === 0) {
                $this->heal($c, 1.0);
                if ($c->saturation < 20.0) {
                    $c->saturation += 1.0;
                }
            }
            if ($c->livingTicks % 10 === 0 && $c->food < 20) {
                ++$c->food;
            }
        }
        if ($c->exhaustion > 4.0) {
            $c->exhaustion = Float32::round($c->exhaustion - 4.0);
            if ($c->saturation > 0.0) {
                $c->saturation = max(Float32::round($c->saturation - 1.0), 0.0);
            } elseif (!$peaceful) {
                $c->food = max($c->food - 1, 0);
            }
        }
        $hurt = $c->health > 0.0 && $c->health < $this->maxHealth($c);
        if ($this->naturalRegeneration && $c->saturation > 0.0 && $hurt && $c->food >= 20) {
            if (++$c->foodTimer >= 10) {
                $spent = min($c->saturation, 6.0);
                $this->heal($c, Float32::round($spent / 6.0));
                $c->exhaustion = min(Float32::round($c->exhaustion + $spent), 40.0);
                $c->foodTimer = 0;
            }
        } elseif ($this->naturalRegeneration && $c->food >= 18 && $hurt) {
            if (++$c->foodTimer >= 80) {
                $this->heal($c, 1.0);
                $c->exhaustion = min(Float32::round($c->exhaustion + 6.0), 40.0);
                $c->foodTimer = 0;
            }
        } elseif ($c->food <= 0) {
            if (++$c->foodTimer >= 80) {
                if ($c->health > 10.0 || $this->config->difficulty === 3 || ($c->health > 1.0 && $this->config->difficulty === 2)) {
                    $this->damage($c, 1.0, 'starve');
                }
                $c->foodTimer = 0;
            }
        } else {
            $c->foodTimer = 0;
        }
        // ServerPlayer.doTick sends health when it, the food level or an empty saturation changes.
        if ([$c->health, $c->food, $c->saturation == 0.0] !== $c->lastSentVitals) {
            $this->health($c);
        }
    }

    /** LivingEntity.heal: living players gain health up to their maximum. */
    private function heal(Client $c, float $amount): void
    {
        if ($c->health > 0.0) {
            $c->health = min($this->maxHealth($c), Float32::round($c->health + $amount));
        }
    }

    private function useHeldItem(Client $c, int $hand): void
    {
        if ($c->dead || $c->gameMode === 3) {
            return;
        }$slot = $hand === 0 ? 36 + $c->heldSlot : 45;
        $stack = $c->inventory[$slot] ?? null;
        if ($stack === null) {
            return;
        }
        if ($this->useSpawnEggInAir($c, $slot)) {
            return;
        }
        $info = $this->version->catalog->item($stack['item']);
        // Foods with canAlwaysEat are eaten at full hunger; Consumable.consumeTicks sets the time.
        $name = 'minecraft:' . $this->version->catalog->itemName($stack['item']);
        if (isset($info['nutrition']) && ($c->food < 20 || in_array($name, $this->consumables()['always_edible'], true))) {
            $ticks = (int) (($this->consumables()['items'][$name]['consume_seconds'] ?? 1.6) * 20);
            $c->eating = ['slot' => $slot, 'item' => $stack['item'], 'complete' => $this->ticks + $ticks];
            return;
        }
        if ($this->version->catalog->itemName($stack['item']) === 'bucket') {
            $yaw = deg2rad($c->yaw);
            $pitch = deg2rad($c->pitch);
            $direction = [-sin($yaw) * cos($pitch), -sin($pitch), cos($yaw) * cos($pitch)];
            for ($step = 0; $step <= 50; ++$step) {
                $distance = $step / 10;
                $x = (int) floor($c->x + $direction[0] * $distance);
                $y = (int) floor($c->y + 1.62 + $direction[1] * $distance);
                $z = (int) floor($c->z + $direction[2] * $distance);
                $state = $this->world->get($x, $y, $z);
                $name = $this->version->catalog->blockName($state);
                if (in_array($name, ['water', 'lava'], true)) {
                    $this->setBlock($x, $y, $z, 0);
                    if ($c->gameMode !== 1) {
                        Inventory::consume($c->inventory, $slot);
                        $this->giveStack($c, ['item' => $this->version->blocks['items'][$name . '_bucket'], 'count' => 1]);
                    }$this->syncInventory($c);
                    return;
                }
                if (!$this->version->catalog->air($state) && !$this->version->catalog->blocks[$name]['replaceable']) {
                    break;
                }
            }
        }
    }

    private function attackEntity(Client $attacker, int $id): void
    {
        if ($attacker->dead || $attacker->gameMode === 3) {
            return;
        }
        if ($this->attackAnimal($attacker, $id)) {
            return;
        }
        if ($this->ticks - $attacker->lastAttackTick < 10) {
            return;
        }
        foreach ($this->localPlayers() as $target) {
            // Player.canHarmPlayer: PvP must be on, and team mates without friendly fire are spared.
            if ($target->entityId !== $id || $target === $attacker || !$this->config->pvp || $this->gameRule('pvp') !== 'true' || $this->friendlyFireBlocked($attacker, $target)) {
                continue;
            }
            if (($target->x - $attacker->x) ** 2 + ($target->y - $attacker->y) ** 2 + ($target->z - $attacker->z) ** 2 > 4.5 ** 2) {
                return;
            }
            $attacker->lastAttackTick = $this->ticks;
            $tool = $this->version->catalog->itemName($attacker->inventory[36 + $attacker->heldSlot]['item'] ?? -1) ?? '';
            $damage = 1;
            if (str_ends_with($tool, '_sword') || str_ends_with($tool, '_axe')) {
                $material = explode('_', $tool)[0];
                $damage = match ($material) {
                    'wooden','golden' => 4,'stone' => 5,'iron' => 6,'diamond' => 7,'netherite' => 8,default => 1
                };
            }
            $this->damage($target, $this->effectAttackDamage($attacker, (float) $damage), 'player_attack', $attacker);
            $attacker->exhaustion += 0.1;
            return;
        }
    }
}
