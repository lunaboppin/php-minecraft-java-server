<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;

/**
 * Level.getSignal, getDirectSignalTo and getBestNeighborSignal over each component's
 * getSignal and getDirectSignal, with RedStoneWireBlock's strength and connections, the
 * redstone torch's delay and burnout, DiodeBlock's repeaters and comparators, and lamps.
 * Conductors pass on only the strong (direct) signal they receive.
 */
trait Redstone
{
    /** @var array<string, array<string, int>> ComparatorBlockEntity output by dimension and "x:y:z" */
    private array $comparatorOutputs = [];

    /** @var array<string, list<array{string, int}>> RedstoneTorchBlock.RECENT_TOGGLES by dimension */
    private array $torchToggles = [];

    /** RedStoneWireBlock.shouldSignal: off while a wire measures the power around it. */
    private bool $wireSignals = true;

    private const RS_DIRECTIONS = [
        'down' => [0, -1, 0], 'up' => [0, 1, 0], 'north' => [0, 0, -1], 'south' => [0, 0, 1], 'west' => [-1, 0, 0], 'east' => [1, 0, 0],
    ];

    private const RS_OPPOSITE = ['down' => 'up', 'up' => 'down', 'north' => 'south', 'south' => 'north', 'west' => 'east', 'east' => 'west'];

    private const RS_HORIZONTAL = ['north', 'east', 'south', 'west'];

    private static function rsStep(array $pos, string $direction, int $distance = 1): array
    {
        [$dx, $dy, $dz] = self::RS_DIRECTIONS[$direction];
        return [$pos[0] + $dx * $distance, $pos[1] + $dy * $distance, $pos[2] + $dz * $distance];
    }

    private function rsConductor(int $state): bool
    {
        return $this->fireBlocks()->flag($state, Blocks::REDSTONE_CONDUCTOR);
    }

    private function rsSource(int $state): bool
    {
        return $this->fireBlocks()->flag($state, Blocks::SIGNAL_SOURCE);
    }

    /** FaceAttachedHorizontalDirectionalBlock.getConnectedDirection: from the block it hangs on toward it. */
    private static function attachedDirection(array $p): string
    {
        return match ($p['face'] ?? 'wall') {
            'floor' => 'up',
            'ceiling' => 'down',
            default => $p['facing'],
        };
    }

    /**
     * BlockState.getSignal of the block at $pos, as seen from the neighbour it reaches by going
     * opposite $direction ($direction points from that receiver to $pos).
     */
    private function emitSignal(array $pos, string $direction): int
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get(...$pos);
        $name = $catalog->blockName($state);
        if (!$this->rsSource($state)) {
            return 0;
        }
        $p = $catalog->properties($state);
        return match (true) {
            $name === 'redstone_wire' => $this->wireEmits($p, $direction),
            $name === 'redstone_torch' => $p['lit'] === 'true' && $direction !== 'up' ? 15 : 0,
            $name === 'redstone_wall_torch' => $p['lit'] === 'true' && $p['facing'] !== $direction ? 15 : 0,
            $name === 'repeater' => $p['powered'] === 'true' && $p['facing'] === $direction ? 15 : 0,
            $name === 'comparator' => $p['facing'] === $direction ? $this->comparatorOutputs[$this->world->dimension][implode(':', $pos)] ?? ($p['powered'] === 'true' ? 15 : 0) : 0,
            $name === 'observer' => $p['powered'] === 'true' && $p['facing'] === $direction ? 15 : 0,
            $name === 'redstone_block' => 15,
            $name === 'daylight_detector' => (int) $p['power'],
            $name === 'trapped_chest' => $this->trappedChestSignal($pos),
            $this->isPressurePlate($name) => $this->plateSignal($state),
            isset($p['power']) => (int) $p['power'],
            isset($p['powered']) => $p['powered'] === 'true' ? 15 : 0,
            default => 0,
        };
    }

    /** RedStoneWireBlock.getSignal: nothing upward, and sideways only where it leads. */
    private function wireEmits(array $p, string $direction): int
    {
        if (!$this->wireSignals || $direction === 'down') {
            return 0;
        }
        $power = (int) $p['power'];
        if ($power === 0) {
            return 0;
        }
        return $direction !== 'up' && $p[self::RS_OPPOSITE[$direction]] === 'none' ? 0 : $power;
    }

    /** BlockState.getDirectSignal: the strong signal into the receiver opposite $direction. */
    private function emitDirectSignal(array $pos, string $direction): int
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get(...$pos);
        if (!$this->rsSource($state)) {
            return 0;
        }
        $name = $catalog->blockName($state);
        $p = $catalog->properties($state);
        return match (true) {
            $name === 'redstone_wire' => $this->wireEmits($p, $direction),
            $name === 'redstone_torch', $name === 'redstone_wall_torch' => $direction === 'down' ? $this->emitSignal($pos, $direction) : 0,
            $name === 'repeater', $name === 'comparator', $name === 'observer' => $this->emitSignal($pos, $direction),
            $name === 'lever' || str_ends_with($name, '_button') => $p['powered'] === 'true' && self::attachedDirection($p) === $direction ? 15 : 0,
            $name === 'tripwire_hook', $this->lightningRod($name) => $p['powered'] === 'true' && $p['facing'] === $direction ? 15 : 0,
            $this->isPressurePlate($name), $name === 'trapped_chest' => $direction === 'up' ? $this->emitSignal($pos, $direction) : 0,
            $name === 'detector_rail' => $p['powered'] === 'true' && $direction === 'up' ? 15 : 0,
            default => 0,
        };
    }

    /** Level.getDirectSignalTo: the strongest strong signal into $pos. */
    private function directSignalTo(array $pos): int
    {
        $power = 0;
        foreach (array_keys(self::RS_DIRECTIONS) as $direction) {
            $power = max($power, $this->emitDirectSignal(self::rsStep($pos, $direction), $direction));
            if ($power >= 15) {
                break;
            }
        }
        return $power;
    }

    /** Level.getSignal: a conductor passes on the strong signal it receives. */
    private function signalFrom(array $pos, string $direction): int
    {
        $signal = $this->emitSignal($pos, $direction);
        return $this->rsConductor($this->world->get(...$pos)) ? max($signal, $this->directSignalTo($pos)) : $signal;
    }

    /** Level.getBestNeighborSignal, leaving out one side when asked. */
    private function bestNeighborSignal(array $pos, ?string $exclude = null): int
    {
        $best = 0;
        foreach (array_keys(self::RS_DIRECTIONS) as $direction) {
            if ($direction === $exclude) {
                continue;
            }
            $best = max($best, $this->signalFrom(self::rsStep($pos, $direction), $direction));
            if ($best >= 15) {
                return 15;
            }
        }
        return $best;
    }

    /** RedStoneWireBlock.shouldConnectTo. */
    private function wireConnectsTo(int $state, ?string $direction): bool
    {
        $catalog = $this->version->catalog;
        $name = $catalog->blockName($state);
        if ($name === 'redstone_wire') {
            return true;
        }
        if ($name === 'repeater') {
            $facing = $catalog->properties($state)['facing'];
            return $direction !== null && ($facing === $direction || self::RS_OPPOSITE[$facing] === $direction);
        }
        if ($name === 'observer') {
            return $direction === $catalog->properties($state)['facing'];
        }
        return $this->rsSource($state) && $direction !== null;
    }

    /** RedStoneWireBlock.canSurviveOn: a sturdy top, or a hopper. */
    private function wireSurvivesOn(int $state): bool
    {
        return $this->fireBlocks()->sturdy($state, 1) || $this->version->catalog->blockName($state) === 'hopper';
    }

    /** RedStoneWireBlock.getConnectingSide. */
    private function wireSide(array $pos, string $direction, bool $canConnectUp): string
    {
        $catalog = $this->version->catalog;
        $relative = self::rsStep($pos, $direction);
        $state = $this->world->get(...$relative);
        if ($canConnectUp) {
            $placeable = str_ends_with($catalog->blockName($state), '_trapdoor') || $this->wireSurvivesOn($state);
            if ($placeable && $this->wireConnectsTo($this->world->get($relative[0], $relative[1] + 1, $relative[2]), null)) {
                return $this->fireBlocks()->sturdy($state, array_search(self::RS_OPPOSITE[$direction], array_keys(self::RS_DIRECTIONS), true)) ? 'up' : 'side';
            }
        }
        $below = $this->world->get($relative[0], $relative[1] - 1, $relative[2]);
        return !$this->wireConnectsTo($state, $direction) && ($this->rsConductor($state) || !$this->wireConnectsTo($below, null)) ? 'none' : 'side';
    }

    private function wireCanConnectUp(array $pos): bool
    {
        return !$this->rsConductor($this->world->get($pos[0], $pos[1] + 1, $pos[2]));
    }

    /**
     * RedStoneWireBlock.getConnectionState: the missing sides filled in, a dot kept a dot, and a
     * single line extended across.
     */
    private function wireConnections(array $pos, array $p): array
    {
        $wasDot = $p['north'] === 'none' && $p['east'] === 'none' && $p['south'] === 'none' && $p['west'] === 'none';
        $up = $this->wireCanConnectUp($pos);
        $state = ['power' => $p['power']];
        foreach (self::RS_HORIZONTAL as $side) {
            $state[$side] = $this->wireSide($pos, $side, $up);
        }
        $isDot = $state['north'] === 'none' && $state['east'] === 'none' && $state['south'] === 'none' && $state['west'] === 'none';
        if ($wasDot && $isDot) {
            return $state;
        }
        $northSouthEmpty = $state['north'] === 'none' && $state['south'] === 'none';
        $eastWestEmpty = $state['east'] === 'none' && $state['west'] === 'none';
        if ($state['west'] === 'none' && $northSouthEmpty) {
            $state['west'] = 'side';
        }
        if ($state['east'] === 'none' && $northSouthEmpty) {
            $state['east'] = 'side';
        }
        if ($state['north'] === 'none' && $eastWestEmpty) {
            $state['north'] = 'side';
        }
        if ($state['south'] === 'none' && $eastWestEmpty) {
            $state['south'] = 'side';
        }
        return $state;
    }

    /** RedStoneWireBlock.getStateForPlacement: from a cross, so an unconnected wire is a cross. */
    private function wirePlacement(array $pos): int
    {
        $p = $this->wireConnections($pos, ['power' => '0', 'north' => 'side', 'east' => 'side', 'south' => 'side', 'west' => 'side']);
        $p['power'] = (string) $this->wireStrength($pos);
        return $this->version->catalog->state('redstone_wire', $p);
    }

    /** RedStoneWireBlock.calculateTargetStrength. */
    private function wireStrength(array $pos): int
    {
        $this->wireSignals = false;
        try {
            $block = $this->bestNeighborSignal($pos);
        } finally {
            $this->wireSignals = true;
        }
        $wire = 0;
        if ($block < 15) {
            $catalog = $this->version->catalog;
            $power = static fn (int $state): int => $catalog->blockName($state) === 'redstone_wire' ? (int) $catalog->properties($state)['power'] : 0;
            $aboveConductor = $this->rsConductor($this->world->get($pos[0], $pos[1] + 1, $pos[2]));
            foreach (self::RS_HORIZONTAL as $side) {
                $neighbour = self::rsStep($pos, $side);
                $state = $this->world->get(...$neighbour);
                $wire = max($wire, $power($state));
                if ($this->rsConductor($state) && !$aboveConductor) {
                    $wire = max($wire, $power($this->world->get($neighbour[0], $neighbour[1] + 1, $neighbour[2])));
                } elseif (!$this->rsConductor($state)) {
                    $wire = max($wire, $power($this->world->get($neighbour[0], $neighbour[1] - 1, $neighbour[2])));
                }
            }
        }
        return max($block, $wire - 1);
    }

    /** A wire's shape after its neighbours change, then its strength; set when either changed. */
    private function updateWire(array $pos): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get(...$pos);
        $p = $catalog->properties($state);
        $shaped = $this->wireConnections($pos, $p);
        $shaped['power'] = (string) $this->wireStrength($pos);
        $new = $catalog->state('redstone_wire', $shaped);
        if ($new !== $state) {
            $this->setBlock(...[...$pos, $new]);
        }
    }

    /** RedstoneTorchBlock.hasNeighborSignal: the block it stands or hangs on is powered. */
    private function torchPowered(array $pos, int $state): bool
    {
        $catalog = $this->version->catalog;
        if ($catalog->blockName($state) === 'redstone_torch') {
            return $this->signalFrom([$pos[0], $pos[1] - 1, $pos[2]], 'down') > 0;
        }
        $attached = self::RS_OPPOSITE[$catalog->properties($state)['facing']];
        return $this->signalFrom(self::rsStep($pos, $attached), $attached) > 0;
    }

    /** RedstoneTorchBlock.isToggledTooFrequently: eight toggles within 60 ticks burn it out. */
    private function torchToggledTooOften(array $pos, bool $add): bool
    {
        $key = implode(':', $pos);
        $list = &$this->torchToggles[$this->world->dimension];
        $list ??= [];
        if ($add) {
            $list[] = [$key, $this->worldAge];
        }
        $count = 0;
        foreach ($list as [$at]) {
            if ($at === $key && ++$count >= 8) {
                return true;
            }
        }
        return false;
    }

    /** RedstoneTorchBlock.tick. */
    private function tickTorch(array $pos): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get(...$pos);
        $name = $catalog->blockName($state);
        if ($name !== 'redstone_torch' && $name !== 'redstone_wall_torch') {
            return;
        }
        $p = $catalog->properties($state);
        $powered = $this->torchPowered($pos, $state);
        $list = &$this->torchToggles[$this->world->dimension];
        $list ??= [];
        while ($list !== [] && $this->worldAge - $list[0][1] > 60) {
            array_shift($list);
        }
        unset($list);
        if ($p['lit'] === 'true') {
            if ($powered) {
                $this->setBlock(...[...$pos, $catalog->state($name, ['lit' => 'false'] + $p)]);
                if ($this->torchToggledTooOften($pos, true)) {
                    $this->levelEvent(1502, $pos, 0);
                    $this->scheduleBlock('torch', $pos[0], $pos[1], $pos[2], 160);
                }
            }
        } elseif (!$powered && !$this->torchToggledTooOften($pos, false)) {
            $this->setBlock(...[...$pos, $catalog->state($name, ['lit' => 'true'] + $p)]);
        }
    }

    /** DiodeBlock.getInputSignal: the signal into its back, or a wire's power there. */
    private function diodeInput(array $pos, array $p): int
    {
        $catalog = $this->version->catalog;
        $back = self::rsStep($pos, $p['facing']);
        $input = $this->signalFrom($back, $p['facing']);
        if ($input >= 15) {
            return $input;
        }
        $state = $this->world->get(...$back);
        return max($input, $catalog->blockName($state) === 'redstone_wire' ? (int) $catalog->properties($state)['power'] : 0);
    }

    /** DiodeBlock.getAlternateSignal: the stronger side input; repeaters only feel other diodes. */
    private function diodeSideInput(array $pos, array $p, bool $diodesOnly): int
    {
        $catalog = $this->version->catalog;
        $sides = in_array($p['facing'], ['north', 'south'], true) ? ['east', 'west'] : ['north', 'south'];
        $best = 0;
        foreach ($sides as $side) {
            $at = self::rsStep($pos, $side);
            $state = $this->world->get(...$at);
            $name = $catalog->blockName($state);
            // Level.getControlInputSignal.
            if ($diodesOnly) {
                $signal = $name === 'repeater' || $name === 'comparator' ? $this->emitDirectSignal($at, $side) : 0;
            } elseif ($name === 'redstone_block') {
                $signal = 15;
            } elseif ($name === 'redstone_wire') {
                $signal = (int) $catalog->properties($state)['power'];
            } else {
                $signal = $this->rsSource($state) ? $this->emitDirectSignal($at, $side) : 0;
            }
            $best = max($best, $signal);
        }
        return $best;
    }

    /**
     * ComparatorBlock.getInputSignal: a block with an analog output behind it, or one behind a
     * conductor there, overrides the signal.
     */
    private function comparatorInput(array $pos, array $p): int
    {
        $input = $this->diodeInput($pos, $p);
        $back = self::rsStep($pos, $p['facing']);
        $state = $this->world->get(...$back);
        if ($this->fireBlocks()->flag($state, Blocks::ANALOG_OUTPUT)) {
            return $this->analogOutput($back, $state);
        }
        if ($input < 15 && $this->rsConductor($state)) {
            $further = self::rsStep($back, $p['facing']);
            $other = $this->world->get(...$further);
            if ($this->fireBlocks()->flag($other, Blocks::ANALOG_OUTPUT)) {
                return $this->analogOutput($further, $other);
            }
        }
        return $input;
    }

    /** BlockState.getAnalogOutputSignal for the blocks with a reading. */
    private function analogOutput(array $pos, int $state): int
    {
        $catalog = $this->version->catalog;
        $name = $catalog->blockName($state);
        $p = $catalog->properties($state);
        if ($name === 'jukebox') {
            $stack = $this->world->container($pos)['slots'][0] ?? null;
            return $stack === null ? 0 : ($catalog->simulation['songs'][$catalog->itemName($stack['item'])]['comparator'] ?? 0);
        }
        if (in_array($name, ['chest', 'trapped_chest', 'barrel', 'furnace', 'blast_furnace', 'smoker', 'hopper', 'dispenser', 'dropper'], true) || str_ends_with($name, 'shulker_box')) {
            $size = match (true) {
                $name === 'furnace', $name === 'blast_furnace', $name === 'smoker' => 3,
                $name === 'hopper' => 5,
                $name === 'dispenser', $name === 'dropper' => 9,
                default => 27,
            };
            $slots = $this->world->container($pos)['slots'] ?? [];
            if (($name === 'chest' || $name === 'trapped_chest') && ($partner = $this->chestPartner($pos)) !== null) {
                $size = 54;
                foreach ($this->world->container($partner)['slots'] ?? [] as $slot => $stack) {
                    $slots[$slot + 27] = $stack;
                }
            }
            // AbstractContainerMenu.getRedstoneSignalFromContainer.
            $share = 0.0;
            foreach ($slots as $stack) {
                if ($stack !== null && $stack['count'] > 0) {
                    $share = \PhpMinecraftJavaServer\World\Generation\Float32::round($share + \PhpMinecraftJavaServer\World\Generation\Float32::round($stack['count'] / min(64, $catalog->item($stack['item'])['max_stack'] ?? 64)));
                }
            }
            $share = \PhpMinecraftJavaServer\World\Generation\Float32::round($share / $size);
            return (int) floor($share * 14) + ($share > 0.0 ? 1 : 0);
        }
        return match (true) {
            $name === 'water_cauldron', $name === 'powder_snow_cauldron' => (int) $p['level'],
            $name === 'lava_cauldron' => 3,
            $name === 'composter' => (int) $p['level'],
            $name === 'cake' => (7 - (int) $p['bites']) * 2,
            $name === 'end_portal_frame' => $p['eye'] === 'true' ? 15 : 0,
            $name === 'respawn_anchor' => (int) floor((int) $p['charges'] / 4 * 15),
            $name === 'beehive', $name === 'bee_nest' => (int) $p['honey_level'],
            default => 0,
        };
    }

    /** ComparatorBlock.calculateOutputSignal. */
    private function comparatorOutputSignal(array $pos, array $p): int
    {
        $input = $this->comparatorInput($pos, $p);
        if ($input === 0) {
            return 0;
        }
        $side = $this->diodeSideInput($pos, $p, false);
        if ($side > $input) {
            return 0;
        }
        return $p['mode'] === 'subtract' ? $input - $side : $input;
    }

    /** DiodeBlock.shouldTurnOn, and ComparatorBlock's own. */
    private function diodeShouldTurnOn(array $pos, string $name, array $p): bool
    {
        if ($name === 'repeater') {
            return $this->diodeInput($pos, $p) > 0;
        }
        $input = $this->comparatorInput($pos, $p);
        if ($input === 0) {
            return false;
        }
        $side = $this->diodeSideInput($pos, $p, false);
        return $input > $side || ($input === $side && $p['mode'] === 'compare');
    }

    /** DiodeBlock.checkTickOnNeighbor and ComparatorBlock's: schedule a change when one is due. */
    private function diodeNeighborChanged(array $pos): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get(...$pos);
        $name = $catalog->blockName($state);
        $p = $catalog->properties($state);
        $key = implode(':', $pos);
        if ($name === 'repeater') {
            // RepeaterBlock.updateShape: locked by a powered diode beside it.
            $locked = $this->diodeSideInput($pos, $p, true) > 0;
            if (($p['locked'] === 'true') !== $locked) {
                $p['locked'] = $locked ? 'true' : 'false';
                $this->setBlock(...[...$pos, $catalog->state('repeater', $p)]);
            }
            if ($locked) {
                return;
            }
            if (($p['powered'] === 'true') !== $this->diodeShouldTurnOn($pos, $name, $p) && !isset($this->blockTicks['repeater:' . $key])) {
                $this->scheduleBlock('repeater', $pos[0], $pos[1], $pos[2], 2 * (int) $p['delay']);
            }
            return;
        }
        $output = $this->comparatorOutputSignal($pos, $p);
        $old = $this->comparatorOutputs[$this->world->dimension][$key] ?? 0;
        if (($output !== $old || ($p['powered'] === 'true') !== $this->diodeShouldTurnOn($pos, $name, $p)) && !isset($this->blockTicks['comparator:' . $key])) {
            $this->scheduleBlock('comparator', $pos[0], $pos[1], $pos[2], 2);
        }
    }

    /** DiodeBlock.tick for repeaters. */
    private function tickRepeater(array $pos): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get(...$pos);
        if ($catalog->blockName($state) !== 'repeater') {
            return;
        }
        $p = $catalog->properties($state);
        if ($this->diodeSideInput($pos, $p, true) > 0) {
            return;
        }
        $on = $p['powered'] === 'true';
        $should = $this->diodeShouldTurnOn($pos, 'repeater', $p);
        if ($on && !$should) {
            $this->setBlock(...[...$pos, $catalog->state('repeater', ['powered' => 'false'] + $p)]);
        } elseif (!$on) {
            $this->setBlock(...[...$pos, $catalog->state('repeater', ['powered' => 'true'] + $p)]);
            if (!$should) {
                $this->scheduleBlock('repeater', $pos[0], $pos[1], $pos[2], 2 * (int) $p['delay']);
            }
        }
    }

    /** ComparatorBlock.refreshOutputState. */
    private function tickComparator(array $pos): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get(...$pos);
        if ($catalog->blockName($state) !== 'comparator') {
            return;
        }
        $p = $catalog->properties($state);
        $key = implode(':', $pos);
        $output = $this->comparatorOutputSignal($pos, $p);
        $old = $this->comparatorOutputs[$this->world->dimension][$key] ?? 0;
        $this->comparatorOutputs[$this->world->dimension][$key] = $output;
        if ($old !== $output || $p['mode'] === 'compare') {
            $should = $this->diodeShouldTurnOn($pos, 'comparator', $p);
            $on = $p['powered'] === 'true';
            if ($on !== $should) {
                $this->setBlock(...[...$pos, $catalog->state('comparator', ['powered' => $should ? 'true' : 'false'] + $p)]);
            }
            // updateNeighborsInFront.
            $front = self::rsStep($pos, self::RS_OPPOSITE[$p['facing']]);
            $this->changedSimulation(...$front);
        }
    }

    /** RedstoneLampBlock: lights at once, goes out four ticks after its power does. */
    private function lampChanged(array $pos, bool $ticked): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get(...$pos);
        $lit = $catalog->properties($state)['lit'] === 'true';
        $powered = $this->bestNeighborSignal($pos) > 0;
        if ($lit && !$powered) {
            if ($ticked) {
                $this->setBlock(...[...$pos, $catalog->state('redstone_lamp', ['lit' => 'false'])]);
            } elseif (!isset($this->blockTicks['lamp:' . implode(':', $pos)])) {
                $this->scheduleBlock('lamp', $pos[0], $pos[1], $pos[2], 4);
            }
        } elseif (!$lit && $powered && !$ticked) {
            $this->setBlock(...[...$pos, $catalog->state('redstone_lamp', ['lit' => 'true'])]);
        }
    }

    /** neighborChanged for one redstone component. */
    private function redstoneNeighborChanged(array $pos): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get(...$pos);
        $name = $catalog->blockName($state);
        switch (true) {
            case $name === 'redstone_wire':
                $this->updateWire($pos);
                break;
            case $name === 'redstone_torch' || $name === 'redstone_wall_torch':
                if (($catalog->properties($state)['lit'] === 'true') === $this->torchPowered($pos, $state) && !isset($this->blockTicks['torch:' . implode(':', $pos)])) {
                    $this->scheduleBlock('torch', $pos[0], $pos[1], $pos[2], 2);
                }
                break;
            case $name === 'repeater' || $name === 'comparator':
                $this->diodeNeighborChanged($pos);
                break;
            case $name === 'redstone_lamp':
                $this->lampChanged($pos, false);
                break;
            case $name === 'note_block' || str_ends_with($name, '_door') || str_ends_with($name, '_trapdoor'):
                $this->tickCircuit($pos[0], $pos[1], $pos[2]);
                break;
        }
    }

    private function isRedstoneComponent(string $name): bool
    {
        return in_array($name, ['redstone_wire', 'redstone_torch', 'redstone_wall_torch', 'repeater', 'comparator', 'redstone_lamp', 'note_block'], true)
            || str_ends_with($name, '_door') || str_ends_with($name, '_trapdoor');
    }

    /**
     * Level.updateNeighborsAt for a changed block, as the redstone around it hears it: the
     * components beside it, beyond a conductor beside it, and wires a step up or down from it.
     */
    private function redstoneChanged(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $positions = [[$x, $y, $z]];
        foreach (self::RS_DIRECTIONS as [$dx, $dy, $dz]) {
            $at = [$x + $dx, $y + $dy, $z + $dz];
            $positions[] = $at;
            if ($this->rsConductor($this->world->get(...$at))) {
                foreach (self::RS_DIRECTIONS as [$ex, $ey, $ez]) {
                    $positions[] = [$at[0] + $ex, $at[1] + $ey, $at[2] + $ez];
                }
            }
        }
        // RedStoneWireBlock.updateIndirectNeighbourShapes: wires a step up or down beside it.
        foreach (self::RS_HORIZONTAL as $side) {
            [$dx, , $dz] = self::RS_DIRECTIONS[$side];
            $positions[] = [$x + $dx, $y + 1, $z + $dz];
            $positions[] = [$x + $dx, $y - 1, $z + $dz];
        }
        $seen = [];
        foreach ($positions as $pos) {
            $key = implode(':', $pos);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $name = $catalog->blockName($this->world->get(...$pos));
            // The changed block hears itself only as a wire (onPlace) or a torch, whose onPlace
            // tells its neighbours' neighbours, itself among them.
            if ($pos === [$x, $y, $z] && !in_array($name, ['redstone_wire', 'redstone_torch', 'redstone_wall_torch'], true)) {
                continue;
            }
            if ($this->isRedstoneComponent($name)) {
                $this->redstoneNeighborChanged($pos);
            }
        }
    }

    /**
     * RedstoneLampBlock.getStateForPlacement lights a lamp a player places beside power, and
     * DiodeBlock.setPlacedBy ticks a diode a player places with input a tick later.
     */
    private function redstonePlaced(array $pos): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get(...$pos);
        $name = $catalog->blockName($state);
        if ($name === 'redstone_lamp' && $this->bestNeighborSignal($pos) > 0 && $catalog->properties($state)['lit'] === 'false') {
            $this->setBlock(...[...$pos, $catalog->state('redstone_lamp', ['lit' => 'true'])]);
        } elseif (($name === 'repeater' || $name === 'comparator') && $this->diodeShouldTurnOn($pos, $name, $catalog->properties($state))) {
            $this->scheduleBlock($name, $pos[0], $pos[1], $pos[2], 1);
        }
    }

    /** The scheduled redstone ticks: torches, repeaters, comparators and lamps going out. */
    private function tickRedstone(string $kind, int $x, int $y, int $z): void
    {
        match ($kind) {
            'torch' => $this->tickTorch([$x, $y, $z]),
            'repeater' => $this->catalogName($x, $y, $z) === 'redstone_lamp' ? $this->lampChanged([$x, $y, $z], true) : $this->tickRepeater([$x, $y, $z]),
            'comparator' => $this->tickComparator([$x, $y, $z]),
            'lamp' => $this->catalogName($x, $y, $z) === 'redstone_lamp' ? $this->lampChanged([$x, $y, $z], true) : null,
            default => $this->redstoneNeighborChanged([$x, $y, $z]),
        };
    }

    private function catalogName(int $x, int $y, int $z): string
    {
        return $this->version->catalog->blockName($this->world->get($x, $y, $z));
    }

    private function restoreRedstone(): void
    {
        $this->comparatorOutputs[$this->world->dimension] = $this->world->setting('comparatorOutputs') ?? [];
    }

    private function saveRedstone(): void
    {
        $this->world->setting('comparatorOutputs', $this->comparatorOutputs[$this->world->dimension] ?? []);
    }
}
