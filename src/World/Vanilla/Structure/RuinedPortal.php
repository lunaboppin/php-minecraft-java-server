<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

use PhpMinecraftJavaServer\World\Generation\Int64;
use PhpMinecraftJavaServer\World\Generation\LegacyRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Region;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/**
 * RuinedPortalPiece: a broken portal frame, aged, partly lava and magma, with netherrack spread
 * around it, placed whole by the chunk at its centre.
 */
final class RuinedPortal extends TemplatePiece
{
    /** BlackstoneReplaceProcessor */
    private const BLACKSTONE = [
        'cobblestone' => 'blackstone', 'mossy_cobblestone' => 'blackstone', 'stone' => 'polished_blackstone', 'stone_bricks' => 'polished_blackstone_bricks',
        'mossy_stone_bricks' => 'polished_blackstone_bricks', 'cobblestone_stairs' => 'blackstone_stairs', 'mossy_cobblestone_stairs' => 'blackstone_stairs',
        'stone_stairs' => 'polished_blackstone_stairs', 'stone_brick_stairs' => 'polished_blackstone_brick_stairs', 'mossy_stone_brick_stairs' => 'polished_blackstone_brick_stairs',
        'cobblestone_slab' => 'blackstone_slab', 'mossy_cobblestone_slab' => 'blackstone_slab', 'smooth_stone_slab' => 'polished_blackstone_slab', 'stone_slab' => 'polished_blackstone_slab',
        'stone_brick_slab' => 'polished_blackstone_brick_slab', 'mossy_stone_brick_slab' => 'polished_blackstone_brick_slab', 'stone_brick_wall' => 'polished_blackstone_brick_wall',
        'mossy_stone_brick_wall' => 'polished_blackstone_brick_wall', 'cobblestone_wall' => 'blackstone_wall', 'mossy_cobblestone_wall' => 'blackstone_wall',
        'chiseled_stone_bricks' => 'chiseled_polished_blackstone', 'cracked_stone_bricks' => 'cracked_polished_blackstone_bricks', 'iron_bars' => 'iron_chain',
    ];

    /** @var array{placement: string, cold: bool, mossiness: float, air_pocket: bool, overgrown: bool, vines: bool, blackstone: bool} */
    public array $properties = [];

    public static function restoreWith(array $piece): self
    {
        $portal = new self($piece['box'], $piece['template'], $piece['position'], $piece['rotation'], $piece['pivot'], $piece['mirror']);
        $portal->properties = $piece['portal'];
        return $portal;
    }

    public function type(): string
    {
        return 'ruined_portal';
    }

    public function data(): array
    {
        return parent::data() + ['portal' => $this->properties];
    }

    private function heightmap(): string
    {
        return $this->properties['placement'] === 'on_ocean_floor' ? Region::OCEAN_FLOOR_WG : Region::WORLD_SURFACE_WG;
    }

    protected function waterlogging(): bool
    {
        return true;
    }

    /** RuleTest random_block_match / block_match against the rule's own positional random. */
    private static function replace(StructureContext $c, string $from, ?float $probability, string $to, array $info, RandomSource $random): ?int
    {
        if ($c->blocks->name($info[3]) !== $from || ($probability !== null && !($random->nextFloat() < $probability))) {
            return null;
        }
        return $c->state($to);
    }

    protected function process(StructureContext $c, array $info, array $original): ?array
    {
        $p = $this->properties;
        $blocks = $c->blocks;
        $name = $blocks->name($info[3]);
        // BlockIgnoreProcessor: structure blocks, and air unless the portal is in an air pocket.
        if ($name === 'structure_block' || !$p['air_pocket'] && $name === 'air') {
            return null;
        }
        // RuleProcessor: gold blocks rot to air; lava cools; netherrack sometimes becomes magma.
        $random = new LegacyRandom(Int64::coordinateSeed($info[0], $info[1], $info[2]));
        $lava = $p['placement'] === 'on_ocean_floor' ? ['lava', null, 'magma_block'] : ($p['cold'] ? ['lava', null, 'netherrack'] : ['lava', F32::r(0.2), 'magma_block']);
        $rules = [['gold_block', F32::r(0.3), 'air'], $lava];
        if (!$p['cold']) {
            $rules[] = ['netherrack', F32::r(0.07), 'magma_block'];
        }
        foreach ($rules as [$from, $probability, $to]) {
            $replaced = self::replace($c, $from, $probability, $to, $info, $random);
            if ($replaced !== null) {
                $info[3] = $replaced;
                break;
            }
        }
        $info = $this->age($c, $info);
        // ProtectedBlockProcessor(features_cannot_replace)
        $existing = $this->region->get($info[0], $info[1], $info[2]);
        if ($blocks->inTag($existing, 'features_cannot_replace')) {
            return null;
        }
        // LavaSubmergedBlockProcessor: lava stays around blocks that are not full cubes.
        if ($blocks->name($existing) === 'lava' && !$blocks->flag($info[3], \PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks::FULL_CUBE)) {
            $info[3] = $c->state('lava');
        }
        if ($p['blackstone']) {
            $replacement = self::BLACKSTONE[$blocks->name($info[3])] ?? null;
            if ($replacement !== null) {
                $old = $blocks->properties($info[3]);
                $state = $c->state($replacement);
                foreach (['facing', 'half', 'type'] as $property) {
                    if (isset($old[$property]) && $blocks->has($state, $property)) {
                        $state = $blocks->with($state, $property, $old[$property]);
                    }
                }
                $info[3] = $state;
            }
        }
        return $info;
    }

    /** BlockAgeProcessor */
    private function age(StructureContext $c, array $info): array
    {
        $random = Template::positionalRandom($info[0], $info[1], $info[2]);
        $blocks = $c->blocks;
        $name = $blocks->name($info[3]);
        $mossiness = F32::r((float) $this->properties['mossiness']);
        $stairs = static fn (string $block): int => $c->state($block, ['facing' => Piece::HORIZONTAL[$random->nextInt(4)], 'half' => $random->nextInt(2) === 0 ? 'top' : 'bottom']);
        $pick = static fn (array $plain, array $mossy): int => $random->nextFloat() < $mossiness ? $mossy[$random->nextInt(count($mossy))] : $plain[$random->nextInt(count($plain))];
        $new = null;
        if (in_array($name, ['stone_bricks', 'stone', 'chiseled_stone_bricks'], true)) {
            if ($random->nextFloat() < 0.5) {
                // Both replacement sets are built before choosing, each drawing a random stair.
                $plain = [$c->state('cracked_stone_bricks'), $stairs('stone_brick_stairs')];
                $mossy = [$c->state('mossy_stone_bricks'), $stairs('mossy_stone_brick_stairs')];
                $new = $pick($plain, $mossy);
            }
        } elseif ($blocks->inTag($info[3], 'stairs')) {
            if ($random->nextFloat() < 0.5) {
                $mossyStairs = $c->state('mossy_stone_brick_stairs');
                foreach ($blocks->properties($info[3]) as $property => $value) {
                    if ($blocks->has($mossyStairs, $property)) {
                        $mossyStairs = $blocks->with($mossyStairs, $property, $value);
                    }
                }
                $new = $pick([$c->state('stone_slab'), $c->state('stone_brick_slab')], [$mossyStairs, $c->state('mossy_stone_brick_slab')]);
            }
        } elseif ($blocks->inTag($info[3], 'slabs')) {
            if ($random->nextFloat() < $mossiness) {
                $new = $c->state('mossy_stone_brick_slab', ['type' => $blocks->properties($info[3])['type'] ?? 'bottom', 'waterlogged' => $blocks->properties($info[3])['waterlogged'] ?? 'false']);
            }
        } elseif ($blocks->inTag($info[3], 'walls')) {
            if ($random->nextFloat() < $mossiness) {
                $wall = $c->state('mossy_stone_brick_wall');
                foreach ($blocks->properties($info[3]) as $property => $value) {
                    $wall = $blocks->with($wall, $property, $value);
                }
                $new = $wall;
            }
        } elseif ($name === 'obsidian') {
            if ($random->nextFloat() < F32::r(0.15)) {
                $new = $c->state('crying_obsidian');
            }
        }
        if ($new !== null) {
            $info[3] = $new;
        }
        return $info;
    }

    private ?Region $region = null;

    public function postProcess(StructureContext $context, Region $region, RandomSource $random, array $chunkBox): void
    {
        $template = Template::load($context->blocks, $context->directory, $this->template);
        $box = $template->boundingBox($this->position, $this->mirror, $this->rotation, $this->pivot);
        // Only the chunk at the portal's centre places it, writing the whole box.
        $center = [$box[0] + intdiv($box[3] - $box[0] + 1, 2), $box[1] + intdiv($box[4] - $box[1] + 1, 2), $box[2] + intdiv($box[5] - $box[2] + 1, 2)];
        if (!Piece::inside($chunkBox, ...$center)) {
            return;
        }
        $whole = [min($chunkBox[0], $box[0]), min($chunkBox[1], $box[1]), min($chunkBox[2], $box[2]), max($chunkBox[3], $box[3]), max($chunkBox[4], $box[4]), max($chunkBox[5], $box[5])];
        $this->region = $region;
        parent::postProcess($context, $region, $random, $whole);
        $this->spreadNetherrack($context, $region, $random);
        $this->dripColumnsBelowPortal($context, $region, $random);
        if ($this->properties['vines'] || $this->properties['overgrown']) {
            for ($z = $this->box[2]; $z <= $this->box[5]; ++$z) {
                for ($y = $this->box[1]; $y <= $this->box[4]; ++$y) {
                    for ($x = $this->box[0]; $x <= $this->box[3]; ++$x) {
                        if ($this->properties['vines']) {
                            $this->maybeAddVines($context, $region, $random, $x, $y, $z);
                        }
                        if ($this->properties['overgrown']) {
                            $this->maybeAddLeavesAbove($context, $region, $random, $x, $y, $z);
                        }
                    }
                }
            }
        }
    }

    private function maybeAddVines(StructureContext $c, Region $r, RandomSource $random, int $x, int $y, int $z): void
    {
        $state = $r->get($x, $y, $z);
        if ($c->blocks->isAir($state) || $c->blocks->name($state) === 'vine') {
            return;
        }
        $direction = $random->nextInt(4);
        [$dx, $dz] = [[0, -1], [1, 0], [0, 1], [-1, 0]][$direction];
        if (!$c->blocks->isAir($r->get($x + $dx, $y, $z + $dz))) {
            return;
        }
        // Block.isFaceFull of the collision shape on that side.
        if ($c->blocks->sturdy($state, [2, 5, 3, 4][$direction])) {
            $r->set($x + $dx, $y, $z + $dz, $c->state('vine', [Piece::HORIZONTAL[($direction + 2) % 4] => 'true']));
        }
    }

    private function maybeAddLeavesAbove(StructureContext $c, Region $r, RandomSource $random, int $x, int $y, int $z): void
    {
        if ($random->nextFloat() < 0.5 && $c->blocks->name($r->get($x, $y, $z)) === 'netherrack' && $c->blocks->isAir($r->get($x, $y + 1, $z))) {
            $r->set($x, $y + 1, $z, $c->state('jungle_leaves', ['persistent' => 'true']));
        }
    }

    private function canBecomeNetherrack(StructureContext $c, int $state): bool
    {
        $name = $c->blocks->name($state);
        return $name !== 'air' && $name !== 'obsidian' && !$c->blocks->inTag($state, 'features_cannot_replace')
            && ($this->properties['placement'] === 'in_nether' || $name !== 'lava');
    }

    private function placeNetherrackOrMagma(StructureContext $c, Region $r, RandomSource $random, int $x, int $y, int $z): void
    {
        if ($this->canBecomeNetherrack($c, $r->get($x, $y, $z))) {
            $r->set($x, $y, $z, $c->state(!$this->properties['cold'] && $random->nextFloat() < F32::r(0.07) ? 'magma_block' : 'netherrack'));
        }
    }

    private function dripColumn(StructureContext $c, Region $r, RandomSource $random, int $x, int $y, int $z): void
    {
        $this->placeNetherrackOrMagma($c, $r, $random, $x, $y, $z);
        for ($cap = 8; $cap > 0 && $random->nextFloat() < 0.5; --$cap) {
            $this->placeNetherrackOrMagma($c, $r, $random, $x, --$y, $z);
        }
    }

    private function dripColumnsBelowPortal(StructureContext $c, Region $r, RandomSource $random): void
    {
        for ($x = $this->box[0] + 1; $x < $this->box[3]; ++$x) {
            for ($z = $this->box[2] + 1; $z < $this->box[5]; ++$z) {
                if ($c->blocks->name($r->get($x, $this->box[1], $z)) === 'netherrack') {
                    $this->dripColumn($c, $r, $random, $x, $this->box[1] - 1, $z);
                }
            }
        }
    }

    private function spreadNetherrack(StructureContext $c, Region $r, RandomSource $random): void
    {
        $followSurface = in_array($this->properties['placement'], ['on_land_surface', 'on_ocean_floor'], true);
        $box = $this->box;
        $centerX = $box[0] + intdiv($box[3] - $box[0] + 1, 2);
        $centerZ = $box[2] + intdiv($box[5] - $box[2] + 1, 2);
        $chances = [1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 0.9, 0.9, 0.8, 0.7, 0.6, 0.4, 0.2];
        $max = count($chances);
        $averageWidth = intdiv($box[3] - $box[0] + 1 + $box[5] - $box[2] + 1, 2);
        $adjustment = $random->nextInt(max(1, 8 - intdiv($averageWidth, 2)));
        for ($x = $centerX - $max; $x <= $centerX + $max; ++$x) {
            for ($z = $centerZ - $max; $z <= $centerZ + $max; ++$z) {
                $distance = max(0, abs($x - $centerX) + abs($z - $centerZ) + $adjustment);
                if ($distance >= $max || !($random->nextDouble() < F32::r($chances[$distance]))) {
                    continue;
                }
                $surface = $r->height($this->heightmap(), $x, $z) - 1;
                $y = $followSurface ? $surface : min($box[1], $surface);
                if (abs($y - $box[1]) <= 3 && $this->canBecomeNetherrack($c, $r->get($x, $y, $z))) {
                    $this->placeNetherrackOrMagma($c, $r, $random, $x, $y, $z);
                    if ($this->properties['overgrown']) {
                        $this->maybeAddLeavesAbove($c, $r, $random, $x, $y, $z);
                    }
                    $this->dripColumn($c, $r, $random, $x, $y - 1, $z);
                }
            }
        }
    }
}
