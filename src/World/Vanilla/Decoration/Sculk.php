<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Vanilla\F32;

/**
 * SculkPatchFeature with the world generation SculkSpreader: charge cursors that turn stone
 * into sculk, spread sculk veins over the faces they pass and grow sensors and shriekers.
 */
final class Sculk
{
    private const FACES = ['down', 'up', 'north', 'south', 'west', 'east'];

    private const REPLACEABLE = 'sculk_replaceable_world_gen';

    private const GROWTH_COST = 50;

    private const NO_GROWTH_RADIUS = 1;

    private const CHARGE_DECAY_RATE = 5;

    private const ADDITIONAL_DECAY_RATE = 10;

    /** ChargeCursor.NON_CORNER_NEIGHBOURS in BlockPos.betweenClosed order (x, then y, then z). */
    private const NEIGHBOURS = [
        [0, -1, -1], [-1, 0, -1], [0, 0, -1], [1, 0, -1], [0, 1, -1],
        [-1, -1, 0], [0, -1, 0], [1, -1, 0], [-1, 0, 0], [1, 0, 0], [-1, 1, 0], [0, 1, 0], [1, 1, 0],
        [0, -1, 1], [-1, 0, 1], [0, 0, 1], [1, 0, 1], [0, 1, 1],
    ];

    private const SAME_POSITION = 0;

    private const SAME_PLANE = 1;

    private const WRAP_AROUND = 2;

    private readonly Blocks $blocks;

    private readonly int $vein;

    private readonly int $sculk;

    public function __construct(Decorator $decorator)
    {
        $this->blocks = $decorator->blocks;
        $vein = $this->blocks->state('sculk_vein');
        foreach (self::FACES as $face) {
            $vein = $this->blocks->with($vein, $face, 'false');
        }
        $this->vein = $this->blocks->with($vein, 'waterlogged', 'false');
        $this->sculk = $this->blocks->state('sculk');
    }

    public function patch(array $feature, Region $region, RandomSource $random, int $x, int $y, int $z): bool
    {
        if (!$this->canSpreadFrom($region, $x, $y, $z)) {
            return false;
        }
        $origin = [$x, $y, $z];
        $rounds = $feature['spread_rounds'] + $feature['growth_rounds'];
        for ($round = 0; $round < $rounds; ++$round) {
            $cursors = [];
            for ($i = 0; $i < $feature['charge_count']; ++$i) {
                for ($charge = $feature['amount_per_charge']; $charge > 0; $charge -= min($charge, 1000)) {
                    if (count($cursors) < 32) {
                        $cursors[] = ['pos' => $origin, 'charge' => min($charge, 1000), 'update' => 0, 'decay' => 1, 'facings' => null];
                    }
                }
            }
            $spreadVeins = $round < $feature['spread_rounds'];
            for ($i = 0; $i < $feature['spread_attempts'] && $cursors !== []; ++$i) {
                $kept = [];
                foreach ($cursors as $cursor) {
                    $this->update($cursor, $region, $origin, $random, $spreadVeins);
                    if ($cursor['charge'] > 0) {
                        $kept[] = $cursor;
                    }
                }
                $cursors = $kept;
            }
        }
        return true;
    }

    private function canSpreadFrom(Region $region, int $x, int $y, int $z): bool
    {
        $start = $region->get($x, $y, $z);
        if ($this->behaviour($start) !== null) {
            return true;
        }
        if (!$this->blocks->isAir($start) && !($this->named($start, 'water') && $this->blocks->isSource($start))) {
            return false;
        }
        foreach (Blocks::OFFSETS as [$dx, $dy, $dz]) {
            if ($this->blocks->flag($region->get($x + $dx, $y + $dy, $z + $dz), Blocks::FULL_CUBE)) {
                return true;
            }
        }
        return false;
    }

    private function named(int $state, string $name): bool
    {
        return $this->blocks->name($state) === $name;
    }

    /** The block's SculkBehaviour: sculk, sculk_vein or null for SculkBehaviour.DEFAULT. */
    private function behaviour(int $state): ?string
    {
        $name = $this->blocks->name($state);
        return $name === 'sculk' || $name === 'sculk_vein' ? $name : null;
    }

    private function hasFace(int $state, int $face): bool
    {
        return ($this->blocks->properties($state)[self::FACES[$face]] ?? null) === 'true';
    }

    private function get(Region $region, array $pos): int
    {
        return $region->get($pos[0], $pos[1], $pos[2]);
    }

    private function set(Region $region, array $pos, int $state): void
    {
        $region->set($pos[0], $pos[1], $pos[2], $state);
    }

    private static function relative(array $pos, int $direction): array
    {
        [$dx, $dy, $dz] = Blocks::OFFSETS[$direction];
        return [$pos[0] + $dx, $pos[1] + $dy, $pos[2] + $dz];
    }

    /** Util.shuffledCopy */
    private static function shuffled(array $list, RandomSource $random): array
    {
        for ($i = count($list); $i > 1; --$i) {
            $j = $random->nextInt($i);
            [$list[$i - 1], $list[$j]] = [$list[$j], $list[$i - 1]];
        }
        return $list;
    }

    /** ChargeCursor.update */
    private function update(array &$cursor, Region $region, array $origin, RandomSource $random, bool $spreadVeins): void
    {
        if ($cursor['update'] > 0) {
            --$cursor['update'];
            return;
        }
        $state = $this->get($region, $cursor['pos']);
        $behaviour = $this->behaviour($state);
        if ($spreadVeins && $this->attemptSpreadVein($behaviour, $region, $cursor['pos'], $state, $cursor['facings']) && $behaviour !== 'sculk') {
            $state = $this->get($region, $cursor['pos']);
            $behaviour = $this->behaviour($state);
        }
        $cursor['charge'] = $this->attemptUseCharge($behaviour, $cursor, $region, $origin, $random, $spreadVeins);
        if ($cursor['charge'] <= 0) {
            $this->onDischarged($behaviour, $region, $state, $cursor['pos']);
            return;
        }
        $transfer = $this->movementPos($region, $cursor['pos'], $random, $origin);
        if ($transfer === null) {
            $this->onDischarged($behaviour, $region, $state, $cursor['pos']);
            $cursor['charge'] = 0;
            return;
        }
        $this->onDischarged($behaviour, $region, $state, $cursor['pos']);
        $cursor['pos'] = $transfer;
        $state = $this->get($region, $transfer);
        if ($this->behaviour($state) !== null) {
            // MultifaceBlock.availableFaces: empty for sculk blocks.
            $faces = [];
            for ($face = 0; $face < 6; ++$face) {
                if ($this->hasFace($state, $face)) {
                    $faces[] = $face;
                }
            }
            $cursor['facings'] = $faces;
        }
        // The previous position's behaviour decides the delays.
        $cursor['decay'] = $behaviour === null ? max($cursor['decay'] - 1, 0) : 1;
        $cursor['update'] = 1;
    }

    private function attemptSpreadVein(?string $behaviour, Region $region, array $pos, int $state, ?array $facings): bool
    {
        if ($behaviour === null) {
            if ($facings === null) {
                return $this->spreadAll([self::SAME_POSITION], $region, $this->get($region, $pos), $pos) > 0;
            }
            if ($facings !== []) {
                if (!$this->blocks->isAir($state) && !$this->blocks->isWater($state)) {
                    return false;
                }
                return $this->regrow($region, $pos, $state, $facings);
            }
        }
        return $this->spreadAll([self::SAME_POSITION, self::SAME_PLANE, self::WRAP_AROUND], $region, $state, $pos) > 0;
    }

    private function attemptUseCharge(?string $behaviour, array $cursor, Region $region, array $origin, RandomSource $random, bool $spreadVeins): int
    {
        $charge = $cursor['charge'];
        if ($behaviour === null) {
            return $cursor['decay'] > 0 ? $charge : 0;
        }
        if ($behaviour === 'sculk_vein') {
            if ($spreadVeins && $this->attemptPlaceSculk($region, $cursor['pos'], $random)) {
                return $charge - 1;
            }
            return $random->nextInt(self::CHARGE_DECAY_RATE) === 0 ? (int) floor(F32::r($charge * 0.5)) : $charge;
        }
        // SculkBlock.attemptUseCharge
        if ($charge === 0 || $random->nextInt(self::CHARGE_DECAY_RATE) !== 0) {
            return $charge;
        }
        $pos = $cursor['pos'];
        $distanceSquared = ($pos[0] - $origin[0]) ** 2 + ($pos[1] - $origin[1]) ** 2 + ($pos[2] - $origin[2]) ** 2;
        $close = $distanceSquared < self::NO_GROWTH_RADIUS ** 2;
        if (!$close && $this->canPlaceGrowth($region, $pos)) {
            if ($random->nextInt(self::GROWTH_COST) < $charge) {
                $above = [$pos[0], $pos[1] + 1, $pos[2]];
                $growth = $random->nextInt(11) === 0
                    ? $this->blocks->with($this->blocks->state('sculk_shrieker'), 'can_summon', 'true')
                    : $this->blocks->state('sculk_sensor');
                if ($this->blocks->hasFluid($this->get($region, $above))) {
                    $growth = $this->blocks->with($growth, 'waterlogged', 'true');
                }
                $this->set($region, $above, $growth);
            }
            return max(0, $charge - self::GROWTH_COST);
        }
        if ($random->nextInt(self::ADDITIONAL_DECAY_RATE) !== 0) {
            return $charge;
        }
        if ($close) {
            return $charge - 1;
        }
        // SculkBlock.getDecayPenalty
        $outer = F32::r(F32::r(sqrt((float) $distanceSquared)) - self::NO_GROWTH_RADIUS);
        $factor = min(1.0, F32::r(F32::r($outer * $outer) / ((24 - self::NO_GROWTH_RADIUS) ** 2)));
        return $charge - max(1, (int) F32::r(F32::r($charge * $factor) * 0.5));
    }

    private function canPlaceGrowth(Region $region, array $pos): bool
    {
        $above = $region->get($pos[0], $pos[1] + 1, $pos[2]);
        if (!$this->blocks->isAir($above) && !$this->named($above, 'water')) {
            return false;
        }
        $found = 0;
        for ($dx = -4; $dx <= 4; ++$dx) {
            for ($dy = 0; $dy <= 2; ++$dy) {
                for ($dz = -4; $dz <= 4; ++$dz) {
                    if ($this->blocks->inTag($region->get($pos[0] + $dx, $pos[1] + $dy, $pos[2] + $dz), 'sculk_growth_inhibitors') && ++$found > 2) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /** SculkVeinBlock.onDischarged */
    private function onDischarged(?string $behaviour, Region $region, int $state, array $pos): void
    {
        if ($behaviour !== 'sculk_vein' || !$this->named($state, 'sculk_vein')) {
            return;
        }
        for ($face = 0; $face < 6; ++$face) {
            if ($this->hasFace($state, $face) && $this->named($this->get($region, self::relative($pos, $face)), 'sculk')) {
                $state = $this->blocks->with($state, self::FACES[$face], 'false');
            }
        }
        $any = false;
        for ($face = 0; $face < 6 && !$any; ++$face) {
            $any = $this->hasFace($state, $face);
        }
        if (!$any) {
            $state = $this->blocks->state($this->blocks->hasFluid($this->get($region, $pos)) ? 'water' : 'air');
        }
        $this->set($region, $pos, $state);
    }

    /** SculkVeinBlock.attemptPlaceSculk */
    private function attemptPlaceSculk(Region $region, array $pos, RandomSource $random): bool
    {
        $state = $this->get($region, $pos);
        foreach (self::shuffled([0, 1, 2, 3, 4, 5], $random) as $support) {
            if (!$this->hasFace($state, $support)) {
                continue;
            }
            $supportPos = self::relative($pos, $support);
            if (!$this->blocks->inTag($this->get($region, $supportPos), self::REPLACEABLE)) {
                continue;
            }
            $this->set($region, $supportPos, $this->sculk);
            $this->spreadAll([self::SAME_POSITION, self::SAME_PLANE, self::WRAP_AROUND], $region, $this->sculk, $supportPos);
            for ($direction = 0; $direction < 6; ++$direction) {
                if ($direction === ($support ^ 1)) {
                    continue;
                }
                $veinPos = self::relative($supportPos, $direction);
                $possible = $this->get($region, $veinPos);
                if ($this->named($possible, 'sculk_vein')) {
                    $this->onDischarged('sculk_vein', $region, $possible, $veinPos);
                }
            }
            return true;
        }
        return false;
    }

    /** SculkVeinBlock.regrow */
    private function regrow(Region $region, array $pos, int $existing, array $faces): bool
    {
        $state = $this->vein;
        $any = false;
        foreach ($faces as $face) {
            if ($this->canAttachTo($region, self::relative($pos, $face), $face)) {
                $state = $this->blocks->with($state, self::FACES[$face], 'true');
                $any = true;
            }
        }
        if (!$any) {
            return false;
        }
        if ($this->blocks->hasFluid($existing)) {
            $state = $this->blocks->with($state, 'waterlogged', 'true');
        }
        $this->set($region, $pos, $state);
        return true;
    }

    /** MultifaceBlock.canAttachTo: the neighbour's face toward the vein is full. */
    private function canAttachTo(Region $region, array $neighbour, int $direction): bool
    {
        return $this->blocks->sturdy($this->get($region, $neighbour), $direction ^ 1);
    }

    /** MultifaceSpreader.spreadAll with the sculk vein spreader configuration. */
    private function spreadAll(array $types, Region $region, int $state, array $pos): int
    {
        $count = 0;
        $otherSource = !$this->named($state, 'sculk_vein');
        for ($face = 0; $face < 6; ++$face) {
            if (!$otherSource && !$this->hasFace($state, $face)) {
                continue;
            }
            for ($direction = 0; $direction < 6; ++$direction) {
                if (($direction >> 1) === ($face >> 1)) {
                    continue;
                }
                if (!$otherSource && $this->hasFace($state, $direction)) {
                    continue;
                }
                foreach ($types as $type) {
                    [$target, $targetFace] = match ($type) {
                        self::SAME_POSITION => [$pos, $direction],
                        self::SAME_PLANE => [self::relative($pos, $direction), $face],
                        self::WRAP_AROUND => [self::relative(self::relative($pos, $direction), $face), $direction ^ 1],
                    };
                    if ($this->canSpreadInto($region, $pos, $target, $targetFace)) {
                        if ($this->placeVein($region, $target, $targetFace)) {
                            ++$count;
                        }
                        break;
                    }
                }
            }
        }
        return $count;
    }

    private function canSpreadInto(Region $region, array $source, array $target, int $face): bool
    {
        $existing = $this->get($region, $target);
        $against = $this->get($region, self::relative($target, $face));
        $againstName = $this->blocks->name($against);
        if ($againstName === 'sculk' || $againstName === 'sculk_catalyst' || $againstName === 'moving_piston') {
            return false;
        }
        if (abs($source[0] - $target[0]) + abs($source[1] - $target[1]) + abs($source[2] - $target[2]) === 2) {
            $neighbour = self::relative($source, $face ^ 1);
            if ($this->blocks->sturdy($this->get($region, $neighbour), $face)) {
                return false;
            }
        }
        if ($this->blocks->hasFluid($existing) && !$this->blocks->isWater($existing)) {
            return false;
        }
        if ($this->blocks->inTag($existing, 'fire')) {
            return false;
        }
        $replaceable = $this->blocks->replaceable($existing) || $this->blocks->isAir($existing) || $this->named($existing, 'sculk_vein')
            || ($this->named($existing, 'water') && $this->blocks->isSource($existing));
        return $replaceable && $this->validPlacement($region, $existing, $target, $face);
    }

    /** MultifaceBlock.isValidStateForPlacement */
    private function validPlacement(Region $region, int $existing, array $pos, int $face): bool
    {
        if ($this->named($existing, 'sculk_vein') && $this->hasFace($existing, $face)) {
            return false;
        }
        return $this->canAttachTo($region, self::relative($pos, $face), $face);
    }

    /** MultifaceSpreader.SpreadConfig.placeBlock */
    private function placeVein(Region $region, array $pos, int $face): bool
    {
        $old = $this->get($region, $pos);
        if (!$this->validPlacement($region, $old, $pos, $face)) {
            return false;
        }
        if ($this->named($old, 'sculk_vein')) {
            $state = $old;
        } elseif ($this->blocks->isWater($old) && $this->blocks->isSource($old)) {
            $state = $this->blocks->with($this->vein, 'waterlogged', 'true');
        } else {
            $state = $this->vein;
        }
        return $region->set($pos[0], $pos[1], $pos[2], $this->blocks->with($state, self::FACES[$face], 'true'));
    }

    /** ChargeCursor.getValidMovementPos */
    private function movementPos(Region $region, array $pos, RandomSource $random, array $origin): ?array
    {
        $found = $pos;
        foreach (self::shuffled(self::NEIGHBOURS, $random) as [$dx, $dy, $dz]) {
            $neighbour = [$pos[0] + $dx, $pos[1] + $dy, $pos[2] + $dz];
            if (($origin[0] - $neighbour[0]) ** 2 + ($origin[2] - $neighbour[2]) ** 2 > 144) {
                continue;
            }
            $state = $this->get($region, $neighbour);
            if ($this->behaviour($state) === null || !$this->unobstructed($region, $pos, [$dx, $dy, $dz])) {
                continue;
            }
            $found = $neighbour;
            if ($this->substrateAccess($region, $state, $neighbour)) {
                break;
            }
        }
        return $found === $pos ? null : $found;
    }

    private function substrateAccess(Region $region, int $state, array $pos): bool
    {
        if (!$this->named($state, 'sculk_vein')) {
            return false;
        }
        for ($face = 0; $face < 6; ++$face) {
            if ($this->hasFace($state, $face) && $this->blocks->inTag($this->get($region, self::relative($pos, $face)), 'sculk_replaceable')) {
                return true;
            }
        }
        return false;
    }

    /** ChargeCursor.isMovementUnobstructed */
    private function unobstructed(Region $region, array $from, array $delta): bool
    {
        [$dx, $dy, $dz] = $delta;
        if (abs($dx) + abs($dy) + abs($dz) === 1) {
            return true;
        }
        $x = $dx < 0 ? Blocks::WEST : Blocks::EAST;
        $y = $dy < 0 ? Blocks::DOWN : Blocks::UP;
        $z = $dz < 0 ? Blocks::NORTH : Blocks::SOUTH;
        [$first, $second] = $dx === 0 ? [$y, $z] : ($dy === 0 ? [$x, $z] : [$x, $y]);
        return $this->open($region, $from, $first) || $this->open($region, $from, $second);
    }

    private function open(Region $region, array $from, int $direction): bool
    {
        return !$this->blocks->sturdy($this->get($region, self::relative($from, $direction)), $direction ^ 1);
    }
}
