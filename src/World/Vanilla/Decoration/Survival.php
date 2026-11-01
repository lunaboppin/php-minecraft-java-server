<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

/**
 * BlockState.canSurvive for the blocks world generation places, chosen by each block's official
 * class chain (block-behaviour.json "classes"). Chunks have no light data during decoration, so
 * the raw brightness is zero.
 */
final class Survival
{
    /** @var array<string, list<string>> */
    private readonly array $classes;

    public function __construct(private readonly Blocks $blocks, string $behaviourPath)
    {
        $this->classes = json_decode((string) file_get_contents($behaviourPath), true, 512, JSON_THROW_ON_ERROR)['classes'];
    }

    /** @return list<string> */
    public function classes(int $state): array
    {
        return $this->classes[$this->blocks->name($state)] ?? [];
    }

    public function isA(int $state, string $class): bool
    {
        return in_array($class, $this->classes($state), true);
    }

    private function prop(int $state, string $name): ?string
    {
        return $this->blocks->properties($state)[$name] ?? null;
    }

    private function vegetationSupport(string $class, int $state, int $below, BlockReader $region, int $x, int $y, int $z): bool
    {
        $blocks = $this->blocks;
        return match ($class) {
            'SeagrassBlock', 'TallSeagrassBlock' => $blocks->sturdy($below, Blocks::UP) && !$blocks->inTag($below, 'cannot_support_seagrass'),
            'LilyPadBlock' => ($blocks->isWater($below) || $blocks->inTag($below, 'supports_lily_pad')) && !$blocks->hasFluid($region->get($x, $y + 1, $z)),
            // Nothing is lit while chunks decorate, so a mushroom survives on any solid block;
            // post-processing removes those the finished chunk lights to 13 or more.
            'MushroomBlock' => $blocks->inTag($below, 'overrides_mushroom_light_requirement') || $blocks->flag($below, Blocks::SOLID_RENDER),
            // Each Nether root and fungus block has its own supports_<block> tag.
            'NetherRootsBlock', 'NetherFungusBlock' => $blocks->inTag($below, 'supports_' . $blocks->name($state)),
            'NetherSproutsBlock' => $blocks->inTag($below, 'supports_nether_sprouts'),
            'DryVegetationBlock', 'ShortDryGrassBlock' => $blocks->inTag($below, 'supports_dry_vegetation'),
            'AzaleaBlock' => $blocks->inTag($below, 'supports_azalea'),
            'MangrovePropaguleBlock' => $blocks->inTag($below, 'supports_mangrove_propagule'),
            'CactusFlowerBlock' => $blocks->inTag($below, 'support_override_cactus_flower') || $blocks->sturdyCenter($below, Blocks::UP),
            'SeaPickleBlock' => $blocks->sturdy($below, Blocks::UP) || $blocks->isSolid($below),
            'SmallDripleafBlock' => $blocks->inTag($below, 'supports_small_dripleaf')
                || ($blocks->isWater($region->get($x, $y + 1, $z)) && $blocks->isSource($region->get($x, $y + 1, $z)) && $blocks->inTag($below, 'supports_vegetation')),
            default => $blocks->inTag($below, 'supports_vegetation'),
        };
    }

    public function canSurvive(int $state, BlockReader $region, int $x, int $y, int $z): bool
    {
        $blocks = $this->blocks;
        $chain = $this->classes($state);
        if ($chain === []) {
            return true;
        }
        $below = $region->get($x, $y - 1, $z);
        $above = $region->get($x, $y + 1, $z);
        $class = $chain[0];
        switch ($class) {
            case 'SugarCaneBlock':
                if ($blocks->name($below) === 'sugar_cane') {
                    return true;
                }
                if (!$blocks->inTag($below, 'supports_sugar_cane')) {
                    return false;
                }
                foreach ([[0, -1], [1, 0], [0, 1], [-1, 0]] as [$dx, $dz]) {
                    $neighbour = $region->get($x + $dx, $y - 1, $z + $dz);
                    if ($blocks->isWater($neighbour) || $blocks->inTag($neighbour, 'supports_sugar_cane_adjacently')) {
                        return true;
                    }
                }
                return false;
            case 'CactusBlock':
                foreach ([[0, -1], [1, 0], [0, 1], [-1, 0]] as [$dx, $dz]) {
                    $neighbour = $region->get($x + $dx, $y, $z + $dz);
                    if ($blocks->isSolid($neighbour) || $blocks->isLava($neighbour)) {
                        return false;
                    }
                }
                return ($blocks->name($below) === 'cactus' || $blocks->inTag($below, 'supports_cactus'))
                    && !in_array($blocks->name($above), ['water', 'lava'], true);
            case 'SnowLayerBlock':
                if ($blocks->inTag($below, 'cannot_support_snow_layer')) {
                    return false;
                }
                return $blocks->inTag($below, 'support_override_snow_layer') || $blocks->sturdy($below, Blocks::UP)
                    || ($blocks->name($below) === 'snow' && $this->prop($below, 'layers') === '8');
            case 'BambooStalkBlock':
                return $blocks->inTag($below, 'supports_bamboo');
            case 'LeafLitterBlock':
                return $blocks->sturdy($below, Blocks::UP);
            case 'CarpetBlock':
                return !$blocks->isAir($below);
            case 'MossyCarpetBlock':
                return $this->prop($state, 'bottom') === 'true' || $this->prop($state, 'base') === 'true'
                    ? !$blocks->isAir($below)
                    : $blocks->name($below) === $blocks->name($state) && ($this->prop($below, 'bottom') ?? $this->prop($below, 'base')) === 'true';
            case 'HangingRootsBlock':
                return $blocks->sturdy($above, Blocks::DOWN);
            case 'SporeBlossomBlock':
                return $blocks->sturdyCenter($above, Blocks::DOWN) && !$blocks->isWater($region->get($x, $y, $z));
            case 'BigDripleafBlock':
                return in_array($blocks->name($below), ['big_dripleaf', 'big_dripleaf_stem'], true) || $blocks->inTag($below, 'supports_big_dripleaf');
        }
        if (in_array('BaseCoralWallFanBlock', $chain, true)) {
            $facing = array_search($this->prop($state, 'facing'), ['down', 'up', 'north', 'south', 'west', 'east'], true);
            [$dx, $dy, $dz] = Blocks::OFFSETS[$facing ^ 1];
            return $blocks->sturdy($region->get($x + $dx, $y + $dy, $z + $dz), $facing);
        }
        if (in_array('BaseCoralPlantTypeBlock', $chain, true)) {
            return $blocks->sturdy($below, Blocks::UP);
        }
        if (in_array('GrowingPlantBlock', $chain, true)) {
            $up = in_array($class, ['KelpBlock', 'KelpPlantBlock', 'TwistingVinesBlock', 'TwistingVinesPlantBlock'], true);
            $attached = $up ? $below : $above;
            $head = preg_replace('/_plant$/', '', $blocks->name($state));
            if (in_array($class, ['KelpBlock', 'KelpPlantBlock'], true) && $blocks->inTag($attached, 'cannot_support_kelp')) {
                return false;
            }
            $name = $blocks->name($attached);
            return $name === $head || $name === $head . '_plant' || $blocks->sturdy($attached, $up ? Blocks::UP : Blocks::DOWN);
        }
        if (in_array('MultifaceBlock', $chain, true)) {
            $any = false;
            foreach (['down', 'up', 'north', 'south', 'west', 'east'] as $direction => $face) {
                if ($this->prop($state, $face) !== 'true') {
                    continue;
                }
                [$dx, $dy, $dz] = Blocks::OFFSETS[$direction];
                if (!$blocks->sturdy($region->get($x + $dx, $y + $dy, $z + $dz), $direction ^ 1)) {
                    return false;
                }
                $any = true;
            }
            return $any;
        }
        if ($class === 'MangrovePropaguleBlock' && $this->prop($state, 'hanging') === 'true') {
            return $blocks->inTag($above, 'supports_hanging_mangrove_propagule');
        }
        if (in_array('DoublePlantBlock', $chain, true) && $this->prop($state, 'half') === 'upper') {
            return $blocks->name($below) === $blocks->name($state) && $this->prop($below, 'half') === 'lower';
        }
        if (in_array('VegetationBlock', $chain, true)) {
            if ($class === 'TallSeagrassBlock' || $class === 'SmallDripleafBlock') {
                $here = $region->get($x, $y, $z);
                if ($class === 'TallSeagrassBlock' && !($blocks->isWater($here) && $blocks->isSource($here))) {
                    return false;
                }
            }
            return $this->vegetationSupport($class, $state, $below, $region, $x, $y, $z);
        }
        return true;
    }
}
