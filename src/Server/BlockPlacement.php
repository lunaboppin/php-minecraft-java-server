<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

/**
 * Block.getStateForPlacement for the blocks whose placed state depends on how the player looks,
 * the face they click or what the block can attach to, dispatched on the block's class chain
 * from block-behaviour.json. StandingAndWallBlockItem picks between a floor and a wall block.
 */
trait BlockPlacement
{
    /** @var array<string, list<string>>|null Java class chains by block name */
    private ?array $placementClasses = null;

    private ?\PhpMinecraftJavaServer\World\Vanilla\Structure\ShapeUpdater $shapeUpdater = null;

    /** The connection blocks whose shapes follow their neighbours: fences, panes and bars, walls and stairs. */
    private const CONNECTING = ['FenceBlock', 'IronBarsBlock', 'WallBlock', 'StairBlock'];

    private ?\PhpMinecraftJavaServer\World\Vanilla\Decoration\Survival $placementSurvivalRules = null;

    private function shapeUpdater(): \PhpMinecraftJavaServer\World\Vanilla\Structure\ShapeUpdater
    {
        $this->placementSurvivalRules ??= new \PhpMinecraftJavaServer\World\Vanilla\Decoration\Survival($this->fireBlocks(), $this->version->directory . '/worldgen/block-behaviour.json');
        return $this->shapeUpdater ??= new \PhpMinecraftJavaServer\World\Vanilla\Structure\ShapeUpdater($this->fireBlocks(), $this->placementSurvivalRules);
    }

    /**
     * BlockItem.canPlace: BlockState.canSurvive where the block would go, read from the world.
     * Mushrooms also need light below 13, which the decoration rules leave out.
     */
    private function placementSurvives(int $state, int $x, int $y, int $z): bool
    {
        $name = $this->version->catalog->blockName($state);
        if (($name === 'red_mushroom' || $name === 'brown_mushroom')
            && !$this->fireBlocks()->inTag($this->world->get($x, $y - 1, $z), 'overrides_mushroom_light_requirement')) {
            return $this->world->rawBrightness($x, $y, $z) < 13 && $this->fireBlocks()->flag($this->world->get($x, $y - 1, $z), \PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks::SOLID_RENDER);
        }
        $world = $this->world;
        $reader = new class ($world) implements \PhpMinecraftJavaServer\World\Vanilla\Decoration\BlockReader {
            public function __construct(private readonly \PhpMinecraftJavaServer\World\World $world)
            {
            }

            public function get(int $x, int $y, int $z): int
            {
                return $this->world->get($x, $y, $z);
            }
        };
        return $this->placementSurvival()->canSurvive($state, $reader, $x, $y, $z);
    }

    private function placementSurvival(): \PhpMinecraftJavaServer\World\Vanilla\Decoration\Survival
    {
        $this->shapeUpdater();
        return $this->placementSurvivalRules;
    }

    /** The connection block's state with its sides joined to its neighbours, as placed. */
    private function connectedState(int $state, int $x, int $y, int $z): int
    {
        $catalog = $this->version->catalog;
        $classes = $this->placementClasses($catalog->blockName($state));
        if (in_array('FenceGateBlock', $classes, true)) {
            // FenceGateBlock: lowered when a wall is beside it across its opening.
            $wall = fn (int $a, int $c): bool => $this->fireBlocks()->inTag($this->world->get($a, $y, $c), 'walls');
            $p = array_map('strval', $catalog->properties($state));
            $p['in_wall'] = (in_array($p['facing'], ['north', 'south'], true) ? $wall($x - 1, $z) || $wall($x + 1, $z) : $wall($x, $z - 1) || $wall($x, $z + 1)) ? 'true' : 'false';
            return $catalog->state($catalog->blockName($state), $p);
        }
        if (array_intersect(self::CONNECTING, $classes) === []) {
            return $state;
        }
        return $this->shapeUpdater()->update($state, $x, $y, $z, fn (int $a, int $b, int $c): int => $this->world->get($a, $b, $c)) ?? $state;
    }

    /**
     * Level.setBlock's neighbour shape updates (BlockState.updateShape) after a player places or
     * breaks a block: the connection blocks beside, above and below take their new shapes.
     */
    private function updateNeighbourShapes(int $x, int $y, int $z): void
    {
        foreach (self::PLACEMENT_DIRECTIONS as [$dx, $dy, $dz]) {
            [$a, $b, $c] = [$x + $dx, $y + $dy, $z + $dz];
            $state = $this->world->get($a, $b, $c);
            $updated = $this->connectedState($state, $a, $b, $c);
            if ($updated !== $state) {
                $this->setBlock($a, $b, $c, $updated);
            }
        }
    }

    /** Direction.values(): offset and opposite by name. */
    private const PLACEMENT_DIRECTIONS = [
        'down' => [0, -1, 0, 'up'], 'up' => [0, 1, 0, 'down'], 'north' => [0, 0, -1, 'south'],
        'south' => [0, 0, 1, 'north'], 'west' => [-1, 0, 0, 'east'], 'east' => [1, 0, 0, 'west'],
    ];

    /** Blocks' direction numbers, Direction.values() order. */
    private const PLACEMENT_INDEX = ['down' => 0, 'up' => 1, 'north' => 2, 'south' => 3, 'west' => 4, 'east' => 5];

    /** @return list<string> */
    private function placementClasses(string $name): array
    {
        if ($this->placementClasses === null) {
            $behaviour = json_decode((string) file_get_contents($this->version->directory . '/worldgen/block-behaviour.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->placementClasses = $behaviour['classes'];
        }
        return $this->placementClasses[$name] ?? [];
    }

    /**
     * Direction.orderedByNearest for the player's view, then BlockPlaceContext moves the face
     * opposite the clicked one to the front unless the clicked block itself is replaced.
     *
     * @return list<string>
     */
    private function nearestLookingDirections(Client $c, ?string $clickedFace, bool $replaceClicked): array
    {
        $pitch = deg2rad($c->pitch);
        $yaw = -deg2rad($c->yaw);
        [$pitchSin, $pitchCos, $yawSin, $yawCos] = [sin($pitch), cos($pitch), sin($yaw), cos($yaw)];
        [$xPos, $yPos, $zPos] = [$yawSin > 0.0, $pitchSin < 0.0, $yawCos > 0.0];
        $xYaw = $xPos ? $yawSin : -$yawSin;
        $yMag = $yPos ? -$pitchSin : $pitchSin;
        $zYaw = $zPos ? $yawCos : -$yawCos;
        [$xMag, $zMag] = [$xYaw * $pitchCos, $zYaw * $pitchCos];
        [$ax, $ay, $az] = [$xPos ? 'east' : 'west', $yPos ? 'up' : 'down', $zPos ? 'south' : 'north'];
        if ($xYaw > $zYaw) {
            $order = $yMag > $xMag ? [$ay, $ax, $az] : ($zMag > $yMag ? [$ax, $az, $ay] : [$ax, $ay, $az]);
        } else {
            $order = $yMag > $zMag ? [$ay, $az, $ax] : ($xMag > $yMag ? [$az, $ax, $ay] : [$az, $ay, $ax]);
        }
        $directions = [$order[0], $order[1], $order[2], self::PLACEMENT_DIRECTIONS[$order[2]][3], self::PLACEMENT_DIRECTIONS[$order[1]][3], self::PLACEMENT_DIRECTIONS[$order[0]][3]];
        if ($replaceClicked || $clickedFace === null) {
            return $directions;
        }
        $first = self::PLACEMENT_DIRECTIONS[$clickedFace][3];
        return [$first, ...array_values(array_diff($directions, [$first]))];
    }

    /** The neighbour's face toward the block at the position is sturdy (BlockState.isFaceSturdy). */
    private function placementSturdy(array $pos, string $toward): bool
    {
        [$dx, $dy, $dz, $opposite] = self::PLACEMENT_DIRECTIONS[$toward];
        return $this->fireBlocks()->sturdy($this->world->get($pos[0] + $dx, $pos[1] + $dy, $pos[2] + $dz), self::PLACEMENT_INDEX[$opposite]);
    }

    private function placementNeighbour(array $pos, string $toward): int
    {
        [$dx, $dy, $dz] = self::PLACEMENT_DIRECTIONS[$toward];
        return $this->world->get($pos[0] + $dx, $pos[1] + $dy, $pos[2] + $dz);
    }

    /**
     * BlockBehaviour.canBeReplaced with the item in hand: replaceable blocks other than the
     * item's own, and the blocks that stack (slabs, candles, snow layers, sea pickles, turtle
     * eggs, flower beds) when clicked with more of themselves. $clicked is
     * BlockPlaceContext.replacingClickedOnBlock, true while the clicked block itself is tested.
     */
    private function canBeReplacedBy(int $state, ?string $item, bool $clicked, int $face, float $cursorY, bool $sneaking): bool
    {
        $catalog = $this->version->catalog;
        $name = $catalog->blockName($state);
        $p = $catalog->properties($state);
        $default = $catalog->blocks[$name]['replaceable'] && $item !== $name;
        $classes = $this->placementClasses($name);
        $horizontal = $face >= 2;
        if (in_array('SlabBlock', $classes, true)) {
            if ($p['type'] === 'double' || $item !== $name) {
                return false;
            }
            if (!$clicked) {
                return true;
            }
            $upper = $cursorY > 0.5;
            return $p['type'] === 'bottom' ? $face === 1 || ($upper && $horizontal) : $face === 0 || (!$upper && $horizontal);
        }
        if (in_array('SnowLayerBlock', $classes, true)) {
            $layers = (int) $p['layers'];
            if ($item !== $name || $layers >= 8) {
                return $layers === 1;
            }
            return !$clicked || $face === 1;
        }
        $count = match (true) {
            in_array('CandleBlock', $classes, true) => 'candles',
            in_array('SeaPickleBlock', $classes, true) => 'pickles',
            in_array('TurtleEggBlock', $classes, true) => 'eggs',
            in_array('FlowerBedBlock', $classes, true), in_array('LeafLitterBlock', $classes, true) => array_key_exists('flower_amount', $p) ? 'flower_amount' : 'segment_amount',
            default => null,
        };
        if ($count !== null && !$sneaking && $item === $name && (int) $p[$count] < 4) {
            return true;
        }
        return $default;
    }

    /**
     * The block and properties to place, or null when the block cannot be placed there.
     *
     * @param array<string, string> $properties what the generic rules chose
     * @param array{int, int, int} $pos
     * @return array{0: string, 1: array<string, string>}|null
     */
    private function placementState(Client $c, string $name, array $properties, array $pos, int $face, array $cursor, bool $replaceClicked): ?array
    {
        $catalog = $this->version->catalog;
        $clicked = array_keys(self::PLACEMENT_INDEX)[$face];
        $horizontal = ['south', 'west', 'north', 'east'][((int) floor($c->yaw / 90 + 0.5)) & 3];
        $opposite = static fn (string $d): string => self::PLACEMENT_DIRECTIONS[$d][3];
        $clockwise = static fn (string $d): string => ['north' => 'east', 'east' => 'south', 'south' => 'west', 'west' => 'north'][$d];
        $looking = fn (): array => $this->nearestLookingDirections($c, $clicked, $replaceClicked);
        $isHorizontal = static fn (string $d): bool => $d !== 'up' && $d !== 'down';
        $classes = $this->placementClasses($name);
        $is = static fn (string ...$names): bool => array_intersect($names, $classes) !== [];

        if (str_ends_with($name, '_hanging_sign') && !str_contains($name, '_wall_')) {
            return $this->hangingSignPlacement($c, $name, $properties, $pos, $clicked, $looking());
        }
        // StandingAndWallBlockItem: the floor block or its wall form, whichever survives first.
        $wall = preg_replace('/(^|_)(torch|banner|skull|head|fan)$/', '$1wall_$2', $name);
        if ($wall !== $name && isset($catalog->blocks[$wall])) {
            $wallState = $this->wallPlacement($wall, $pos, $looking());
            foreach ($looking() as $direction) {
                if ($direction === 'up') {
                    continue;
                }
                if ($direction === 'down' && (str_ends_with($name, '_skull') || str_ends_with($name, '_head'))) {
                    // SkullBlock: RotationSegment.convertToSegment of the yaw itself.
                    $properties['rotation'] = (string) (((int) floor($c->yaw / 22.5 + 0.5)) & 15);
                }
                $state = $direction === 'down' ? [$name, $properties] : $wallState;
                if ($state !== null && ($direction !== 'down' || $this->standingSurvives($name, $pos))) {
                    return $state;
                }
            }
            return null;
        }
        // Clicking a block of the same kind adds to it.
        $existing = $this->world->get(...$pos);
        if ($catalog->blockName($existing) === $name) {
            $old = array_map('strval', $catalog->properties($existing));
            $more = static fn (string $key, int $max): array => [$name, [$key => (string) min($max, (int) $old[$key] + 1)] + $old];
            if ($is('SlabBlock')) {
                return [$name, ['type' => 'double', 'waterlogged' => 'false'] + $old];
            }
            foreach (['CandleBlock' => ['candles', 4], 'SnowLayerBlock' => ['layers', 8], 'SeaPickleBlock' => ['pickles', 4], 'TurtleEggBlock' => ['eggs', 4],
                'FlowerBedBlock' => [array_key_exists('flower_amount', $old) ? 'flower_amount' : 'segment_amount', 4], 'LeafLitterBlock' => ['segment_amount', 4]] as $class => [$key, $max]) {
                if ($is($class)) {
                    return $more($key, $max);
                }
            }
        }
        if ($is('FlowerBedBlock', 'LeafLitterBlock')) {
            $properties['facing'] = $opposite($horizontal);
        }
        if ($is('FenceGateBlock')) {
            $properties['facing'] = $horizontal;
        }
        if ($is(
            'AbstractFurnaceBlock',
            'BeehiveBlock',
            'CarvedPumpkinBlock',
            'ChiseledBookShelfBlock',
            'CopperGolemStatueBlock',
            'DiodeBlock',
            'EnderChestBlock',
            'EndPortalFrameBlock',
            'GlazedTerracottaBlock',
            'LecternBlock',
            'LoomBlock',
            'ShelfBlock',
            'StonecutterBlock',
            'VaultBlock',
            'BigDripleafBlock',
            'SmallDripleafBlock'
        )) {
            $properties['facing'] = $opposite($horizontal);
        } elseif ($is('AnvilBlock')) {
            $properties['facing'] = $clockwise($horizontal);
        } elseif ($is('BarrelBlock', 'CommandBlock', 'DispenserBlock', 'PistonBaseBlock')) {
            // Entity.getNearestLookingDirection, from the view alone.
            $properties['facing'] = $opposite($this->nearestLookingDirections($c, null, true)[0]);
        } elseif ($is('ObserverBlock')) {
            $properties['facing'] = $this->nearestLookingDirections($c, null, true)[0];
        } elseif ($is('AmethystClusterBlock', 'LightningRodBlock', 'ShulkerBoxBlock')) {
            $properties['facing'] = $clicked;
        } elseif ($is('EndRodBlock')) {
            // An end rod placed on another facing the same way faces back toward it.
            $behind = $this->placementNeighbour($pos, $opposite($clicked));
            $same = $catalog->blockName($behind) === $name && $catalog->properties($behind)['facing'] === $clicked;
            $properties['facing'] = $same ? $opposite($clicked) : $clicked;
        } elseif ($is('HopperBlock')) {
            $properties['facing'] = $isHorizontal($clicked) ? $opposite($clicked) : 'down';
        } elseif ($is('TrapDoorBlock')) {
            if (!$replaceClicked && $isHorizontal($clicked)) {
                $properties['facing'] = $clicked;
                $properties['half'] = $cursor[1] > 0.5 ? 'top' : 'bottom';
            } else {
                $properties['facing'] = $opposite($horizontal);
                $properties['half'] = $clicked === 'up' ? 'bottom' : 'top';
            }
        } elseif ($is('FaceAttachedHorizontalDirectionalBlock')) {
            // Buttons, levers and grindstones: the first looking direction they can attach toward.
            foreach ($looking() as $direction) {
                if ($isHorizontal($direction)) {
                    [$properties['face'], $properties['facing']] = ['wall', $opposite($direction)];
                } else {
                    [$properties['face'], $properties['facing']] = [$direction === 'up' ? 'ceiling' : 'floor', $horizontal];
                }
                if ($is('GrindstoneBlock') || $this->placementSturdy($pos, $direction)) {
                    return [$name, $properties];
                }
            }
            return null;
        } elseif ($is('BellBlock')) {
            return $this->bellPlacement($name, $properties, $pos, $clicked, $horizontal);
        } elseif ($is('LadderBlock', 'TripWireHookBlock')) {
            if ($is('LadderBlock') && !$replaceClicked) {
                $behind = $this->placementNeighbour($pos, $opposite($clicked));
                if ($catalog->blockName($behind) === $name && $catalog->properties($behind)['facing'] === $clicked) {
                    return null;
                }
            }
            foreach ($looking() as $direction) {
                if ($isHorizontal($direction) && $this->placementSturdy($pos, $direction)) {
                    $properties['facing'] = $opposite($direction);
                    return [$name, $properties];
                }
            }
            return null;
        } elseif ($is('BaseRailBlock')) {
            $properties['shape'] = $horizontal === 'east' || $horizontal === 'west' ? 'east_west' : 'north_south';
        } elseif ($is('ScaffoldingBlock')) {
            // ScaffoldingBlock: its distance from support, refused once it would be 7.
            $distance = $this->scaffoldingDistance($pos);
            if ($distance >= 7) {
                return null;
            }
            $properties['distance'] = (string) $distance;
            $properties['bottom'] = $distance > 0 && $catalog->blockName($this->world->get($pos[0], $pos[1] - 1, $pos[2])) !== $name ? 'true' : 'false';
        } elseif ($is('LeavesBlock')) {
            $properties['persistent'] = 'true';
        } elseif ($is('VineBlock')) {
            return $this->vinePlacement($name, $properties, $pos, $looking());
        } elseif ($is('MultifaceBlock')) {
            return $this->multifacePlacement($name, $properties, $pos, $looking());
        }
        return [$name, $properties];
    }

    /**
     * HangingSignItem: a StandingAndWallBlockItem attached upward. The ceiling sign needs a block
     * above with a sturdy centre; the wall sign hangs across a looking direction off the clicked
     * face's axis, from a sturdy side or another wall hanging sign beside it.
     */
    private function hangingSignPlacement(Client $c, string $name, array $properties, array $pos, string $clicked, array $looking): ?array
    {
        $catalog = $this->version->catalog;
        $blocks = $this->fireBlocks();
        $wall = str_replace('_hanging_sign', '_wall_hanging_sign', $name);
        $axis = static fn (string $d): string => in_array($d, ['north', 'south'], true) ? 'z' : (in_array($d, ['east', 'west'], true) ? 'x' : 'y');
        $clockwise = ['north' => 'east', 'east' => 'south', 'south' => 'west', 'west' => 'north'];
        $wallState = null;
        foreach ($looking as $direction) {
            if ($axis($direction) !== 'y' && $axis($direction) !== $axis($clicked)) {
                $facing = self::PLACEMENT_DIRECTIONS[$direction][3];
                $attaches = function (string $side, string $face) use ($pos, $facing, $blocks, $axis): bool {
                    [$dx, , $dz] = self::PLACEMENT_DIRECTIONS[$side];
                    $state = $this->world->get($pos[0] + $dx, $pos[1], $pos[2] + $dz);
                    return $blocks->inTag($state, 'wall_hanging_signs')
                        ? $axis($this->version->catalog->properties($state)['facing']) === $axis($facing)
                        : $blocks->sturdy($state, self::PLACEMENT_INDEX[$face]);
                };
                $cw = $clockwise[$facing];
                $ccw = array_search($facing, $clockwise, true);
                if ($attaches($cw, $ccw) || $attaches($ccw, $cw)) {
                    $wallState = [$wall, ['facing' => $facing, 'waterlogged' => $properties['waterlogged'] ?? 'false']];
                    break;
                }
            }
        }
        foreach ($looking as $direction) {
            if ($direction === 'down') {
                continue;
            }
            if ($direction !== 'up') {
                if ($wallState !== null) {
                    return $wallState;
                }
                continue;
            }
            // CeilingHangingSignBlock: chains to the middle unless the block above has a full bottom face.
            $above = $this->world->get($pos[0], $pos[1] + 1, $pos[2]);
            if (!$blocks->sturdyCenter($above, 0)) {
                continue;
            }
            $toward = ['south', 'west', 'north', 'east'][((int) floor($c->yaw / 90 + 0.5)) & 3];
            $middle = !$blocks->sturdy($above, 0) || $c->sneaking;
            if ($blocks->inTag($above, 'all_hanging_signs') && !$c->sneaking) {
                $p = $catalog->properties($above);
                $aboveFacing = $p['facing'] ?? ((int) ($p['rotation'] ?? 1) % 4 === 0 ? ['south', 'west', 'north', 'east'][intdiv((int) $p['rotation'], 4)] : null);
                if ($aboveFacing !== null && $axis($aboveFacing) === $axis($toward)) {
                    $middle = false;
                }
            }
            $rotation = $middle ? ((int) floor(($c->yaw + 180) / 22.5 + 0.5)) & 15 : ['south' => 0, 'west' => 4, 'north' => 8, 'east' => 12][self::PLACEMENT_DIRECTIONS[$toward][3]];
            return [$name, ['attached' => $middle ? 'true' : 'false', 'rotation' => (string) $rotation, 'waterlogged' => $properties['waterlogged'] ?? 'false']];
        }
        return null;
    }

    /** ScaffoldingBlock.getDistance: 0 on a sturdy block, else one more than the nearest scaffolding beside or the one below's. */
    private function scaffoldingDistance(array $pos): int
    {
        $catalog = $this->version->catalog;
        $below = $this->world->get($pos[0], $pos[1] - 1, $pos[2]);
        $distance = 7;
        if ($catalog->blockName($below) === 'scaffolding') {
            $distance = (int) $catalog->properties($below)['distance'];
        } elseif ($this->fireBlocks()->sturdy($below, 1)) {
            return 0;
        }
        foreach (['north', 'east', 'south', 'west'] as $direction) {
            $side = $this->placementNeighbour($pos, $direction);
            if ($catalog->blockName($side) === 'scaffolding') {
                $distance = min($distance, (int) $catalog->properties($side)['distance'] + 1);
                if ($distance === 1) {
                    break;
                }
            }
        }
        return $distance;
    }

    /** The wall form's getStateForPlacement: the first horizontal looking direction it can hang toward. */
    private function wallPlacement(string $wall, array $pos, array $looking): ?array
    {
        $catalog = $this->version->catalog;
        $base = array_map('strval', $catalog->properties($catalog->state($wall)));
        foreach ($looking as $direction) {
            if ($direction === 'up' || $direction === 'down') {
                continue;
            }
            $behind = $this->placementNeighbour($pos, $direction);
            $holds = match (true) {
                str_ends_with($wall, '_banner') => $this->fireBlocks()->isSolid($behind),
                // WallSkullBlock: anything behind it that cannot be replaced.
                str_ends_with($wall, '_skull') || str_ends_with($wall, '_head') => !$catalog->blocks[$catalog->blockName($behind)]['replaceable'],
                default => $this->placementSturdy($pos, $direction),
            };
            if ($holds) {
                $base['facing'] = self::PLACEMENT_DIRECTIONS[$direction][3];
                return [$wall, $base];
            }
        }
        return null;
    }

    /** The standing form's canSurvive. */
    private function standingSurvives(string $name, array $pos): bool
    {
        $below = $this->world->get($pos[0], $pos[1] - 1, $pos[2]);
        return match (true) {
            str_ends_with($name, '_banner') => $this->fireBlocks()->isSolid($below),
            str_ends_with($name, '_skull') || str_ends_with($name, '_head') => true,
            str_ends_with($name, '_fan') => $this->fireBlocks()->sturdy($below, 1),
            // Block.canSupportCenter for torches.
            default => $this->fireBlocks()->sturdyCenter($below, 1),
        };
    }

    /** BellBlock.getStateForPlacement */
    private function bellPlacement(string $name, array $properties, array $pos, string $clicked, string $horizontal): ?array
    {
        if ($clicked === 'up' || $clicked === 'down') {
            $properties['attachment'] = $clicked === 'down' ? 'ceiling' : 'floor';
            $properties['facing'] = $horizontal;
            return $this->placementSturdy($pos, $clicked === 'down' ? 'up' : 'down') ? [$name, $properties] : null;
        }
        $axis = $clicked === 'north' || $clicked === 'south' ? ['north', 'south'] : ['west', 'east'];
        $double = $this->placementSturdy($pos, $axis[0]) && $this->placementSturdy($pos, $axis[1]);
        $properties['facing'] = self::PLACEMENT_DIRECTIONS[$clicked][3];
        $properties['attachment'] = $double ? 'double_wall' : 'single_wall';
        if ($this->placementSturdy($pos, $properties['facing'])) {
            return [$name, $properties];
        }
        $properties['attachment'] = $this->placementSturdy($pos, 'down') ? 'floor' : 'ceiling';
        return $this->placementSturdy($pos, $properties['attachment'] === 'floor' ? 'down' : 'up') ? [$name, $properties] : null;
    }

    /** VineBlock.getStateForPlacement: the first looking direction, other than down, it can hold to. */
    private function vinePlacement(string $name, array $properties, array $pos, array $looking): ?array
    {
        $catalog = $this->version->catalog;
        $old = $this->world->get(...$pos);
        $clickedVine = $catalog->blockName($old) === $name;
        $properties += array_map('strval', $catalog->properties($clickedVine ? $old : $catalog->state($name)));
        foreach ($looking as $direction) {
            if ($direction === 'down' || ($clickedVine && $properties[$direction] === 'true')) {
                continue;
            }
            // VineBlock.canSupportAtFace: the block that way, or the vine above holding that side.
            $above = $this->world->get($pos[0], $pos[1] + 1, $pos[2]);
            if ($this->placementSturdy($pos, $direction)
                || ($direction !== 'up' && $catalog->blockName($above) === $name && $catalog->properties($above)[$direction] === 'true')) {
                $properties[$direction] = 'true';
                return [$name, $properties];
            }
        }
        return $clickedVine ? [$name, $properties] : null;
    }

    /** MultifaceBlock.getStateForPlacement: the first looking direction it has no face on yet and can attach toward. */
    private function multifacePlacement(string $name, array $properties, array $pos, array $looking): ?array
    {
        $catalog = $this->version->catalog;
        $old = $this->world->get(...$pos);
        $properties += array_map('strval', $catalog->properties($catalog->blockName($old) === $name ? $old : $catalog->state($name)));
        foreach ($looking as $direction) {
            if (($properties[$direction] ?? 'true') !== 'true' && $this->placementSturdy($pos, $direction)) {
                $properties[$direction] = 'true';
                return [$name, $properties];
            }
        }
        return null;
    }
}
