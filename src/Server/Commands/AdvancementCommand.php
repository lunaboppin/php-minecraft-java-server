<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait AdvancementCommand
{
    /**
     * AdvancementCommands: grant or revoke one advancement, or one criterion of it, an
     * advancement with its parents (until), its children (from) or both (through), or every
     * advancement. Granting everything shows no toasts: the players' changes so far are sent
     * first, and theirs with the toasts off.
     */
    private function executeAdvancement(Client $sender, array $arguments, string $text): void
    {
        [$action, $selector, $mode] = $arguments;
        $players = $this->selectPlayers($selector, $sender);
        $reference = $this->advancementReference()['byId'];
        if ($mode === 'everything') {
            $this->performAdvancements($sender, $players, $action, array_keys($reference), $action === 'revoke');
            return;
        }
        $id = str_contains($arguments[3], ':') ? $arguments[3] : 'minecraft:' . $arguments[3];
        if (!isset($reference[$id])) {
            throw new CommandFailure(['translate' => 'advancement.advancementNotFound', 'with' => [['text' => $id]]]);
        }
        if ($mode === 'only' && isset($arguments[4])) {
            // The criterion is a greedy string: the rest of the command.
            $this->performAdvancementCriterion($sender, $players, $action, $id, CommandArguments::rest($text, 4));
            return;
        }
        $advancements = [];
        if ($mode === 'until' || $mode === 'through') {
            for ($parent = $reference[$id]['parent'] ?? null; $parent !== null; $parent = $reference[$parent]['parent'] ?? null) {
                $advancements[] = $parent;
            }
        }
        $advancements[] = $id;
        if ($mode === 'from' || $mode === 'through') {
            $children = function (string $parent) use (&$children, &$advancements, $reference): void {
                foreach ($reference[$parent]['children'] as $child) {
                    $advancements[] = $child;
                    $children($child);
                }
            };
            $children($id);
        }
        $this->performAdvancements($sender, $players, $action, $advancements, true);
    }

    /** AdvancementCommands.perform: CommandResponseTracker counts the advancements changed per player. */
    private function performAdvancements(Client $sender, array $players, string $action, array $advancements, bool $showAdvancements): void
    {
        $total = 0;
        $changed = [];
        foreach ($players as $player) {
            if (!$showAdvancements) {
                $this->flushAdvancements($player, true);
            }
            $count = 0;
            foreach ($advancements as $id) {
                $count += (int) $this->performAdvancement($player, $action, $id);
            }
            if (!$showAdvancements) {
                $this->flushAdvancements($player, false);
            }
            $total += $count;
            if ($count !== 0) {
                $changed[] = $player;
            }
        }
        $prefix = 'commands.advancement.' . $action . (count($advancements) === 1 ? '.one' : '.many');
        $subject = count($advancements) === 1 ? $this->advancementReference()['byId'][$advancements[0]]['name'] : count($advancements);
        if ($total === 0) {
            // ElementType.ANY: the one player named, or all of them counted.
            throw new CommandFailure(count($players) === 1 ? ['translate' => $prefix . '.to.one.failure', 'with' => [$subject, $this->displayName($players[0])]]
                : ['translate' => $prefix . '.to.many.failure', 'with' => [$subject, count($players)]]);
        }
        $this->sendSuccess($sender, count($changed) === 1 ? ['translate' => $prefix . '.to.one.success', 'with' => [$subject, $this->displayName($changed[0])]]
            : ['translate' => $prefix . '.to.many.success', 'with' => [$subject, count($changed)]], true);
    }

    /** Action.perform: grant every criterion still unmet, or revoke every one met. */
    private function performAdvancement(Client $player, string $action, string $id): bool
    {
        $player->advancementProgress[$id] ??= [];
        if ($action === 'grant') {
            if ($this->advancementDone($player, $id)) {
                return false;
            }
            $met = $player->advancementProgress[$id];
            foreach (self::advancementCriteria($this->advancementReference()['byId'][$id]) as $criterion) {
                if (!isset($met[$criterion])) {
                    $this->awardAdvancement($player, $id, $criterion);
                }
            }
            return true;
        }
        if ($player->advancementProgress[$id] === []) {
            return false;
        }
        foreach (array_keys($player->advancementProgress[$id]) as $criterion) {
            $this->revokeAdvancement($player, $id, $criterion);
        }
        return true;
    }

    /** AdvancementCommands.performCriterion. */
    private function performAdvancementCriterion(Client $sender, array $players, string $action, string $id, string $criterion): void
    {
        $advancement = $this->advancementReference()['byId'][$id];
        if (!isset($advancement['criteria'][$criterion])) {
            throw new CommandFailure(['translate' => 'commands.advancement.criterionNotFound', 'with' => [$advancement['name'], ['text' => $criterion]]]);
        }
        $total = 0;
        $changed = [];
        foreach ($players as $player) {
            if ($action === 'grant' ? $this->awardAdvancement($player, $id, $criterion) : $this->revokeAdvancement($player, $id, $criterion)) {
                ++$total;
                $changed[] = $player;
            }
        }
        $prefix = 'commands.advancement.' . $action . '.criterion';
        if ($total === 0) {
            throw new CommandFailure(count($players) === 1 ? ['translate' => $prefix . '.to.one.failure', 'with' => [['text' => $criterion], $advancement['name'], $this->displayName($players[0])]]
                : ['translate' => $prefix . '.to.many.failure', 'with' => [['text' => $criterion], $advancement['name'], count($players)]]);
        }
        $this->sendSuccess($sender, count($changed) === 1 ? ['translate' => $prefix . '.to.one.success', 'with' => [['text' => $criterion], $advancement['name'], $this->displayName($changed[0])]]
            : ['translate' => $prefix . '.to.many.success', 'with' => [['text' => $criterion], $advancement['name'], count($changed)]], true);
    }
}
