<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/**
 * End features: obsidian spikes, small floating islands, return gateways, chorus plants and the
 * arrival platform.
 */
final class End
{
    /** Direction.Plane.HORIZONTAL: north, east, south, west. */
    private const HORIZONTAL = [[0, -1], [1, 0], [0, 1], [-1, 0]];

    private const FACES = ['north', 'east', 'south', 'west'];

    private readonly Blocks $blocks;

    /** @var array<int, list<array{0: int, 1: int, 2: int, 3: int, 4: bool}>> spikes by world seed */
    private array $spikes = [];

    public function __construct(Decorator $decorator)
    {
        $this->blocks = $decorator->blocks;
    }

    /**
     * EndSpikeFeature.getSpikesForLevel: ten spikes on a circle of radius 42, their sizes
     * shuffled by the world seed.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int, 4: bool}> centre x, centre z, radius, height, guarded
     */
    public function spikes(int $seed): array
    {
        if (isset($this->spikes[$seed])) {
            return $this->spikes[$seed];
        }
        $random = new LegacyRandom(new LegacyRandom($seed)->nextLong() & 65535);
        $sizes = range(0, 9);
        for ($i = 10; $i > 1; --$i) {
            $swap = $random->nextInt($i);
            [$sizes[$i - 1], $sizes[$swap]] = [$sizes[$swap], $sizes[$i - 1]];
        }
        $spikes = [];
        for ($i = 0; $i < 10; ++$i) {
            $angle = 2.0 * (-M_PI + (M_PI / 10) * $i);
            $size = $sizes[$i];
            $spikes[] = [(int) floor(42.0 * cos($angle)), (int) floor(42.0 * sin($angle)), 2 + intdiv($size, 3), 76 + $size * 3, $size === 1 || $size === 2];
        }
        return $this->spikes[$seed] = $spikes;
    }

    /** EndSpikeFeature: the spikes centred in this chunk, with cages, bedrock and fire for their crystals. */
    public function spike(Region $region, RandomSource $random, int $x, int $z): bool
    {
        $obsidian = $this->blocks->state('obsidian');
        $air = $this->blocks->state('air');
        foreach ($this->spikes($region->seed) as [$centerX, $centerZ, $radius, $height, $guarded]) {
            if ($centerX >> 4 !== $x >> 4 || $centerZ >> 4 !== $z >> 4) {
                continue;
            }
            for ($py = $region->minY; $py <= $height + 10; ++$py) {
                for ($pz = $centerZ - $radius; $pz <= $centerZ + $radius; ++$pz) {
                    for ($px = $centerX - $radius; $px <= $centerX + $radius; ++$px) {
                        if (($px - $centerX) ** 2 + ($pz - $centerZ) ** 2 <= $radius * $radius + 1 && $py < $height) {
                            $region->set($px, $py, $pz, $obsidian);
                        } elseif ($py > 65) {
                            $region->set($px, $py, $pz, $air);
                        }
                    }
                }
            }
            if ($guarded) {
                $bars = $this->blocks->state('iron_bars');
                for ($dx = -2; $dx <= 2; ++$dx) {
                    for ($dz = -2; $dz <= 2; ++$dz) {
                        for ($dy = 0; $dy <= 3; ++$dy) {
                            $top = $dy === 3;
                            if (abs($dx) !== 2 && abs($dz) !== 2 && !$top) {
                                continue;
                            }
                            $xEdge = $dx === -2 || $dx === 2 || $top;
                            $zEdge = $dz === -2 || $dz === 2 || $top;
                            $state = $bars;
                            foreach (['north' => $xEdge && $dz !== -2, 'south' => $xEdge && $dz !== 2, 'west' => $zEdge && $dx !== -2, 'east' => $zEdge && $dx !== 2] as $side => $on) {
                                $state = $this->blocks->with($state, $side, $on ? 'true' : 'false');
                            }
                            $region->set($centerX + $dx, $height + $dy, $centerZ + $dz, $state);
                        }
                    }
                }
            }
            // The end crystal's yaw; the crystal entity itself is not generated yet.
            $random->nextFloat();
            $region->set($centerX, $height, $centerZ, $this->blocks->state('bedrock'));
            $region->set($centerX, $height + 1, $centerZ, $this->blocks->state('fire'));
        }
        return true;
    }

    /** EndIslandFeature: a stack of shrinking end stone discs. */
    public function island(Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        $endStone = $this->blocks->state('end_stone');
        $size = F32::r($random->nextInt(3) + 4.0);
        for ($dy = 0; $size > 0.5; --$dy) {
            $limit = F32::r(($size + 1.0) * ($size + 1.0));
            for ($dx = (int) floor(-$size); $dx <= (int) ceil($size); ++$dx) {
                for ($dz = (int) floor(-$size); $dz <= (int) ceil($size); ++$dz) {
                    if ($dx * $dx + $dz * $dz <= $limit) {
                        $region->set($x + $dx, $y + $dy, $z + $dz, $endStone);
                    }
                }
            }
            $size = F32::r($size - ($random->nextInt(2) + 0.5));
        }
        return true;
    }

    /** EndGatewayFeature: a gateway block in a bedrock frame, cleared around it. */
    public function gateway(Region $region, int $x, int $y, int $z): bool
    {
        $bedrock = $this->blocks->state('bedrock');
        $air = $this->blocks->state('air');
        for ($dz = -1; $dz <= 1; ++$dz) {
            for ($dy = -2; $dy <= 2; ++$dy) {
                for ($dx = -1; $dx <= 1; ++$dx) {
                    $end = abs($dy) === 2;
                    $state = match (true) {
                        $dx === 0 && $dy === 0 && $dz === 0 => $this->blocks->state('end_gateway'),
                        $dy === 0 => $air,
                        $end && $dx === 0 && $dz === 0, ($dx === 0 || $dz === 0) && !$end => $bedrock,
                        default => $air,
                    };
                    $region->set($x + $dx, $y + $dy, $z + $dz, $state);
                }
            }
        }
        return true;
    }

    /** EndPlatformFeature: the obsidian arrival platform with room above it. */
    public function platform(Region $region, int $x, int $y, int $z): bool
    {
        $obsidian = $this->blocks->state('obsidian');
        $air = $this->blocks->state('air');
        for ($dz = -2; $dz <= 2; ++$dz) {
            for ($dx = -2; $dx <= 2; ++$dx) {
                for ($dy = -1; $dy < 3; ++$dy) {
                    $state = $dy === -1 ? $obsidian : $air;
                    if ($this->blocks->name($region->get($x + $dx, $y + $dy, $z + $dz)) !== $this->blocks->name($state)) {
                        $region->set($x + $dx, $y + $dy, $z + $dz, $state);
                    }
                }
            }
        }
        return true;
    }

    /** ChorusPlantFeature: ChorusFlowerBlock.generatePlant on supporting ground. */
    public function chorus(Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        if (!$this->blocks->isAir($region->get($x, $y, $z)) || !$this->blocks->inTag($region->get($x, $y - 1, $z), 'supports_chorus_plant')) {
            return false;
        }
        $this->placePlant($region, $x, $y, $z);
        $this->grow($region, $random, [$x, $y, $z], [$x, $y, $z], 8, 0);
        return true;
    }

    /** ChorusFlowerBlock.growTreeRecursive */
    private function grow(Region $region, RandomSource $random, array $current, array $start, int $spread, int $depth): void
    {
        [$x, $y, $z] = $current;
        $height = $random->nextInt(4) + 1 + ($depth === 0 ? 1 : 0);
        for ($i = 0; $i < $height; ++$i) {
            if (!$this->neighboursEmpty($region, $x, $y + $i + 1, $z, null)) {
                return;
            }
            $this->placePlant($region, $x, $y + $i + 1, $z);
            $this->placePlant($region, $x, $y + $i, $z);
        }
        $placedStem = false;
        if ($depth < 4) {
            $stems = $random->nextInt(4) + ($depth === 0 ? 1 : 0);
            for ($i = 0; $i < $stems; ++$i) {
                $direction = $random->nextInt(4);
                [$dx, $dz] = self::HORIZONTAL[$direction];
                [$tx, $ty, $tz] = [$x + $dx, $y + $height, $z + $dz];
                if (abs($tx - $start[0]) < $spread && abs($tz - $start[2]) < $spread
                    && $this->blocks->isAir($region->get($tx, $ty, $tz)) && $this->blocks->isAir($region->get($tx, $ty - 1, $tz))
                    && $this->neighboursEmpty($region, $tx, $ty, $tz, ($direction + 2) % 4)) {
                    $placedStem = true;
                    $this->placePlant($region, $tx, $ty, $tz);
                    $this->placePlant($region, $tx - $dx, $ty, $tz - $dz);
                    $this->grow($region, $random, [$tx, $ty, $tz], $start, $spread, $depth + 1);
                }
            }
        }
        if (!$placedStem) {
            $region->set($x, $y + $height, $z, $this->blocks->with($this->blocks->state('chorus_flower'), 'age', '5'));
        }
    }

    private function neighboursEmpty(Region $region, int $x, int $y, int $z, ?int $ignore): bool
    {
        foreach (self::HORIZONTAL as $direction => [$dx, $dz]) {
            if ($direction !== $ignore && !$this->blocks->isAir($region->get($x + $dx, $y, $z + $dz))) {
                return false;
            }
        }
        return true;
    }

    /** ChorusPlantBlock.getStateWithConnections */
    private function placePlant(Region $region, int $x, int $y, int $z): void
    {
        $connects = function (int $state): bool {
            $name = $this->blocks->name($state);
            return $name === 'chorus_plant' || $name === 'chorus_flower';
        };
        $below = $region->get($x, $y - 1, $z);
        $state = $this->blocks->with($this->blocks->state('chorus_plant'), 'down', $connects($below) || $this->blocks->inTag($below, 'supports_chorus_plant') ? 'true' : 'false');
        $state = $this->blocks->with($state, 'up', $connects($region->get($x, $y + 1, $z)) ? 'true' : 'false');
        foreach (self::HORIZONTAL as $direction => [$dx, $dz]) {
            $state = $this->blocks->with($state, self::FACES[$direction], $connects($region->get($x + $dx, $y, $z + $dz)) ? 'true' : 'false');
        }
        $region->set($x, $y, $z, $state);
    }
}
