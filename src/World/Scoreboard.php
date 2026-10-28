<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

/**
 * The server scoreboard: objectives, score holders' scores and display slots. Objectives iterate
 * as vanilla's fastutil Object2ObjectOpenHashMap does, which some command replies expose.
 */
final class Scoreboard
{
    /** DisplaySlot ids and names. */
    public const SLOTS = [
        'list' => 0, 'sidebar' => 1, 'below_name' => 2,
        'sidebar.team.black' => 3, 'sidebar.team.dark_blue' => 4, 'sidebar.team.dark_green' => 5, 'sidebar.team.dark_aqua' => 6,
        'sidebar.team.dark_red' => 7, 'sidebar.team.dark_purple' => 8, 'sidebar.team.gold' => 9, 'sidebar.team.gray' => 10,
        'sidebar.team.dark_gray' => 11, 'sidebar.team.blue' => 12, 'sidebar.team.green' => 13, 'sidebar.team.aqua' => 14,
        'sidebar.team.red' => 15, 'sidebar.team.light_purple' => 16, 'sidebar.team.yellow' => 17, 'sidebar.team.white' => 18,
    ];

    /** @var array<string, array{criteria: string, displayName: array, renderType: string, displayAutoUpdate: bool}> */
    public array $objectives = [];

    private OpenHashOrder $objectiveOrder;

    /** @var array<string, array{displayName: array, color: ?string, prefix: array, suffix: array, friendlyFire: bool, seeFriendlyInvisibles: bool, nametagVisibility: string, deathMessageVisibility: string, collisionRule: string, players: list<string>}> */
    public array $teams = [];

    private OpenHashOrder $teamOrder;

    /** @var array<string, array<string, array{value: int, locked: bool}>> holder => objective => score */
    public array $scores = [];

    /** @var array<string, string> slot => objective */
    public array $display = [];

    public function __construct()
    {
        $this->objectiveOrder = new OpenHashOrder();
        $this->teamOrder = new OpenHashOrder();
    }

    public static function fromArray(?array $data): self
    {
        $scoreboard = new self();
        foreach ($data['objectives'] ?? [] as $name => $objective) {
            $scoreboard->addObjective((string) $name, $objective);
        }
        foreach ($data['teams'] ?? [] as $name => $team) {
            $scoreboard->teams[(string) $name] = $team;
            $scoreboard->teamOrder->insert((string) $name);
        }
        $scoreboard->scores = $data['scores'] ?? [];
        $scoreboard->display = $data['display'] ?? [];
        return $scoreboard;
    }

    /** Saved in iteration order and added again on load, as ScoreboardSaveData does. */
    public function toArray(): array
    {
        $objectives = [];
        foreach ($this->objectiveNames() as $name) {
            $objectives[$name] = $this->objectives[$name];
        }
        $teams = [];
        foreach ($this->teamNames() as $name) {
            $teams[$name] = $this->teams[$name];
        }
        return ['objectives' => $objectives, 'teams' => $teams, 'scores' => $this->scores, 'display' => $this->display];
    }

    public function addObjective(string $name, array $objective): void
    {
        $this->objectives[$name] = $objective;
        $this->objectiveOrder->insert($name);
    }

    public function removeObjective(string $name): void
    {
        unset($this->objectives[$name]);
        $this->objectiveOrder->delete($name);
        foreach ($this->scores as $holder => $scores) {
            unset($this->scores[$holder][$name]);
            if ($this->scores[$holder] === []) {
                unset($this->scores[$holder]);
            }
        }
        $this->display = array_filter($this->display, static fn (string $objective): bool => $objective !== $name);
    }

    /** Object2ObjectOpenHashMap iteration order. */
    public function objectiveNames(): array
    {
        return $this->objectiveOrder->keys();
    }

    /** Scoreboard.addPlayerTeam with PlayerTeam's defaults. */
    public function addTeam(string $name, array $displayName): void
    {
        $this->teams[$name] = ['displayName' => $displayName, 'color' => null, 'prefix' => ['text' => ''], 'suffix' => ['text' => ''], 'friendlyFire' => true, 'seeFriendlyInvisibles' => true, 'nametagVisibility' => 'always', 'deathMessageVisibility' => 'always', 'collisionRule' => 'always', 'players' => []];
        $this->teamOrder->insert($name);
    }

    public function removeTeam(string $name): void
    {
        unset($this->teams[$name]);
        $this->teamOrder->delete($name);
    }

    /** @return list<string> */
    public function teamNames(): array
    {
        return $this->teamOrder->keys();
    }

    public function teamOf(string $holder): ?string
    {
        foreach ($this->teams as $name => $team) {
            if (in_array($holder, $team['players'], true)) {
                return (string) $name;
            }
        }
        return null;
    }

    /** Scoreboard.addPlayerToTeam: leaving any previous team first. */
    public function joinTeam(string $holder, string $team): bool
    {
        $current = $this->teamOf($holder);
        if ($current === $team) {
            return false;
        }
        if ($current !== null) {
            $this->leaveTeam($holder);
        }
        $this->teams[$team]['players'][] = $holder;
        return true;
    }

    public function leaveTeam(string $holder): ?string
    {
        $team = $this->teamOf($holder);
        if ($team !== null) {
            $this->teams[$team]['players'] = array_values(array_filter($this->teams[$team]['players'], static fn (string $player): bool => $player !== $holder));
        }
        return $team;
    }

    /** A team's members in java.util.HashSet order. */
    public function members(string $team): array
    {
        return \PhpMinecraftJavaServer\Protocol\Nbt::hashMapOrder($this->teams[$team]['players']);
    }

    /**
     * Holders with any score in Scoreboard.playerScores order (an Object2ObjectOpenHashMap), as
     * if inserted in the order they first got a score.
     */
    public function scoreHolderOrder(): array
    {
        $order = new OpenHashOrder();
        foreach (array_keys($this->scores) as $holder) {
            $order->insert((string) $holder);
        }
        return $order->keys();
    }

    /** Holders with any score, in java.util.HashMap order as the players list reports them. */
    public function holders(): array
    {
        return \PhpMinecraftJavaServer\Protocol\Nbt::hashMapOrder(array_map('strval', array_keys($this->scores)));
    }

}
