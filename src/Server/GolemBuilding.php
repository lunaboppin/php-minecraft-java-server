<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

/**
 * CarvedPumpkinBlock.trySpawnGolem: a carved pumpkin or jack o'lantern set on two snow blocks
 * becomes a snow golem, and on a T of iron blocks with air beside its legs, an iron golem.
 */
trait GolemBuilding
{
    /** BlockPatternBuilder aisles, top row first; each pattern is one block deep. */
    private const GOLEM_PATTERNS = [
        'snow_golem' => ['rows' => ['^', '#', '#'], 'body' => 'snow_block', 'spawn' => [0, 2]],
        'iron_golem' => ['rows' => ['~^~', '###', '~#~'], 'body' => 'iron_block', 'spawn' => [1, 2]],
    ];

    /** CarvedPumpkinBlock.onPlace: only when a pumpkin replaces some other block. */
    private function golemHeadPlaced(int $x, int $y, int $z, int $previous, int $state): void
    {
        $name = $this->version->catalog->blockName($state);
        if (($name !== 'carved_pumpkin' && $name !== 'jack_o_lantern') || $this->version->catalog->blockName($previous) === $name) {
            return;
        }
        foreach (self::GOLEM_PATTERNS as $kind => $pattern) {
            $match = $this->findGolemPattern($pattern, $x, $y, $z);
            if ($match !== null) {
                $this->buildGolem($kind, $pattern, $match);
                return;
            }
        }
    }

    /**
     * BlockPattern.find: every corner within the pattern's largest size above and beside the
     * pumpkin (x fastest, then y, then z), facing every way with every perpendicular up, in
     * Direction order; the first that matches.
     *
     * @return array{origin: array{int, int, int}, forwards: array{int, int, int}, up: array{int, int, int}}|null
     */
    private function findGolemPattern(array $pattern, int $x, int $y, int $z): ?array
    {
        $rows = $pattern['rows'];
        $height = count($rows);
        $width = strlen($rows[0]);
        $size = max($width, $height, 1);
        $directions = [[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]];
        for ($dz = 0; $dz < $size; ++$dz) {
            for ($dy = 0; $dy < $size; ++$dy) {
                for ($dx = 0; $dx < $size; ++$dx) {
                    $origin = [$x + $dx, $y + $dy, $z + $dz];
                    foreach ($directions as $forwards) {
                        foreach ($directions as $up) {
                            if ($up === $forwards || $up === array_map(static fn (int $v): int => -$v, $forwards)) {
                                continue;
                            }
                            $match = ['origin' => $origin, 'forwards' => $forwards, 'up' => $up];
                            if ($this->golemPatternMatches($pattern, $match, $width, $height)) {
                                return $match;
                            }
                        }
                    }
                }
            }
        }
        return null;
    }

    private function golemPatternMatches(array $pattern, array $match, int $width, int $height): bool
    {
        for ($column = 0; $column < $width; ++$column) {
            for ($row = 0; $row < $height; ++$row) {
                $name = $this->version->catalog->blockName($this->world->get(...$this->golemPatternBlock($match, $column, $row)));
                $ok = match ($pattern['rows'][$row][$column]) {
                    '^' => $name === 'carved_pumpkin' || $name === 'jack_o_lantern',
                    '#' => $name === $pattern['body'],
                    '~' => in_array($name, ['air', 'cave_air', 'void_air'], true),
                    default => true,
                };
                if (!$ok) {
                    return false;
                }
            }
        }
        return true;
    }

    /** BlockPattern.translateAndRotate: right is forwards x up, and rows go down along up. */
    private function golemPatternBlock(array $match, int $right, int $down): array
    {
        [$f, $u] = [$match['forwards'], $match['up']];
        $r = [$f[1] * $u[2] - $f[2] * $u[1], $f[2] * $u[0] - $f[0] * $u[2], $f[0] * $u[1] - $f[1] * $u[0]];
        return [
            $match['origin'][0] - $u[0] * $down + $r[0] * $right,
            $match['origin'][1] - $u[1] * $down + $r[1] * $right,
            $match['origin'][2] - $u[2] * $down + $r[2] * $right,
        ];
    }

    /**
     * CarvedPumpkinBlock.spawnGolemInWorld: each pattern block is cleared with its breaking
     * effect (level event 2001), then the golem stands 0.05 above its spawn block's bottom
     * centre, facing south; an iron golem built this way is player-created.
     */
    private function buildGolem(string $kind, array $pattern, array $match): void
    {
        $height = count($pattern['rows']);
        $width = strlen($pattern['rows'][0]);
        $air = $this->version->catalog->state('air');
        for ($column = 0; $column < $width; ++$column) {
            for ($row = 0; $row < $height; ++$row) {
                $pos = $this->golemPatternBlock($match, $column, $row);
                $state = $this->world->get(...$pos);
                $this->setBlock(...[...$pos, $air]);
                $this->levelEvent(2001, $pos, $state);
            }
        }
        [$x, $y, $z] = $this->golemPatternBlock($match, ...$pattern['spawn']);
        $this->spawnAnimal($kind, $x + 0.5, $y + 0.05, $z + 0.5, $kind === 'iron_golem' ? ['yaw' => 0.0, 'playerCreated' => true] : ['yaw' => 0.0]);
    }

    /**
     * SnowGolem.aiStep, NoAI or not: with mob griefing on, a snow layer goes into each air block
     * a quarter block from the golem's centre at its feet' height that can hold one.
     */
    private function tickSnowGolem(array $mob): void
    {
        if ($mob['kind'] !== 'snow_golem' || $this->gameRule('mob_griefing') !== 'true') {
            return;
        }
        $snow = $this->version->catalog->state('snow');
        for ($i = 0; $i < 4; ++$i) {
            $x = (int) floor($mob['x'] + ($i % 2 * 2 - 1) * 0.25);
            $y = (int) floor($mob['y']);
            $z = (int) floor($mob['z'] + (intdiv($i, 2) % 2 * 2 - 1) * 0.25);
            if (in_array($this->version->catalog->blockName($this->world->get($x, $y, $z)), ['air', 'cave_air', 'void_air'], true) && $this->snowSurvives($x, $y, $z)) {
                $this->setBlock($x, $y, $z, $snow);
            }
        }
    }
}
