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

    /** @var list<?string> the objective map's open-addressing table */
    private array $table;

    /** @var array<string, array<string, array{value: int, locked: bool}>> holder => objective => score */
    public array $scores = [];

    /** @var array<string, string> slot => objective */
    public array $display = [];

    public function __construct()
    {
        $this->table = array_fill(0, 32, null);
    }

    public static function fromArray(?array $data): self
    {
        $scoreboard = new self();
        foreach ($data['objectives'] ?? [] as $name => $objective) {
            $scoreboard->addObjective((string) $name, $objective);
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
        return ['objectives' => $objectives, 'scores' => $this->scores, 'display' => $this->display];
    }

    public function addObjective(string $name, array $objective): void
    {
        $this->objectives[$name] = $objective;
        $this->insert($name);
    }

    public function removeObjective(string $name): void
    {
        unset($this->objectives[$name]);
        $this->delete($name);
        foreach ($this->scores as $holder => $scores) {
            unset($this->scores[$holder][$name]);
            if ($this->scores[$holder] === []) {
                unset($this->scores[$holder]);
            }
        }
        $this->display = array_filter($this->display, static fn (string $objective): bool => $objective !== $name);
    }

    /** Object2ObjectOpenHashMap iteration: the table from its last slot to its first. */
    public function objectiveNames(): array
    {
        return array_values(array_filter(array_reverse($this->table), static fn (?string $name): bool => $name !== null));
    }

    /** Holders with any score, in java.util.HashMap order as the players list reports them. */
    public function holders(): array
    {
        return \PhpMinecraftJavaServer\Protocol\Nbt::hashMapOrder(array_map('strval', array_keys($this->scores)));
    }

    private function insert(string $key): void
    {
        $mask = count($this->table) - 1;
        $position = self::mix(self::hash($key)) & $mask;
        while ($this->table[$position] !== null) {
            if ($this->table[$position] === $key) {
                return;
            }
            $position = ($position + 1) & $mask;
        }
        $this->table[$position] = $key;
        // maxFill(n, 0.5): rehash to twice the size once half full.
        if (count(array_filter($this->table, static fn (?string $name): bool => $name !== null)) >= intdiv(count($this->table), 2)) {
            $keys = $this->objectiveNames();
            $this->table = array_fill(0, count($this->table) * 2, null);
            foreach ($keys as $existing) {
                $this->place($existing);
            }
        }
    }

    private function place(string $key): void
    {
        $mask = count($this->table) - 1;
        $position = self::mix(self::hash($key)) & $mask;
        while ($this->table[$position] !== null) {
            $position = ($position + 1) & $mask;
        }
        $this->table[$position] = $key;
    }

    /** Removal with fastutil's shiftKeys, which moves later entries of the run back. */
    private function delete(string $key): void
    {
        $mask = count($this->table) - 1;
        $position = self::mix(self::hash($key)) & $mask;
        while ($this->table[$position] !== $key) {
            if ($this->table[$position] === null) {
                return;
            }
            $position = ($position + 1) & $mask;
        }
        while (true) {
            $last = $position;
            $position = ($position + 1) & $mask;
            while (true) {
                $current = $this->table[$position];
                if ($current === null) {
                    $this->table[$last] = null;
                    return;
                }
                $slot = self::mix(self::hash($current)) & $mask;
                if ($last <= $position ? ($last >= $slot || $slot > $position) : ($last >= $slot && $slot > $position)) {
                    break;
                }
                $position = ($position + 1) & $mask;
            }
            $this->table[$last] = $this->table[$position];
        }
    }

    /** String.hashCode */
    private static function hash(string $key): int
    {
        $hash = 0;
        foreach (unpack('n*', iconv('UTF-8', 'UTF-16BE', $key)) ?: [] as $unit) {
            $hash = (31 * $hash + $unit) & 0xffffffff;
        }
        return $hash;
    }

    /** HashCommon.mix */
    private static function mix(int $value): int
    {
        // 32-bit multiplication in halves, as the 64-bit product could overflow PHP's integers.
        $phi = 0x9E3779B9;
        $product = (($value * ($phi & 0xffff)) + ((($value * ($phi >> 16)) & 0xffff) << 16)) & 0xffffffff;
        return ($product ^ ($product >> 16)) & 0xffffffff;
    }
}
