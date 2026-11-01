<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Server\Tracking\EntityMovementTracker;
use PhpMinecraftJavaServer\World\Collision;

/**
 * FallingBlock and FallingBlockEntity: sand, gravel, concrete powder, anvils and the dragon egg
 * with nothing under them fall as entities and set down where they land, or drop as items when
 * they cannot. Suspicious sand and gravel fall too but break when they land. Anvils hurt what
 * they land on and may crack.
 */
trait FallingBlocks
{
    /** @var array<int, array> falling block entities by entity ID */
    private array $fallingBlocks = [];

    /** @var array<int, EntityMovementTracker> */
    private array $fallingTrackers = [];

    /** The block tick delay of a block that falls, or null for one that does not. */
    private function fallDelay(int $state): ?int
    {
        $name = $this->version->catalog->blockName($state);
        if ($name === 'suspicious_sand' || $name === 'suspicious_gravel') {
            // BrushableBlock.onPlace and updateShape.
            return 2;
        }
        $classes = $this->placementClasses($name);
        if (!in_array('FallingBlock', $classes, true)) {
            return null;
        }
        // DragonEggBlock.getDelayAfterPlace.
        return in_array('DragonEggBlock', $classes, true) ? 5 : 2;
    }

    /**
     * FallingBlock.onPlace and updateShape: a falling block, or one beside a changed block, checks
     * whether it should fall after its delay; concrete powder touching water sets.
     */
    private function fallingChanged(int $x, int $y, int $z): void
    {
        foreach ([[0, 0, 0], [0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]] as [$dx, $dy, $dz]) {
            [$a, $b, $c] = [$x + $dx, $y + $dy, $z + $dz];
            $state = $this->world->get($a, $b, $c);
            $delay = $this->fallDelay($state);
            if ($delay === null) {
                continue;
            }
            $name = $this->version->catalog->blockName($state);
            if ($dx . $dy . $dz !== '000' && str_ends_with($name, '_concrete_powder') && $this->touchesLiquid($a, $b, $c)) {
                // ConcretePowderBlock.updateShape.
                $this->setBlock($a, $b, $c, $this->version->catalog->state(substr($name, 0, -7)));
                continue;
            }
            $this->scheduleBlock('falling', $a, $b, $c, $delay);
        }
    }

    /** ConcretePowderBlock.touchesLiquid: water on any side but below, through a face it does not cover. */
    private function touchesLiquid(int $x, int $y, int $z): bool
    {
        $blocks = $this->fireBlocks();
        foreach ([[1, 0, 1, 0], [3, 0, 0, -1], [2, 0, 0, 1], [5, -1, 0, 0], [4, 1, 0, 0]] as [$face, $dx, $dy, $dz]) {
            $state = $this->world->get($x + $dx, $y + $dy, $z + $dz);
            // isFaceSturdy is asked of the water's state at the powder's own position, toward it.
            if ($blocks->isWater($state) && !$blocks->sturdy($state, $face)) {
                return true;
            }
        }
        return false;
    }

    /** FallingBlock.isFree: air, fire, a liquid or a replaceable block lets a block fall into it. */
    private function freeToFall(int $state): bool
    {
        $blocks = $this->fireBlocks();
        return $blocks->isAir($state) || $blocks->inTag($state, 'fire') || $blocks->replaceable($state)
            || in_array($this->version->catalog->blockName($state), ['water', 'lava'], true);
    }

    /** FallingBlock.tick and BrushableBlock.tick: a block with room below falls. */
    private function tickFallingBlock(int $x, int $y, int $z): void
    {
        $state = $this->world->get($x, $y, $z);
        if ($this->fallDelay($state) === null || !$this->freeToFall($this->world->get($x, $y - 1, $z)) || $y < $this->world->minY) {
            return;
        }
        $name = $this->version->catalog->blockName($state);
        $id = $this->startFalling($x, $y, $z, $state);
        if (str_ends_with($name, 'anvil')) {
            // AnvilBlock.falling.
            $this->fallingBlocks[$id]['hurt'] = [2.0, 40];
        } elseif ($name === 'suspicious_sand' || $name === 'suspicious_gravel') {
            // BrushableBlock.tick: it breaks wherever it lands.
            $this->fallingBlocks[$id]['cancelDrop'] = true;
        }
    }

    /** FallingBlockEntity.fall: the block leaves only its water behind and an entity takes its place. */
    private function startFalling(int $x, int $y, int $z, int $state): int
    {
        $catalog = $this->version->catalog;
        $properties = $catalog->properties($state);
        $waterlogged = ($properties['waterlogged'] ?? 'false') === 'true';
        if (isset($properties['waterlogged'])) {
            $properties['waterlogged'] = 'false';
            $state = $catalog->state($catalog->blockName($state), $properties);
        }
        $id = $this->addFallingBlock($x + 0.5, (float) $y, $z + 0.5, $state, [0.0, 0.0, 0.0]);
        $this->setBlock($x, $y, $z, $catalog->state($waterlogged ? 'water' : 'air'));
        foreach ($this->localPlayers() as $player) {
            $this->showFallingBlock($player, $id);
        }
        return $id;
    }

    private function addFallingBlock(float $x, float $y, float $z, int $state, array $velocity, array $saved = []): int
    {
        $id = $this->nextEntity++;
        $this->fallingBlocks[$id] = $saved + ['id' => $id, 'uuid' => random_bytes(16), 'x' => $x, 'y' => $y, 'z' => $z, 'velocity' => $velocity,
            'state' => $state, 'start' => [(int) floor($x), (int) floor($y), (int) floor($z)], 'time' => 0, 'fall' => 0.0, 'dropItem' => true,
            'cancelDrop' => false, 'hurt' => null, 'ground' => false, 'dirty' => true];
        $this->fallingBlocks[$id]['id'] = $id;
        return $id;
    }

    /** Falling blocks saved with the level fall on from where they were. */
    private function restoreFallingBlocks(): void
    {
        foreach ($this->world->setting('fallingBlocks') ?? [] as $saved) {
            if (!isset($saved['uuid'], $saved['x'], $saved['y'], $saved['z'], $saved['state'])) {
                continue;
            }
            $saved['uuid'] = hex2bin($saved['uuid']);
            $this->addFallingBlock(
                (float) $saved['x'],
                (float) $saved['y'],
                (float) $saved['z'],
                (int) $saved['state'],
                array_map('floatval', $saved['velocity'] ?? [0.0, 0.0, 0.0]),
                $saved
            );
        }
    }

    /** FallingBlockEntity.addAdditionalSaveData: the block, its time and how it drops and hurts. */
    private function savedFallingBlocks(): array
    {
        return array_values(array_map(static fn (array $block): array => ['uuid' => bin2hex($block['uuid'])]
            + array_intersect_key($block, array_flip(['x', 'y', 'z', 'velocity', 'state', 'start', 'time', 'fall', 'dropItem', 'cancelDrop', 'hurt'])), $this->fallingBlocks));
    }

    /**
     * ChunkMap.tick sends what changed before the entities tick; then FallingBlockEntity.tick:
     * gravity and movement, and on landing the block sets down, breaks or drops as an item.
     */
    private function tickFallingBlocks(): void
    {
        foreach (array_keys($this->fallingBlocks) as $id) {
            $this->synchronizeFallingBlock($id);
        }
        $collision = new Collision($this->version->catalog);
        foreach (array_keys($this->fallingBlocks) as $id) {
            if (!isset($this->fallingBlocks[$id])) {
                continue;
            }
            $block = &$this->fallingBlocks[$id];
            ++$block['time'];
            $block['velocity'][1] -= 0.04;
            $box = [$block['x'] - 0.49, $block['y'], $block['z'] - 0.49, $block['x'] + 0.49, $block['y'] + 0.98, $block['z'] + 0.49];
            $moved = $collision->move($this->world, $box, $block['velocity']);
            $block['ground'] = $moved[1] !== $block['velocity'][1] && $block['velocity'][1] < 0.0;
            $block['precise'] = $moved !== $block['velocity'];
            [$block['x'], $block['y'], $block['z']] = [$block['x'] + $moved[0], $block['y'] + $moved[1], $block['z'] + $moved[2]];
            foreach ([0, 1, 2] as $axis) {
                if ($moved[$axis] !== $block['velocity'][$axis]) {
                    $block['velocity'][$axis] = 0.0;
                }
            }
            $position = [(int) floor($block['x']), (int) floor($block['y']), (int) floor($block['z'])];
            $inWater = $this->fireBlocks()->isWater($this->world->get(...$position));
            // Entity.checkFallDamage: the distance fallen adds up, each move cast to float, out of water until it lands.
            if (!$inWater && $moved[1] < 0.0) {
                $block['fall'] -= \PhpMinecraftJavaServer\World\Generation\Float32::round($moved[1]);
            }
            $state = $block['state'];
            unset($block);
            if ($this->fallingBlocks[$id]['ground']) {
                if ($this->fallingBlocks[$id]['fall'] > 0.0) {
                    $this->fallingBlockFallOn($id, $position);
                }
                $this->fallingBlocks[$id]['fall'] = 0.0;
            }
            $stuckInWater = str_ends_with($this->version->catalog->blockName($state), '_concrete_powder') && $inWater;
            if (!$this->fallingBlocks[$id]['ground'] && !$stuckInWater) {
                $time = $this->fallingBlocks[$id]['time'];
                if ($time > 100 && ($position[1] <= $this->world->minY || $position[1] > $this->world->minY + $this->world->height - 1) || $time > 600) {
                    if ($this->fallingBlocks[$id]['dropItem'] && $this->gameRule('entity_drops') === 'true') {
                        $this->dropFallingBlockItem($id);
                    }
                    $this->removeFallingBlock($id);
                    continue;
                }
            } elseif ($this->landFallingBlock($id, $position, $stuckInWater)) {
                continue;
            }
            $block = &$this->fallingBlocks[$id];
            $block['velocity'] = [$block['velocity'][0] * 0.98, $block['velocity'][1] * 0.98, $block['velocity'][2] * 0.98];
            unset($block);
        }
    }

    /**
     * FallingBlockEntity's landing: into a replaceable block where it can stay, it sets down as a
     * block; otherwise it breaks, dropping itself as an item unless told not to. True once gone.
     */
    private function landFallingBlock(int $id, array $position, bool $stuckInWater): bool
    {
        $catalog = $this->version->catalog;
        $block = $this->fallingBlocks[$id];
        $this->fallingBlocks[$id]['velocity'] = [$block['velocity'][0] * 0.7, $block['velocity'][1] * -0.5, $block['velocity'][2] * 0.7];
        [$x, $y, $z] = $position;
        $current = $this->world->get($x, $y, $z);
        $name = $catalog->blockName($block['state']);
        if ($catalog->blockName($current) === 'moving_piston') {
            return false;
        }
        $drops = $block['dropItem'] && $this->gameRule('entity_drops') === 'true';
        if ($block['cancelDrop']) {
            $this->removeFallingBlock($id);
            $this->fallingBlockBroke($name, $position);
            return true;
        }
        $continues = $this->freeToFall($this->world->get($x, $y - 1, $z)) && !(str_ends_with($name, '_concrete_powder') && $stuckInWater);
        $survives = $this->fallingBlockSurvives($block['state'], $x, $y, $z) && !$continues;
        if (!$this->fireBlocks()->replaceable($current) || !$survives) {
            $this->removeFallingBlock($id);
            if ($drops) {
                $this->fallingBlockBroke($name, $position);
                $this->dropFallingBlockItem($id, $block);
            }
            return true;
        }
        $state = $block['state'];
        if (isset($catalog->properties($state)['waterlogged']) && $this->fireBlocks()->isWater($current)) {
            $state = $this->fireBlocks()->with($state, 'waterlogged', 'true');
        }
        $this->setBlock($x, $y, $z, $state);
        $payload = Binary::writePosition($x, $y, $z) . Binary::writeVarInt($this->world->get($x, $y, $z));
        foreach ($this->localPlayers() as $player) {
            if (isset($player->entities[$id])) {
                $player->send($this->version, 'block_update', $payload);
                $player->send($this->version, 'add_transient_block', $payload);
            }
        }
        $this->removeFallingBlock($id);
        // Fallable.onLand.
        if (str_ends_with($name, 'anvil')) {
            $this->levelEvent(1031, $position, 0);
        } elseif (str_ends_with($name, '_concrete_powder') && ($this->fireBlocks()->isWater($current) || $this->touchesLiquid($x, $y, $z))) {
            $this->setBlock($x, $y, $z, $catalog->state(substr($name, 0, -7)));
        }
        return true;
    }

    /** BlockState.canSurvive for the blocks that fall: those that need support check for it. */
    private function fallingBlockSurvives(int $state, int $x, int $y, int $z): bool
    {
        return $this->placementSurvives($state, $x, $y, $z);
    }

    /** Fallable.onBrokenAfterFall: an anvil that cannot set down sounds as it breaks. */
    private function fallingBlockBroke(string $name, array $position): void
    {
        if (str_ends_with($name, 'anvil')) {
            $this->levelEvent(1029, $position, 0);
        }
    }

    /** Entity.spawnAtLocation of the block's item at the entity's feet. */
    private function dropFallingBlockItem(int $id, ?array $block = null): void
    {
        $block ??= $this->fallingBlocks[$id];
        $name = $this->version->catalog->blockName($block['state']);
        $item = $this->version->catalog->items[$name]['id'] ?? null;
        if ($item !== null) {
            $this->spawnAtLocation($block['x'], $block['y'], $block['z'], ['item' => $item, 'count' => 1]);
        }
    }

    /**
     * Block.fallOn and FallingBlockEntity.causeFallDamage: a block set to hurt deals damage by the
     * distance fallen to the living things it lands in; an anvil may crack, or shatter entirely.
     */
    private function fallingBlockFallOn(int $id, array $position): void
    {
        $block = $this->fallingBlocks[$id];
        if ($block['hurt'] === null) {
            return;
        }
        $fall = $block['fall'];
        $below = $this->world->get($position[0], $position[1] - 1, $position[2]);
        $p = $this->version->catalog->properties($below);
        if ($this->fireBlocks()->inTag($below, 'speleothems') && ($p['vertical_direction'] ?? null) === 'up' && ($p['thickness'] ?? null) === 'tip') {
            // PointedDripstoneBlock.fallOn: a stalagmite's tip adds to the fall.
            $fall += 2.5;
        }
        $distance = (int) ceil($fall - 1.0);
        if ($distance < 0) {
            return;
        }
        [$perDistance, $max] = $block['hurt'];
        $amount = (float) min((int) floor($distance * $perDistance), $max);
        $name = $this->version->catalog->blockName($block['state']);
        $type = str_ends_with($name, 'anvil') ? 'minecraft:falling_anvil' : (in_array($name, ['pointed_dripstone'], true) ? 'minecraft:falling_stalactite' : 'minecraft:falling_block');
        $box = [$block['x'] - 0.49, $block['y'], $block['z'] - 0.49, $block['x'] + 0.49, $block['y'] + 0.98, $block['z'] + 0.49];
        $overlaps = static fn (array $other): bool => $other[0] < $box[3] && $other[3] > $box[0] && $other[1] < $box[4] && $other[4] > $box[1] && $other[2] < $box[5] && $other[5] > $box[2];
        foreach ($this->localPlayers() as $player) {
            if (!$player->dead && $player->gameMode !== 1 && $player->gameMode !== 3
                && $overlaps([$player->x - 0.3, $player->y, $player->z - 0.3, $player->x + 0.3, $player->y + 1.8, $player->z + 0.3])) {
                $this->damage($player, $amount, $type);
            }
        }
        foreach (array_keys($this->animals) as $mobId) {
            $mob = $this->animals[$mobId] ?? null;
            if ($mob === null || isset($mob['deadAt'])) {
                continue;
            }
            $info = $this->version->catalog->mobs['entities'][$mob['kind']];
            $half = $info['width'] / 2.0;
            if ($overlaps([$mob['x'] - $half, $mob['y'], $mob['z'] - $half, $mob['x'] + $half, $mob['y'] + $info['height'], $mob['z'] + $half])) {
                $this->hurtMob($mobId, $amount, $type);
            }
        }
        if (str_ends_with($name, 'anvil') && $amount > 0.0 && self::randomUnit() < 0.05 + $distance * 0.05) {
            // AnvilBlock.damage: anvil, chipped, damaged, then gone.
            $next = ['anvil' => 'chipped_anvil', 'chipped_anvil' => 'damaged_anvil'][$name] ?? null;
            if ($next === null) {
                $this->fallingBlocks[$id]['cancelDrop'] = true;
            } else {
                $this->fallingBlocks[$id]['state'] = $this->version->catalog->state($next, $this->version->catalog->properties($block['state']));
            }
        }
    }

    private function removeFallingBlock(int $id): void
    {
        unset($this->fallingBlocks[$id], $this->fallingTrackers[$id]);
        foreach ($this->localPlayers() as $player) {
            $this->forgetEntity($player, $id);
        }
    }

    private function fallingTrackerState(array $block): array
    {
        return ['position' => [$block['x'], $block['y'], $block['z']], 'velocity' => $block['velocity'], 'yaw' => 0.0, 'pitch' => 0.0, 'head' => 0.0,
            'ground' => $block['ground'], 'needs_sync' => false, 'sync_velocity' => false, 'sync_position' => false, 'precise' => $block['precise'] ?? false];
    }

    /** The start position, FallingBlockEntity's only tracked data: index 8, a block position. */
    private function fallingBlockData(array $block): string
    {
        return Binary::writeVarInt($block['id']) . "\x08\x0a" . Binary::writePosition(...$block['start']) . "\xff";
    }

    /** ServerEntity.sendChanges for a falling block, every 20 ticks, with its start position once more after it spawns. */
    private function synchronizeFallingBlock(int $id): void
    {
        $block = $this->fallingBlocks[$id];
        foreach ($this->localPlayers() as $player) {
            if (!isset($player->entities[$id])) {
                $this->showFallingBlock($player, $id);
            } elseif (!$this->tracksPosition($player, $block['x'], $block['z'], 'falling_block')) {
                $this->forgetEntity($player, $id);
            }
        }
        $state = $this->fallingTrackerState($block);
        $tracker = $this->fallingTrackers[$id] ??= new EntityMovementTracker($id, 20, true, $state, false);
        $packets = $tracker->update($state);
        if ($block['dirty']) {
            $this->fallingBlocks[$id]['dirty'] = false;
            $packets[] = ['name' => 'set_entity_data', 'payload' => $this->fallingBlockData($block)];
        }
        foreach ($packets as $packet) {
            foreach ($this->localPlayers() as $player) {
                if (isset($player->entities[$id])) {
                    $player->send($this->version, $packet['name'], $packet['payload']);
                }
            }
        }
    }

    /** ServerEntity.sendPairingData: the spawn, carrying the block state, and the start position, bundled. */
    private function showFallingBlock(Client $player, int $id): bool
    {
        $block = $this->fallingBlocks[$id];
        if (isset($player->entities[$id]) || !$this->tracksPosition($player, $block['x'], $block['z'], 'falling_block')) {
            return isset($player->entities[$id]);
        }
        $tracker = $this->fallingTrackers[$id] ??= new EntityMovementTracker($id, 20, true, $this->fallingTrackerState($block), false);
        $player->send($this->version, 'bundle_delimiter', '');
        $player->send($this->version, 'add_entity', $tracker->spawnPacket($block['uuid'], $this->version->blocks['entity_types']['falling_block'], $block['state']));
        $player->send($this->version, 'set_entity_data', $this->fallingBlockData($block));
        $player->send($this->version, 'bundle_delimiter', '');
        $player->entities[$id] = true;
        return true;
    }
}
