<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Vanilla\BiomeClimate;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;

/**
 * ServerLevel.tickPrecipitation: a chunk picks a random column now and then. Water under the
 * open sky freezes where the biome is cold enough there; while it rains, snow settles or piles
 * up and cauldrons catch rain or powder snow.
 */
trait Precipitation
{
    private ?BiomeClimate $precipitationClimate = null;

    /** @var array<string, bool> whether each biome has precipitation */
    private array $precipitationBiomes = [];

    /** ServerLevel.tickChunk: tickSpeed tries, each one in 48, before the chunk's random ticks. */
    private function tickChunkPrecipitation(int $cx, int $cz, int $speed): void
    {
        if ($this->world->dimension !== 'overworld') {
            return;
        }
        for ($i = 0; $i < $speed; ++$i) {
            if (random_int(0, 47) !== 0) {
                continue;
            }
            // Level.getBlockRandomPos
            $this->randomTickValue = ($this->randomTickValue * 3 + 1013904223) & 0xffffffff;
            $k = ($this->randomTickValue >= 0x80000000 ? $this->randomTickValue - 0x100000000 : $this->randomTickValue) >> 2;
            $this->tickPrecipitation($cx * 16 + ($k & 15), $cz * 16 + (($k >> 8) & 15));
        }
    }

    private function tickPrecipitation(int $x, int $z): void
    {
        $catalog = $this->version->catalog;
        $top = $this->motionBlockingHeight($x, $z);
        $below = $top - 1;
        $biome = preg_replace('/^minecraft:/', '', $this->world->column($x, $z)['biome']);
        $this->precipitationClimate ??= new BiomeClimate($this->version->directory . '/worldgen');
        $cold = fn (int $y): bool => $this->precipitationClimate->coldEnoughToSnow($biome, $x, $y, $z, 63);
        $height = $this->world->minY + $this->world->height;
        // Biome.shouldFreeze at the edge of water: a still source in block light below 10.
        if ($below >= $this->world->minY && $below < $height && $cold($below) && $this->world->rawBrightness($x, $below, $z, true) < 10) {
            $state = $this->world->get($x, $below, $z);
            if ($catalog->blockName($state) === 'water' && $catalog->properties($state)['level'] === '0') {
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]) {
                    if ($catalog->blockName($this->world->get($x + $dx, $below, $z + $dz)) !== 'water') {
                        $this->setBlock($x, $below, $z, $catalog->state('ice'));
                        break;
                    }
                }
            }
        }
        if (!$this->raining()) {
            return;
        }
        $maxLayers = (int) $this->gameRule('max_snow_accumulation_height');
        // Biome.shouldSnow: air where snow survives, cold enough, in block light below 10.
        if ($maxLayers > 0 && $top >= $this->world->minY && $top < $height && $cold($top) && $this->world->rawBrightness($x, $top, $z, true) < 10) {
            $state = $this->world->get($x, $top, $z);
            if ($catalog->blockName($state) === 'snow') {
                $layers = (int) $catalog->properties($state)['layers'];
                if ($layers < min($maxLayers, 8)) {
                    $this->setBlock($x, $top, $z, $catalog->state('snow', ['layers' => (string) ($layers + 1)]));
                }
            } elseif ($catalog->air($state) && $this->snowSurvives($x, $top, $z)) {
                $this->setBlock($x, $top, $z, $catalog->state('snow'));
            }
        }
        // Block.handlePrecipitation: cauldrons fill by chance with rain or powder snow.
        $this->precipitationBiomes[$biome] ??= (bool) (json_decode((string) file_get_contents($this->version->directory . '/worldgen/biome/' . $biome . '.json'), true, 512, JSON_THROW_ON_ERROR)['has_precipitation'] ?? false);
        if (!$this->precipitationBiomes[$biome] || $below < $this->world->minY) {
            return;
        }
        $snow = $cold($below);
        $state = $this->world->get($x, $below, $z);
        $name = $catalog->blockName($state);
        $chance = self::randomUnit() < ($snow ? 0.1 : 0.05);
        if ($name === 'cauldron' && $chance) {
            $this->setBlock($x, $below, $z, $catalog->state($snow ? 'powder_snow_cauldron' : 'water_cauldron', ['level' => '1']));
        } elseif ($name === ($snow ? 'powder_snow_cauldron' : 'water_cauldron') && $chance && (int) $catalog->properties($state)['level'] < 3) {
            $this->setBlock($x, $below, $z, $catalog->state($name, ['level' => (string) ((int) $catalog->properties($state)['level'] + 1)]));
        }
    }

    /** SnowLayerBlock.canSurvive */
    private function snowSurvives(int $x, int $y, int $z): bool
    {
        $blocks = $this->fireBlocks();
        $below = $this->world->get($x, $y - 1, $z);
        if ($blocks->inTag($below, 'cannot_support_snow_layer')) {
            return false;
        }
        if ($blocks->inTag($below, 'support_override_snow_layer')) {
            return true;
        }
        $catalog = $this->version->catalog;
        if ($catalog->blockName($below) === 'snow') {
            return $catalog->properties($below)['layers'] === '8';
        }
        // Block.isFaceFull for the collision shape's top face.
        foreach ((new Collision($catalog))->shape($below) as $box) {
            if ($box[0] <= 0.0 && $box[2] <= 0.0 && $box[3] >= 1.0 && $box[5] >= 1.0 && $box[4] >= 1.0) {
                return true;
            }
        }
        return $blocks->flag($below, Blocks::FULL_CUBE);
    }
}
