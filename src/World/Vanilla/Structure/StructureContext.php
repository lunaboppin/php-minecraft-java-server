<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Predicates;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Survival;

/** What structure pieces share while they are placed: block states, transforms and shape marks. */
final class StructureContext
{
    public readonly BlockTransform $transform;

    /** @var array<string, int> */
    private array $states = [];

    /** @var array<string, array{0: int, 1: int, 2: int}> ProtoChunk.markPosForPostProcessing, by "x,y,z" */
    public array $postProcessing = [];

    public function __construct(public readonly Blocks $blocks, public readonly int $seed = 0, public readonly string $directory = '')
    {
        $this->transform = new BlockTransform($blocks);
        $this->predicates = new Predicates($blocks);
    }

    /** @param array<string, string> $properties */
    public function state(string $name, array $properties = []): int
    {
        $key = $name . json_encode($properties);
        return $this->states[$key] ??= $this->blocks->state($name, $properties);
    }

    public function with(int $state, string $property, string $value): int
    {
        return $this->blocks->with($state, $property, $value);
    }

    /** StructureStart.placeInChunk's reference position: the first piece's centre at its bottom. */
    public array $referencePos = [0, 0, 0];

    public readonly Predicates $predicates;

    /** @var (\Closure(string, Region, RandomSource, array): bool)|null PlacedFeature.place for feature pool elements */
    public ?\Closure $placeFeature = null;

    /** Block class chains, for checks such as FallingBlock. */
    public ?Survival $survival = null;

    /** Block.updateFromNeighbourShapes for the blocks a template places. */
    public ?ShapeUpdater $shapes = null;

    /** @var (\Closure(string, string): bool)|null whether a biome is in a biome tag */
    public ?\Closure $biomeInTag = null;

    /** WorldGenRegion.getRandom: one stream per decorated chunk, from its origin. */
    public ?RandomSource $regionRandom = null;

    /**
     * Block entities placed by structures, by "x,y,z": a container's loot table and seed.
     *
     * @var array<string, array>
     */
    public array $blockEntities = [];

    /**
     * Entities placed by structures, in placement order: each one's saved tag (id, Pos, Rotation
     * and the template's own tags) and whether Mob.finalizeSpawn runs when it is added.
     *
     * @var list<array{nbt: array, finalize: bool}>
     */
    public array $entities = [];

    /** WorldGenRegion.addFreshEntity of an entity a structure created, with its saved tags. */
    public function addEntity(string $id, array $pos, array $nbt = [], bool $finalize = false): void
    {
        $this->entities[] = ['nbt' => ['id' => 'minecraft:' . $id, 'Pos' => $pos, 'Rotation' => [0.0, 0.0]] + $nbt, 'finalize' => $finalize];
        if ($finalize && $this->regionRandom !== null) {
            self::finalizeDraws($this->regionRandom, $id);
        }
    }

    /**
     * The WorldGenRegion.getRandom draws of Mob.finalizeSpawn for a structure spawn, which later
     * structure code in the chunk (a mansion's allay count) reads after: the follow-range bonus
     * (RandomSource.triangle) and the left-handed roll, then a vindicator's axe enchantment roll.
     * PatrollingMonster skips its leader roll for structure spawns. Worldgen's regional difficulty
     * has no special multiplier below hard, so the enchantment roll draws nothing more.
     */
    private static function finalizeDraws(RandomSource $random, string $id): void
    {
        if (!in_array($id, ['allay', 'evoker', 'vindicator', 'witch', 'elder_guardian'], true)) {
            return;
        }
        $random->nextDouble();
        $random->nextDouble();
        $random->nextFloat();
        if ($id === 'vindicator') {
            $random->nextFloat();
        }
    }

    /** BlockPos.asLong */
    public function blockPosLong(int $x, int $y, int $z): int
    {
        return (($x & 0x3FFFFFF) << 38) | (($z & 0x3FFFFFF) << 12) | ($y & 0xFFF);
    }

    public function markForPostProcessing(int $x, int $y, int $z): void
    {
        $this->postProcessing["$x,$y,$z"] = [$x, $y, $z];
    }
}
