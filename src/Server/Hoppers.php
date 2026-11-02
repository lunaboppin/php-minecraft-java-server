<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\World\Inventory;

/**
 * Hoppers (HopperBlock and HopperBlockEntity): every eight ticks an enabled hopper passes one
 * item into the container it faces and takes one from the container above it, or picks up the
 * items lying in its bowl. Redstone power disables it. Chests (both halves of a double chest),
 * barrels, furnaces and other hoppers are the containers it reaches.
 */
trait Hoppers
{
    /** Each container block's slot count, per block (a double chest has two). */
    private const HOPPER_CONTAINERS = ['chest' => 27, 'trapped_chest' => 27, 'barrel' => 27, 'furnace' => 3, 'blast_furnace' => 3, 'smoker' => 3, 'hopper' => 5, 'dispenser' => 9, 'dropper' => 9, 'brewing_stand' => 5];

    private const HOPPER_FACING = ['down' => [0, -1, 0], 'north' => [0, 0, -1], 'south' => [0, 0, 1], 'west' => [-1, 0, 0], 'east' => [1, 0, 0]];

    /** @var array<string, array<string, array{position: array{int, int, int}, cooldown: int, ticked: int}>> hoppers by dimension */
    private array $hoppers = [];

    /** The dimension's hoppers, found among the stored containers when first needed. */
    private function knownHoppers(): array
    {
        $dimension = $this->world->dimension;
        if (!isset($this->hoppers[$dimension])) {
            $this->hoppers[$dimension] = [];
            foreach ($this->world->containerKeys() as $key) {
                $position = array_map('intval', explode(':', $key));
                if ($this->version->catalog->blockName($this->world->get(...$position)) === 'hopper') {
                    $this->hoppers[$dimension][$key] = ['position' => $position, 'cooldown' => $this->world->container($position)['cooldown'] ?? -1, 'ticked' => 0];
                }
            }
        }
        return $this->hoppers[$dimension];
    }

    /** A hopper set where there was none gets its block entity, and HopperBlock.onPlace checks its power. */
    private function hopperChanged(int $x, int $y, int $z): void
    {
        $this->knownHoppers();
        $key = $x . ':' . $y . ':' . $z;
        $dimension = $this->world->dimension;
        if ($this->version->catalog->blockName($this->world->get($x, $y, $z)) !== 'hopper') {
            if (isset($this->hoppers[$dimension][$key])) {
                unset($this->hoppers[$dimension][$key]);
                $this->world->deleteContainer([$x, $y, $z]);
            }
            return;
        }
        if (!isset($this->hoppers[$dimension][$key])) {
            $this->hoppers[$dimension][$key] = ['position' => [$x, $y, $z], 'cooldown' => -1, 'ticked' => 0];
            $data = $this->world->container([$x, $y, $z]);
            $this->world->container([$x, $y, $z], ['slots' => $data['slots'], 'cooldown' => -1]);
            $this->checkHopperPowered([$x, $y, $z]);
        }
    }

    /** HopperBlock.checkPoweredState: a hopper is enabled while no neighbour powers it. */
    private function checkHopperPowered(array $position): void
    {
        $state = $this->world->get(...$position);
        $properties = $this->version->catalog->properties($state);
        $enabled = $this->bestNeighborSignal($position) === 0 ? 'true' : 'false';
        if ($properties['enabled'] !== $enabled) {
            $this->setBlock(...[...$position, $this->version->catalog->state('hopper', ['enabled' => $enabled] + $properties)]);
        }
    }

    /** HopperBlockEntity.pushItemsTick for each hopper, as block entities tick after the entities. */
    private function tickHoppers(): void
    {
        foreach (array_keys($this->knownHoppers()) as $key) {
            $position = $this->hoppers[$this->world->dimension][$key]['position'] ?? null;
            if ($position === null || !$this->mobIsTicking(['x' => $position[0] + 0.5, 'z' => $position[2] + 0.5])) {
                continue;
            }
            $hopper = &$this->hoppers[$this->world->dimension][$key];
            --$hopper['cooldown'];
            $hopper['ticked'] = $this->worldAge;
            if ($hopper['cooldown'] <= 0) {
                $hopper['cooldown'] = 0;
                unset($hopper);
                $this->tryMoveHopperItems($key, fn (): bool => $this->suckInItems($key));
            }
            unset($hopper);
        }
    }

    /** HopperBlockEntity.tryMoveItems: off cooldown and enabled, pass an item on, then take one in. */
    private function tryMoveHopperItems(string $key, callable $action): bool
    {
        $hopper = $this->hoppers[$this->world->dimension][$key] ?? null;
        if ($hopper === null || $hopper['cooldown'] > 0) {
            return false;
        }
        $state = $this->world->get(...$hopper['position']);
        if ($this->version->catalog->properties($state)['enabled'] !== 'true') {
            return false;
        }
        $changed = false;
        if ($this->world->container($hopper['position'])['slots'] !== []) {
            $changed = $this->ejectItems($key);
        }
        if (!$this->hopperFull($hopper['position'])) {
            $changed = $action() || $changed;
        }
        if ($changed) {
            $this->hoppers[$this->world->dimension][$key]['cooldown'] = 8;
            return true;
        }
        return false;
    }

    /** HopperBlockEntity.inventoryFull: every slot holds a full stack. */
    private function hopperFull(array $position): bool
    {
        $slots = $this->world->container($position)['slots'];
        for ($slot = 0; $slot < 5; ++$slot) {
            $stack = $slots[$slot] ?? null;
            if ($stack === null || $stack['count'] !== $this->version->catalog->item($stack['item'])['max_stack']) {
                return false;
            }
        }
        return true;
    }

    /** HopperBlockEntity.ejectItems: one item of the first stack that fits into the container the hopper faces. */
    private function ejectItems(string $key): bool
    {
        $position = $this->hoppers[$this->world->dimension][$key]['position'];
        $facing = $this->version->catalog->properties($this->world->get(...$position))['facing'];
        [$dx, $dy, $dz] = self::HOPPER_FACING[$facing];
        $target = $this->hopperContainer([$position[0] + $dx, $position[1] + $dy, $position[2] + $dz]);
        if ($target === null) {
            return false;
        }
        // The item goes in through the face that looks back at the hopper.
        $face = $facing === 'down' ? 'up' : ['north' => 'south', 'south' => 'north', 'west' => 'east', 'east' => 'west'][$facing];
        if ($this->containerFull($target, $face)) {
            return false;
        }
        $slots = $this->world->container($position)['slots'];
        for ($slot = 0; $slot < 5; ++$slot) {
            if (!isset($slots[$slot])) {
                continue;
            }
            if ($this->addToContainer($key, $target, array_replace($slots[$slot], ['count' => 1]), $face) === null) {
                $this->takeFromContainer($this->hopperContainer($position), $slot);
                return true;
            }
        }
        return false;
    }

    /**
     * HopperBlockEntity.suckInItems: one item from the container above, through its bottom; or,
     * unless a full block covers the hopper, the items lying in or above its bowl.
     */
    private function suckInItems(string $key): bool
    {
        [$x, $y, $z] = $this->hoppers[$this->world->dimension][$key]['position'];
        $source = $this->hopperContainer([$x, $y + 1, $z]);
        if ($source !== null) {
            $stacks = $this->containerStacks($source);
            foreach ($this->containerFaceSlots($source, 'down') as $slot) {
                $stack = $stacks[$slot] ?? null;
                if ($stack === null || !$this->canTakeFromContainer($source, $slot, $stack, 'down')) {
                    continue;
                }
                $from = $source['name'] === 'hopper' ? $x . ':' . ($y + 1) . ':' . $z : null;
                if ($this->addToContainer($from, $this->hopperContainer([$x, $y, $z]), array_replace($stack, ['count' => 1]), null) === null) {
                    $this->takeFromContainer($source, $slot);
                    return true;
                }
            }
            return false;
        }
        $above = $this->world->get($x, $y + 1, $z);
        $catalog = $this->version->catalog;
        $full = $catalog->collision['shapes'][$catalog->collision['states'][$above]] === [[0.0, 0.0, 0.0, 1.0, 1.0, 1.0]];
        if ($full && !in_array($catalog->blockName($above), ['beehive', 'bee_nest'], true)) {
            return false;
        }
        // Hopper.SUCK_AABB: the column from the bowl up to a block above the hopper.
        $box = [$x, $y + 0.6875, $z, $x + 1, $y + 2, $z + 1];
        foreach (array_keys($this->itemEntities) as $id) {
            $item = $this->itemEntities[$id] ?? null;
            if ($item === null || ($item['discard'] ?? false) || !self::itemInBox($item, $box)) {
                continue;
            }
            if ($this->hopperTakesItem($key, $id)) {
                return true;
            }
        }
        return false;
    }

    private static function itemInBox(array $item, array $box): bool
    {
        return $item['x'] + 0.125 > $box[0] && $item['x'] - 0.125 < $box[3]
            && $item['y'] + 0.25 > $box[1] && $item['y'] < $box[4]
            && $item['z'] + 0.125 > $box[2] && $item['z'] - 0.125 < $box[5];
    }

    /** HopperBlockEntity.addItem(Container, ItemEntity): as much of the stack as fits; the item goes once all of it is in. */
    private function hopperTakesItem(string $key, int $id): bool
    {
        $position = $this->hoppers[$this->world->dimension][$key]['position'];
        $left = $this->addToContainer(null, $this->hopperContainer($position), $this->itemEntities[$id]['stack'], null);
        if ($left === null) {
            $this->removeItem($id);
            return true;
        }
        // ItemEntity.setItem marks the stack changed whether or not any of it went in.
        $this->itemEntities[$id]['stack'] = $left;
        $this->itemEntities[$id]['dirty'] = true;
        return false;
    }

    /** HopperBlock.entityInside: an item in the hopper's block that reaches its bowl is taken in at once. */
    private function itemInsideHoppers(int $id): void
    {
        $item = $this->itemEntities[$id];
        $dimension = $this->world->dimension;
        for ($x = (int) floor($item['x'] - 0.125 + 1.0E-5); $x <= (int) floor($item['x'] + 0.125 - 1.0E-5); ++$x) {
            for ($y = (int) floor($item['y'] + 1.0E-5); $y <= (int) floor($item['y'] + 0.25 - 1.0E-5); ++$y) {
                for ($z = (int) floor($item['z'] - 0.125 + 1.0E-5); $z <= (int) floor($item['z'] + 0.125 - 1.0E-5); ++$z) {
                    $key = $x . ':' . $y . ':' . $z;
                    if (!isset($this->hoppers[$dimension][$key]) || !isset($this->itemEntities[$id]) || !self::itemInBox($this->itemEntities[$id], [$x, $y + 0.6875, $z, $x + 1, $y + 2, $z + 1])) {
                        continue;
                    }
                    $this->tryMoveHopperItems($key, fn (): bool => $this->hopperTakesItem($key, $id));
                }
            }
        }
    }

    /** HopperBlockEntity.getBlockContainer: the container block there, with a double chest's right half first. */
    private function hopperContainer(array $position): ?array
    {
        $state = $this->world->get(...$position);
        $name = $this->version->catalog->blockName($state);
        if ($name === 'composter') {
            // ComposterBlock.getContainer: an input below seven layers, the bone meal when ripe, else nothing.
            return ['name' => $name, 'parts' => [$position], 'size' => 1, 'level' => (int) $this->version->catalog->properties($state)['level']];
        }
        if (!isset(self::HOPPER_CONTAINERS[$name])) {
            return null;
        }
        $parts = [$position];
        if ($name === 'chest' || $name === 'trapped_chest') {
            $partner = $this->chestPartner($position);
            if ($partner !== null) {
                $parts = $this->version->catalog->properties($state)['type'] === 'left' ? [$partner, $position] : [$position, $partner];
            }
        }
        return ['name' => $name, 'parts' => $parts, 'size' => self::HOPPER_CONTAINERS[$name] * count($parts)];
    }

    /** @return array<int, array> the container's stacks by slot, across both halves of a double chest */
    private function containerStacks(array $container): array
    {
        if ($container['name'] === 'composter') {
            return $container['level'] === 8 ? [0 => ['item' => $this->version->blocks['items']['bone_meal'], 'count' => 1]] : [];
        }
        $stacks = [];
        foreach ($container['parts'] as $index => $position) {
            foreach ($this->world->container($position)['slots'] as $slot => $stack) {
                if ($stack !== null) {
                    $stacks[$index * self::HOPPER_CONTAINERS[$container['name']] + (int) $slot] = $stack;
                }
            }
        }
        return $stacks;
    }

    /** Store the container's stacks, and show them to anyone looking in. */
    private function storeContainerStacks(array $container, array $stacks): void
    {
        $size = self::HOPPER_CONTAINERS[$container['name']];
        foreach ($container['parts'] as $index => $position) {
            $data = $this->world->container($position);
            $data['slots'] = [];
            for ($slot = 0; $slot < $size; ++$slot) {
                if (isset($stacks[$index * $size + $slot])) {
                    $data['slots'][$slot] = $stacks[$index * $size + $slot];
                }
            }
            if ($container['name'] === 'hopper') {
                $data['cooldown'] = $this->hoppers[$this->world->dimension][implode(':', $position)]['cooldown'] ?? -1;
            }
            $this->world->container($position, $data);
            $this->syncContainer($position);
            $this->containerContentsChanged($position);
        }
    }

    private function takeFromContainer(array $container, int $slot): void
    {
        if ($container['name'] === 'composter') {
            // ComposterBlock.OutputContainer.setChanged: taking the bone meal empties it.
            $this->setBlock(...[...$container['parts'][0], $this->version->catalog->state('composter', ['level' => '0'])]);
            return;
        }
        $stacks = $this->containerStacks($container);
        if (--$stacks[$slot]['count'] <= 0) {
            unset($stacks[$slot]);
        }
        $this->storeContainerStacks($container, $stacks);
    }

    /** WorldlyContainer.getSlotsForFace: a furnace's input from above, fuel from the sides, result and fuel from below. */
    private function containerFaceSlots(array $container, ?string $face): array
    {
        if ($container['name'] === 'composter') {
            return ($container['level'] < 7 && $face === 'up') || ($container['level'] === 8 && $face === 'down') ? [0] : [];
        }
        if ($face !== null && $container['name'] === 'brewing_stand') {
            // BrewingStandBlockEntity.getSlotsForFace: the reagent from above, bottles and fuel from the sides, bottles and the reagent from below.
            return match ($face) {
                'up' => [3],
                'down' => [0, 1, 2, 3],
                default => [0, 1, 2, 4],
            };
        }
        if ($face !== null && $container['parts'] !== [] && isset(self::FURNACES[$container['name']])) {
            return match ($face) {
                'down' => [2, 1],
                'up' => [0],
                default => [1],
            };
        }
        return range(0, $container['size'] - 1);
    }

    /** HopperBlockEntity.isFullContainer: every slot reachable through the face holds a full stack. */
    private function containerFull(array $container, string $face): bool
    {
        $stacks = $this->containerStacks($container);
        foreach ($this->containerFaceSlots($container, $face) as $slot) {
            $stack = $stacks[$slot] ?? null;
            if ($stack === null || $stack['count'] < $this->version->catalog->item($stack['item'])['max_stack']) {
                return false;
            }
        }
        return true;
    }

    /** Container.canPlaceItem: a furnace takes nothing into its result and only fuel, or one bucket, as fuel. */
    private function canPlaceInContainer(array $container, array $stacks, int $slot, array $stack): bool
    {
        if ($container['name'] === 'brewing_stand') {
            return $this->brewingCanPlace($stacks, $slot, $stack);
        }
        if (!isset(self::FURNACES[$container['name']]) || $slot === 0) {
            return true;
        }
        if ($slot === 2) {
            return false;
        }
        $bucket = $this->version->blocks['items']['bucket'];
        return $this->fuelTicks($stack['item']) > 0 || ($stack['item'] === $bucket && ($stacks[1]['item'] ?? null) !== $bucket);
    }

    /** AbstractFurnaceBlockEntity.canTakeItemThroughFace: from below, only a bucket leaves the fuel slot. */
    private function canTakeFromContainer(array $container, int $slot, array $stack, string $face): bool
    {
        if ($container['name'] === 'brewing_stand') {
            // Only an emptied reagent's bottle leaves the reagent slot.
            return $slot !== 3 || $this->version->catalog->itemName($stack['item']) === 'glass_bottle';
        }
        if ($container['name'] === 'composter') {
            return $face === 'down' && $container['level'] === 8;
        }
        if (!isset(self::FURNACES[$container['name']]) || $face !== 'down' || $slot !== 1) {
            return true;
        }
        return in_array($this->version->catalog->itemName($stack['item']), ['bucket', 'water_bucket'], true);
    }

    /**
     * HopperBlockEntity.addItem and tryMoveInItem: the stack into the slots reachable through the
     * face in order, each empty slot taking it all and each matching one what it has room for.
     * A hopper that was empty waits its full cooldown, a tick less when the item came from a
     * hopper that has not ticked since it did. Returns what did not fit, or null.
     */
    private function addToContainer(?string $from, array $container, array $stack, ?string $face): ?array
    {
        if ($container['name'] === 'composter') {
            // ComposterBlock.InputContainer: one compostable item through the top, composted at once.
            if ($this->containerFaceSlots($container, $face) === [] || $this->compostLayers($stack['item'], $container['level']) === null) {
                return $stack;
            }
            $added = $this->addCompostLayer($container['parts'][0], $stack['item']);
            $this->levelEvent(1500, $container['parts'][0], $added ? 1 : 0);
            return $stack['count'] > 1 ? array_replace($stack, ['count' => $stack['count'] - 1]) : null;
        }
        $stacks = $this->containerStacks($container);
        $changed = false;
        foreach ($this->containerFaceSlots($container, $face) as $slot) {
            if ($stack === null) {
                break;
            }
            if (!$this->canPlaceInContainer($container, $stacks, $slot, $stack)) {
                continue;
            }
            $wasEmpty = $stacks === [];
            $success = false;
            if (!isset($stacks[$slot])) {
                $stacks[$slot] = $stack;
                $stack = null;
                $success = true;
            } elseif (Inventory::same($stacks[$slot], $stack, $this->version->catalog)) {
                $move = min($stack['count'], $this->version->catalog->item($stack['item'])['max_stack'] - $stacks[$slot]['count']);
                $stacks[$slot]['count'] += $move;
                $stack['count'] -= $move;
                if ($stack['count'] <= 0) {
                    $stack = null;
                }
                $success = $move > 0;
            }
            if ($success) {
                $changed = true;
                $key = implode(':', $container['parts'][0]);
                $target = $container['name'] === 'hopper' ? ($this->hoppers[$this->world->dimension][$key] ?? null) : null;
                if ($wasEmpty && $target !== null && $target['cooldown'] <= 8) {
                    $source = $from === null ? null : ($this->hoppers[$this->world->dimension][$from] ?? null);
                    $this->hoppers[$this->world->dimension][$key]['cooldown'] = 8 - ($source !== null && $target['ticked'] >= $source['ticked'] ? 1 : 0);
                }
            }
        }
        if ($changed) {
            $this->storeContainerStacks($container, $stacks);
        }
        return $stack;
    }
}
