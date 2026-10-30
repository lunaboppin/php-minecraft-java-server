<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;

/**
 * FlowingFluid, WaterFluid and LavaFluid: how water and lava spread, drain, form sources and meet
 * each other. A fluid is null (empty) or [family, amount, falling, source]; the amount is 8 for
 * sources and falling fluid and drops by the family's drop-off with each step sideways.
 */
final class Fluids
{
    /** Direction.Plane.HORIZONTAL: north, east, south, west. */
    private const HORIZONTAL = [[0, 0, -1], [1, 0, 0], [0, 0, 1], [-1, 0, 0]];

    /** The horizontal directions in Direction order (north, south, west, east), as an EnumMap iterates. */
    private const ENUM_ORDER = [0, 2, 3, 1];

    /** LiquidBlock.POSSIBLE_FLOW_DIRECTIONS: down, south, north, east, west. */
    private const FLOW_DIRECTIONS = [[0, -1, 0], [0, 0, 1], [0, 0, -1], [1, 0, 0], [-1, 0, 0]];

    /** Blocks that hold water without taking more (LiquidBlockContainer that refuses every fluid). */
    private const SEALED = ['kelp', 'kelp_plant', 'seagrass', 'tall_seagrass'];

    /** @var array<string, bool> collision shape is the full block */
    private array $full = [];

    public function __construct(private readonly Blocks $blocks, private readonly Collision $collision)
    {
    }

    /** @return ?array{0: string, 1: int, 2: bool, 3: bool} */
    public function fluid(int $state): ?array
    {
        $name = $this->blocks->name($state);
        if ($name === 'water' || $name === 'lava') {
            // LiquidBlock.getFluidState: level 0 is the source, 1-7 flowing, 8 and above falling.
            $level = min((int) ($this->blocks->properties($state)['level'] ?? 0), 8);
            return $level === 0 ? [$name, 8, false, true] : ($level === 8 ? [$name, 8, true, false] : [$name, 8 - $level, false, false]);
        }
        if (($this->blocks->properties($state)['waterlogged'] ?? 'false') === 'true' || in_array($name, ['bubble_column', ...self::SEALED], true)) {
            return ['water', 8, false, true];
        }
        return null;
    }

    /** Fluid.getTickDelay */
    public function tickDelay(string $family, FluidLevel $level): int
    {
        return $family === 'water' ? 5 : ($level->fastLava() ? 10 : 30);
    }

    /** The scheduled tick type of a fluid: its source or flowing variant. */
    public static function tickType(array $fluid): string
    {
        return $fluid[3] ? $fluid[0] : 'flowing_' . $fluid[0];
    }

    /**
     * LiquidBlock.shouldSpreadLiquid: lava next to water hardens into obsidian or cobblestone, and
     * lava over soul soil next to blue ice into basalt. False when the lava turned into a block.
     */
    public function shouldSpread(FluidLevel $level, int $x, int $y, int $z): bool
    {
        $fluid = $this->fluid($level->block($x, $y, $z));
        if ($fluid === null || $fluid[0] !== 'lava' || $this->blocks->name($level->block($x, $y, $z)) !== 'lava') {
            return true;
        }
        $soulSoil = $this->blocks->name($level->block($x, $y - 1, $z)) === 'soul_soil';
        foreach (self::FLOW_DIRECTIONS as [$dx, $dy, $dz]) {
            // The neighbour is in the direction's opposite.
            [$nx, $ny, $nz] = [$x - $dx, $y - $dy, $z - $dz];
            if (($this->fluid($level->block($nx, $ny, $nz))[0] ?? null) === 'water') {
                $level->setBlockAndUpdate($x, $y, $z, $this->blocks->state($fluid[3] ? 'obsidian' : 'cobblestone'));
                $level->levelEvent(1501, $x, $y, $z, 0);
                return false;
            }
            if ($soulSoil && $this->blocks->name($level->block($nx, $ny, $nz)) === 'blue_ice') {
                $level->setBlockAndUpdate($x, $y, $z, $this->blocks->state('basalt'));
                $level->levelEvent(1501, $x, $y, $z, 0);
                return false;
            }
        }
        return true;
    }

    /** BubbleColumnBlock.canOccupy: a bubble column, or a full water source block. */
    public function canHoldColumn(int $state): bool
    {
        $name = $this->blocks->name($state);
        return $name === 'bubble_column' || $name === 'water' && $this->blocks->properties($state)['level'] === '0';
    }

    /** Whether the block below a water source raises a bubble column (soul sand pushes up, magma drags down). */
    public function raisesColumn(int $below): bool
    {
        return $this->blocks->inTag($below, 'enables_bubble_column_push_up') || $this->blocks->inTag($below, 'enables_bubble_column_drag_down');
    }

    /** BubbleColumnBlock.canSurvive */
    public function columnSurvives(int $below): bool
    {
        return $this->blocks->name($below) === 'bubble_column' || $this->raisesColumn($below);
    }

    /**
     * BubbleColumnBlock.updateColumn: the block and the water above it become the column the block
     * below asks for, or plain water where nothing below raises one.
     */
    public function updateColumn(FluidLevel $level, int $x, int $y, int $z): void
    {
        $occupy = $level->block($x, $y, $z);
        if (!$this->canHoldColumn($occupy)) {
            return;
        }
        $below = $level->block($x, $y - 1, $z);
        if ($this->blocks->name($below) === 'bubble_column') {
            $column = $below;
        } elseif ($this->blocks->inTag($below, 'enables_bubble_column_push_up')) {
            $column = $this->blocks->state('bubble_column', ['drag' => 'false']);
        } elseif ($this->blocks->inTag($below, 'enables_bubble_column_drag_down')) {
            $column = $this->blocks->state('bubble_column', ['drag' => 'true']);
        } else {
            $column = $this->blocks->name($occupy) === 'bubble_column' ? $this->blocks->state('water') : $occupy;
        }
        $level->setBlockAndUpdate($x, $y, $z, $column);
        for (++$y; $this->canHoldColumn($level->block($x, $y, $z)); ++$y) {
            $level->setBlockAndUpdate($x, $y, $z, $column);
        }
    }

    /** FlowingFluid.tick */
    public function tick(FluidLevel $level, int $x, int $y, int $z): void
    {
        $state = $level->block($x, $y, $z);
        $fluid = $this->fluid($state);
        if ($fluid === null) {
            return;
        }
        $family = $fluid[0];
        if (!$fluid[3]) {
            $new = $this->newLiquid($level, $family, $x, $y, $z, $state);
            $delay = $this->spreadDelay($level, $family, $x, $y, $z, $fluid, $new);
            if ($new === null) {
                $fluid = null;
                $state = $this->blocks->state('air');
                $level->setBlockAndUpdate($x, $y, $z, $state);
            } elseif ($new !== $fluid) {
                $fluid = $new;
                $state = $this->legacyBlock($new);
                $level->setBlockAndUpdate($x, $y, $z, $state);
                $level->scheduleFluidTick(self::tickType($new), $x, $y, $z, $delay);
            }
        }
        if ($fluid !== null) {
            $this->spread($level, $family, $x, $y, $z, $state, $fluid);
        }
    }

    /** LavaFluid.getSpreadDelay: lava rising in level waits four times as long three times in four. */
    private function spreadDelay(FluidLevel $level, string $family, int $x, int $y, int $z, array $old, ?array $new): int
    {
        $delay = $this->tickDelay($family, $level);
        if ($family === 'lava' && $new !== null && !$old[2] && !$new[2] && $this->height($level, $new, $x, $y, $z) > $this->height($level, $old, $x, $y, $z) && $level->randomInt(4) !== 0) {
            $delay *= 4;
        }
        return $delay;
    }

    /** FlowingFluid.getHeight: full when the same fluid is above, else its own height. */
    private function height(FluidLevel $level, array $fluid, int $x, int $y, int $z): float
    {
        return ($this->fluid($level->block($x, $y + 1, $z))[0] ?? null) === $fluid[0] ? 1.0 : $fluid[1] / 9.0;
    }

    private function dropOff(string $family, FluidLevel $level): int
    {
        return $family === 'water' || $level->fastLava() ? 1 : 2;
    }

    private function slopeFindDistance(string $family, FluidLevel $level): int
    {
        return $family === 'water' || $level->fastLava() ? 4 : 2;
    }

    /** FlowingFluid.createLegacyBlock (getLegacyLevel) */
    public function legacyBlock(array $fluid): int
    {
        $level = $fluid[3] ? 0 : 8 - min($fluid[1], 8) + ($fluid[2] ? 8 : 0);
        return $this->blocks->state($fluid[0], ['level' => (string) $level]);
    }

    /** FlowingFluid.spread */
    private function spread(FluidLevel $level, string $family, int $x, int $y, int $z, int $state, array $fluid): void
    {
        $below = $level->block($x, $y - 1, $z);
        $belowFluid = $this->fluid($below);
        if ($this->canMaybePassThrough($family, $state, [0, -1, 0], $below, $belowFluid)) {
            $new = $this->newLiquid($level, $family, $x, $y - 1, $z, $below);
            if ($new !== null && $this->canBeReplacedWith($level, $belowFluid, $x, $y - 1, $z, $new, [0, -1, 0]) && $this->canHoldSpecific($below, $new)) {
                $this->spreadTo($level, $family, $x, $y - 1, $z, $below, [0, -1, 0], $new);
                if ($this->sourceNeighbours($level, $family, $x, $y, $z) >= 3) {
                    $this->spreadToSides($level, $family, $x, $y, $z, $fluid, $state);
                }
                return;
            }
        }
        if ($fluid[3] || !$this->isHole($family, $state, $below)) {
            $this->spreadToSides($level, $family, $x, $y, $z, $fluid, $state);
        }
    }

    private function spreadToSides(FluidLevel $level, string $family, int $x, int $y, int $z, array $fluid, int $state): void
    {
        $neighbour = $fluid[2] ? 7 : $fluid[1] - $this->dropOff($family, $level);
        if ($neighbour <= 0) {
            return;
        }
        foreach ($this->spreads($level, $family, $x, $y, $z, $state) as $index => $new) {
            [$dx, , $dz] = self::HORIZONTAL[$index];
            $this->spreadTo($level, $family, $x + $dx, $y, $z + $dz, $level->block($x + $dx, $y, $z + $dz), self::HORIZONTAL[$index], $new);
        }
    }

    /** FlowingFluid.getNewLiquid */
    private function newLiquid(FluidLevel $level, string $family, int $x, int $y, int $z, int $state): ?array
    {
        $highest = 0;
        $sources = 0;
        foreach (self::HORIZONTAL as [$dx, $dy, $dz]) {
            $neighbour = $level->block($x + $dx, $y, $z + $dz);
            $fluid = $this->fluid($neighbour);
            if (($fluid[0] ?? null) === $family && $this->canPassThroughWall([$dx, 0, $dz], $state, $neighbour)) {
                if ($fluid[3]) {
                    ++$sources;
                }
                $highest = max($highest, $fluid[1]);
            }
        }
        if ($sources >= 2 && $level->gameRule($family === 'water' ? 'water_source_conversion' : 'lava_source_conversion')) {
            $below = $level->block($x, $y - 1, $z);
            $belowFluid = $this->fluid($below);
            if ($this->blocks->isSolid($below) || (($belowFluid[0] ?? null) === $family && $belowFluid[3])) {
                return [$family, 8, false, true];
            }
        }
        $above = $level->block($x, $y + 1, $z);
        if (($this->fluid($above)[0] ?? null) === $family && $this->canPassThroughWall([0, 1, 0], $state, $above)) {
            return [$family, 8, true, false];
        }
        $amount = $highest - $this->dropOff($family, $level);
        return $amount <= 0 ? null : [$family, $amount, false, false];
    }

    /** FlowingFluid.getSpread: the sideways spreads towards the nearest way down, by direction index. */
    private function spreads(FluidLevel $level, string $family, int $x, int $y, int $z, int $state): array
    {
        $lowest = 1000;
        $result = [];
        $holes = [];
        $states = [];
        foreach (self::HORIZONTAL as $index => [$dx, $dy, $dz]) {
            $test = $level->block($x + $dx, $y, $z + $dz);
            $testFluid = $this->fluid($test);
            if (!$this->canMaybePassThrough($family, $state, [$dx, 0, $dz], $test, $testFluid)) {
                continue;
            }
            $new = $this->newLiquid($level, $family, $x + $dx, $y, $z + $dz, $test);
            if ($new === null || !$this->canHoldSpecific($test, $new)) {
                continue;
            }
            $distance = $this->hole($level, $family, $x + $dx, $y, $z + $dz, $holes, $states) ? 0
                : $this->slopeDistance($level, $family, $x + $dx, $y, $z + $dz, 1, [-$dx, 0, -$dz], $test, $holes, $states);
            if ($distance < $lowest) {
                $result = [];
            }
            if ($distance <= $lowest) {
                if ($this->canBeReplacedWith($level, $testFluid, $x + $dx, $y, $z + $dz, $new, [$dx, 0, $dz])) {
                    $result[$index] = $new;
                }
                $lowest = $distance;
            }
        }
        // EnumMap iteration: north, south, west, east.
        $ordered = [];
        foreach (self::ENUM_ORDER as $index) {
            if (isset($result[$index])) {
                $ordered[$index] = $result[$index];
            }
        }
        return $ordered;
    }

    /** FlowingFluid.getSlopeDistance */
    private function slopeDistance(FluidLevel $level, string $family, int $x, int $y, int $z, int $pass, array $from, int $state, array &$holes, array &$states): int
    {
        $lowest = 1000;
        foreach (self::HORIZONTAL as [$dx, $dy, $dz]) {
            if ([$dx, 0, $dz] === $from) {
                continue;
            }
            $key = ($x + $dx) . ',' . ($z + $dz);
            $test = $states[$key] ??= $level->block($x + $dx, $y, $z + $dz);
            $testFluid = $this->fluid($test);
            if (!$this->canMaybePassThrough($family, $state, [$dx, 0, $dz], $test, $testFluid) || !$this->canHoldSpecific($test, [$family, 1, false, false])) {
                continue;
            }
            if ($this->hole($level, $family, $x + $dx, $y, $z + $dz, $holes, $states)) {
                return $pass;
            }
            if ($pass < $this->slopeFindDistance($family, $level)) {
                $lowest = min($lowest, $this->slopeDistance($level, $family, $x + $dx, $y, $z + $dz, $pass + 1, [-$dx, 0, -$dz], $test, $holes, $states));
            }
        }
        return $lowest;
    }

    /** SpreadContext.isHole, cached by column. */
    private function hole(FluidLevel $level, string $family, int $x, int $y, int $z, array &$holes, array &$states): bool
    {
        $key = "$x,$z";
        if (!isset($holes[$key])) {
            $state = $states[$key] ??= $level->block($x, $y, $z);
            $holes[$key] = $this->isHole($family, $state, $level->block($x, $y - 1, $z));
        }
        return $holes[$key];
    }

    /** FlowingFluid.isWaterHole */
    private function isHole(string $family, int $top, int $bottom): bool
    {
        if (!$this->canPassThroughWall([0, -1, 0], $top, $bottom)) {
            return false;
        }
        return ($this->fluid($bottom)[0] ?? null) === $family || ($this->canHoldAny($bottom) && $this->canHoldSpecific($bottom, [$family, 1, false, false]));
    }

    private function sourceNeighbours(FluidLevel $level, string $family, int $x, int $y, int $z): int
    {
        $count = 0;
        foreach (self::HORIZONTAL as [$dx, $dy, $dz]) {
            $fluid = $this->fluid($level->block($x + $dx, $y, $z + $dz));
            if (($fluid[0] ?? null) === $family && $fluid[3]) {
                ++$count;
            }
        }
        return $count;
    }

    private function canMaybePassThrough(string $family, int $source, array $direction, int $test, ?array $testFluid): bool
    {
        return !(($testFluid[0] ?? null) === $family && $testFluid[3]) && $this->canHoldAny($test) && $this->canPassThroughWall($direction, $source, $test);
    }

    /** FlowingFluid.canHoldAnyFluid: liquid containers, and blocks fluids wash away (air among them). */
    private function canHoldAny(int $state): bool
    {
        return $this->container($state) || $this->blocks->inTag($state, 'washed_away_by_fluids');
    }

    /** LiquidBlockContainer.canPlaceLiquid: waterloggable blocks take only water sources. */
    private function canHoldSpecific(int $state, array $fluid): bool
    {
        if (!$this->container($state)) {
            return true;
        }
        return !in_array($this->blocks->name($state), self::SEALED, true) && $fluid[0] === 'water' && $fluid[3];
    }

    private function container(int $state): bool
    {
        return $this->blocks->has($state, 'waterlogged') || in_array($this->blocks->name($state), self::SEALED, true);
    }

    /** Fluid.canBeReplacedWith for the fluid already there. */
    private function canBeReplacedWith(FluidLevel $level, ?array $existing, int $x, int $y, int $z, array $new, array $direction): bool
    {
        if ($existing === null) {
            return true;
        }
        if ($existing[0] === 'water') {
            return $direction === [0, -1, 0] && $new[0] !== 'water';
        }
        return $this->height($level, $existing, $x, $y, $z) >= 0.44444445 && $new[0] === 'water';
    }

    /** FlowingFluid.spreadTo, with LavaFluid's lava falling into water turning it to stone. */
    private function spreadTo(FluidLevel $level, string $family, int $x, int $y, int $z, int $state, array $direction, array $fluid): void
    {
        if ($family === 'lava' && $direction === [0, -1, 0] && ($this->fluid($state)[0] ?? null) === 'water') {
            if (in_array($this->blocks->name($state), ['water', 'lava'], true)) {
                $level->setBlockAndUpdate($x, $y, $z, $this->blocks->state('stone'));
            }
            $level->levelEvent(1501, $x, $y, $z, 0);
            return;
        }
        if ($this->container($state)) {
            // SimpleWaterloggedBlock.placeLiquid
            if (!in_array($this->blocks->name($state), self::SEALED, true) && $this->blocks->properties($state)['waterlogged'] === 'false' && $fluid[0] === 'water' && $fluid[3]) {
                $level->setBlockAndUpdate($x, $y, $z, $this->blocks->with($state, 'waterlogged', 'true'));
                $level->scheduleFluidTick('water', $x, $y, $z, 5);
            }
            return;
        }
        if (!$this->blocks->isAir($state)) {
            if ($family === 'water') {
                $level->dropResources($x, $y, $z, $state);
            } else {
                $level->levelEvent(1501, $x, $y, $z, 0);
            }
        }
        $level->setBlockAndUpdate($x, $y, $z, $this->legacyBlock($fluid));
    }

    /**
     * FlowingFluid.canPassThroughWall: no full collision block on either side, and the two
     * collision shapes' touching faces do not together cover the face (Shapes.mergedFaceOccludes).
     */
    private function canPassThroughWall(array $direction, int $source, int $target): bool
    {
        if ($this->fullShape($target) || $this->fullShape($source)) {
            return false;
        }
        $sourceBoxes = $this->collision->shape($source);
        $targetBoxes = $this->collision->shape($target);
        if ($sourceBoxes === [] && $targetBoxes === []) {
            return true;
        }
        $rectangles = [...$this->face($sourceBoxes, $direction, true), ...$this->face($targetBoxes, $direction, false)];
        return !self::covers($rectangles);
    }

    private function fullShape(int $state): bool
    {
        return $this->full[$state] ??= $this->collision->shape($state) === [[0.0, 0.0, 0.0, 1.0, 1.0, 1.0]];
    }

    /**
     * VoxelShape.getFaceShape: the rectangles of a shape on the face towards the direction (the
     * source's own side), or on the face away from it (the target's side), in the face's two axes.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    private function face(array $boxes, array $direction, bool $towards): array
    {
        $axis = $direction[0] !== 0 ? 0 : ($direction[1] !== 0 ? 1 : 2);
        $positive = ($direction[$axis] > 0) === $towards;
        [$u, $v] = array_values(array_diff([0, 1, 2], [$axis]));
        $out = [];
        foreach ($boxes as $box) {
            $touches = $positive ? $box[$axis + 3] >= 1.0 - 1.0E-7 : $box[$axis] <= 1.0E-7;
            if ($touches) {
                $out[] = [$box[$u], $box[$v], $box[$u + 3], $box[$v + 3]];
            }
        }
        return $out;
    }

    /** Whether rectangles together cover the unit square. */
    private static function covers(array $rectangles): bool
    {
        if ($rectangles === []) {
            return false;
        }
        $us = [0.0, 1.0];
        $vs = [0.0, 1.0];
        foreach ($rectangles as [$u0, $v0, $u1, $v1]) {
            array_push($us, $u0, $u1);
            array_push($vs, $v0, $v1);
        }
        $us = array_values(array_unique(array_filter($us, static fn (float $u): bool => $u >= 0.0 && $u <= 1.0)));
        $vs = array_values(array_unique(array_filter($vs, static fn (float $v): bool => $v >= 0.0 && $v <= 1.0)));
        sort($us);
        sort($vs);
        for ($i = 0; $i + 1 < count($us); ++$i) {
            for ($j = 0; $j + 1 < count($vs); ++$j) {
                [$mu, $mv] = [($us[$i] + $us[$i + 1]) / 2, ($vs[$j] + $vs[$j + 1]) / 2];
                $covered = false;
                foreach ($rectangles as [$u0, $v0, $u1, $v1]) {
                    if ($mu > $u0 && $mu < $u1 && $mv > $v0 && $mv < $v1) {
                        $covered = true;
                        break;
                    }
                }
                if (!$covered) {
                    return false;
                }
            }
        }
        return true;
    }
}
