<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\Collision;

/**
 * PistonBaseBlock, PistonStructureResolver, PistonMovingBlockEntity and PistonHeadBlock: a
 * powered piston queues a block event, which pushes up to twelve blocks (dragging what slime and
 * honey hold, breaking what pops) into moving_piston blocks that land two ticks later; an
 * unpowered one retracts, a sticky one pulling the block in front back with it.
 */
trait Pistons
{
    /** @var array<string, array<string, array>> moving pistons by dimension and "x:y:z" */
    private array $movingPistons = [];

    private ?array $pistonData = null;

    /** ServerLevel.isHandlingTick: true while scheduled block ticks run. */
    private bool $handlingBlockTicks = false;

    /** Direction.values() order: offset by name. */
    private const PISTON_DIRECTIONS = [
        'down' => [0, -1, 0], 'up' => [0, 1, 0], 'north' => [0, 0, -1], 'south' => [0, 0, 1], 'west' => [-1, 0, 0], 'east' => [1, 0, 0],
    ];

    private const PISTON_OPPOSITE = ['down' => 'up', 'up' => 'down', 'north' => 'south', 'south' => 'north', 'west' => 'east', 'east' => 'west'];

    /** PushReaction other than push_pull by state, unbreakable states and blocks with block entities. */
    private function pistonData(): array
    {
        if ($this->pistonData === null) {
            $behaviour = json_decode((string) file_get_contents($this->version->directory . '/worldgen/block-behaviour.json'), true, 512, JSON_THROW_ON_ERROR);
            $data = $behaviour['piston'];
            $this->pistonData = [
                'reactions' => $data['push_reactions'],
                'unbreakable' => array_flip($data['unbreakable']),
                'entities' => array_flip($data['block_entities']),
            ];
        }
        return $this->pistonData;
    }

    private function pushReaction(int $state): string
    {
        return $this->pistonData()['reactions'][(string) $state] ?? 'push_pull';
    }

    private static function pistonStep(array $pos, string $direction, int $distance = 1): array
    {
        [$dx, $dy, $dz] = self::PISTON_DIRECTIONS[$direction];
        return [$pos[0] + $dx * $distance, $pos[1] + $dy * $distance, $pos[2] + $dz * $distance];
    }

    private function isPistonBase(string $name): bool
    {
        return $name === 'piston' || $name === 'sticky_piston';
    }

    /**
     * PistonBaseBlock.getNeighborSignal: power from any side but the face, or (quasi-connectivity)
     * into the block above from any side but the piston itself.
     */
    private function pistonPowered(array $pos, string $facing): bool
    {
        [$x, $y, $z] = $pos;
        return $this->powerAt($x, $y, $z, true, self::PISTON_DIRECTIONS[$facing]) > 0 || $this->powerAt($x, $y + 1, $z, true, [0, -1, 0]) > 0;
    }

    /** neighborChanged, onPlace and setPlacedBy: pistons at and beside a changed block check whether to move. */
    private function pistonsChanged(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        $seen = [];
        $check = function (int $a, int $b, int $c) use ($catalog, &$seen): void {
            $key = $a . ':' . $b . ':' . $c;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            if ($this->isPistonBase($catalog->blockName($this->world->get($a, $b, $c)))) {
                $this->checkIfExtend([$a, $b, $c]);
            }
        };
        $check($x, $y, $z);
        foreach (self::PISTON_DIRECTIONS as [$dx, $dy, $dz]) {
            [$a, $b, $c] = [$x + $dx, $y + $dy, $z + $dz];
            $check($a, $b, $c);
            // Power passes through one opaque block to a piston on its far side.
            if ($catalog->blocks[$catalog->blockName($this->world->get($a, $b, $c))]['full_cube']) {
                foreach (self::PISTON_DIRECTIONS as [$ex, $ey, $ez]) {
                    $check($a + $ex, $b + $ey, $c + $ez);
                }
            }
        }
        $this->pistonHeadsChanged($x, $y, $z);
    }

    /** PistonBaseBlock.checkIfExtend: queue the extend or retract block event. */
    private function checkIfExtend(array $pos): void
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get(...$pos);
        $name = $catalog->blockName($state);
        $p = $catalog->properties($state);
        $facing = $p['facing'];
        $data = array_search($facing, array_keys(self::PISTON_DIRECTIONS), true);
        $extend = $this->pistonPowered($pos, $facing);
        if ($extend && $p['extended'] === 'false') {
            if ($this->resolvePistonStructure($pos, $facing, true) !== null) {
                $this->queueBlockEvent($pos, $name, 0, $data);
            }
        } elseif (!$extend && $p['extended'] === 'true') {
            $pushed = self::pistonStep($pos, $facing, 2);
            $event = 1;
            $moving = $this->movingPistons[$this->world->dimension][implode(':', $pushed)] ?? null;
            if ($catalog->blockName($this->world->get(...$pushed)) === 'moving_piston' && $moving !== null && $moving['direction'] === $facing && $moving['extending']
                && ($moving['progress'] < 0.5 || $moving['lastTicked'] === $this->worldAge || $this->handlingBlockTicks)) {
                $event = 2;
            }
            $this->queueBlockEvent($pos, $name, $event, $data);
        }
    }

    private function queueBlockEvent(array $pos, string $name, int $type, int $data): void
    {
        $event = [$pos, $name, $type, $data];
        if (!in_array($event, $this->blockEvents[$this->world->dimension] ?? [], true)) {
            $this->blockEvents[$this->world->dimension][] = $event;
        }
    }

    /** PistonBaseBlock.isPushable. */
    private function pistonPushable(int $state, array $pos, string $direction, bool $allowDestroyable, string $connection): bool
    {
        $catalog = $this->version->catalog;
        [$minY, $maxY] = [$this->world->minY, $this->world->minY + $this->world->height - 1];
        if ($pos[1] < $minY || $pos[1] > $maxY || !$this->border($this->world->dimension)->contains($pos[0] + 0.5, $pos[2] + 0.5, $this->worldAge)) {
            return false;
        }
        if ($catalog->air($state)) {
            return true;
        }
        if (($direction === 'down' && $pos[1] === $minY) || ($direction === 'up' && $pos[1] === $maxY)) {
            return false;
        }
        $name = $catalog->blockName($state);
        if ($this->isPistonBase($name)) {
            return $catalog->properties($state)['extended'] !== 'true';
        }
        if (isset($this->pistonData()['unbreakable'][$state])) {
            return false;
        }
        switch ($this->pushReaction($state)) {
            case 'immoveable':
                return false;
            case 'popped':
                return $allowDestroyable;
            case 'push':
                return $direction === $connection;
        }
        return !isset($this->pistonData()['entities'][$name]);
    }

    private function pistonSticky(int $state): bool
    {
        $name = $this->version->catalog->blockName($state);
        return $name === 'slime_block' || $name === 'honey_block';
    }

    /** PistonStructureResolver.canStickToEachOther: slime and honey never hold each other. */
    private function pistonSticksTo(int $first, int $second): bool
    {
        $a = $this->version->catalog->blockName($first);
        $b = $this->version->catalog->blockName($second);
        if (($a === 'honey_block' && $b === 'slime_block') || ($a === 'slime_block' && $b === 'honey_block')) {
            return false;
        }
        return $this->pistonSticky($first) || $this->pistonSticky($second);
    }

    /**
     * PistonStructureResolver.resolve: the blocks to push, in order, and those to break, or null
     * when the structure cannot move.
     *
     * @return array{push: list<array{int, int, int}>, destroy: list<array{int, int, int}>, direction: string}|null
     */
    private function resolvePistonStructure(array $piston, string $facing, bool $extending): ?array
    {
        $resolver = new \stdClass();
        $resolver->piston = $piston;
        $resolver->facing = $facing;
        $resolver->direction = $extending ? $facing : self::PISTON_OPPOSITE[$facing];
        $resolver->push = [];
        $resolver->destroy = [];
        $start = $extending ? self::pistonStep($piston, $facing) : self::pistonStep($piston, $facing, 2);
        $state = $this->world->get(...$start);
        if (!$this->pistonPushable($state, $start, $resolver->direction, false, $facing)) {
            if ($extending && $this->pushReaction($state) === 'popped') {
                $resolver->destroy[] = $start;
                return ['push' => [], 'destroy' => $resolver->destroy, 'direction' => $resolver->direction];
            }
            return null;
        }
        if (!$this->addPistonLine($resolver, $start, $resolver->direction)) {
            return null;
        }
        for ($i = 0; $i < count($resolver->push); ++$i) {
            $pos = $resolver->push[$i];
            if ($this->pistonSticky($this->world->get(...$pos)) && !$this->addPistonBranches($resolver, $pos)) {
                return null;
            }
        }
        return ['push' => $resolver->push, 'destroy' => $resolver->destroy, 'direction' => $resolver->direction];
    }

    /** PistonStructureResolver.addBlockLine. */
    private function addPistonLine(\stdClass $r, array $start, string $direction): bool
    {
        $back = self::PISTON_OPPOSITE[$r->direction];
        $next = $this->world->get(...$start);
        if ($this->version->catalog->air($next) || !$this->pistonPushable($next, $start, $r->direction, false, $direction)
            || $start === $r->piston || in_array($start, $r->push, true)) {
            return true;
        }
        $count = 1;
        if ($count + count($r->push) > 12) {
            return false;
        }
        while ($this->pistonSticky($next)) {
            $pos = self::pistonStep($start, $back, $count);
            $previous = $next;
            $next = $this->world->get(...$pos);
            if ($this->version->catalog->air($next) || !$this->pistonSticksTo($previous, $next)
                || !$this->pistonPushable($next, $pos, $r->direction, false, $back) || $pos === $r->piston) {
                break;
            }
            if (++$count + count($r->push) > 12) {
                return false;
            }
        }
        $added = 0;
        for ($i = $count - 1; $i >= 0; --$i) {
            $r->push[] = self::pistonStep($start, $back, $i);
            ++$added;
        }
        for ($i = 1; ; ++$i) {
            $pos = self::pistonStep($start, $r->direction, $i);
            $collision = array_search($pos, $r->push, true);
            if ($collision !== false) {
                // reorderListAtCollision: the line just added goes before the blocks it ran into.
                $head = array_slice($r->push, 0, $collision);
                $last = array_slice($r->push, count($r->push) - $added);
                $between = array_slice($r->push, $collision, count($r->push) - $added - $collision);
                $r->push = array_merge($head, $last, $between);
                for ($j = 0; $j <= $collision + $added; ++$j) {
                    $block = $r->push[$j];
                    if ($this->pistonSticky($this->world->get(...$block)) && !$this->addPistonBranches($r, $block)) {
                        return false;
                    }
                }
                return true;
            }
            $next = $this->world->get(...$pos);
            if ($this->version->catalog->air($next)) {
                return true;
            }
            if (!$this->pistonPushable($next, $pos, $r->direction, true, $r->direction) || $pos === $r->piston) {
                return false;
            }
            if ($this->pushReaction($next) === 'popped') {
                $r->destroy[] = $pos;
                return true;
            }
            if (count($r->push) >= 12) {
                return false;
            }
            $r->push[] = $pos;
            ++$added;
        }
    }

    /** PistonStructureResolver.addBranchingBlocks: what slime or honey holds on the sides. */
    private function addPistonBranches(\stdClass $r, array $from): bool
    {
        $fromState = $this->world->get(...$from);
        $axis = static fn (string $d): string => match ($d) {
            'down', 'up' => 'y',
            'north', 'south' => 'z',
            default => 'x',
        };
        foreach (array_keys(self::PISTON_DIRECTIONS) as $direction) {
            if ($axis($direction) === $axis($r->direction)) {
                continue;
            }
            $neighbour = self::pistonStep($from, $direction);
            if ($this->pistonSticksTo($this->world->get(...$neighbour), $fromState) && !$this->addPistonLine($r, $neighbour, $direction)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Level.setBlock without UPDATE_CLIENTS (flags 324 and 276): the block changes and its
     * neighbours react, but clients are not told; they move it themselves from the block event.
     */
    private function setBlockUnseen(array $pos, int $state): void
    {
        [$x, $y, $z] = $pos;
        if ($this->world->get($x, $y, $z) === $state) {
            return;
        }
        $this->world->set($x, $y, $z, $state);
        $this->changedSimulation($x, $y, $z);
        $this->relightAround($x, $z);
    }

    /** A moving_piston block, unseen by clients, and its PistonMovingBlockEntity. */
    private function setMovingPiston(array $pos, string $facing, ?string $type, int $moved, bool $extending, bool $source): void
    {
        $properties = ['facing' => $facing, 'type' => $type ?? 'normal'];
        $this->setBlockUnseen($pos, $this->version->catalog->state('moving_piston', $properties));
        $this->movingPistons[$this->world->dimension][implode(':', $pos)] = ['position' => $pos, 'moved' => $moved, 'direction' => $facing,
            'extending' => $extending, 'source' => $source, 'progress' => 0.0, 'progressO' => 0.0, 'lastTicked' => -1];
        $this->refreshMovingShape(implode(':', $pos));
    }

    /** PistonMovingBlockEntity.getCollisionShape, kept on the world for Collision. */
    private function refreshMovingShape(string $key): void
    {
        $entity = $this->movingPistons[$this->world->dimension][$key] ?? null;
        if ($entity === null) {
            unset($this->world->movingShapes[$key]);
            return;
        }
        $catalog = $this->version->catalog;
        $collision = new Collision($catalog);
        $movedName = $catalog->blockName($entity['moved']);
        $head = [];
        if (!$entity['extending'] && $entity['source'] && $this->isPistonBase($movedName)) {
            $head = $collision->shape($catalog->state($movedName, ['extended' => 'true'] + $catalog->properties($entity['moved'])));
        }
        $state = $entity['source']
            ? $catalog->state('piston_head', ['facing' => $entity['direction'], 'short' => $entity['extending'] !== (1.0 - $entity['progress'] < 0.25) ? 'true' : 'false', 'type' => 'normal'])
            : $entity['moved'];
        $current = $entity['extending'] ? $entity['progress'] - 1.0 : 1.0 - $entity['progress'];
        [$dx, $dy, $dz] = array_map(static fn (int $step): float => $step * $current, self::PISTON_DIRECTIONS[$entity['direction']]);
        $shape = $head;
        foreach ($collision->shape($state) as $box) {
            $shape[] = [$box[0] + $dx, $box[1] + $dy, $box[2] + $dz, $box[3] + $dx, $box[4] + $dy, $box[5] + $dz];
        }
        $this->world->movingShapes[$key] = ['shape' => $shape, 'noclip' => $head,
            'movement' => $entity['progress'] < 1.0 ? ($entity['extending'] ? $entity['direction'] : self::PISTON_OPPOSITE[$entity['direction']]) : null];
    }

    /** PistonBaseBlock.moveBlocks. */
    private function movePistonBlocks(array $piston, string $facing, bool $extending, bool $sticky): bool
    {
        $catalog = $this->version->catalog;
        $arm = self::pistonStep($piston, $facing);
        if (!$extending && $catalog->blockName($this->world->get(...$arm)) === 'piston_head') {
            $this->setBlockUnseen($arm, $catalog->state('air'));
        }
        $structure = $this->resolvePistonStructure($piston, $facing, $extending);
        if ($structure === null) {
            return false;
        }
        $direction = $structure['direction'];
        $deleteAfterMove = [];
        $states = [];
        foreach ($structure['push'] as $i => $pos) {
            $states[$i] = $this->world->get(...$pos);
            $deleteAfterMove[implode(':', $pos)] = $pos;
        }
        foreach (array_reverse($structure['destroy']) as $pos) {
            $state = $this->world->get(...$pos);
            $this->dropBlockResources($pos[0], $pos[1], $pos[2], $state);
            $this->setBlock(...[...$pos, $catalog->state('air')]);
        }
        for ($i = count($structure['push']) - 1; $i >= 0; --$i) {
            $target = self::pistonStep($structure['push'][$i], $direction);
            unset($deleteAfterMove[implode(':', $target)]);
            $this->setMovingPiston($target, $facing, null, $states[$i], $extending, false);
        }
        if ($extending) {
            $type = $sticky ? 'sticky' : 'normal';
            unset($deleteAfterMove[implode(':', $arm)]);
            $this->setMovingPiston($arm, $facing, $type, $catalog->state('piston_head', ['facing' => $facing, 'short' => 'false', 'type' => $type]), true, true);
        }
        foreach ($deleteAfterMove as $pos) {
            $this->setBlock(...[...$pos, $catalog->state('air')]);
        }
        return true;
    }

    private function pistonSound(array $pos, string $sound, float $pitch): void
    {
        $payload = Packets::sound($this->version->blocks['sounds'][$sound], 4, $pos[0] + 0.5, $pos[1] + 0.5, $pos[2] + 0.5, 0.5, $pitch, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $pos[0] - 0.5) ** 2 + ($player->y - $pos[1] - 0.5) ** 2 + ($player->z - $pos[2] - 0.5) ** 2 < 256.0) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    /**
     * PistonBaseBlock.triggerEvent: true when the event happened, so its block_event is sent.
     * Extending moves the blocks and the head out; retracting turns the base into a moving
     * piston, and a sticky one pulls back the block two ahead if it can be pulled.
     */
    private function pistonEvent(array $pos, string $name, int $type, int $data): bool
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get(...$pos);
        $p = $catalog->properties($state);
        $facing = $p['facing'];
        $extendedState = $catalog->state($name, ['extended' => 'true'] + $p);
        $sticky = $name === 'sticky_piston';
        $extend = $this->pistonPowered($pos, $facing);
        if ($extend && ($type === 1 || $type === 2)) {
            $this->setBlock(...[...$pos, $extendedState]);
            return false;
        }
        if (!$extend && $type === 0) {
            return false;
        }
        if ($type === 0) {
            if (!$this->movePistonBlocks($pos, $facing, true, $sticky)) {
                return false;
            }
            $this->setBlock(...[...$pos, $extendedState]);
            $this->pistonSound($pos, 'block.piston.extend', self::randomUnit() * 0.25 + 0.6);
            return true;
        }
        $this->finishMovingPiston(self::pistonStep($pos, $facing));
        $base = $catalog->state($name, ['facing' => array_keys(self::PISTON_DIRECTIONS)[$data & 7], 'extended' => 'false']);
        $this->setMovingPiston($pos, $facing, $sticky ? 'sticky' : 'normal', $base, false, true);
        if ($sticky) {
            $two = self::pistonStep($pos, $facing, 2);
            $moving = $this->world->get(...$two);
            $entity = $this->movingPistons[$this->world->dimension][implode(':', $two)] ?? null;
            $pistonPiece = false;
            if ($catalog->blockName($moving) === 'moving_piston' && $entity !== null && $entity['direction'] === $facing && $entity['extending']) {
                $this->finishMovingPiston($two);
                $pistonPiece = true;
            }
            if (!$pistonPiece) {
                $movingName = $catalog->blockName($moving);
                if ($type !== 1 || $catalog->air($moving) || !$this->pistonPushable($moving, $two, self::PISTON_OPPOSITE[$facing], false, $facing)
                    || ($this->pushReaction($moving) !== 'push_pull' && !$this->isPistonBase($movingName))) {
                    $this->setBlock(...[...self::pistonStep($pos, $facing), $catalog->state('air')]);
                } else {
                    $this->movePistonBlocks($pos, $facing, false, true);
                }
            }
        } else {
            $this->setBlock(...[...self::pistonStep($pos, $facing), $catalog->state('air')]);
        }
        $this->pistonSound($pos, 'block.piston.contract', self::randomUnit() * 0.15 + 0.6);
        return true;
    }

    /** PistonMovingBlockEntity.finalTick: a moving block lands at once. */
    private function finishMovingPiston(array $pos): void
    {
        $key = implode(':', $pos);
        $entity = $this->movingPistons[$this->world->dimension][$key] ?? null;
        if ($entity === null || $entity['progressO'] >= 1.0) {
            return;
        }
        unset($this->movingPistons[$this->world->dimension][$key], $this->world->movingShapes[$key]);
        if ($this->version->catalog->blockName($this->world->get(...$pos)) === 'moving_piston') {
            $state = $entity['source'] ? $this->version->catalog->state('air') : $this->connectedState($entity['moved'], ...$pos);
            $this->setBlock(...[...$pos, $state]);
        }
    }

    /**
     * PistonMovingBlockEntity.tick for every moving piston: half way each tick, and the tick after
     * it arrives the moved block takes its place, shaped by its new neighbours.
     */
    private function tickMovingPistons(): void
    {
        $catalog = $this->version->catalog;
        foreach (array_keys($this->movingPistons[$this->world->dimension] ?? []) as $key) {
            $entity = &$this->movingPistons[$this->world->dimension][$key];
            if ($entity === null) {
                unset($this->movingPistons[$this->world->dimension][$key], $this->world->movingShapes[$key]);
                continue;
            }
            $entity['lastTicked'] = $this->worldAge;
            $entity['progressO'] = $entity['progress'];
            if ($entity['progressO'] < 1.0) {
                $this->pistonMoveEntities($entity, $entity['progress'] + 0.5);
                $entity['progress'] = min(1.0, $entity['progress'] + 0.5);
                unset($entity);
                $this->refreshMovingShape($key);
                continue;
            }
            $pos = $entity['position'];
            $moved = $entity['moved'];
            unset($entity, $this->movingPistons[$this->world->dimension][$key], $this->world->movingShapes[$key]);
            if ($catalog->blockName($this->world->get(...$pos)) !== 'moving_piston') {
                continue;
            }
            $state = $this->connectedState($moved, ...$pos);
            if (($catalog->properties($state)['waterlogged'] ?? 'false') === 'true') {
                $state = $catalog->state($catalog->blockName($state), ['waterlogged' => 'false'] + $catalog->properties($state));
            }
            if ($this->placementSurvives($state, ...$pos)) {
                $this->setBlock(...[...$pos, $state]);
            } else {
                // Block.updateOrDestroy: a block that cannot stay where it landed breaks.
                $this->dropBlockResources($pos[0], $pos[1], $pos[2], $state);
                $this->setBlock(...[...$pos, $catalog->state('air')]);
            }
        }
    }

    /** Moving pistons saved with the level carry on from where they were. */
    private function restoreMovingPistons(): void
    {
        foreach ($this->world->setting('movingPistons') ?? [] as $entity) {
            $this->movingPistons[$this->world->dimension][implode(':', $entity['position'])] = $entity;
            $this->refreshMovingShape(implode(':', $entity['position']));
        }
    }

    private function saveMovingPistons(): void
    {
        $this->world->setting('movingPistons', array_values($this->movingPistons[$this->world->dimension] ?? []));
    }

    /**
     * PistonHeadBlock: a head with no extended base of its kind behind it goes; an extended base
     * whose head has gone breaks, dropping itself.
     */
    private function pistonHeadsChanged(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        foreach ([[0, 0, 0], ...array_values(self::PISTON_DIRECTIONS)] as [$dx, $dy, $dz]) {
            $pos = [$x + $dx, $y + $dy, $z + $dz];
            $state = $this->world->get(...$pos);
            $name = $catalog->blockName($state);
            if ($name === 'piston_head') {
                $p = $catalog->properties($state);
                $base = $this->world->get(...self::pistonStep($pos, self::PISTON_OPPOSITE[$p['facing']]));
                $baseName = $catalog->blockName($base);
                $fits = $this->isPistonBase($baseName) && ($baseName === 'sticky_piston') === ($p['type'] === 'sticky')
                    && $catalog->properties($base)['facing'] === $p['facing'] && $catalog->properties($base)['extended'] === 'true';
                if (!$fits && $baseName !== 'moving_piston') {
                    $this->setBlock(...[...$pos, $catalog->state('air')]);
                }
            } elseif ($this->isPistonBase($name) && $catalog->properties($state)['extended'] === 'true') {
                $front = self::pistonStep($pos, $catalog->properties($state)['facing']);
                $frontName = $catalog->blockName($this->world->get(...$front));
                if ($frontName !== 'piston_head' && $frontName !== 'moving_piston') {
                    $this->levelEvent(2001, $pos, $state);
                    $this->dropBlockResources($pos[0], $pos[1], $pos[2], $state);
                    $this->setBlock(...[...$pos, $catalog->state('air')]);
                }
            }
        }
    }

    /** Entities a moving block pushes: the property holding them, and their half width and height. */
    private const PISTON_PUSHED = ['itemEntities' => [0.125, 0.25], 'experienceOrbs' => [0.25, 0.5], 'tntEntities' => [0.49, 0.98], 'fallingBlocks' => [0.49, 0.98]];

    /** @var array<string, array{int, array<int, float>}> pistonDeltasGameTime and pistonDeltas by "property:id" */
    private array $pistonDeltas = [];

    private static function pistonBox(array $entity, array $size): array
    {
        return [$entity['x'] - $size[0], $entity['y'], $entity['z'] - $size[0], $entity['x'] + $size[0], $entity['y'] + $size[1], $entity['z'] + $size[0]];
    }

    private static function boxesIntersect(array $a, array $b): bool
    {
        return $a[0] < $b[3] && $a[3] > $b[0] && $a[1] < $b[4] && $a[4] > $b[1] && $a[2] < $b[5] && $a[5] > $b[2];
    }

    /** PistonMath.getMovementArea. */
    private static function pistonMovementArea(array $box, string $direction, float $amount): array
    {
        $delta = $amount * array_sum(self::PISTON_DIRECTIONS[$direction]);
        [$min, $max] = [min($delta, 0.0), max($delta, 0.0)];
        return match ($direction) {
            'west' => [$box[0] + $min, $box[1], $box[2], $box[0] + $max, $box[4], $box[5]],
            'east' => [$box[3] + $min, $box[1], $box[2], $box[3] + $max, $box[4], $box[5]],
            'down' => [$box[0], $box[1] + $min, $box[2], $box[3], $box[1] + $max, $box[5]],
            'up' => [$box[0], $box[4] + $min, $box[2], $box[3], $box[4] + $max, $box[5]],
            'north' => [$box[0], $box[1], $box[2] + $min, $box[3], $box[4], $box[2] + $max],
            'south' => [$box[0], $box[1], $box[5] + $min, $box[3], $box[4], $box[5] + $max],
        };
    }

    /** PistonMovingBlockEntity.getMovement: how far the entity box must move to leave the other. */
    private static function pistonOverlap(array $outside, string $direction, array $box): float
    {
        return match ($direction) {
            'east' => $outside[3] - $box[0],
            'west' => $box[3] - $outside[0],
            'down' => $box[4] - $outside[1],
            'south' => $outside[5] - $box[2],
            'north' => $box[5] - $outside[2],
            default => $outside[4] - $box[1],
        };
    }

    /** moveByPositionAndProgress: a local box where the moving block is drawn now. */
    private static function pistonShift(array $box, array $entity): array
    {
        $current = $entity['extending'] ? $entity['progress'] - 1.0 : 1.0 - $entity['progress'];
        [$sx, $sy, $sz] = self::PISTON_DIRECTIONS[$entity['direction']];
        [$x, $y, $z] = [$entity['position'][0] + $current * $sx, $entity['position'][1] + $current * $sy, $entity['position'][2] + $current * $sz];
        return [$box[0] + $x, $box[1] + $y, $box[2] + $z, $box[3] + $x, $box[4] + $y, $box[5] + $z];
    }

    /**
     * PistonMovingBlockEntity.moveCollidedEntities and moveStuckEntities: entities in the way of
     * the moving block's collision shape are pushed out of it; slime launches them along the
     * movement and honey drags what stands on it sideways.
     */
    private function pistonMoveEntities(array $entity, float $newProgress): void
    {
        $catalog = $this->version->catalog;
        $collision = new Collision($catalog);
        $movement = $entity['extending'] ? $entity['direction'] : self::PISTON_OPPOSITE[$entity['direction']];
        $deltaProgress = $newProgress - $entity['progress'];
        $moved = $entity['moved'];
        $related = $moved;
        $movedName = $catalog->blockName($moved);
        if (!$entity['extending'] && $entity['source'] && $this->isPistonBase($movedName)) {
            $related = $catalog->state('piston_head', ['facing' => $catalog->properties($moved)['facing'],
                'short' => $entity['progress'] > 0.25 ? 'true' : 'false', 'type' => $movedName === 'sticky_piston' ? 'sticky' : 'normal']);
        }
        $shape = $collision->shape($related);
        if ($shape !== []) {
            $bounds = [INF, INF, INF, -INF, -INF, -INF];
            foreach ($shape as $box) {
                for ($i = 0; $i < 3; ++$i) {
                    $bounds[$i] = min($bounds[$i], $box[$i]);
                    $bounds[$i + 3] = max($bounds[$i + 3], $box[$i + 3]);
                }
            }
            $aabb = self::pistonShift($bounds, $entity);
            $area = self::pistonMovementArea($aabb, $movement, $deltaProgress);
            $search = [min($area[0], $aabb[0]), min($area[1], $aabb[1]), min($area[2], $aabb[2]), max($area[3], $aabb[3]), max($area[4], $aabb[4]), max($area[5], $aabb[5])];
            foreach (self::PISTON_PUSHED as $property => $size) {
                foreach (array_keys($this->{$property}) as $id) {
                    if (!isset($this->{$property}[$id]) || !self::boxesIntersect($search, self::pistonBox($this->{$property}[$id], $size))) {
                        continue;
                    }
                    if ($movedName === 'slime_block') {
                        $axis = ['x' => 0, 'y' => 1, 'z' => 2][match ($movement) {
                            'east', 'west' => 'x', 'up', 'down' => 'y', default => 'z'
                        }];
                        $this->{$property}[$id]['velocity'][$axis] = (float) array_sum(self::PISTON_DIRECTIONS[$movement]);
                    }
                    $delta = 0.0;
                    foreach ($shape as $box) {
                        $moving = self::pistonMovementArea(self::pistonShift($box, $entity), $movement, $deltaProgress);
                        $entityBox = self::pistonBox($this->{$property}[$id], $size);
                        if (self::boxesIntersect($moving, $entityBox)) {
                            $delta = max($delta, self::pistonOverlap($moving, $movement, $entityBox));
                            if ($delta >= $deltaProgress) {
                                break;
                            }
                        }
                    }
                    if ($delta > 0.0) {
                        $delta = min($delta, $deltaProgress) + 0.01;
                        $this->moveEntityByPiston($collision, $property, $id, $movement, $delta);
                        if (!$entity['extending'] && $entity['source']) {
                            $this->fixEntityWithinPistonBase($collision, $property, $id, $entity['position'], $movement, $deltaProgress);
                        }
                    }
                }
            }
        }
        if ($movedName !== 'honey_block' || $movement === 'up' || $movement === 'down') {
            return;
        }
        $top = max(array_map(static fn (array $box): float => $box[4], $collision->shape($moved)));
        $aabb = self::pistonShift([0.0, $top, 0.0, 1.0, 1.5000010000000001, 1.0], $entity);
        foreach (self::PISTON_PUSHED as $property => $size) {
            foreach (array_keys($this->{$property}) as $id) {
                $pushed = $this->{$property}[$id] ?? null;
                if ($pushed !== null && ($pushed['ground'] ?? false) && self::boxesIntersect($aabb, self::pistonBox($pushed, $size))
                    && $pushed['x'] >= $aabb[0] && $pushed['x'] <= $aabb[3] && $pushed['z'] >= $aabb[2] && $pushed['z'] <= $aabb[5]) {
                    $this->moveEntityByPiston($collision, $property, $id, $movement, $deltaProgress);
                }
            }
        }
    }

    /** moveEntityByPiston: Entity.move(MoverType.PISTON), at most 0.51 along each axis a tick. */
    private function moveEntityByPiston(Collision $collision, string $property, int $id, string $movement, float $delta, ?string $noclip = null): void
    {
        $size = self::PISTON_PUSHED[$property];
        $key = $property . ':' . $id;
        if (($this->pistonDeltas[$key][0] ?? null) !== $this->worldAge) {
            $this->pistonDeltas[$key] = [$this->worldAge, [0.0, 0.0, 0.0]];
        }
        $step = self::PISTON_DIRECTIONS[$movement];
        $axis = $step[0] !== 0 ? 0 : ($step[1] !== 0 ? 1 : 2);
        $amount = $delta * $step[$axis];
        if (abs($amount) <= 1.0E-7) {
            return;
        }
        $limited = max(-0.51, min(0.51, $amount + $this->pistonDeltas[$key][1][$axis]));
        $amount = $limited - $this->pistonDeltas[$key][1][$axis];
        $this->pistonDeltas[$key][1][$axis] = $limited;
        if (abs($amount) <= 1.0E-5) {
            return;
        }
        $wanted = [0.0, 0.0, 0.0];
        $wanted[$axis] = $amount;
        $pushed = &$this->{$property}[$id];
        $collision->noclip = $noclip ?? $movement;
        $moved = $collision->move($this->world, self::pistonBox($pushed, $size), $wanted);
        $collision->noclip = null;
        [$pushed['x'], $pushed['y'], $pushed['z']] = [$pushed['x'] + $moved[0], $pushed['y'] + $moved[1], $pushed['z'] + $moved[2]];
        if ($moved[$axis] !== $amount) {
            // restituteMovementAfterCollisions: these entities do not bounce.
            $pushed['velocity'][$axis] = 0.0;
        }
        $pushed['horizontalCollision'] = abs($moved[0] - $wanted[0]) >= 1.0E-5 || abs($moved[2] - $wanted[2]) >= 1.0E-5;
        // isLocalInstanceAuthoritative: the server's own entities update their ground state on every move.
        $pushed['verticalCollision'] = $moved[1] !== $wanted[1];
        $pushed['ground'] = $pushed['verticalCollision'] && $wanted[1] < 0.0;
        unset($pushed);
        $this->pistonDeltas = array_filter($this->pistonDeltas, fn (array $entry): bool => $entry[0] === $this->worldAge);
    }

    /** fixEntityWithinPistonBase: a retracting head drags no entity into its base. */
    private function fixEntityWithinPistonBase(Collision $collision, string $property, int $id, array $pos, string $direction, float $deltaProgress): void
    {
        $box = self::pistonBox($this->{$property}[$id], self::PISTON_PUSHED[$property]);
        $base = [$pos[0], $pos[1], $pos[2], $pos[0] + 1.0, $pos[1] + 1.0, $pos[2] + 1.0];
        if (!self::boxesIntersect($box, $base)) {
            return;
        }
        $opposite = self::PISTON_OPPOSITE[$direction];
        $intersection = [max($box[0], $base[0]), max($box[1], $base[1]), max($box[2], $base[2]), min($box[3], $base[3]), min($box[4], $base[4]), min($box[5], $base[5])];
        $delta = self::pistonOverlap($base, $opposite, $box) + 0.01;
        if (abs($delta - (self::pistonOverlap($base, $opposite, $intersection) + 0.01)) < 0.01) {
            $this->moveEntityByPiston($collision, $property, $id, $opposite, min($delta, $deltaProgress) + 0.01, $direction);
        }
    }
}
