<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Vanilla\BiomeClimate;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;

/**
 * FireBlock and SoulFireBlock in the world: fire ticks every 30 to 39 game ticks, ages, burns
 * away and spreads into flammable blocks with FireBlock.bootStrap's odds, and goes out without
 * fuel, in rain or when what holds it is gone.
 */
trait FireSimulation
{
    private ?Blocks $fireBlocks = null;

    /** @var array<string, array{0: int, 1: int}>|null ignite and burn odds by block name */
    private ?array $flammable = null;

    /** @var list<int>|null skylight-down and dampening bits by state */
    private ?array $skyBits = null;

    private ?BiomeClimate $fireClimate = null;

    /** @var array<string, array> biome definitions by name */
    private array $fireBiomes = [];

    /** Direction.values() order: down, up, north, south, west, east. */
    private const FIRE_DIRECTIONS = [[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]];

    private function fireBlocks(): Blocks
    {
        if ($this->fireBlocks === null) {
            $path = $this->version->directory . '/worldgen/block-behaviour.json';
            $behaviour = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $this->flammable = $behaviour['flammable'];
            $this->skyBits = array_values(unpack('v*', base64_decode($behaviour['spawning'])));
            $this->fireBlocks = new Blocks($this->version, $path);
        }
        return $this->fireBlocks;
    }

    /** @return array{0: int, 1: int} FireBlock.getIgniteOdds and getBurnOdds of a state */
    private function fireOdds(int $state): array
    {
        $this->fireBlocks();
        if (($this->version->catalog->properties($state)['waterlogged'] ?? 'false') === 'true') {
            return [0, 0];
        }
        return $this->flammable[$this->version->catalog->blockName($state)] ?? [0, 0];
    }

    /**
     * After a block changes: FireBlock.onPlace schedules a placed fire's tick, and fire beside the
     * change re-checks its shape (BaseFireBlock.updateShape), going out where it cannot survive.
     */
    private function fireChanged(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        if ($catalog->blockName($this->world->get($x, $y, $z)) === 'fire' && !isset($this->blockTicks['fire:' . $x . ':' . $y . ':' . $z])) {
            $this->scheduleBlock('fire', $x, $y, $z, 30 + random_int(0, 9));
        }
        foreach (self::FIRE_DIRECTIONS as [$dx, $dy, $dz]) {
            [$a, $b, $c] = [$x + $dx, $y + $dy, $z + $dz];
            $state = $this->world->get($a, $b, $c);
            $name = $catalog->blockName($state);
            if ($name === 'fire') {
                $age = (int) $catalog->properties($state)['age'];
                $this->setBlock($a, $b, $c, $this->fireSurvives($a, $b, $c) ? $this->fireWithAge($a, $b, $c, $age) : $catalog->state('air'));
            } elseif ($name === 'soul_fire' && !$this->fireBlocks()->inTag($this->world->get($a, $b - 1, $c), 'soul_fire_base_blocks')) {
                $this->setBlock($a, $b, $c, $catalog->state('air'));
            }
        }
    }

    private function canBurn(int $state): bool
    {
        return $this->fireOdds($state)[0] > 0;
    }

    /** FireBlock.canSurvive */
    private function fireSurvives(int $x, int $y, int $z): bool
    {
        return $this->fireBlocks()->sturdy($this->world->get($x, $y - 1, $z), Blocks::UP) || $this->validFireLocation($x, $y, $z);
    }

    /** FireBlock.isValidFireLocation: a flammable block on some side. */
    private function validFireLocation(int $x, int $y, int $z): bool
    {
        foreach (self::FIRE_DIRECTIONS as [$dx, $dy, $dz]) {
            if ($this->canBurn($this->world->get($x + $dx, $y + $dy, $z + $dz))) {
                return true;
            }
        }
        return false;
    }

    /**
     * BaseFireBlock.getState with FireBlock.getStateWithAge: soul fire over its base blocks, else
     * fire that clings to the flammable sides when nothing below holds it.
     */
    private function fireWithAge(int $x, int $y, int $z, int $age): int
    {
        $catalog = $this->version->catalog;
        $blocks = $this->fireBlocks();
        $below = $this->world->get($x, $y - 1, $z);
        if ($blocks->inTag($below, 'soul_fire_base_blocks')) {
            return $catalog->state('soul_fire');
        }
        $properties = ['age' => (string) $age, 'north' => 'false', 'east' => 'false', 'south' => 'false', 'west' => 'false', 'up' => 'false'];
        if (!$this->canBurn($below) && !$blocks->sturdy($below, Blocks::UP)) {
            foreach (['up' => [0, 1, 0], 'north' => [0, 0, -1], 'south' => [0, 0, 1], 'west' => [-1, 0, 0], 'east' => [1, 0, 0]] as $side => [$dx, $dy, $dz]) {
                $properties[$side] = $this->canBurn($this->world->get($x + $dx, $y + $dy, $z + $dz)) ? 'true' : 'false';
            }
        }
        return $catalog->state('fire', $properties);
    }

    /** FireBlock.tick */
    private function tickFire(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        if ($catalog->blockName($state) !== 'fire') {
            return;
        }
        $this->scheduleBlock('fire', $x, $y, $z, 30 + random_int(0, 9));
        if (!$this->canSpreadFireAround($x, $y, $z)) {
            return;
        }
        $air = $catalog->state('air');
        if (!$this->fireSurvives($x, $y, $z)) {
            $this->setBlock($x, $y, $z, $air);
        }
        $blocks = $this->fireBlocks();
        $infiniburn = $blocks->inTag($this->world->get($x, $y - 1, $z), match ($this->world->dimension) {
            'the_end' => 'infiniburn_end',
            'the_nether' => 'infiniburn_nether',
            default => 'infiniburn_overworld',
        });
        $age = (int) $catalog->properties($state)['age'];
        $raining = $this->world->dimension === 'overworld' && $this->weather !== 'clear';
        if (!$infiniburn && $raining && $this->nearRain($x, $y, $z) && self::randomUnit() < 0.2 + $age * 0.03) {
            $this->setBlock($x, $y, $z, $air);
            return;
        }
        $newAge = min(15, $age + intdiv(random_int(0, 2), 2));
        if ($newAge !== $age) {
            $state = $catalog->state('fire', array_merge($catalog->properties($state), ['age' => (string) $newAge]));
            $this->setBlockQuietly($x, $y, $z, $state);
        }
        if (!$infiniburn) {
            if (!$this->validFireLocation($x, $y, $z)) {
                if (!$blocks->sturdy($this->world->get($x, $y - 1, $z), Blocks::UP) || $age > 3) {
                    $this->setBlock($x, $y, $z, $air);
                }
                return;
            }
            if ($age === 15 && random_int(0, 3) === 0 && !$this->canBurn($this->world->get($x, $y - 1, $z))) {
                $this->setBlock($x, $y, $z, $air);
                return;
            }
        }
        $burnout = $this->increasedFireBurnout($x, $z);
        $extra = $burnout ? -50 : 0;
        foreach ([[1, 0, 0, 300], [-1, 0, 0, 300], [0, -1, 0, 250], [0, 1, 0, 250], [0, 0, -1, 300], [0, 0, 1, 300]] as [$dx, $dy, $dz, $chance]) {
            $this->checkBurnOut($x + $dx, $y + $dy, $z + $dz, $chance + $extra, $age);
        }
        for ($dx = -1; $dx <= 1; ++$dx) {
            for ($dz = -1; $dz <= 1; ++$dz) {
                for ($dy = -1; $dy <= 4; ++$dy) {
                    if ($dx === 0 && $dy === 0 && $dz === 0) {
                        continue;
                    }
                    $rate = 100 + ($dy > 1 ? ($dy - 1) * 100 : 0);
                    [$a, $b, $c] = [$x + $dx, $y + $dy, $z + $dz];
                    $ignite = $this->igniteOddsAt($a, $b, $c);
                    if ($ignite <= 0) {
                        continue;
                    }
                    $odds = intdiv($ignite + 40 + $this->config->difficulty * 7, $age + 30);
                    if ($burnout) {
                        $odds = intdiv($odds, 2);
                    }
                    if ($odds > 0 && random_int(0, $rate - 1) <= $odds && (!$raining || !$this->nearRain($a, $b, $c))) {
                        $this->setBlock($a, $b, $c, $this->fireWithAge($a, $b, $c, min(15, $age + intdiv(random_int(0, 4), 4))));
                    }
                }
            }
        }
    }

    /** FireBlock.getIgniteOdds at an empty position: the most flammable block beside it. */
    private function igniteOddsAt(int $x, int $y, int $z): int
    {
        if (!$this->version->catalog->air($this->world->get($x, $y, $z))) {
            return 0;
        }
        $odds = 0;
        foreach (self::FIRE_DIRECTIONS as [$dx, $dy, $dz]) {
            $odds = max($odds, $this->fireOdds($this->world->get($x + $dx, $y + $dy, $z + $dz))[0]);
        }
        return $odds;
    }

    /** FireBlock.checkBurnOut: a burning block is consumed, sometimes catching fire itself. */
    private function checkBurnOut(int $x, int $y, int $z, int $chance, int $age): void
    {
        $state = $this->world->get($x, $y, $z);
        if (random_int(0, $chance - 1) >= $this->fireOdds($state)[1]) {
            return;
        }
        if (random_int(0, $age + 9) < 5 && !$this->rainingAt($x, $y, $z)) {
            $this->setBlock($x, $y, $z, $this->fireWithAge($x, $y, $z, min($age + intdiv(random_int(0, 4), 4), 15)));
        } else {
            $this->setBlock($x, $y, $z, $this->version->catalog->state('air'));
        }
        // A burnt TNT block primes.
        if ($this->version->catalog->blockName($state) === 'tnt') {
            $this->primeTnt($x, $y, $z);
        }
    }

    /** ServerLevel.canSpreadFireAround: a non-spectator within fire_spread_radius_around_player blocks. */
    private function canSpreadFireAround(int $x, int $y, int $z): bool
    {
        $radius = (int) $this->gameRule('fire_spread_radius_around_player');
        if ($radius === -1) {
            return true;
        }
        foreach ($this->localPlayers() as $player) {
            if ($player->gameMode !== 3 && sqrt(($player->x - $x) ** 2 + ($player->y - $y) ** 2 + ($player->z - $z) ** 2) < $radius) {
                return true;
            }
        }
        return false;
    }

    /** FireBlock.isNearRain */
    private function nearRain(int $x, int $y, int $z): bool
    {
        foreach ([[0, 0], [-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dz]) {
            if ($this->rainingAt($x + $dx, $y, $z + $dz)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Level.isRainingAt: rain falls on a position open to the sky, at or above the
     * MOTION_BLOCKING heightmap, in a biome warm enough to rain rather than snow.
     */
    private function rainingAt(int $x, int $y, int $z): bool
    {
        if ($this->world->dimension !== 'overworld' || $this->weather === 'clear') {
            return false;
        }
        $blocks = $this->fireBlocks();
        for ($b = $this->world->minY + $this->world->height - 1; $b > $y; --$b) {
            $state = $this->world->get($x, $b, $z);
            // Sky light stays 15 only through blocks that pass it down undimmed.
            if ($blocks->flag($state, Blocks::MOTION_HEIGHTMAP) || $blocks->hasFluid($state) || ($this->skyBits[$state] & 31) !== 16) {
                return false;
            }
        }
        [$biome, $json] = $this->fireBiome($x, $z);
        $this->fireClimate ??= new BiomeClimate($this->version->directory . '/worldgen');
        return ($json['has_precipitation'] ?? false) && !$this->fireClimate->coldEnoughToSnow($biome, $x, $y, $z, 63);
    }

    /** EnvironmentAttributes.INCREASED_FIRE_BURNOUT of the biome there. */
    private function increasedFireBurnout(int $x, int $z): bool
    {
        return $this->fireBiome($x, $z)[1]['attributes']['minecraft:gameplay/increased_fire_burnout'] ?? false;
    }

    /** @return array{0: string, 1: array} the biome at a column and its definition */
    private function fireBiome(int $x, int $z): array
    {
        $biome = preg_replace('/^minecraft:/', '', $this->world->column($x, $z)['biome']);
        return [$biome, $this->fireBiomes[$biome] ??= json_decode((string) file_get_contents($this->version->directory . '/worldgen/biome/' . $biome . '.json'), true, 512, JSON_THROW_ON_ERROR)];
    }
}
