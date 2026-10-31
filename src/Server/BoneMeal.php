<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Decorator;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\WorldgenRandom;

/**
 * BoneMealItem.growCrop: a bone-mealable block that is a valid target grows by its own rules
 * (BonemealableBlock.performBonemeal), the bone meal is used up, and level event 1505 shows the
 * particles.
 */
trait BoneMeal
{
    /** @var list<string>|null configured features tagged can_spawn_from_bone_meal */
    private ?array $boneMealFlowers = null;

    /** Whether the bone meal was used, by its target's rules. */
    private function useBoneMeal(Client $c, int $x, int $y, int $z, int $slot): bool
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $name = $catalog->blockName($state);
        $p = $catalog->properties($state);
        $grow = $this->boneMealGrowth($x, $y, $z, $name, $p);
        if ($grow === null) {
            return false;
        }
        $grow();
        if ($c->gameMode !== 1) {
            \PhpMinecraftJavaServer\World\Inventory::consume($c->inventory, $slot);
            $this->syncInventory($c);
        }
        $this->levelEvent(1505, [$x, $y, $z], 15);
        return true;
    }

    /**
     * isValidBonemealTarget, then isBonemealSuccess and performBonemeal as a closure.
     *
     * @param array<string, string> $p
     */
    private function boneMealGrowth(int $x, int $y, int $z, string $name, array $p): ?\Closure
    {
        $catalog = $this->version->catalog;
        $with = fn (array $changes): int => $catalog->state($name, array_merge($p, array_map('strval', $changes)));
        if (isset(self::CROPS[$name])) {
            // CropBlock.growCrops: 2 to 5 stages, beetroot a third of that.
            $max = self::CROPS[$name];
            if ((int) $p['age'] >= $max) {
                return null;
            }
            return function () use ($x, $y, $z, $name, $p, $max, $with): void {
                $increase = random_int(2, 5);
                if ($name === 'beetroots') {
                    $increase = intdiv($increase, 3);
                }
                $this->setBlock($x, $y, $z, $with(['age' => min($max, (int) $p['age'] + $increase)]));
            };
        }
        if ($name === 'melon_stem' || $name === 'pumpkin_stem') {
            if ((int) $p['age'] === 7) {
                return null;
            }
            return function () use ($x, $y, $z, $name, $p, $with): void {
                $age = min(7, (int) $p['age'] + random_int(2, 5));
                $this->setBlock($x, $y, $z, $with(['age' => $age]));
                // A grown stem takes a random tick straight away.
                if ($age === 7) {
                    $this->growCrop($x, $y, $z, $name, $this->version->catalog->properties($this->world->get($x, $y, $z)));
                }
            };
        }
        if ($name === 'sweet_berry_bush') {
            if ((int) $p['age'] >= 3) {
                return null;
            }
            return fn () => $this->setBlock($x, $y, $z, $with(['age' => (int) $p['age'] + 1]));
        }
        if (isset(self::GROWERS[$name]) && !($name === 'mangrove_propagule' && $p['hanging'] === 'true')) {
            // TreeGrower.canGrow: a grower with only mega trees needs a 2×2 of saplings.
            if (self::GROWERS[$name][0] === [] && !$this->twoByTwoSaplings($x, $y, $z, $name)) {
                return null;
            }
            return function () use ($x, $y, $z, $name, $p, $with): void {
                // SaplingBlock.isBonemealSuccess: 45%.
                if (self::randomUnit() >= 0.45) {
                    return;
                }
                if ($p['stage'] === '0') {
                    $this->setBlockQuietly($x, $y, $z, $with(['stage' => 1]));
                } else {
                    $this->growTree($x, $y, $z, $name);
                }
            };
        }
        if ($name === 'short_grass' || $name === 'fern') {
            if (!$catalog->air($this->world->get($x, $y + 1, $z)) || $y + 1 >= $this->world->minY + $this->world->height) {
                return null;
            }
            // TallGrassBlock: DoublePlantBlock.placeAt the tall form.
            $tall = $name === 'fern' ? 'large_fern' : 'tall_grass';
            return function () use ($x, $y, $z, $tall, $catalog): void {
                $this->setBlock($x, $y, $z, $catalog->state($tall, ['half' => 'lower']));
                $this->setBlock($x, $y + 1, $z, $catalog->state($tall, ['half' => 'upper']));
            };
        }
        if ($name === 'grass_block') {
            if (!$catalog->air($this->world->get($x, $y + 1, $z)) || $y + 1 >= $this->world->minY + $this->world->height) {
                return null;
            }
            return fn () => $this->boneMealGrass($x, $y, $z);
        }
        return null;
    }

    private function twoByTwoSaplings(int $x, int $y, int $z, string $name): bool
    {
        for ($dx = 0; $dx >= -1; --$dx) {
            for ($dz = 0; $dz >= -1; --$dz) {
                $all = true;
                foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$ox, $oz]) {
                    $all = $all && $this->version->catalog->blockName($this->world->get($x + $dx + $ox, $y, $z + $dz + $oz)) === $name;
                }
                if ($all) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * GrassBlock.performBonemeal: 128 walks over the grass from the block above, each placing
     * short grass, a flower from the biome's bone meal features, or growing short grass tall.
     */
    private function boneMealGrass(int $x, int $y, int $z): void
    {
        $random = new WorldgenRandom(random_int(PHP_INT_MIN, PHP_INT_MAX));
        $this->onRegion($x, $z, function (Region $region, Decorator $decorator) use ($x, $y, $z, $random): bool {
            $blocks = $decorator->blocks;
            $short = $blocks->state('short_grass');
            for ($attempt = 0; $attempt < 128; ++$attempt) {
                [$tx, $ty, $tz] = [$x, $y + 1, $z];
                for ($i = 0, $steps = intdiv($attempt, 16); $i < $steps; ++$i) {
                    $tx += $random->nextInt(3) - 1;
                    $ty += intdiv(($random->nextInt(3) - 1) * $random->nextInt(3), 2);
                    $tz += $random->nextInt(3) - 1;
                    // stopBonemealSpread: off the grass, or into a full block.
                    if ($blocks->name($region->get($tx, $ty - 1, $tz)) !== 'grass_block' || $blocks->flag($region->get($tx, $ty, $tz), Blocks::FULL_CUBE)) {
                        continue 2;
                    }
                }
                $state = $region->get($tx, $ty, $tz);
                if ($state === $short && $random->nextFloat() < 0.1) {
                    if ($blocks->isAir($region->get($tx, $ty + 1, $tz)) && !$region->isOutsideBuildHeight($ty + 1)) {
                        $region->set($tx, $ty, $tz, $blocks->state('tall_grass', ['half' => 'lower']));
                        $region->set($tx, $ty + 1, $tz, $blocks->state('tall_grass', ['half' => 'upper']));
                    }
                }
                if (!$blocks->isAir($state) || $region->isOutsideBuildHeight($ty)) {
                    continue;
                }
                if ($random->nextFloat() < 0.125) {
                    $flowers = $this->boneMealFlowers($tx, $tz);
                    if ($flowers !== []) {
                        $decorator->place($flowers[$random->nextInt(count($flowers))], $region, $random, $tx, $ty, $tz);
                    }
                } else {
                    $decorator->placeFeature('grass_bonemeal', $region, $random, [$tx, $ty, $tz]);
                }
            }
            return true;
        });
    }

    /**
     * BiomeGenerationSettings.getBoneMealFeatures: the configured features of the biome's placed
     * features, nested ones included, tagged can_spawn_from_bone_meal.
     *
     * @return list<string>
     */
    private function boneMealFlowers(int $x, int $z): array
    {
        $directory = $this->version->directory . '/worldgen';
        $this->boneMealFlowers ??= array_map(
            static fn (string $f): string => preg_replace('/^minecraft:/', '', $f),
            json_decode((string) file_get_contents($directory . '/tags/feature/can_spawn_from_bone_meal.json'), true, 512, JSON_THROW_ON_ERROR)['values']
        );
        static $byBiome = [];
        $biome = preg_replace('/^minecraft:/', '', $this->world->column($x, $z)['biome']);
        if (!isset($byBiome[$biome])) {
            $found = [];
            $collect = function (mixed $node) use (&$collect, &$found, $directory): void {
                if (is_string($node)) {
                    $name = preg_replace('/^minecraft:/', '', $node);
                    if (in_array($name, $this->boneMealFlowers, true)) {
                        $found[] = $name;
                    }
                    if (is_file("$directory/feature/$name.json")) {
                        $collect(json_decode((string) file_get_contents("$directory/feature/$name.json"), true, 512, JSON_THROW_ON_ERROR));
                    }
                } elseif (is_array($node)) {
                    foreach ($node as $key => $value) {
                        if ($key !== 'type' && (is_array($value) || ($key === 'feature' || $key === 'features' || is_int($key)))) {
                            $collect($value);
                        }
                    }
                }
            };
            $definition = json_decode((string) file_get_contents("$directory/biome/$biome.json"), true, 512, JSON_THROW_ON_ERROR);
            foreach ($definition['features'] ?? [] as $step) {
                foreach ((array) $step as $placed) {
                    $placed = preg_replace('/^minecraft:/', '', $placed);
                    $collect(json_decode((string) file_get_contents("$directory/placed_feature/$placed.json"), true, 512, JSON_THROW_ON_ERROR)['feature']);
                }
            }
            $byBiome[$biome] = $found;
        }
        return $byBiome[$biome];
    }
}
