<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Nbt;
use PhpMinecraftJavaServer\World\Scoreboard;

/**
 * The ServerScoreboard: saved with the Overworld, and synchronized only for objectives shown in
 * a display slot (ServerScoreboard.trackedObjectives).
 */
trait Scoreboards
{
    private ?Scoreboard $scoreboard = null;

    private function scoreboard(): Scoreboard
    {
        return $this->scoreboard ??= Scoreboard::fromArray($this->worlds['overworld']->setting('scoreboard'));
    }

    private function saveScoreboard(): void
    {
        $this->worlds['overworld']->setting('scoreboard', $this->scoreboard()->toArray());
    }

    private function tracked(string $objective): bool
    {
        return in_array($objective, $this->scoreboard()->display, true);
    }

    private function broadcastScoreboard(string $packet, string $payload): void
    {
        foreach ($this->players() as $player) {
            $player->send($this->version, $packet, $payload);
        }
    }

    /** ClientboundSetObjectivePacket: 0 adds, 1 removes and 2 updates. */
    private function objectivePacket(string $name, int $method): string
    {
        $payload = Binary::writeString($name) . chr($method);
        if ($method !== 1) {
            $objective = $this->scoreboard()->objectives[$name];
            $payload .= Nbt::component($objective['displayName']) . Binary::writeVarInt($objective['renderType'] === 'hearts' ? 1 : 0) . "\0";
        }
        return $payload;
    }

    private function scorePacket(string $holder, string $objective, int $value): string
    {
        return Binary::writeString($holder) . Binary::writeString($objective) . Binary::writeVarInt($value) . "\0\0";
    }

    /** ServerScoreboard.getStartTrackingPackets: the objective, the slots showing it and its scores. */
    private function startTrackingPackets(string $objective): array
    {
        $packets = [['set_objective', $this->objectivePacket($objective, 0)]];
        foreach ($this->scoreboard()->display as $slot => $shown) {
            if ($shown === $objective) {
                $packets[] = ['set_display_objective', Binary::writeVarInt(Scoreboard::SLOTS[$slot]) . Binary::writeString($objective)];
            }
        }
        foreach ($this->scoreboard()->scores as $holder => $scores) {
            if (isset($scores[$objective])) {
                $packets[] = ['set_score', $this->scorePacket((string) $holder, $objective, $scores[$objective]['value'])];
            }
        }
        return $packets;
    }

    /** PlayerList.updateEntireScoreboard for a joining player. */
    private function sendScoreboard(Client $c): void
    {
        foreach (array_unique($this->scoreboard()->display) as $objective) {
            foreach ($this->startTrackingPackets($objective) as [$packet, $payload]) {
                $c->send($this->version, $packet, $payload);
            }
        }
    }

    /** ServerScoreboard.setDisplayObjective, with its tracking changes. */
    private function setDisplayObjective(string $slot, ?string $objective): void
    {
        $scoreboard = $this->scoreboard();
        $old = $scoreboard->display[$slot] ?? null;
        if ($objective === null) {
            unset($scoreboard->display[$slot]);
        } else {
            $wasTracked = $this->tracked($objective);
            $scoreboard->display[$slot] = $objective;
        }
        if ($old !== null && $old !== $objective) {
            if ($this->tracked($old)) {
                $this->broadcastScoreboard('set_display_objective', Binary::writeVarInt(Scoreboard::SLOTS[$slot]) . Binary::writeString($objective ?? ''));
            } else {
                $this->broadcastScoreboard('set_objective', $this->objectivePacket($old, 1));
            }
        }
        if ($objective !== null) {
            if ($wasTracked) {
                $this->broadcastScoreboard('set_display_objective', Binary::writeVarInt(Scoreboard::SLOTS[$slot]) . Binary::writeString($objective));
            } else {
                foreach ($this->startTrackingPackets($objective) as [$packet, $payload]) {
                    $this->broadcastScoreboard($packet, $payload);
                }
            }
        }
        $this->saveScoreboard();
    }

    /**
     * ScoreAccess.set: creating a score (locked, for triggers) or changing it sends one update;
     * an unchanged value sends nothing.
     */
    private function setScore(string $holder, string $objective, int $value): void
    {
        $scores = &$this->scoreboard()->scores;
        $created = !isset($scores[$holder][$objective]);
        if ($created || $scores[$holder][$objective]['value'] !== $value) {
            $scores[$holder][$objective] = ['value' => $value, 'locked' => $scores[$holder][$objective]['locked'] ?? true];
            $this->scoreChanged($holder, $objective);
        }
    }

    private function scoreChanged(string $holder, string $objective): void
    {
        if ($this->tracked($objective)) {
            $this->broadcastScoreboard('set_score', $this->scorePacket($holder, $objective, $this->scoreboard()->scores[$holder][$objective]['value']));
        }
    }

    /**
     * Scoreboard.resetSinglePlayerScore and resetAllPlayerScores: a holder left without scores is
     * removed, which every player is told; a single score is reported only when tracked.
     */
    private function resetScore(string $holder, ?string $objective): void
    {
        $scores = &$this->scoreboard()->scores;
        if ($objective === null || (isset($scores[$holder][$objective]) && count($scores[$holder]) === 1)) {
            if (isset($scores[$holder])) {
                unset($scores[$holder]);
                $this->broadcastScoreboard('reset_score', Binary::writeString($holder) . "\0");
            }
            return;
        }
        if (!isset($scores[$holder][$objective])) {
            return;
        }
        unset($scores[$holder][$objective]);
        if ($this->tracked($objective)) {
            $this->broadcastScoreboard('reset_score', Binary::writeString($holder) . "\1" . Binary::writeString($objective));
        }
    }

    /** Scoreboard.forAllObjectives(criteria, holder, ScoreAccess::increment) */
    private function incrementCriteria(string $criteria, string $holder): void
    {
        foreach ($this->scoreboard()->objectiveNames() as $objective) {
            if ($this->scoreboard()->objectives[$objective]['criteria'] === $criteria) {
                $value = ($this->scoreboard()->scores[$holder][$objective]['value'] ?? 0) + 1;
                $this->setScore($holder, $objective, $value > 2147483647 ? -2147483648 : $value);
            }
        }
        $this->saveScoreboard();
    }

    /** Objective.getFormattedDisplayName: the bracketed display name, hovering the objective's name. */
    private function objectiveComponent(string $name): array
    {
        $display = $this->scoreboard()->objectives[$name]['displayName'];
        $display['hover_event'] = ['action' => 'show_text', 'value' => ['text' => $name]];
        return ['translate' => 'chat.square_brackets', 'with' => [$display]];
    }

    /** ScoreHolder.getFeedbackDisplayName: a player's styled name, otherwise the plain holder name. */
    private function holderComponent(string $holder): array
    {
        foreach ($this->players() as $player) {
            if ($player->name === $holder) {
                return ['text' => $holder, 'insertion' => $holder, 'click_event' => ['action' => 'suggest_command', 'command' => '/tell ' . $holder . ' '], 'hover_event' => ['action' => 'show_text', 'value' => ['text' => $holder]]];
            }
        }
        return ['text' => $holder];
    }

    /** Entity.getScoreboardName: a player's name, otherwise the UUID. */
    private static function scoreboardName(array $entity): string
    {
        return isset($entity['client']) ? $entity['client']->name : Binary::formatUuid($entity['uuid']);
    }

    /**
     * EntitySelectorOptions "scores": {objective=range, ...}; every listed score must exist and be
     * in range.
     */
    private function matchesScores(string $text, array $entity): bool
    {
        $holder = self::scoreboardName($entity);
        preg_match_all('/([^{},=\s]+)\s*=\s*([^,}\s]+)/', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $objective, $range]) {
            $value = $this->scoreboard()->scores[$holder][$objective]['value'] ?? null;
            [$minimum, $maximum] = str_contains($range, '..') ? explode('..', $range, 2) : [$range, $range];
            if ($value === null || ($minimum !== '' && $value < (int) $minimum) || ($maximum !== '' && $value > (int) $maximum)) {
                return false;
            }
        }
        return true;
    }
}
