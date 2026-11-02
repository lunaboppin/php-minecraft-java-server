<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

/**
 * ChorusPlantBlock and ChorusFlowerBlock: plants connect to plants, flowers and the end stone
 * below them, and break (dropping) a tick after losing what holds them, so a tree falls apart
 * from its broken base; flowers grow up or branch out on random ticks until they die at age 5.
 */
trait Chorus
{
    private const CHORUS_SIDES = ['north' => [0, -1], 'east' => [1, 0], 'south' => [0, 1], 'west' => [-1, 0]];

    private function chorusName(int $x, int $y, int $z): string
    {
        return $this->version->catalog->blockName($this->world->get($x, $y, $z));
    }

    private function chorusSupport(int $x, int $y, int $z, string $tag): bool
    {
        return $this->fireBlocks()->inTag($this->world->get($x, $y, $z), $tag);
    }

    private function chorusEmpty(int $x, int $y, int $z): bool
    {
        return $this->version->catalog->air($this->world->get($x, $y, $z));
    }

    /** ChorusPlantBlock.getStateWithConnections. */
    private function chorusPlantState(int $x, int $y, int $z): int
    {
        $joins = fn (int $a, int $b, int $c): bool => in_array($this->chorusName($a, $b, $c), ['chorus_plant', 'chorus_flower'], true);
        $p = ['down' => $joins($x, $y - 1, $z) || $this->chorusSupport($x, $y - 1, $z, 'supports_chorus_plant'), 'up' => $joins($x, $y + 1, $z)];
        foreach (self::CHORUS_SIDES as $side => [$dx, $dz]) {
            $p[$side] = $joins($x + $dx, $y, $z + $dz);
        }
        return $this->version->catalog->state('chorus_plant', array_map(static fn (bool $v): string => $v ? 'true' : 'false', $p));
    }

    /** ChorusPlantBlock.canSurvive: on a plant or end stone, or beside a plant standing on one, unless also capped and footed. */
    private function chorusPlantSurvives(int $x, int $y, int $z): bool
    {
        $capped = !$this->chorusEmpty($x, $y + 1, $z) && !$this->chorusEmpty($x, $y - 1, $z);
        foreach (self::CHORUS_SIDES as [$dx, $dz]) {
            if ($this->chorusName($x + $dx, $y, $z + $dz) === 'chorus_plant') {
                if ($capped) {
                    return false;
                }
                if ($this->chorusName($x + $dx, $y - 1, $z + $dz) === 'chorus_plant' || $this->chorusSupport($x + $dx, $y - 1, $z + $dz, 'supports_chorus_plant')) {
                    return true;
                }
            }
        }
        return $this->chorusName($x, $y - 1, $z) === 'chorus_plant' || $this->chorusSupport($x, $y - 1, $z, 'supports_chorus_plant');
    }

    /** ChorusFlowerBlock.canSurvive: on a plant or end stone, or in the air beside exactly one plant and nothing else. */
    private function chorusFlowerSurvives(int $x, int $y, int $z): bool
    {
        if ($this->chorusName($x, $y - 1, $z) === 'chorus_plant' || $this->chorusSupport($x, $y - 1, $z, 'supports_chorus_flower')) {
            return true;
        }
        if (!$this->chorusEmpty($x, $y - 1, $z)) {
            return false;
        }
        $plants = 0;
        foreach (self::CHORUS_SIDES as [$dx, $dz]) {
            if ($this->chorusName($x + $dx, $y, $z + $dz) === 'chorus_plant') {
                ++$plants;
            } elseif (!$this->chorusEmpty($x + $dx, $y, $z + $dz)) {
                return false;
            }
        }
        return $plants === 1;
    }

    /**
     * updateShape for chorus around a changed block: a plant that can no longer stand breaks
     * next tick, otherwise its connection toward the change follows; a flower checks itself unless
     * the change is above it.
     */
    private function chorusChanged(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $changed = $this->chorusName($x, $y, $z);
        foreach (['down' => [0, 1, 0], 'up' => [0, -1, 0], 'north' => [0, 0, 1], 'south' => [0, 0, -1], 'west' => [1, 0, 0], 'east' => [-1, 0, 0]] as $toward => [$dx, $dy, $dz]) {
            [$a, $b, $c] = [$x + $dx, $y + $dy, $z + $dz];
            $state = $this->world->get($a, $b, $c);
            $name = $catalog->blockName($state);
            if ($name === 'chorus_plant') {
                if (!$this->chorusPlantSurvives($a, $b, $c)) {
                    $this->scheduleBlock('chorus', $a, $b, $c, 1);
                    continue;
                }
                $connect = $changed === 'chorus_plant' || $changed === 'chorus_flower' || ($toward === 'down' && $this->chorusSupport($x, $y, $z, 'supports_chorus_plant'));
                $p = $catalog->properties($state);
                if ($p[$toward] !== ($connect ? 'true' : 'false')) {
                    $p[$toward] = $connect ? 'true' : 'false';
                    $this->setBlock($a, $b, $c, $catalog->state('chorus_plant', $p));
                }
            } elseif ($name === 'chorus_flower' && $toward !== 'up' && !$this->chorusFlowerSurvives($a, $b, $c)) {
                $this->scheduleBlock('chorus', $a, $b, $c, 1);
            }
        }
    }

    /** ChorusPlantBlock.tick and ChorusFlowerBlock.tick: break with drops when unsupported. */
    private function tickChorus(int $x, int $y, int $z): void
    {
        $state = $this->world->get($x, $y, $z);
        $name = $this->version->catalog->blockName($state);
        $survives = match ($name) {
            'chorus_plant' => $this->chorusPlantSurvives($x, $y, $z),
            'chorus_flower' => $this->chorusFlowerSurvives($x, $y, $z),
            default => true,
        };
        if (!$survives) {
            $this->levelEvent(2001, [$x, $y, $z], $state);
            $this->dropBlockResources($x, $y, $z, $state);
            $this->setBlock($x, $y, $z, $this->version->catalog->state('air'));
        }
    }

    /** ChorusFlowerBlock.allNeighborsEmpty. */
    private function chorusSidesEmpty(int $x, int $y, int $z, ?string $ignore): bool
    {
        foreach (self::CHORUS_SIDES as $side => [$dx, $dz]) {
            if ($side !== $ignore && !$this->chorusEmpty($x + $dx, $y, $z + $dz)) {
                return false;
            }
        }
        return true;
    }

    /** ChorusFlowerBlock.randomTick: grow upward, or branch out sideways, or die. */
    private function chorusFlowerRandomTick(int $x, int $y, int $z, int $state): void
    {
        $catalog = $this->version->catalog;
        if (!$this->chorusEmpty($x, $y + 1, $z) || $y + 1 >= $this->world->minY + $this->world->height) {
            return;
        }
        $age = (int) $catalog->properties($state)['age'];
        if ($age >= 5) {
            return;
        }
        $up = false;
        $pillarOnSupport = false;
        if ($this->chorusSupport($x, $y - 1, $z, 'supports_chorus_flower')) {
            $up = true;
        } elseif ($this->chorusName($x, $y - 1, $z) === 'chorus_plant') {
            $height = 1;
            for ($i = 0; $i < 4; ++$i) {
                if ($this->chorusName($x, $y - $height - 1, $z) !== 'chorus_plant') {
                    $pillarOnSupport = $this->chorusSupport($x, $y - $height - 1, $z, 'supports_chorus_flower');
                    break;
                }
                ++$height;
            }
            if ($height < 2 || $height <= random_int(0, $pillarOnSupport ? 4 : 3)) {
                $up = true;
            }
        } elseif ($this->chorusEmpty($x, $y - 1, $z)) {
            $up = true;
        }
        $flower = function (int $a, int $b, int $c, int $age) use ($catalog): void {
            $this->setBlock($a, $b, $c, $catalog->state('chorus_flower', ['age' => (string) $age]));
            $this->levelEvent($age === 5 ? 1034 : 1033, [$a, $b, $c], 0);
        };
        if ($up && $this->chorusSidesEmpty($x, $y + 1, $z, null) && $this->chorusEmpty($x, $y + 2, $z)) {
            $this->setBlock($x, $y, $z, $this->chorusPlantState($x, $y, $z));
            $flower($x, $y + 1, $z, $age);
            return;
        }
        if ($age < 4) {
            $attempts = random_int(0, 3) + ($pillarOnSupport ? 1 : 0);
            $branched = false;
            $opposite = ['north' => 'south', 'south' => 'north', 'east' => 'west', 'west' => 'east'];
            for ($i = 0; $i < $attempts; ++$i) {
                // Direction.Plane.HORIZONTAL.getRandomDirection: north, east, south, west.
                $side = array_keys(self::CHORUS_SIDES)[random_int(0, 3)];
                [$dx, $dz] = self::CHORUS_SIDES[$side];
                if ($this->chorusEmpty($x + $dx, $y, $z + $dz) && $this->chorusEmpty($x + $dx, $y - 1, $z + $dz) && $this->chorusSidesEmpty($x + $dx, $y, $z + $dz, $opposite[$side])) {
                    $flower($x + $dx, $y, $z + $dz, $age + 1);
                    $branched = true;
                }
            }
            if ($branched) {
                $this->setBlock($x, $y, $z, $this->chorusPlantState($x, $y, $z));
                return;
            }
        }
        $flower($x, $y, $z, 5);
    }
}
