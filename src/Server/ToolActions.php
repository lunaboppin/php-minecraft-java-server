<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Packets;

/**
 * Block transformers (the minecraft:block_transformer component): axes strip logs and scrape or
 * unwax copper, hoes till dirt into farmland, and shovels flatten dirt into paths, each from the
 * data-driven rules in block-transformers.json, wearing the tool and sounding for others nearby.
 */
trait ToolActions
{
    private ?array $blockTransformers = null;

    private function blockTransformerData(): array
    {
        return $this->blockTransformers ??= json_decode((string) file_get_contents($this->version->directory . '/block-transformers.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** BlockPredicate for the block transformers: all_of, any_of, not, matching_blocks and matching_block_tag, each with an offset. */
    private function transformPredicate(array $predicate, array $position): bool
    {
        $offset = $predicate['offset'] ?? [0, 0, 0];
        $at = [$position[0] + $offset[0], $position[1] + $offset[1], $position[2] + $offset[2]];
        return match ($predicate['type']) {
            'minecraft:all_of' => array_reduce($predicate['predicates'], fn (bool $all, array $inner): bool => $all && $this->transformPredicate($inner, $position), true),
            'minecraft:any_of' => array_reduce($predicate['predicates'], fn (bool $any, array $inner): bool => $any || $this->transformPredicate($inner, $position), false),
            'minecraft:not' => !$this->transformPredicate($predicate['predicate'], $position),
            'minecraft:matching_blocks' => in_array('minecraft:' . $this->version->catalog->blockName($this->world->get(...$at)), (array) $predicate['blocks'], true),
            'minecraft:matching_block_tag' => in_array($this->version->catalog->blockName($this->world->get(...$at)), $this->version->registryTags('minecraft:block')[$predicate['tag']] ?? [], true),
            default => false,
        };
    }

    /** BlockStateProvider.getOptionalState: a rule-based provider's first matching rule, or a state. */
    private function transformState(array $provider, array $position): ?int
    {
        $catalog = $this->version->catalog;
        if (($provider['type'] ?? null) === 'minecraft:rule_based') {
            foreach ($provider['rules'] as $rule) {
                if ($this->transformPredicate($rule['if_true'], $position)) {
                    return $this->transformState($rule['then'], $position);
                }
            }
            return isset($provider['fallback']) ? $this->transformState($provider['fallback'], $position) : null;
        }
        if (($provider['type'] ?? null) === 'minecraft:copy_properties') {
            // CopyPropertiesProvider: the target block with every property the old state shares.
            $target = substr($provider['source']['id'], 10);
            $properties = $catalog->properties($this->world->get(...$position));
            $own = $catalog->blocks[$target]['properties'] ?? [];
            return $catalog->state($target, array_intersect_key($properties, $own) + ($provider['source']['properties'] ?? []));
        }
        return $catalog->state(substr($provider['id'], 10), $provider['properties'] ?? []);
    }

    /**
     * BlockTransformer.transformBlock: the first transform whose face is allowed and whose rules
     * give a state changes the block, drops its loot, wears the tool, and plays its sound and
     * particles for the players nearby other than the user. Returns whether it did.
     */
    private function transformBlock(Client $c, int $hand, int $slot, int $x, int $y, int $z, int $face): bool
    {
        $data = $this->blockTransformerData();
        $stack = $c->inventory[$slot] ?? null;
        $transformer = $stack === null ? null : ($data['items']['minecraft:' . $this->version->catalog->itemName($stack['item'])] ?? null);
        if ($transformer === null) {
            return false;
        }
        // BlockTransformer.playerHasBlockingItemUseIntent: a shield in the other hand blocks instead.
        $offhand = $c->inventory[45] ?? null;
        if ($hand === 0 && $offhand !== null && $this->version->catalog->itemName($offhand['item']) === 'shield' && !$c->sneaking) {
            return false;
        }
        $faces = ['down', 'up', 'north', 'south', 'west', 'east'];
        foreach ($data['transformers'][$transformer] as $transform) {
            if (in_array($faces[$face], $transform['disallowed_faces'] ?? [], true)) {
                continue;
            }
            $state = $this->transformState($transform['block_state_provider'], [$x, $y, $z]);
            if ($state === null) {
                continue;
            }
            if (isset($transform['loot'])) {
                foreach ($this->transformLoot($data['loot'][$transform['loot']]) as $drop) {
                    ($transform['drop_strategy'] ?? 'from_middle') === 'clicked_face' ? $this->popResourceFromFace($x, $y, $z, $face, $drop) : $this->popResource($x, $y, $z, $drop);
                }
            }
            if (($this->version->catalog->item($stack['item'])['max_stack'] ?? 1) > 1) {
                if (($transform['consume_on_use'] ?? true) && $c->gameMode !== 1) {
                    \PhpMinecraftJavaServer\World\Inventory::consume($c->inventory, $slot);
                }
            } else {
                $this->hurtHeldItem($c, $slot, $transform['item_damage_per_use'] ?? 1);
            }
            // ItemStack.useOn counts a use that did something.
            $this->awardStat($c, 'used', $this->version->catalog->itemName($stack['item']));
            $this->syncInventory($c);
            $this->setBlock($x, $y, $z, $state);
            // Level.playSound and levelEvent with the player: everyone near but them.
            if (isset($transform['sound'])) {
                $payload = Packets::sound($data['sounds'][$transform['sound']], 4, $x + 0.5, $y + 0.5, $z + 0.5, 1.0, 1.0, unpack('J', random_bytes(8))[1]);
                foreach ($this->localPlayers() as $player) {
                    if ($player !== $c && ($player->x - $x - 0.5) ** 2 + ($player->y - $y - 0.5) ** 2 + ($player->z - $z - 0.5) ** 2 < 256.0) {
                        $player->send($this->version, 'sound', $payload);
                    }
                }
            }
            $event = ['scrape' => 3005, 'wax_on' => 3003, 'wax_off' => 3004][$transform['particle'] ?? 'none'] ?? null;
            if ($event !== null) {
                $payload = pack('N', $event) . \PhpMinecraftJavaServer\Protocol\Binary::writePosition($x, $y, $z) . pack('N', 0) . "\0";
                foreach ($this->localPlayers() as $player) {
                    if ($player !== $c && isset($player->loadedChunks[($x >> 4) . ':' . ($z >> 4)])) {
                        $player->send($this->version, 'level_event', $payload);
                    }
                }
            }
            return true;
        }
        return false;
    }

    /** CampfireBlock.useItemOn: a shovel (douses_campfires) puts out a lit campfire, wearing by one. */
    private function douseCampfire(Client $c, int $slot, int $x, int $y, int $z): bool
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $name = $catalog->blockName($state);
        $stack = $c->inventory[$slot] ?? null;
        if (!in_array($name, ['campfire', 'soul_campfire'], true) || $stack === null || $catalog->properties($state)['lit'] !== 'true'
            || !in_array($catalog->itemName($stack['item']), $this->version->registryTags('minecraft:item')['minecraft:douses_campfires'] ?? [], true)) {
            return false;
        }
        $this->levelEvent(1009, [$x, $y, $z], 0);
        $this->setBlock($x, $y, $z, $catalog->state($name, ['lit' => 'false'] + $catalog->properties($state)));
        $this->hurtHeldItem($c, $slot, 1);
        $this->syncInventory($c);
        return true;
    }

    /** A block-interact loot table of fixed single-item pools, as the transformers use. */
    private function transformLoot(array $table): array
    {
        $drops = [];
        foreach ($table['pools'] ?? [] as $pool) {
            foreach ($pool['entries'] as $entry) {
                if ($entry['type'] === 'minecraft:item') {
                    $count = ($entry['functions'][0] ?? $entry['modifier'] ?? null)['count'] ?? 1;
                    $drops[] = ['item' => $this->version->blocks['items'][substr($entry['name'], 10)], 'count' => is_int($count) ? $count : 1];
                }
            }
        }
        return $drops;
    }

    /** Block.popResourceFromFace: just outside the clicked face, pushed away from it. */
    private function popResourceFromFace(int $x, int $y, int $z, int $face, array $stack): void
    {
        [$sx, $sy, $sz] = [[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]][$face];
        $between = static fn (float $low, float $high): float => $low + self::randomUnit() * ($high - $low);
        $position = [
            $x + 0.5 + ($sx === 0 ? $between(-0.25, 0.25) : $sx * 0.625),
            $y + 0.5 + ($sy === 0 ? $between(-0.25, 0.25) : $sy * 0.625) - 0.125,
            $z + 0.5 + ($sz === 0 ? $between(-0.25, 0.25) : $sz * 0.625),
        ];
        $velocity = [$sx === 0 ? $between(-0.1, 0.1) : $sx * 0.1, $sy === 0 ? $between(0.0, 0.1) : $sy * 0.1 + 0.1, $sz === 0 ? $between(-0.1, 0.1) : $sz * 0.1];
        if ($this->gameRule('block_drops') === 'true') {
            $this->dropStack(...[...$position, $stack, 0, $velocity, 10]);
        }
    }

    /**
     * ItemStack.hurtAndBreak for a player's item: each point of wear may be saved by Unbreaking
     * (one chance in its level plus one to wear), none in creative; a worn-out item breaks.
     */
    private function hurtHeldItem(Client $c, int $slot, int $amount): void
    {
        $stack = $c->inventory[$slot] ?? null;
        $max = $stack === null ? 0 : ($this->version->catalog->item($stack['item'])['durability'] ?? 0);
        if ($stack === null || $max <= 0 || $c->gameMode === 1 || $amount <= 0) {
            return;
        }
        $unbreaking = $stack['enchantments']['minecraft:unbreaking'] ?? 0;
        $wear = 0;
        for ($point = 0; $point < $amount; ++$point) {
            if ($unbreaking <= 0 || random_int(0, $unbreaking) === 0) {
                ++$wear;
            }
        }
        if ($wear === 0) {
            return;
        }
        $stack['damage'] = ($stack['damage'] ?? 0) + $wear;
        if ($stack['damage'] >= $max) {
            $this->awardStat($c, 'broken', $this->version->catalog->itemName($stack['item']));
            unset($c->inventory[$slot]);
            return;
        }
        $c->inventory[$slot] = $stack;
    }
}
