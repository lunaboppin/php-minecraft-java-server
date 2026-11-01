<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;

/**
 * ServerStatsCounter: each player's statistics by type (custom, mined, crafted, used, broken,
 * picked up, dropped, killed and killed by) and value, saved with the player, the scoreboard
 * objectives that follow them, and the award_stats packet answering the client's request with
 * those changed since it last asked (all of them after joining).
 */
trait Statistics
{
    /** The registry each statistic type counts. */
    private const STAT_VALUES = [
        'custom' => 'custom_stats', 'mined' => 'block_ids', 'crafted' => 'items', 'used' => 'items', 'broken' => 'items',
        'picked_up' => 'items', 'dropped' => 'items', 'killed' => 'entity_types', 'killed_by' => 'entity_types',
    ];

    /**
     * Player.awardStat: add to a statistic and to the objectives with its criterion.
     *
     * @param string $type a stat type, without its namespace
     * @param string $value the block, item, entity type or custom stat, without its namespace
     */
    private function awardStat(Client $c, string $type, string $value, int $amount = 1): void
    {
        if ($amount === 0 || !isset($this->version->blocks[self::STAT_VALUES[$type]][$value])) {
            return;
        }
        $total = ($c->stats[$type][$value] ?? 0) + $amount;
        $c->stats[$type][$value] = $total > 2147483647 ? 2147483647 : $total;
        $c->statsDirty[$type . '|' . $value] = true;
        // Stat.buildName: the criterion objectives count it under.
        $this->incrementCriteria('minecraft.' . $type . ':minecraft.' . $value, $c->name, $amount);
    }

    /** Player.resetStat: set a statistic back to zero, and reset its objectives' scores. */
    private function resetStat(Client $c, string $type, string $value): void
    {
        $c->stats[$type][$value] = 0;
        $c->statsDirty[$type . '|' . $value] = true;
        $criteria = 'minecraft.' . $type . ':minecraft.' . $value;
        foreach ($this->scoreboard()->objectiveNames() as $objective) {
            if ($this->scoreboard()->objectives[$objective]['criteria'] === $criteria && isset($this->scoreboard()->scores[$c->name][$objective])) {
                $this->resetScore($c->name, $objective);
            }
        }
    }

    /** ServerStatsCounter.sendStats: the statistics changed since the last request, then none are. */
    private function sendStats(Client $c): void
    {
        $entries = [];
        foreach (array_keys($c->statsDirty) as $key) {
            [$type, $value] = explode('|', $key, 2);
            $entries[] = Binary::writeVarInt($this->version->blocks['stat_types'][$type]) . Binary::writeVarInt($this->version->blocks[self::STAT_VALUES[$type]][$value])
                . Binary::writeVarInt($c->stats[$type][$value] ?? 0);
        }
        $c->statsDirty = [];
        $c->send($this->version, 'award_stats', Binary::writeVarInt(count($entries)) . implode('', $entries));
    }

    /**
     * ServerPlayer.tick: play and world time, time since death while alive, crouching time, and
     * time since resting while awake.
     */
    private function tickStatistics(Client $c): void
    {
        $this->awardStat($c, 'custom', 'play_time');
        $this->awardStat($c, 'custom', 'total_world_time');
        if (!$c->dead) {
            $this->awardStat($c, 'custom', 'time_since_death');
        }
        if ($c->sneaking) {
            $this->awardStat($c, 'custom', 'crouch_time');
        }
        if ($c->sleepingPos === null) {
            $this->awardStat($c, 'custom', 'time_since_rest');
        }
    }

    /**
     * ServerPlayer.checkMovementStatistics: the distance moved, in centimetres, by how the player
     * moved it.
     */
    private function movementStatistics(Client $c, float $dx, float $dy, float $dz): void
    {
        if (($dx === 0.0 && $dy === 0.0 && $dz === 0.0) || $c->vehicle !== null) {
            return;
        }
        $f = static fn (float $value): float => unpack('g', pack('g', $value))[1];
        $full = (int) round($f(sqrt($dx * $dx + $dy * $dy + $dz * $dz)) * 100.0);
        $horizontal = (int) round($f(sqrt($dx * $dx + $dz * $dz)) * 100.0);
        $catalog = $this->version->catalog;
        $feet = $catalog->blockName($this->world->get((int) floor($c->x), (int) floor($c->y), (int) floor($c->z)));
        $eye = $catalog->blockName($this->world->get((int) floor($c->x), (int) floor($c->y + ($c->sneaking ? 1.27 : 1.62)), (int) floor($c->z)));
        $climbable = in_array($feet, $this->version->registryTags('minecraft:block')['minecraft:climbable'] ?? [], true);
        // Player.isSwimming: sprinting under water.
        if ($c->sprinting && $eye === 'water' && $full > 0) {
            $this->awardStat($c, 'custom', 'swim_one_cm', $full);
        } elseif ($eye === 'water' && $full > 0) {
            $this->awardStat($c, 'custom', 'walk_under_water_one_cm', $full);
        } elseif ($feet === 'water') {
            $this->awardStat($c, 'custom', 'walk_on_water_one_cm', $horizontal);
        } elseif ($climbable) {
            if ($dy > 0.0) {
                $this->awardStat($c, 'custom', 'climb_one_cm', (int) round($dy * 100.0));
            }
        } elseif ($c->ground) {
            $this->awardStat($c, 'custom', $c->sprinting ? 'sprint_one_cm' : ($c->sneaking ? 'crouch_one_cm' : 'walk_one_cm'), $horizontal);
        } elseif ($horizontal > 25) {
            $this->awardStat($c, 'custom', 'fly_one_cm', $horizontal);
        }
    }

    /** The statistics as saved: those not zero, by type and value. */
    private function savedStatistics(Client $c): array
    {
        return array_filter(array_map(static fn (array $values): array => array_filter($values), $c->stats));
    }

    /** ServerStatsCounter.parseLocal: the statistics of known types and values. */
    private function loadStatistics(Client $c, array $saved): void
    {
        $c->stats = [];
        foreach ($saved as $type => $values) {
            if (!is_string($type) || !isset(self::STAT_VALUES[$type]) || !is_array($values)) {
                continue;
            }
            foreach ($values as $value => $amount) {
                if (is_string($value) && isset($this->version->blocks[self::STAT_VALUES[$type]][$value]) && is_int($amount)) {
                    $c->stats[$type][$value] = $amount;
                }
            }
        }
        // PlayerList.placeNewPlayer marks them all changed, so the first request sends them all.
        $c->statsDirty = [];
        foreach ($c->stats as $type => $values) {
            foreach (array_keys($values) as $value) {
                $c->statsDirty[$type . '|' . $value] = true;
            }
        }
    }
}
