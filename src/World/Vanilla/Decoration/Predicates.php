<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

use PhpMinecraftJavaServer\World\Generation\RandomSource;

/** Block predicates (levelgen.blockpredicates) and ore rule tests (templatesystem.RuleTest). */
final class Predicates
{
    public ?Survival $survival = null;

    public function __construct(private readonly Blocks $blocks)
    {
    }

    private static function offset(array $json): array
    {
        return $json['offset'] ?? [0, 0, 0];
    }

    /** @return list<string> block names or tags */
    private static function list(mixed $blocks): array
    {
        return is_array($blocks) ? $blocks : [$blocks];
    }

    private function matchesBlocks(mixed $blocks, int $state): bool
    {
        foreach (self::list($blocks) as $block) {
            if ($this->blocks->is($state, $block)) {
                return true;
            }
        }
        return false;
    }

    public function test(mixed $json, Region $region, int $x, int $y, int $z): bool
    {
        $type = preg_replace('/^minecraft:/', '', $json['type']);
        [$ox, $oy, $oz] = self::offset($json);
        $px = $x + $ox;
        $py = $y + $oy;
        $pz = $z + $oz;
        switch ($type) {
            case 'true':
                return true;
            case 'not':
                return !$this->test($json['predicate'], $region, $x, $y, $z);
            case 'all_of':
                foreach ($json['predicates'] as $predicate) {
                    if (!$this->test($predicate, $region, $x, $y, $z)) {
                        return false;
                    }
                }
                return true;
            case 'any_of':
                foreach ($json['predicates'] as $predicate) {
                    if ($this->test($predicate, $region, $x, $y, $z)) {
                        return true;
                    }
                }
                return false;
            case 'matching_blocks':
                return $this->matchesBlocks($json['blocks'], $region->get($px, $py, $pz));
            case 'matching_block_tag':
                return $this->blocks->inTag($region->get($px, $py, $pz), preg_replace('/^#?(minecraft:)?/', '', $json['tag']));
            case 'matching_fluids':
                $state = $region->get($px, $py, $pz);
                foreach (self::list($json['fluids']) as $fluid) {
                    $fluid = preg_replace('/^minecraft:/', '', $fluid);
                    if (($fluid === 'water' || $fluid === 'flowing_water') && $this->blocks->isWater($state)
                        && ($fluid === 'flowing_water') !== $this->blocks->isSource($state)) {
                        return true;
                    }
                    if (($fluid === 'lava' || $fluid === 'flowing_lava') && $this->blocks->isLava($state)
                        && ($fluid === 'flowing_lava') !== $this->blocks->isSource($state)) {
                        return true;
                    }
                }
                return false;
            case 'solid':
                return $this->blocks->isSolid($region->get($px, $py, $pz));
            case 'replaceable':
                return $this->blocks->replaceable($region->get($px, $py, $pz));
            case 'would_survive':
                return $this->survival->canSurvive($this->blocks->parse($json['state']), $region, $px, $py, $pz);
            case 'has_sturdy_face':
                $direction = array_search($json['direction'], ['down', 'up', 'north', 'south', 'west', 'east'], true);
                return $this->blocks->sturdy($region->get($px, $py, $pz), $direction);
            case 'inside_world_bounds':
                return !$region->isOutsideBuildHeight($py);
            case 'matching_biomes':
                return in_array($region->biome($x, $y, $z), array_map(static fn (string $b): string => preg_replace('/^minecraft:/', '', $b), self::list($json['biomes'])), true);
            case 'height_range':
                return $y >= Providers::anchor($json['min_inclusive'], $region) && $y <= Providers::anchor($json['max_inclusive'], $region);
            case 'volume_match':
                [$minX, $minY, $minZ] = $json['min'];
                [$maxX, $maxY, $maxZ] = $json['max'];
                for ($dx = $minX; $dx <= $maxX; ++$dx) {
                    for ($dz = $minZ; $dz <= $maxZ; ++$dz) {
                        for ($dy = $minY; $dy <= $maxY; ++$dy) {
                            if (!$this->test($json['predicate'], $region, $x + $dx, $y + $dy, $z + $dz)) {
                                return false;
                            }
                        }
                    }
                }
                return true;
        }
        throw new \LogicException('Unsupported block predicate ' . $type);
    }

    /** RuleTest.test for ore targets. */
    public function rule(array $json, int $state, int $y, RandomSource $random): bool
    {
        switch (preg_replace('/^minecraft:/', '', $json['predicate_type'])) {
            case 'always_true':
                return true;
            case 'tag_match':
                return $this->blocks->inTag($state, preg_replace('/^#?(minecraft:)?/', '', $json['tag']));
            case 'block_match':
                return $this->blocks->is($state, $json['block']);
            case 'blockstate_match':
                return $state === $this->blocks->parse($json['block_state']);
            case 'random_block_match':
                return $this->blocks->is($state, $json['block']) && $random->nextFloat() < (float) $json['probability'];
            case 'height_match':
                return $y >= $json['min_inclusive'] && $y <= $json['max_inclusive'];
            case 'not':
                return !$this->rule($json['rule'], $state, $y, $random);
            case 'all_of':
                foreach ($json['rules'] as $rule) {
                    if (!$this->rule($rule, $state, $y, $random)) {
                        return false;
                    }
                }
                return true;
            case 'any_of':
                foreach ($json['rules'] as $rule) {
                    if ($this->rule($rule, $state, $y, $random)) {
                        return true;
                    }
                }
                return false;
        }
        throw new \LogicException('Unsupported rule test ' . $json['predicate_type']);
    }
}
