<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Collision;

/**
 * SpeleothemBlock and PointedDripstoneBlock: pointed dripstone and sulfur spikes point up or
 * down from a sturdy face or from more of themselves, thicken toward their base, fall when what
 * holds a stalactite goes and break when a stalagmite loses its footing. Under a fluid they grow
 * now and then, and pointed dripstone drips that fluid into a cauldron below or dries mud to clay.
 */
trait Speleothems
{
    private const SPELEOTHEMS = ['pointed_dripstone' => 'dripstone_block', 'sulfur_spike' => 'sulfur'];

    /** The vertical direction a speleothem state points, or null for any other block. */
    private function speleothemTip(int $state): ?string
    {
        $catalog = $this->version->catalog;
        return isset(self::SPELEOTHEMS[$catalog->blockName($state)]) ? $catalog->properties($state)['vertical_direction'] : null;
    }

    /** SpeleothemBlock.isValidSpeleothemPlacement: a sturdy face behind it, or more of the same block pointing the same way. */
    private function speleothemValid(string $name, int $x, int $y, int $z, string $tip): bool
    {
        $behind = $tip === 'up' ? $y - 1 : $y + 1;
        $state = $this->world->get($x, $behind, $z);
        return $this->fireBlocks()->sturdy($state, $tip === 'up' ? 1 : 0)
            || ($this->speleothemTip($state) === $tip && $this->version->catalog->blockName($state) === $name);
    }

    /** SpeleothemBlock.calculateSpeleothemThickness from the blocks in front of and behind it. */
    private function speleothemThickness(string $name, int $x, int $y, int $z, string $tip, bool $mergeOpposingTips): string
    {
        $catalog = $this->version->catalog;
        $base = $tip === 'up' ? 'down' : 'up';
        $front = $this->world->get($x, $tip === 'up' ? $y + 1 : $y - 1, $z);
        if ($this->speleothemTip($front) === $base && $catalog->blockName($front) === $name) {
            return !$mergeOpposingTips && $catalog->properties($front)['thickness'] !== 'tip_merge' ? 'tip' : 'tip_merge';
        }
        if ($this->speleothemTip($front) !== $tip) {
            return 'tip';
        }
        $frontThickness = $catalog->properties($front)['thickness'];
        if ($frontThickness === 'tip' || $frontThickness === 'tip_merge') {
            return 'frustum';
        }
        return $this->speleothemTip($this->world->get($x, $tip === 'up' ? $y - 1 : $y + 1, $z)) !== $tip ? 'base' : 'middle';
    }

    /**
     * SpeleothemBlock.getStateForPlacement: pointing away from where the player looks vertically,
     * or the other way when only that holds; sneaking keeps it from merging with a tip it meets.
     */
    private function speleothemPlacement(Client $c, string $name, array $pos): ?array
    {
        [$x, $y, $z] = $pos;
        // BlockPlaceContext.getNearestLookingVerticalDirection, opposite.
        $tip = $c->pitch < 0.0 ? 'down' : 'up';
        if (!$this->speleothemValid($name, $x, $y, $z, $tip)) {
            $tip = $tip === 'up' ? 'down' : 'up';
            if (!$this->speleothemValid($name, $x, $y, $z, $tip)) {
                return null;
            }
        }
        return [$name, ['thickness' => $this->speleothemThickness($name, $x, $y, $z, $tip, !$c->sneaking), 'vertical_direction' => $tip,
            'waterlogged' => $this->fireBlocks()->isWater($this->world->get($x, $y, $z)) ? 'true' : 'false']];
    }

    /**
     * SpeleothemBlock.updateShape for the speleothems above and below a changed block: losing
     * what holds it schedules its fall or break, otherwise its thickness follows its neighbours.
     */
    private function speleothemsChanged(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        foreach ([[1, 'down'], [-1, 'up']] as [$dy, $toward]) {
            $b = $y + $dy;
            $state = $this->world->get($x, $b, $z);
            $tip = $this->speleothemTip($state);
            if ($tip === null || ($tip === 'down' && isset($this->blockTicks['speleothem:' . $x . ':' . $b . ':' . $z]))) {
                continue;
            }
            $name = $catalog->blockName($state);
            $p = $catalog->properties($state);
            if ($toward === ($tip === 'up' ? 'down' : 'up') && !$this->speleothemValid($name, $x, $b, $z, $tip)) {
                $this->scheduleBlock('speleothem', $x, $b, $z, $tip === 'down' ? 2 : 1);
                continue;
            }
            $thickness = $this->speleothemThickness($name, $x, $b, $z, $tip, $p['thickness'] === 'tip_merge');
            if ($thickness !== $p['thickness']) {
                $this->setBlock($x, $b, $z, $catalog->state($name, ['thickness' => $thickness] + $p));
            }
        }
    }

    /** SpeleothemBlock.tick: an unsupported stalagmite breaks; a stalactite falls with everything hanging from it. */
    private function tickSpeleothem(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $tip = $this->speleothemTip($state);
        if ($tip === null) {
            return;
        }
        $name = $catalog->blockName($state);
        if ($tip === 'up') {
            if (!$this->speleothemValid($name, $x, $y, $z, 'up')) {
                // Level.destroyBlock with drops.
                $this->levelEvent(2001, [$x, $y, $z], $state);
                $this->dropBlockResources($x, $y, $z, $state);
                $this->setBlock($x, $y, $z, $catalog->state($catalog->properties($state)['waterlogged'] === 'true' ? 'water' : 'air'));
            }
            return;
        }
        // SpeleothemBlock.spawnFallingStalactite: the tip hurts by the stalactite's length, at least 6.
        for ($b = $y; $this->speleothemTip($state = $this->world->get($x, $b, $z)) === 'down'; --$b) {
            $thickness = $catalog->properties($state)['thickness'];
            $id = $this->startFalling($x, $b, $z, $state);
            if ($thickness === 'tip' || $thickness === 'tip_merge') {
                $this->fallingBlocks[$id]['hurt'] = [(float) max(1 + $y - $b, 6), 40];
                break;
            }
        }
    }

    /**
     * SpeleothemBlock.randomTick and PointedDripstoneBlock.maybeTransferFluid: the top of a
     * stalactite may pass the fluid above its support down to a cauldron, or dry mud to clay,
     * and may grow the stalactite or a stalagmite under it.
     */
    private function speleothemRandomTick(int $x, int $y, int $z, int $state): void
    {
        $name = $this->version->catalog->blockName($state);
        if ($name === 'pointed_dripstone') {
            $this->dripstoneTransferFluid($x, $y, $z, $state, self::randomUnit());
        }
        if (self::randomUnit() < 0.011377778 && $this->stalactiteStart($x, $y, $z, $state)) {
            $this->growSpeleothem($x, $y, $z, $state);
        }
    }

    /** SpeleothemBlock.isStalactiteStartPos: the top block of a stalactite. */
    private function stalactiteStart(int $x, int $y, int $z, int $state): bool
    {
        $catalog = $this->version->catalog;
        return $this->speleothemTip($state) === 'down' && $catalog->blockName($this->world->get($x, $y + 1, $z)) !== $catalog->blockName($state);
    }

    /** SpeleothemBlock.isTip. */
    private function speleothemIsTip(int $state, bool $merged): bool
    {
        if ($this->speleothemTip($state) === null) {
            return false;
        }
        $thickness = $this->version->catalog->properties($state)['thickness'];
        return $thickness === 'tip' || ($merged && $thickness === 'tip_merge');
    }

    /** SpeleothemBlock.findTip: the tip at most $max - 1 blocks along the speleothem, or null. */
    private function speleothemFindTip(int $x, int $y, int $z, int $state, int $max, bool $merged): ?int
    {
        if ($this->speleothemIsTip($state, $merged)) {
            return $y;
        }
        $catalog = $this->version->catalog;
        $tip = $this->speleothemTip($state);
        $step = $tip === 'up' ? 1 : -1;
        for ($i = 1, $b = $y + $step; $i < $max; ++$i, $b += $step) {
            $at = $this->world->get($x, $b, $z);
            if ($this->speleothemIsTip($at, $merged)) {
                return $b;
            }
            if ($this->outsideBuildHeight($b) || $catalog->blockName($at) !== $catalog->blockName($state) || $this->speleothemTip($at) !== $tip) {
                return null;
            }
        }
        return null;
    }

    private function outsideBuildHeight(int $y): bool
    {
        return $y < $this->world->minY || $y >= $this->world->minY + $this->world->height;
    }

    /** SpeleothemBlock.isFreeHangingStalactite: a dry stalactite tip. */
    private function freeHangingStalactite(int $state): bool
    {
        $p = $this->version->catalog->properties($state);
        return $this->speleothemTip($state) === 'down' && $p['thickness'] === 'tip' && $p['waterlogged'] === 'false';
    }

    /** SpeleothemBlock.canTipGrow: into air, or to meet an unmerged tip pointing back. */
    private function speleothemTipCanGrow(string $name, int $x, int $y, int $z, string $tip): bool
    {
        $at = $this->world->get($x, $tip === 'up' ? $y + 1 : $y - 1, $z);
        if ($this->fireBlocks()->hasFluid($at)) {
            return false;
        }
        return $this->fireBlocks()->isAir($at) || $this->unmergedTip($name, $at, $tip === 'up' ? 'down' : 'up');
    }

    private function unmergedTip(string $name, int $state, string $tip): bool
    {
        return $this->speleothemIsTip($state, false) && $this->speleothemTip($state) === $tip && $this->version->catalog->blockName($state) === $name;
    }

    /** SpeleothemBlock.growStalactiteOrStalagmiteIfPossible. */
    private function growSpeleothem(int $x, int $y, int $z, int $state): void
    {
        $catalog = $this->version->catalog;
        $name = $catalog->blockName($state);
        // canGrow: on its block, and for pointed dripstone under a water source above that.
        if ($catalog->blockName($this->world->get($x, $y + 1, $z)) !== self::SPELEOTHEMS[$name]) {
            return;
        }
        if ($name === 'pointed_dripstone') {
            $above = $this->world->get($x, $y + 2, $z);
            if (!$this->fireBlocks()->isWater($above) || !$this->fireBlocks()->isSource($above)) {
                return;
            }
        }
        $tipY = $this->speleothemFindTip($x, $y, $z, $state, $name === 'sulfur_spike' ? 2 : 7, false);
        if ($tipY === null) {
            return;
        }
        $tipState = $this->world->get($x, $tipY, $z);
        if (!$this->freeHangingStalactite($tipState) || !$this->speleothemTipCanGrow($name, $x, $tipY, $z, 'down')) {
            return;
        }
        if (random_int(0, 1) === 1) {
            $this->growSpeleothemTo($name, $x, $tipY - 1, $z, 'down');
            return;
        }
        // growStalagmiteBelow: the first footing within ten blocks, unless a fluid or, for dripstone, a block it cannot drip through comes first.
        for ($i = 0, $b = $tipY - 1; $i < 10; ++$i, --$b) {
            $at = $this->world->get($x, $b, $z);
            if ($this->fireBlocks()->hasFluid($at)) {
                return;
            }
            if ($this->unmergedTip($name, $at, 'up') && $this->speleothemTipCanGrow($name, $x, $b, $z, 'up')) {
                $this->growSpeleothemTo($name, $x, $b + 1, $z, 'up');
                return;
            }
            if ($this->speleothemValid($name, $x, $b, $z, 'up') && !$this->fireBlocks()->isWater($this->world->get($x, $b - 1, $z))) {
                $this->growSpeleothemTo($name, $x, $b, $z, 'up');
                return;
            }
            if ($name === 'pointed_dripstone' && !$this->dripsThrough($at)) {
                return;
            }
        }
    }

    /** SpeleothemBlock.grow: a new tip, or two merged tips where it meets one pointing back. */
    private function growSpeleothemTo(string $name, int $x, int $y, int $z, string $tip): void
    {
        $catalog = $this->version->catalog;
        $at = $this->world->get($x, $y, $z);
        $place = function (int $b, string $direction, string $thickness) use ($catalog, $name, $x, $z): void {
            $water = $this->fireBlocks()->isWater($this->world->get($x, $b, $z));
            $this->setBlock($x, $b, $z, $catalog->state($name, ['thickness' => $thickness, 'vertical_direction' => $direction, 'waterlogged' => $water ? 'true' : 'false']));
        };
        if ($this->unmergedTip($name, $at, $tip === 'up' ? 'down' : 'up')) {
            // createMergedTips: the stalactite half first.
            $down = $this->speleothemTip($at) === 'down' ? $y : $y + 1;
            $place($down, 'down', 'tip_merge');
            $place($down - 1, 'up', 'tip_merge');
        } elseif ($this->fireBlocks()->isAir($at) || $catalog->blockName($at) === 'water') {
            $place($y, $tip, 'tip');
        }
    }

    /** PointedDripstoneBlock.canDripThrough: air, or a non-opaque dry block clear down the middle. */
    private function dripsThrough(int $state): bool
    {
        $blocks = $this->fireBlocks();
        if ($blocks->isAir($state)) {
            return true;
        }
        if ($blocks->flag($state, \PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks::SOLID_RENDER) || $blocks->hasFluid($state)) {
            return false;
        }
        // REQUIRED_SPACE_TO_DRIP_THROUGH_NON_SOLID_BLOCK: the column 6 to 10 pixels across.
        foreach ((new Collision($this->version->catalog))->shape($state) as $box) {
            if ($box[0] < 0.625 && $box[3] > 0.375 && $box[1] < 1.0 && $box[4] > 0.0 && $box[2] < 0.625 && $box[5] > 0.375) {
                return false;
            }
        }
        return true;
    }

    /**
     * PointedDripstoneBlock.getFluidAboveStalactite: the fluid over the block a stalactite hangs
     * from, with mud counting as water outside the Nether. Null when there is no stalactite.
     *
     * @return array{0: string, 1: int, 2: int}|null fluid (water, lava or empty), its y and the state there
     */
    private function fluidAboveStalactite(int $x, int $y, int $z, int $state): ?array
    {
        if ($this->speleothemTip($state) !== 'down') {
            return null;
        }
        $catalog = $this->version->catalog;
        $name = $catalog->blockName($state);
        // findRootBlock: the first block above that is not this stalactite.
        $root = null;
        for ($i = 1, $b = $y + 1; $i < 11; ++$i, ++$b) {
            $at = $this->world->get($x, $b, $z);
            if ($catalog->blockName($at) !== $name) {
                $root = $b;
                break;
            }
            if ($this->outsideBuildHeight($b) || $this->speleothemTip($at) !== 'down') {
                return null;
            }
        }
        if ($root === null) {
            return null;
        }
        $above = $this->world->get($x, $root + 1, $z);
        $fluid = match (true) {
            $catalog->blockName($above) === 'mud' && $this->world->dimension !== 'the_nether' => 'water',
            $this->fireBlocks()->isWater($above) => 'water',
            $this->fireBlocks()->isLava($above) => 'lava',
            default => 'empty',
        };
        return [$fluid, $root + 1, $above];
    }

    /** AbstractCauldronBlock.canReceiveStalactiteDrip: an empty cauldron takes either, a water cauldron water. */
    private function cauldronTakesDrip(int $state, string $fluid): bool
    {
        $name = $this->version->catalog->blockName($state);
        return $name === 'cauldron' || ($name === 'water_cauldron' && $fluid === 'water');
    }

    private function dripstoneTransferFluid(int $x, int $y, int $z, int $state, float $random): void
    {
        if ($random > 0.17578125 || !$this->stalactiteStart($x, $y, $z, $state)) {
            return;
        }
        $info = $this->fluidAboveStalactite($x, $y, $z, $state);
        if ($info === null || $info[0] === 'empty' || $random >= ($info[0] === 'water' ? 0.17578125 : 0.05859375)) {
            return;
        }
        [$fluid, $sourceY, $source] = $info;
        $tipY = $this->speleothemFindTip($x, $y, $z, $state, 11, false);
        if ($tipY === null) {
            return;
        }
        $catalog = $this->version->catalog;
        if ($catalog->blockName($source) === 'mud' && $fluid === 'water') {
            $this->setBlock($x, $sourceY, $z, $catalog->state('clay'));
            $this->levelEvent(1504, [$x, $tipY, $z], 0);
            return;
        }
        // findFillableCauldronBelowStalactiteTip.
        for ($i = 1, $b = $tipY - 1; $i < 11; ++$i, --$b) {
            $at = $this->world->get($x, $b, $z);
            if ($this->cauldronTakesDrip($at, $fluid)) {
                $this->levelEvent(1504, [$x, $tipY, $z], 0);
                $this->scheduleBlock('cauldron', $x, $b, $z, 50 + $tipY - $b);
                return;
            }
            if ($this->outsideBuildHeight($b) || !$this->dripsThrough($at)) {
                return;
            }
        }
    }

    /** AbstractCauldronBlock.tick: a dry stalactite tip above with a fluid over its support fills the cauldron a step. */
    private function tickCauldron(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $name = $catalog->blockName($state);
        if (!in_array($name, ['cauldron', 'water_cauldron', 'lava_cauldron', 'powder_snow_cauldron'], true)) {
            return;
        }
        // findStalactiteTipAboveCauldron.
        $tipY = null;
        for ($i = 1, $b = $y + 1; $i < 11; ++$i, ++$b) {
            $at = $this->world->get($x, $b, $z);
            if ($this->freeHangingStalactite($at)) {
                $tipY = $b;
                break;
            }
            if ($this->outsideBuildHeight($b) || !$this->dripsThrough($at)) {
                return;
            }
        }
        if ($tipY === null) {
            return;
        }
        $fluid = $this->fluidAboveStalactite($x, $tipY, $z, $this->world->get($x, $tipY, $z))[0] ?? 'empty';
        if ($fluid === 'empty' || !$this->cauldronTakesDrip($state, $fluid)) {
            return;
        }
        if ($name === 'cauldron') {
            $this->setBlock($x, $y, $z, $catalog->state($fluid === 'water' ? 'water_cauldron' : 'lava_cauldron', $fluid === 'water' ? ['level' => '1'] : []));
            $this->levelEvent($fluid === 'water' ? 1047 : 1046, [$x, $y, $z], 0);
        } elseif ((int) $catalog->properties($state)['level'] < 3) {
            $this->setBlock($x, $y, $z, $catalog->state($name, ['level' => (string) ((int) $catalog->properties($state)['level'] + 1)]));
            $this->levelEvent(1047, [$x, $y, $z], 0);
        }
    }
}
