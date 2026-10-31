<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait SpreadPlayersCommand
{
    /**
     * SpreadPlayersCommand: random spots within maxRange of the center are pushed apart until each
     * is spreadDistance from the others and stands on a block entities can teleport to, at most
     * 10000 rounds; each target (or each team's targets) then stands on the highest such block.
     */
    private function executeSpreadPlayers(Client $sender, array $arguments): void
    {
        $center = [$this->vec2Coordinate($arguments[0] ?? '', $sender, 0), $this->vec2Coordinate($arguments[1] ?? '', $sender, 2)];
        $spread = (float) ($arguments[2] ?? 0);
        $range = (float) ($arguments[3] ?? 1);
        $rest = array_slice($arguments, 4);
        $minY = $this->world->minY;
        $maxHeight = $minY + $this->world->height - 1;
        if (($rest[0] ?? '') === 'under') {
            $maxHeight = (int) $rest[1];
            $rest = array_slice($rest, 2);
        }
        $teams = ($rest[0] ?? 'false') === 'true';
        $targets = $this->selectEntities(implode(' ', array_slice($rest, 1)), $sender);
        if ($maxHeight < $minY) {
            throw new CommandFailure(['translate' => 'commands.spreadplayers.failed.invalid.height', 'with' => [$maxHeight, $minY]]);
        }
        $bounds = [$center[0] - $range, $center[1] - $range, $center[0] + $range, $center[1] + $range];
        $groups = [];
        foreach ($targets as $target) {
            // A player's team, or none for other entities.
            $groups[$teams ? (isset($target['client']) ? 'team:' . ($this->scoreboard()->teamOf($target['client']->name) ?? '') : 'none') : count($groups)] = true;
        }
        $positions = [];
        for ($index = 0; $index < count($groups); ++$index) {
            $positions[] = $this->spreadRandom($bounds);
        }
        $this->spreadPositions($center, $spread, $bounds, $maxHeight, $positions, $teams);
        $distance = 0.0;
        $assigned = [];
        $next = 0;
        foreach ($targets as $target) {
            $key = $teams ? (isset($target['client']) ? 'team:' . ($this->scoreboard()->teamOf($target['client']->name) ?? '') : 'none') : $next;
            $assigned[$key] ??= $next++;
            $position = $positions[$assigned[$key]];
            $this->moveEntity($target, $this->sourceDimension($sender), [floor($position[0]) + 0.5, (float) $this->spreadSpawnY($position, $maxHeight), floor($position[1]) + 0.5]);
            $closest = INF;
            foreach ($positions as $index => $other) {
                if ($index !== $assigned[$key]) {
                    $closest = min($closest, hypot($position[0] - $other[0], $position[1] - $other[1]));
                }
            }
            $distance += is_infinite($closest) ? PHP_FLOAT_MAX : $closest;
        }
        $distance = count($targets) < 2 ? 0.0 : $distance / count($targets);
        $this->sendSuccess($sender, ['translate' => 'commands.spreadplayers.success.' . ($teams ? 'teams' : 'entities'), 'with' => [
            count($positions), (float) $center[0], (float) $center[1], ['text' => self::javaFixed($distance)],
        ]], true);
    }

    /** String.format("%.2f"): Java spells the infinities and NaN out. */
    private static function javaFixed(float $value): string
    {
        return is_nan($value) ? 'NaN' : (is_infinite($value) ? ($value > 0 ? 'Infinity' : '-Infinity') : sprintf('%.2f', $value));
    }

    /** Vec2Argument: a coordinate, relative with ~, an integer centered on its block. */
    private function vec2Coordinate(string $text, Client $sender, int $axis): float
    {
        $origin = $this->sourcePosition($sender)[$axis];
        if (str_starts_with($text, '~')) {
            return $origin + (float) substr($text, 1);
        }
        return (float) $text + (preg_match('/\A-?\d+\z/D', $text) === 1 ? 0.5 : 0.0);
    }

    /** Mth.nextDouble over the bounds. */
    private function spreadRandom(array $bounds): array
    {
        $next = static fn (float $min, float $max): float => $min >= $max ? $min : (mt_rand() / (mt_getrandmax() + 1)) * ($max - $min) + $min;
        return [$next($bounds[0], $bounds[2]), $next($bounds[1], $bounds[3])];
    }

    /** SpreadPlayersCommand.spreadPositions. */
    private function spreadPositions(array $center, float $spread, array $bounds, int $maxHeight, array &$positions, bool $teams): void
    {
        $collisions = true;
        $minimum = (float) 3.4028234663852886E38;
        for ($iteration = 0; $iteration < 10000 && $collisions; ++$iteration) {
            $collisions = false;
            $minimum = (float) 3.4028234663852886E38;
            foreach ($positions as $i => &$position) {
                $neighbours = 0;
                $away = [0.0, 0.0];
                foreach ($positions as $j => $other) {
                    if ($i === $j) {
                        continue;
                    }
                    $distance = hypot($position[0] - $other[0], $position[1] - $other[1]);
                    $minimum = min($distance, $minimum);
                    if ($distance < $spread) {
                        ++$neighbours;
                        $away[0] += $other[0] - $position[0];
                        $away[1] += $other[1] - $position[1];
                    }
                }
                if ($neighbours > 0) {
                    $away = [$away[0] / $neighbours, $away[1] / $neighbours];
                    $length = hypot($away[0], $away[1]);
                    $position = $length > 0.0 ? [$position[0] - $away[0] / $length, $position[1] - $away[1] / $length] : $this->spreadRandom($bounds);
                    $collisions = true;
                }
                $clamped = [max($bounds[0], min($bounds[2], $position[0])), max($bounds[1], min($bounds[3], $position[1]))];
                if ($clamped !== $position) {
                    $position = $clamped;
                    $collisions = true;
                }
            }
            unset($position);
            if (!$collisions) {
                foreach ($positions as &$position) {
                    if (!$this->spreadSafe($position, $maxHeight)) {
                        $position = $this->spreadRandom($bounds);
                        $collisions = true;
                    }
                }
                unset($position);
            }
        }
        if ($minimum === (float) 3.4028234663852886E38) {
            $minimum = 0.0;
        }
        if ($iteration >= 10000) {
            throw new CommandFailure(['translate' => 'commands.spreadplayers.failed.' . ($teams ? 'teams' : 'entities'), 'with' => [
                count($positions), (float) $center[0], (float) $center[1], ['text' => self::javaFixed($minimum)],
            ]]);
        }
    }

    /** Position.getSpawnY: one above the highest block under two air blocks, from maxHeight + 1 down. */
    private function spreadSpawnY(array $position, int $maxHeight): int
    {
        [$x, $z] = [(int) floor($position[0]), (int) floor($position[1])];
        $y = $maxHeight + 1;
        $catalog = $this->version->catalog;
        $twoAbove = $catalog->air($this->world->get($x, $y, $z));
        --$y;
        $oneAbove = $catalog->air($this->world->get($x, $y, $z));
        while ($y > $this->world->minY) {
            --$y;
            $air = $catalog->air($this->world->get($x, $y, $z));
            if (!$air && $oneAbove && $twoAbove) {
                return $y + 1;
            }
            [$twoAbove, $oneAbove] = [$oneAbove, $air];
        }
        return $maxHeight + 1;
    }

    /** Position.isSafe: the block underfoot is below maxHeight, not a liquid, and one entities can teleport to. */
    private function spreadSafe(array $position, int $maxHeight): bool
    {
        $y = $this->spreadSpawnY($position, $maxHeight) - 1;
        $state = $this->world->get((int) floor($position[0]), $y, (int) floor($position[1]));
        $name = $this->version->catalog->blockName($state);
        return $y < $maxHeight && !$this->version->catalog->fluid($state) && in_array($name, $this->version->blockTags()['minecraft:entities_can_teleport_to'] ?? [], true);
    }
}
