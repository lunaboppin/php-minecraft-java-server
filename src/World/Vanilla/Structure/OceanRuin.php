<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\Int64;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\PositionalRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/**
 * OceanRuinPieces: a warm sandstone ruin, or a cold stone brick ruin in brick, cracked and mossy
 * layers, rotted by integrity, settled on the ocean floor, sometimes amid a cluster of small ruins.
 */
final class OceanRuin extends TemplatePiece
{
    private const WARM = ['warm_1', 'warm_2', 'warm_3', 'warm_4', 'warm_5', 'warm_6', 'warm_7', 'warm_8'];

    private const BIG_WARM = ['big_warm_4', 'big_warm_5', 'big_warm_6', 'big_warm_7'];

    private const COLD = ['brick' => ['brick_1', 'brick_2', 'brick_3', 'brick_4', 'brick_5', 'brick_6', 'brick_7', 'brick_8'], 'cracked' => ['cracked_1', 'cracked_2', 'cracked_3', 'cracked_4', 'cracked_5', 'cracked_6', 'cracked_7', 'cracked_8'], 'mossy' => ['mossy_1', 'mossy_2', 'mossy_3', 'mossy_4', 'mossy_5', 'mossy_6', 'mossy_7', 'mossy_8']];

    private const BIG_COLD = ['brick' => ['big_brick_1', 'big_brick_2', 'big_brick_3', 'big_brick_8'], 'cracked' => ['big_cracked_1', 'big_cracked_2', 'big_cracked_3', 'big_cracked_8'], 'mossy' => ['big_mossy_1', 'big_mossy_2', 'big_mossy_3', 'big_mossy_8']];

    public float $integrity = 0.8;

    public string $temperature = 'warm';

    public bool $large = false;

    /**
     * OceanRuinPieces.addPieces
     *
     * @return list<self>
     */
    public static function pieces(Blocks $blocks, string $directory, array $structure, array $position, int $rotation, RandomSource $random): array
    {
        $large = $random->nextFloat() <= F32::r((float) $structure['large_probability']);
        $pieces = [];
        self::add($pieces, $blocks, $directory, $structure['biome_temp'], $position, $rotation, $random, $large, F32::r($large ? 0.9 : 0.8));
        if ($large && $random->nextFloat() <= F32::r((float) $structure['cluster_probability'])) {
            self::cluster($pieces, $blocks, $directory, $structure['biome_temp'], $position, $rotation, $random);
        }
        return $pieces;
    }

    /** Mth.nextInt */
    private static function between(RandomSource $random, int $min, int $max): int
    {
        return $min >= $max ? $min : $random->nextInt($max - $min + 1) + $min;
    }

    /** OceanRuinPieces.addClusterRuins: up to eight small ruins around a large one. */
    private static function cluster(array &$pieces, Blocks $blocks, string $directory, string $temperature, array $p, int $rotation, RandomSource $random): void
    {
        $parent = [$p[0], 90, $p[2]];
        [$cx, , $cz] = Template::transform(15, 0, 15, BlockTransform::NONE, $rotation, [0, 0, 0]);
        $corner = [$cx + $parent[0], 90, $cz + $parent[2]];
        $parentBox = [min($parent[0], $corner[0]), 90, min($parent[2], $corner[2]), max($parent[0], $corner[0]), 90, max($parent[2], $corner[2])];
        [$ox, , $oz] = [min($parent[0], $corner[0]), 90, min($parent[2], $corner[2])];
        $positions = [];
        foreach ([[-16, 1, 8, 16, 1, 7], [-16, 1, 8, 0, 1, 7], [-16, 1, 8, -16, 4, 8], [0, 1, 7, 16, 1, 7], [0, 1, 7, -16, 4, 6], [16, 1, 7, 16, 3, 8], [16, 1, 7, 0, 1, 7], [16, 1, 7, -16, 4, 8]] as [$bx, $x0, $x1, $bz, $z0, $z1]) {
            $x = $ox + $bx + self::between($random, $x0, $x1);
            $positions[] = [$x, 90, $oz + $bz + self::between($random, $z0, $z1)];
        }
        $ruins = self::between($random, 4, 8);
        for ($i = 0; $i < $ruins; ++$i) {
            if ($positions === []) {
                continue;
            }
            $pos = array_splice($positions, $random->nextInt(count($positions)), 1)[0];
            $nextRotation = $random->nextInt(4);
            [$nx, , $nz] = Template::transform(5, 0, 6, BlockTransform::NONE, $nextRotation, [0, 0, 0]);
            $box = [min($pos[0], $pos[0] + $nx), 90, min($pos[2], $pos[2] + $nz), max($pos[0], $pos[0] + $nx), 90, max($pos[2], $pos[2] + $nz)];
            if (!Piece::intersects($box, $parentBox)) {
                self::add($pieces, $blocks, $directory, $temperature, $pos, $nextRotation, $random, false, F32::r(0.8));
            }
        }
    }

    /** OceanRuinPieces.addPiece */
    private static function add(array &$pieces, Blocks $blocks, string $directory, string $temperature, array $position, int $rotation, RandomSource $random, bool $large, float $integrity): void
    {
        if ($temperature === 'cold') {
            $sets = $large ? self::BIG_COLD : self::COLD;
            $index = $random->nextInt(count($sets['brick']));
            foreach ([['brick', $integrity], ['cracked', F32::r(0.7)], ['mossy', F32::r(0.5)]] as [$set, $layerIntegrity]) {
                $pieces[] = self::create($blocks, $directory, 'underwater_ruin/' . $sets[$set][$index], $position, $rotation, $layerIntegrity, $temperature, $large);
            }
            return;
        }
        $names = $large ? self::BIG_WARM : self::WARM;
        $pieces[] = self::create($blocks, $directory, 'underwater_ruin/' . $names[$random->nextInt(count($names))], $position, $rotation, $integrity, $temperature, $large);
    }

    private static function create(Blocks $blocks, string $directory, string $template, array $position, int $rotation, float $integrity, string $temperature, bool $large): self
    {
        $box = Template::load($blocks, $directory, $template)->boundingBox($position, BlockTransform::NONE, $rotation, [0, 0, 0]);
        $piece = new self($box, $template, $position, $rotation, [0, 0, 0]);
        [$piece->integrity, $piece->temperature, $piece->large] = [$integrity, $temperature, $large];
        return $piece;
    }

    public static function restoreWith(array $piece): self
    {
        $ruin = new self($piece['box'], $piece['template'], $piece['position'], $piece['rotation'], $piece['pivot']);
        [$ruin->integrity, $ruin->temperature, $ruin->large] = $piece['ruin'];
        return $ruin;
    }

    public function type(): string
    {
        return 'ocean_ruin';
    }

    public function data(): array
    {
        return parent::data() + ['ruin' => [$this->integrity, $this->temperature, $this->large]];
    }

    protected function process(StructureContext $context, array $info, array $original): ?array
    {
        // BlockRotProcessor(integrity), then BlockIgnoreProcessor.STRUCTURE_AND_AIR.
        if (!(Template::positionalRandom($info[0], $info[1], $info[2])->nextFloat() <= $this->integrity)) {
            return null;
        }
        $name = $context->blocks->name($info[3]);
        return $name === 'structure_block' || $name === 'air' ? null : $info;
    }

    protected function wholePiece(): bool
    {
        return true;
    }

    /** The CappedProcessor of five suspicious sand or gravel blocks, with archaeology loot. */
    protected function finalize(StructureContext $context, Region $region, array $processed, array $originals): array
    {
        if ($processed === []) {
            return $processed;
        }
        [$candidate, $replacement, $table] = $this->temperature === 'cold' ? ['gravel', 'suspicious_gravel', 'ocean_ruin_cold'] : ['sand', 'suspicious_sand', 'ocean_ruin_warm'];
        $random = (new PositionalRandom(false, (new LegacyRandom($context->seed))->nextLong()))->at(...$this->position);
        $count = min(5, count($processed));
        $indexes = range(0, count($processed) - 1);
        for ($i = count($indexes); $i > 1; --$i) {
            $j = $random->nextInt($i);
            [$indexes[$i - 1], $indexes[$j]] = [$indexes[$j], $indexes[$i - 1]];
        }
        $replaced = 0;
        foreach ($indexes as $index) {
            if ($replaced >= $count) {
                break;
            }
            [$x, $y, $z, $state, $nbt] = $processed[$index];
            if ($context->blocks->name($state) !== $candidate) {
                continue;
            }
            // RuleProcessor with AppendLoot: the rule's random also draws the loot seed.
            $ruleRandom = new LegacyRandom(Int64::coordinateSeed($x, $y, $z));
            $processed[$index] = [$x, $y, $z, $context->state($replacement), ($nbt ?? []) + ['LootTable' => "minecraft:archaeology/$table", 'LootTableSeed' => $ruleRandom->nextLong()]];
            ++$replaced;
        }
        return $processed;
    }

    protected function waterlogging(): bool
    {
        return true;
    }

    protected function handleDataMarker(StructureContext $context, Region $region, RandomSource $random, array $chunkBox, string $marker, int $x, int $y, int $z): void
    {
        $inside = Piece::inside($chunkBox, $x, $y, $z);
        if ($marker === 'chest') {
            if ($inside) {
                $water = $context->blocks->isWater($region->get($x, $y, $z));
                $region->set($x, $y, $z, $context->state('chest', ['waterlogged' => $water ? 'true' : 'false']));
            }
            if ($context->blocks->name($region->get($x, $y, $z)) === 'chest') {
                $context->blockEntities["$x,$y,$z"] = ['id' => 'chest', 'loot_table' => 'minecraft:chests/underwater_ruin_' . ($this->large ? 'big' : 'small'), 'seed' => $random->nextLong()];
            }
        } elseif ($marker === 'drowned' && $inside) {
            // The drowned itself is not spawned yet.
            $region->set($x, $y, $z, $context->state($y > $region->seaLevel ? 'air' : 'water'));
        }
    }

    public function postProcess(StructureContext $context, Region $region, RandomSource $random, array $chunkBox): void
    {
        $template = Template::load($context->blocks, $context->directory, $this->template);
        $this->position[1] = $region->height(Region::OCEAN_FLOOR_WG, $this->position[0], $this->position[2]);
        [$cx, , $cz] = Template::transform($template->size[0] - 1, 0, $template->size[2] - 1, BlockTransform::NONE, $this->rotation, [0, 0, 0]);
        $this->position[1] = $this->settledHeight($context, $region, $this->position, [$cx + $this->position[0], 0, $cz + $this->position[2]]);
        parent::postProcess($context, $region, $random, $chunkBox);
    }

    /** OceanRuinPiece.getHeight: sink into the floor where most of the footprint is overhanging. */
    private function settledHeight(StructureContext $c, Region $region, array $pos, array $corner): int
    {
        $newY = $pos[1];
        $minY = 512;
        $topY = $newY - 1;
        $area = 0;
        for ($z = min($pos[2], $corner[2]); $z <= max($pos[2], $corner[2]); ++$z) {
            for ($x = min($pos[0], $corner[0]); $x <= max($pos[0], $corner[0]); ++$x) {
                $floorY = $pos[1] - 1;
                $state = $region->get($x, $floorY, $z);
                while (($c->blocks->isAir($state) || $c->blocks->isWater($state) || $c->blocks->inTag($state, 'ice')) && $floorY > $region->minY + 1) {
                    $state = $region->get($x, --$floorY, $z);
                }
                $minY = min($minY, $floorY);
                if ($floorY < $topY - 2) {
                    ++$area;
                }
            }
        }
        if ($topY - $minY > 2 && $area > abs($pos[0] - $corner[0]) - 2) {
            $newY = $minY + 1;
        }
        return $newY;
    }
}
