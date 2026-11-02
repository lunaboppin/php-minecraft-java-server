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
        $scores = $this->scoreboard()->scores;
        foreach ($this->scoreboard()->scoreHolderOrder() as $holder) {
            if (isset($scores[$holder][$objective])) {
                $packets[] = ['set_score', $this->scorePacket($holder, $objective, $scores[$holder][$objective]['value'])];
            }
        }
        return $packets;
    }

    private const TEAM_COLORS = ['black', 'dark_blue', 'dark_green', 'dark_aqua', 'dark_red', 'dark_purple', 'gold', 'gray', 'dark_gray', 'blue', 'green', 'aqua', 'red', 'light_purple', 'yellow', 'white'];

    private const TEAM_VISIBILITY = ['always' => 0, 'never' => 1, 'hideForOtherTeams' => 2, 'hideForOwnTeam' => 3];

    private const TEAM_COLLISION = ['always' => 0, 'never' => 1, 'pushOtherTeams' => 2, 'pushOwnTeam' => 3];

    /**
     * ClientboundSetPlayerTeamPacket: 0 adds (with members), 1 removes, 2 updates the parameters,
     * 3 and 4 add and remove members.
     */
    private function teamPacket(string $name, int $method, array $players = []): string
    {
        $payload = Binary::writeString($name) . chr($method);
        if ($method === 0 || $method === 2) {
            $team = $this->scoreboard()->teams[$name];
            // Parameters: names, visibility and collision ids, an optional colour, then the option bits.
            $payload .= Nbt::component($team['displayName']) . Nbt::component($team['prefix']) . Nbt::component($team['suffix'])
                . Binary::writeVarInt(self::TEAM_VISIBILITY[$team['nametagVisibility']]) . Binary::writeVarInt(self::TEAM_COLLISION[$team['collisionRule']])
                . ($team['color'] === null ? "\0" : "\1" . Binary::writeVarInt(array_search($team['color'], self::TEAM_COLORS, true)))
                . chr(($team['friendlyFire'] ? 1 : 0) | ($team['seeFriendlyInvisibles'] ? 2 : 0));
        }
        if ($method === 0 || $method === 3 || $method === 4) {
            $payload .= Binary::writeVarInt(count($players)) . implode('', array_map(static fn (string $player): string => Binary::writeString($player), $players));
        }
        return $payload;
    }

    /** Team.getFormattedDisplayName: the bracketed display name, hovering and inserting the team's name, in its colour. */
    private function teamComponent(string $name): array
    {
        $team = $this->scoreboard()->teams[$name];
        $display = $team['displayName'];
        $display['insertion'] = $name;
        $display['hover_event'] = ['action' => 'show_text', 'value' => ['text' => $name]];
        return ['translate' => 'chat.square_brackets', 'with' => [$display]] + ($team['color'] === null ? [] : ['color' => $team['color']]);
    }

    /** Team.isAlliedTo and isAllowFriendlyFire: members of a team without friendly fire cannot hurt each other. */
    private function friendlyFireBlocked(Client $attacker, Client $target): bool
    {
        $team = $this->scoreboard()->teamOf($attacker->name);
        return $team !== null && $team === $this->scoreboard()->teamOf($target->name) && !$this->scoreboard()->teams[$team]['friendlyFire'];
    }

    /**
     * ServerPlayer.die: who sees a death message under the team's deathMessageVisibility.
     *
     * @return list<Client>
     */
    private function deathMessageRecipients(Client $victim): array
    {
        $team = $this->scoreboard()->teamOf($victim->name);
        $visibility = $team === null ? 'always' : $this->scoreboard()->teams[$team]['deathMessageVisibility'];
        return array_values(array_filter($this->players(), fn (Client $player): bool => match ($visibility) {
            'never' => false,
            // PlayerList.broadcastSystemToTeam leaves out the dying player.
            'hideForOtherTeams' => $player !== $victim && $this->scoreboard()->teamOf($player->name) === $team,
            'hideForOwnTeam' => $this->scoreboard()->teamOf($player->name) !== $team,
            default => true,
        }));
    }

    /** PlayerList.updateEntireScoreboard for a joining player. */
    private function sendScoreboard(Client $c): void
    {
        // Teams first, with their members.
        foreach ($this->scoreboard()->teamNames() as $team) {
            $c->send($this->version, 'set_player_team', $this->teamPacket($team, 0, $this->scoreboard()->members($team)));
        }
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
            $this->scoreboard()->syncHolderOrder();
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
        if (!isset($scores[$holder])) {
            return;
        }
        $removed = $objective !== null && isset($scores[$holder][$objective]);
        if ($objective !== null) {
            unset($scores[$holder][$objective]);
        }
        if ($objective === null || $scores[$holder] === []) {
            unset($scores[$holder]);
            $this->scoreboard()->syncHolderOrder();
            $this->broadcastScoreboard('reset_score', Binary::writeString($holder) . "\0");
            return;
        }
        if ($removed && $this->tracked($objective)) {
            $this->broadcastScoreboard('reset_score', Binary::writeString($holder) . "\1" . Binary::writeString($objective));
        }
    }

    /** Scoreboard.entityRemoved: a destroyed non-player entity loses its scores and team. */
    private function entityRemoved(string $uuid): void
    {
        $holder = Binary::formatUuid($uuid);
        $changed = isset($this->scoreboard()->scores[$holder]);
        $this->resetScore($holder, null);
        $team = $this->scoreboard()->leaveTeam($holder);
        if ($team !== null) {
            $this->broadcastScoreboard('set_player_team', $this->teamPacket($team, 4, [$holder]));
        }
        if ($changed || $team !== null) {
            $this->saveScoreboard();
        }
    }

    /** Scoreboard.forAllObjectives(criteria, holder, score -> score.add(amount)), as ints wrap */
    private function incrementCriteria(string $criteria, string $holder, int $amount = 1): void
    {
        $changed = false;
        foreach ($this->scoreboard()->objectiveNames() as $objective) {
            if ($this->scoreboard()->objectives[$objective]['criteria'] === $criteria) {
                $value = (($this->scoreboard()->scores[$holder][$objective]['value'] ?? 0) + $amount + 2147483648) % 4294967296;
                $this->setScore($holder, $objective, ($value < 0 ? $value + 4294967296 : $value) - 2147483648);
                $changed = true;
            }
        }
        if ($changed) {
            $this->saveScoreboard();
        }
    }

    /** Player.getDisplayName, formatted for the player's team. */
    private function displayName(Client $c): array
    {
        return \PhpMinecraftJavaServer\Protocol\Packets::playerName($c->name, $c->uuid, $this->teamFormat($c->name));
    }

    private function teamFormat(string $holder): ?array
    {
        $team = $this->scoreboard()->teamOf($holder);
        return $team === null ? null : $this->scoreboard()->teams[$team];
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
                // Player.getFeedbackDisplayName: team-formatted, hovering the plain name.
                return ['hover_event' => ['action' => 'show_text', 'value' => ['text' => $holder]]] + $this->displayName($player);
            }
        }
        // Entity.getFeedbackDisplayName likewise, for a mob or item that still exists.
        foreach ($this->animals as $mob) {
            if (Binary::formatUuid(hex2bin($mob['uuid'])) === $holder) {
                return ['hover_event' => ['action' => 'show_text', 'value' => ['text' => $holder]]] + $this->entityComponent($mob['kind'], hex2bin($mob['uuid']), $mob['customName'] ?? null);
            }
        }
        foreach ($this->itemEntities as $id => $item) {
            if (Binary::formatUuid($item['uuid']) === $holder) {
                return ['hover_event' => ['action' => 'show_text', 'value' => ['text' => $holder]]] + $this->entityName(['item' => $id, 'uuid' => $item['uuid']]);
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
