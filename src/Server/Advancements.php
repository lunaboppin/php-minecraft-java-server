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
 * Criteria are met by commands and by the minecraft:tick and minecraft:recipe_unlocked triggers;
 * the other triggers are not reported yet. A criterion listens, as vanilla's listeners do, only
 * while it is unmet and its advancement is not done.
 */
trait Advancements
{
    /**
     * @return array{byId: array<string, array>, tick: list<array{string, string}>, recipeUnlocked: array<string, list<array{string, string}>>}
     */
    private function advancementReference(): array
    {
        static $reference = null;
        if ($reference === null) {
            $data = json_decode((string) file_get_contents($this->version->directory . '/advancements.json'), true, 512, JSON_THROW_ON_ERROR);
            $byId = [];
            $tick = [];
            $recipeUnlocked = [];
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
                }
            }
            foreach ($byId as $id => $advancement) {
                $root = $id;
                while (isset($byId[$root]['parent'])) {
                    $root = $byId[$root]['parent'];
                }
                $byId[$id]['root'] = $root;
            }
            $reference = ['byId' => $byId, 'tick' => $tick, 'recipeUnlocked' => $recipeUnlocked];
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

    /** CriterionTrigger.trigger: the criteria still listening among those given. */
    private function triggerAdvancements(Client $c, array $criteria): void
    {
        foreach ($criteria as [$id, $criterion]) {
            if (!isset($c->advancementProgress[$id][$criterion]) && !$this->advancementDone($c, $id)) {
                $this->awardAdvancement($c, $id, $criterion);
            }
        }
    }

    /** RecipeUnlockedTrigger: a recipe was added to the player's book. */
    private function recipeUnlocked(Client $c, string $recipe): void
    {
        $this->triggerAdvancements($c, $this->advancementReference()['recipeUnlocked'][$recipe] ?? []);
    }

    /** ServerPlayer.tick: the minecraft:tick trigger, then flushDirty. */
    private function tickAdvancements(Client $c): void
    {
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
