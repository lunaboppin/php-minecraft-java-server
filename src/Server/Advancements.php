<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;

/**
 * PlayerAdvancements: each player's progress through the advancements the official server loads
 * (advancements.json), the rewards and chat announcements on completing one, and the tabs and
 * nodes the client is shown, as AdvancementVisibilityEvaluator decides, sent once a tick by
 * flushDirty.
 *
 * Criteria are met by commands and by the minecraft:tick, minecraft:recipe_unlocked,
 * minecraft:inventory_changed and minecraft:location (for biomes) triggers; the others are not
 * reported yet. A criterion
 * listens, as vanilla's listeners do, only while it is unmet and its advancement is not done.
 */
trait Advancements
{
    /**
     * @return array{byId: array<string, array>, tick: list<array{string, string}>, recipeUnlocked: array<string, list<array{string, string}>>, inventoryByItem: array<int, list<array{string, string}>>, inventory: list<array{string, string, array}>}
     */
    private function advancementReference(): array
    {
        static $reference = null;
        if ($reference === null) {
            $data = json_decode((string) file_get_contents($this->version->directory . '/advancements.json'), true, 512, JSON_THROW_ON_ERROR);
            $byId = [];
            $tick = [];
            $recipeUnlocked = [];
            $inventoryByItem = [];
            $inventory = [];
            $location = [];
            foreach ($data['advancements'] as $advancement) {
                $id = $advancement['id'];
                $advancement['entry'] = (string) hex2bin($advancement['entry']);
                $advancement['name'] = self::advancementComponent($advancement['name']);
                $byId[$id] = $advancement;
                foreach ($advancement['criteria'] as $name => $criterion) {
                    match ($criterion['trigger']) {
                        'minecraft:tick' => $tick[] = [$id, $name],
                        'minecraft:recipe_unlocked' => array_map(static function (string $recipe) use (&$recipeUnlocked, $id, $name): void {
                            $recipeUnlocked[$recipe][] = [$id, $name];
                        }, $criterion['conditions']['recipes']),
                        default => null,
                    };
                    // PlayerTrigger with an entity_properties condition on the player's location
                    // biome; structures and other properties are not reported yet.
                    $predicate = $criterion['conditions']['player']['predicate'] ?? [];
                    if ($criterion['trigger'] === 'minecraft:location' && array_keys($predicate) === ['minecraft:location'] && array_keys($predicate['minecraft:location']) === ['biomes']) {
                        $location[] = [$id, $name, array_fill_keys(array_map(static fn (string $biome): string => substr($biome, strlen('minecraft:')), (array) $predicate['minecraft:location']['biomes']), true)];
                    }
                    if ($criterion['trigger'] === 'minecraft:inventory_changed') {
                        $conditions = $criterion['conditions'] ?? [];
                        $items = array_map(fn (array $predicate): array => $this->itemPredicateItems($predicate), $conditions['items'] ?? []);
                        if (count($items) === 1 && !isset($conditions['slots']) && !isset($conditions['player'])) {
                            // One item predicate tests only the stack that changed.
                            foreach (array_keys($items[0]) as $item) {
                                $inventoryByItem[$item][] = [$id, $name];
                            }
                        } else {
                            $inventory[] = [$id, $name, ['items' => $items, 'slots' => $conditions['slots'] ?? []]];
                        }
                    }
                }
            }
            foreach ($byId as $id => $advancement) {
                $root = $id;
                while (isset($byId[$root]['parent'])) {
                    $root = $byId[$root]['parent'];
                }
                $byId[$id]['root'] = $root;
            }
            $reference = ['byId' => $byId, 'tick' => $tick, 'recipeUnlocked' => $recipeUnlocked, 'inventoryByItem' => $inventoryByItem, 'inventory' => $inventory, 'location' => $location];
        }
        return $reference;
    }

    /** A component as the codec writes it, with plain strings, as the arrays components are built from here. */
    private static function advancementComponent(mixed $component): array
    {
        if (!is_array($component) || array_is_list($component)) {
            return is_array($component) ? ['text' => '', 'extra' => array_map(self::advancementComponent(...), $component)] : ['text' => (string) $component];
        }
        foreach (['extra', 'with'] as $key) {
            if (isset($component[$key])) {
                $component[$key] = array_map(self::advancementComponent(...), $component[$key]);
            }
        }
        if (isset($component['hover_event']['value'])) {
            $component['hover_event']['value'] = self::advancementComponent($component['hover_event']['value']);
        }
        return $component;
    }

    /** @return list<string> AdvancementRequirements.names: every criterion the requirements name. */
    private static function advancementCriteria(array $advancement): array
    {
        return array_values(array_unique(array_merge(...$advancement['requirements'])));
    }

    /** AdvancementProgress.isDone: each requirement has one of its criteria met. */
    private function advancementDone(Client $c, string $id): bool
    {
        $obtained = $c->advancementProgress[$id] ?? [];
        foreach ($this->advancementReference()['byId'][$id]['requirements'] as $any) {
            if (array_intersect_key($obtained, array_flip($any)) === []) {
                return false;
            }
        }
        return true;
    }

    /** PlayerAdvancements.award: meet one criterion, granting the rewards when that completes the advancement. */
    private function awardAdvancement(Client $c, string $id, string $criterion): bool
    {
        $advancement = $this->advancementReference()['byId'][$id];
        $c->advancementProgress[$id] ??= [];
        $wasDone = $this->advancementDone($c, $id);
        $result = false;
        if (in_array($criterion, self::advancementCriteria($advancement), true) && !isset($c->advancementProgress[$id][$criterion])) {
            $c->advancementProgress[$id][$criterion] = (int) floor(microtime(true) * 1000);
            $c->advancementsChanged[$id] = true;
            $result = true;
            if (!$wasDone && $this->advancementDone($c, $id)) {
                $this->grantAdvancementRewards($c, $advancement);
                if (($advancement['announce'] ?? false) && $this->gameRule('show_advancement_messages') === 'true') {
                    $message = ['translate' => 'chat.type.advancement.' . $advancement['frame'], 'with' => [$this->displayName($c), $advancement['name']]];
                    $this->broadcastComponent($message, self::plainText($message));
                }
            }
        }
        if (!$wasDone && $this->advancementDone($c, $id)) {
            $c->advancementRootsToUpdate[$advancement['root']] = true;
        }
        return $result;
    }

    /** PlayerAdvancements.revoke: unmeet one criterion. */
    private function revokeAdvancement(Client $c, string $id, string $criterion): bool
    {
        $c->advancementProgress[$id] ??= [];
        $wasDone = $this->advancementDone($c, $id);
        $result = false;
        if (isset($c->advancementProgress[$id][$criterion])) {
            unset($c->advancementProgress[$id][$criterion]);
            $c->advancementsChanged[$id] = true;
            $result = true;
        }
        if ($wasDone && !$this->advancementDone($c, $id)) {
            $c->advancementRootsToUpdate[$this->advancementReference()['byId'][$id]['root']] = true;
        }
        return $result;
    }

    /** AdvancementRewards.grant: experience and recipes; vanilla's advancements reward no loot or functions. */
    private function grantAdvancementRewards(Client $c, array $advancement): void
    {
        if ($advancement['experience'] !== 0) {
            $this->giveExperiencePoints($c, $advancement['experience']);
        }
        if ($advancement['recipes'] !== []) {
            $this->awardRecipes($c, $advancement['recipes']);
        }
    }

    /**
     * SimpleCriterionTrigger.trigger: the criteria still listening among those given, all found
     * before any is met.
     */
    private function triggerAdvancements(Client $c, array $criteria): void
    {
        $listening = array_filter($criteria, fn (array $criterion): bool => !isset($c->advancementProgress[$criterion[0]][$criterion[1]]) && !$this->advancementDone($c, $criterion[0]));
        foreach ($listening as [$id, $criterion]) {
            $this->awardAdvancement($c, $id, $criterion);
        }
    }

    /** ItemPredicate's items: an item, a list of them or a tag, as a set of item IDs. */
    private function itemPredicateItems(array $predicate): array
    {
        $ids = [];
        foreach ((array) ($predicate['items'] ?? []) as $item) {
            $names = str_starts_with($item, '#') ? $this->version->registryTags('minecraft:item')[substr($item, 1)] ?? [] : [substr($item, strlen('minecraft:'))];
            foreach ($names as $name) {
                $ids[$this->version->catalog->items[$name]['id']] = true;
            }
        }
        return $ids;
    }

    /**
     * AbstractContainerMenu.broadcastChanges with ServerPlayer's slot listener: each slot of the
     * player's inventory in the open menu that changed since the menu last looked reports
     * inventory_changed with its new stack. A new menu has looked at nothing yet.
     */
    private function inventoryChangedCheck(Client $c): void
    {
        $player = $c->windowType === 'player';
        // The inventory menu's armour, main, hotbar and offhand slots; a container menu's main and hotbar.
        $seen = $player ? $c->inventoryMenuSeen : $c->containerMenuSeen;
        $changed = [];
        foreach ($player ? range(5, 45) : range(9, 44) as $slot) {
            $stack = $c->inventory[$slot] ?? null;
            if (($seen[$slot] ?? null) != $stack) {
                $seen[$slot] = $stack;
                $changed[] = $stack;
            }
        }
        if ($player) {
            $c->inventoryMenuSeen = $seen;
        } else {
            $c->containerMenuSeen = $seen;
        }
        foreach ($changed as $stack) {
            $this->inventoryChanged($c, $stack);
        }
    }

    /** InventoryChangeTrigger.trigger: slot counts over the player's inventory, and the stack that changed. */
    private function inventoryChanged(Client $c, ?array $changed): void
    {
        $reference = $this->advancementReference();
        $criteria = $changed === null ? [] : $reference['inventoryByItem'][$changed['item']] ?? [];
        if ($reference['inventory'] !== []) {
            $full = $empty = $occupied = 0;
            $stacks = [];
            // Inventory: main, armour and offhand, then the body and saddle slots players leave empty.
            foreach ([...range(9, 44), 5, 6, 7, 8, 45] as $slot) {
                $stack = $c->inventory[$slot] ?? null;
                if ($stack === null) {
                    ++$empty;
                    continue;
                }
                ++$occupied;
                $stacks[] = $stack;
                if ($stack['count'] >= ($this->version->catalog->item($stack['item'])['max_stack'] ?? 64)) {
                    ++$full;
                }
            }
            $empty += 2;
            $range = static fn (array $bounds, int $value): bool => (!isset($bounds['min']) || $value >= $bounds['min']) && (!isset($bounds['max']) || $value <= $bounds['max']);
            foreach ($reference['inventory'] as [$id, $name, $conditions]) {
                $slots = $conditions['slots'];
                if (!$range($slots['full'] ?? [], $full) || !$range($slots['empty'] ?? [], $empty) || !$range($slots['occupied'] ?? [], $occupied)) {
                    continue;
                }
                $items = $conditions['items'];
                if (count($items) === 1) {
                    if ($changed === null || !isset($items[0][$changed['item']])) {
                        continue;
                    }
                } else {
                    // Every predicate is met by some stack in the inventory.
                    foreach ($stacks as $stack) {
                        $items = array_filter($items, static fn (array $set): bool => !isset($set[$stack['item']]));
                    }
                    if ($items !== []) {
                        continue;
                    }
                }
                $criteria[] = [$id, $name];
            }
        }
        if ($criteria !== []) {
            $this->triggerAdvancements($c, $criteria);
        }
    }

    /** RecipeUnlockedTrigger: a recipe was added to the player's book. */
    private function recipeUnlocked(Client $c, string $recipe): void
    {
        $this->triggerAdvancements($c, $this->advancementReference()['recipeUnlocked'][$recipe] ?? []);
    }

    /**
     * ServerPlayer.doTick's location trigger every 20 ticks of the player's age, then
     * ServerPlayer.tick: the open menu's changes, the minecraft:tick trigger and flushDirty.
     */
    private function tickAdvancements(Client $c): void
    {
        if ($c->livingTicks % 20 === 0 && !$c->dead && $this->advancementReference()['location'] !== []) {
            // LocationPredicate: the biome where the player stands, in a loaded chunk.
            $biome = $this->worlds[$c->dimension]->biomeAt((int) floor($c->x), (int) floor($c->y), (int) floor($c->z));
            $this->triggerAdvancements($c, array_map(static fn (array $criterion): array => [$criterion[0], $criterion[1]], array_filter($this->advancementReference()['location'], static fn (array $criterion): bool => isset($criterion[2][$biome]))));
        }
        $this->inventoryChangedCheck($c);
        $this->triggerAdvancements($c, $this->advancementReference()['tick']);
        $this->flushAdvancements($c, true);
    }

    /** PlayerAdvancements.flushDirty: nodes shown or hidden, and the progress of shown ones that changed. */
    private function flushAdvancements(Client $c, bool $showAdvancements): void
    {
        if (!$c->advancementsFirstPacket && $c->advancementRootsToUpdate === [] && $c->advancementsChanged === []) {
            return;
        }
        $reference = $this->advancementReference()['byId'];
        $added = [];
        $removed = [];
        foreach (array_keys($c->advancementRootsToUpdate) as $root) {
            // AdvancementVisibilityEvaluator: the ascendants' rules start with three NO_CHANGE.
            $this->advancementVisibility($c, $root, ['', '', ''], $added, $removed);
        }
        $c->advancementRootsToUpdate = [];
        $progress = [];
        foreach (array_keys($c->advancementsChanged) as $id) {
            if (isset($c->visibleAdvancements[$id])) {
                $obtained = $c->advancementProgress[$id] ?? [];
                $criteria = self::advancementCriteria($reference[$id]);
                $progress[] = Binary::writeString($id) . Binary::writeVarInt(count($criteria)) . implode('', array_map(
                    static fn (string $name): string => Binary::writeString($name) . (isset($obtained[$name]) ? "\1" . pack('J', $obtained[$name]) : "\0"),
                    $criteria,
                ));
            }
        }
        $c->advancementsChanged = [];
        if ($progress !== [] || $added !== [] || $removed !== []) {
            $c->send($this->version, 'update_advancements', ($c->advancementsFirstPacket ? "\1" : "\0")
                . Binary::writeVarInt(count($added)) . implode('', array_map(static fn (string $id): string => $reference[$id]['entry'], $added))
                . Binary::writeVarInt(count($removed)) . implode('', array_map(Binary::writeString(...), $removed))
                . Binary::writeVarInt(count($progress)) . implode('', $progress)
                . ($showAdvancements ? "\1" : "\0"));
        }
        $c->advancementsFirstPacket = false;
    }

    /**
     * AdvancementVisibilityEvaluator.evaluateVisibility: a node is shown when it or a descendant is
     * done, or when the nearest of its three closest ascendants (itself included) that decides is SHOW.
     *
     * @param list<string> $ascendants rules, 'show', 'hide' or '' for no change, the nearest last
     * @return bool whether the node or a descendant is done
     */
    private function advancementVisibility(Client $c, string $id, array $ascendants, array &$added, array &$removed): bool
    {
        $advancement = $this->advancementReference()['byId'][$id];
        // The done test starts the node's progress, so each node shown has its progress sent.
        $c->advancementProgress[$id] ??= [];
        $done = $this->advancementDone($c, $id);
        $ascendants[] = !isset($advancement['frame']) ? 'hide' : ($done ? 'show' : ($advancement['hidden'] ? 'hide' : ''));
        $doneBelow = $done;
        foreach ($advancement['children'] as $child) {
            $doneBelow = $this->advancementVisibility($c, $child, $ascendants, $added, $removed) || $doneBelow;
        }
        $visible = $doneBelow;
        if (!$visible) {
            foreach (array_slice(array_reverse($ascendants), 0, 3) as $rule) {
                if ($rule !== '') {
                    $visible = $rule === 'show';
                    break;
                }
            }
        }
        if ($visible) {
            if (!isset($c->visibleAdvancements[$id])) {
                $c->visibleAdvancements[$id] = true;
                $added[] = $id;
                $c->advancementsChanged[$id] = true;
            }
        } elseif (isset($c->visibleAdvancements[$id])) {
            unset($c->visibleAdvancements[$id]);
            $removed[] = $id;
        }
        return $doneBelow;
    }

    /** ServerGamePacketListenerImpl.handleSeenAdvancements: opening a tab selects it, if it is a root shown. */
    private function seenAdvancements(Client $c, ?string $tab): void
    {
        $advancement = $tab === null ? null : ($this->advancementReference()['byId'][$tab] ?? null);
        $selected = $advancement !== null && !isset($advancement['parent']) && isset($advancement['frame']) ? $tab : null;
        if ($selected !== $c->selectedAdvancementTab) {
            $c->selectedAdvancementTab = $selected;
            $c->send($this->version, 'select_advancements_tab', $selected === null ? "\0" : "\1" . Binary::writeString($selected));
        }
    }

    /** PlayerAdvancements.asData: the advancements with progress, and when each criterion was met. */
    private function savedAdvancements(Client $c): array
    {
        return array_filter($c->advancementProgress, static fn (array $obtained): bool => $obtained !== []);
    }

    /**
     * PlayerAdvancements.load and applyFrom: progress for advancements that still exist, each to be
     * sent and its tree's visibility worked out on the first flush.
     */
    private function loadAdvancements(Client $c, array $saved): void
    {
        $reference = $this->advancementReference()['byId'];
        $c->advancementProgress = [];
        if ($saved !== [] && array_is_list($saved)) {
            // Saves that listed only the advancements done: each had all its criteria met.
            $now = (int) floor(microtime(true) * 1000);
            $saved = array_values(array_filter($saved, 'is_string'));
            $saved = array_map(static fn (string $id): array => array_fill_keys(isset($reference[$id]) ? self::advancementCriteria($reference[$id]) : [], $now), array_combine($saved, $saved));
        }
        foreach ($saved as $id => $obtained) {
            if (!is_string($id) || !is_array($obtained) || !isset($reference[$id])) {
                $this->log('Ignored advancement \'' . $id . '\' in progress file - it doesn\'t exist anymore?');
                continue;
            }
            $criteria = array_flip(self::advancementCriteria($reference[$id]));
            $c->advancementProgress[$id] = array_map('intval', array_intersect_key(array_filter($obtained, 'is_numeric'), $criteria));
            $c->advancementsChanged[$id] = true;
            $c->advancementRootsToUpdate[$reference[$id]['root']] = true;
        }
    }
}
