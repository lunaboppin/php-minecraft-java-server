<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\Server\Tracking\EntityMovementTracker;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * TntBlock, PrimedTnt and ServerExplosion: TNT primed by redstone, fire, explosions or flint and
 * steel falls as an entity and explodes after its fuse. An explosion casts rays that lose power
 * to each block's explosion resistance, hurts and pushes entities by how much of them it sees,
 * blows up the blocks its rays reached with their drops merged, and tells nearby players.
 */
trait Explosions
{
    /** @var array<int, array> primed TNT entities by entity ID */
    private array $tntEntities = [];

    /** @var array<int, EntityMovementTracker> */
    private array $tntTrackers = [];

    /** @var array<string, float>|null Block.getExplosionResistance by block name */
    private ?array $explosionResistance = null;

    /**
     * The fixed tail of a large explosion's ClientboundExplodePacket, as the official server sends
     * it: the explosion_emitter particle, entity.generic.explode, the block particles (poof at
     * scale 0.5 and smoke, each speed 1 and weight 1) and playSound.
     */
    private const LARGE_EXPLOSION_TAIL = '1dc00502453f0000003f80000001483f8000003f8000000101';

    /** TntBlock.prime: a primed TNT entity at the block's center, with the primed sound. */
    private function primeTnt(int $x, int $y, int $z, ?Client $source = null, bool $shortFuse = false): void
    {
        if ($this->gameRule('tnt_explodes') !== 'true') {
            return;
        }
        $rotation = self::randomUnit() * Float32::round(M_PI * 2);
        $id = $this->nextEntity++;
        $fuse = 80;
        if ($shortFuse) {
            // PrimedTnt.getRandomShortFuse for TNT set off by an explosion.
            $fuse = random_int(0, max(1, intdiv($fuse, 4)) - 1) + intdiv($fuse, 8);
        }
        $this->tntEntities[$id] = [
            'id' => $id, 'uuid' => random_bytes(16), 'x' => $x + 0.5, 'y' => (float) $y, 'z' => $z + 0.5,
            'velocity' => [-sin($rotation) * 0.02, Float32::round(0.2), -cos($rotation) * 0.02], 'fuse' => $fuse, 'ground' => false,
            'owner' => $source?->uuid, 'dirty' => $fuse !== 80,
        ];
        if (!$shortFuse) {
            $payload = Packets::sound($this->version->blocks['sounds']['entity.tnt.primed'], 4, $x + 0.5, (float) $y, $z + 0.5, 1.0, 1.0, unpack('J', random_bytes(8))[1]);
            foreach ($this->localPlayers() as $player) {
                if (($player->x - $x - 0.5) ** 2 + ($player->y - $y) ** 2 + ($player->z - $z - 0.5) ** 2 < 256.0) {
                    $player->send($this->version, 'sound', $payload);
                }
            }
        }
        $this->synchronizeTnt($id);
    }

    /** TntBlock.onPlace and neighborChanged: TNT with a redstone signal beside it primes. */
    private function tntChanged(int $x, int $y, int $z): void
    {
        foreach ([[0, 0, 0], [0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]] as [$dx, $dy, $dz]) {
            [$a, $b, $c] = [$x + $dx, $y + $dy, $z + $dz];
            if ($this->version->catalog->blockName($this->world->get($a, $b, $c)) === 'tnt' && $this->powerAt($a, $b, $c) > 0 && $this->gameRule('tnt_explodes') === 'true') {
                $this->primeTnt($a, $b, $c);
                $this->setBlock($a, $b, $c, $this->version->catalog->state('air'));
            }
        }
    }

    /** PrimedTnt.tick: gravity, movement, drag and bounce, then the fuse burns down. */
    private function tickTnt(): void
    {
        $collision = new Collision($this->version->catalog);
        foreach (array_keys($this->tntEntities) as $id) {
            if (!isset($this->tntEntities[$id])) {
                continue;
            }
            $tnt = &$this->tntEntities[$id];
            $tnt['velocity'][1] -= 0.04;
            $box = [$tnt['x'] - 0.49, $tnt['y'], $tnt['z'] - 0.49, $tnt['x'] + 0.49, $tnt['y'] + 0.98, $tnt['z'] + 0.49];
            $moved = $collision->move($this->world, $box, $tnt['velocity']);
            $tnt['ground'] = $moved[1] !== $tnt['velocity'][1] && $tnt['velocity'][1] < 0.0;
            // PrimedTnt.setRequiresPrecisePosition: after a collision its position is sent exactly.
            $tnt['precise'] = $moved !== $tnt['velocity'];
            [$tnt['x'], $tnt['y'], $tnt['z']] = [$tnt['x'] + $moved[0], $tnt['y'] + $moved[1], $tnt['z'] + $moved[2]];
            foreach ([0, 1, 2] as $axis) {
                if ($moved[$axis] !== $tnt['velocity'][$axis]) {
                    $tnt['velocity'][$axis] = 0.0;
                }
            }
            $tnt['velocity'] = [$tnt['velocity'][0] * 0.98, $tnt['velocity'][1] * 0.98, $tnt['velocity'][2] * 0.98];
            if ($tnt['ground']) {
                $tnt['velocity'] = [$tnt['velocity'][0] * 0.7, $tnt['velocity'][1] * -0.5, $tnt['velocity'][2] * 0.7];
            }
            --$tnt['fuse'];
            $tnt['dirty'] = true;
            if ($tnt['fuse'] <= 0) {
                [$x, $y, $z, $owner] = [$tnt['x'], $tnt['y'], $tnt['z'], $tnt['owner']];
                unset($tnt);
                $this->removeTnt($id);
                if ($this->gameRule('tnt_explodes') === 'true') {
                    // Entity.getY(0.0625): a sixteenth of the way up the TNT's height.
                    $this->explode($x, $y + Float32::round(0.98) * 0.0625, $z, 4.0, $id, $owner);
                }
                continue;
            }
            unset($tnt);
            $this->synchronizeTnt($id);
        }
    }

    private function removeTnt(int $id): void
    {
        unset($this->tntEntities[$id], $this->tntTrackers[$id]);
        foreach ($this->localPlayers() as $player) {
            $this->forgetEntity($player, $id);
        }
    }

    /**
     * ServerEntity.sendChanges for primed TNT: changed entity data (its fuse, every tick) forces
     * an update, so movement goes out each tick, followed by the new fuse.
     */
    private function synchronizeTnt(int $id): void
    {
        $tnt = $this->tntEntities[$id];
        foreach ($this->localPlayers() as $player) {
            if (!isset($player->entities[$id])) {
                $this->showTnt($player, $id);
            } elseif (!$this->tracksPosition($player, $tnt['x'], $tnt['z'], 'tnt')) {
                $this->forgetEntity($player, $id);
            }
        }
        $state = ['position' => [$tnt['x'], $tnt['y'], $tnt['z']], 'velocity' => $tnt['velocity'], 'yaw' => 0.0, 'pitch' => 0.0, 'head' => 0.0,
            'ground' => $tnt['ground'], 'needs_sync' => false, 'sync_velocity' => false, 'sync_position' => false, 'precise' => $tnt['precise'] ?? false,
            'force_update' => $tnt['dirty']];
        $tracker = $this->tntTrackers[$id] ??= new EntityMovementTracker($id, 10, true, $state);
        $packets = $tracker->update($state);
        if ($tnt['dirty']) {
            $this->tntEntities[$id]['dirty'] = false;
            $packets[] = ['name' => 'set_entity_data', 'payload' => Binary::writeVarInt($id) . "\x08\x01" . Binary::writeVarInt($tnt['fuse']) . "\xff"];
        }
        foreach ($packets as $packet) {
            foreach ($this->localPlayers() as $player) {
                if (isset($player->entities[$id])) {
                    $player->send($this->version, $packet['name'], $packet['payload']);
                }
            }
        }
    }

    /** ServerEntity.sendPairingData for primed TNT: the spawn, then its fuse unless still the default 80. */
    private function showTnt(Client $player, int $id): bool
    {
        $tnt = $this->tntEntities[$id];
        if (isset($player->entities[$id]) || !$this->tracksPosition($player, $tnt['x'], $tnt['z'], 'tnt')) {
            return isset($player->entities[$id]);
        }
        $state = ['position' => [$tnt['x'], $tnt['y'], $tnt['z']], 'velocity' => $tnt['velocity'], 'yaw' => 0.0, 'pitch' => 0.0, 'head' => 0.0,
            'ground' => $tnt['ground'], 'needs_sync' => false, 'sync_velocity' => false, 'sync_position' => false, 'precise' => $tnt['precise'] ?? false];
        $tracker = $this->tntTrackers[$id] ??= new EntityMovementTracker($id, 10, true, $state);
        $player->send($this->version, 'add_entity', $tracker->spawnPacket($tnt['uuid'], $this->version->blocks['entity_types']['tnt'], 0));
        if ($tnt['fuse'] !== 80) {
            $player->send($this->version, 'set_entity_data', Binary::writeVarInt($id) . "\x08\x01" . Binary::writeVarInt($tnt['fuse']) . "\xff");
        }
        $player->entities[$id] = true;
        return true;
    }

    private function explosionResistance(int $state): ?float
    {
        if ($this->explosionResistance === null) {
            $behaviour = json_decode((string) file_get_contents($this->version->directory . '/worldgen/block-behaviour.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->explosionResistance = $behaviour['explosion_resistance'];
        }
        $catalog = $this->version->catalog;
        $name = $catalog->blockName($state);
        // FluidState.getExplosionResistance: water and lava resist with 100.
        $fluid = in_array($name, ['water', 'lava', 'bubble_column'], true) || ($catalog->properties($state)['waterlogged'] ?? 'false') === 'true';
        if ($catalog->air($state) && !$fluid) {
            return null;
        }
        return max($this->explosionResistance[$name] ?? 0.0, $fluid ? 100.0 : 0.0);
    }

    /**
     * ServerLevel.explode for TNT: ServerExplosion.explode, then the explode packet to players
     * within 64 blocks, carrying the knockback of a player it pushed.
     */
    private function explode(float $cx, float $cy, float $cz, float $radius, ?int $sourceTnt = null, ?string $owner = null): void
    {
        $catalog = $this->version->catalog;
        $f = Float32::round(...);
        // ServerExplosion.calculateExplodedPositions: rays from each cell on the surface of a 16³ cube.
        $blown = [];
        for ($i = 0; $i < 16; ++$i) {
            for ($j = 0; $j < 16; ++$j) {
                for ($k = 0; $k < 16; ++$k) {
                    if ($i !== 0 && $i !== 15 && $j !== 0 && $j !== 15 && $k !== 0 && $k !== 15) {
                        continue;
                    }
                    $xd = $f($f($f($i / 15.0) * 2.0) - 1.0);
                    $yd = $f($f($f($j / 15.0) * 2.0) - 1.0);
                    $zd = $f($f($f($k / 15.0) * 2.0) - 1.0);
                    $d = sqrt($xd * $xd + $yd * $yd + $zd * $zd);
                    [$xd, $yd, $zd] = [$xd / $d, $yd / $d, $zd / $d];
                    $power = $f($radius * $f(0.7 + $f(self::randomUnit() * 0.6)));
                    [$x, $y, $z] = [$cx, $cy, $cz];
                    for (; $power > 0.0; $power = $f($power - 0.22500001)) {
                        [$bx, $by, $bz] = [(int) floor($x), (int) floor($y), (int) floor($z)];
                        if ($by < $this->world->minY || $by >= $this->world->minY + $this->world->height || abs($bx) >= 30000000 || abs($bz) >= 30000000) {
                            break;
                        }
                        $resistance = $this->explosionResistance($this->world->get($bx, $by, $bz));
                        if ($resistance !== null) {
                            $power = $f($power - $f($f($resistance + 0.3) * 0.3));
                        }
                        if ($power > 0.0) {
                            $blown["$bx:$by:$bz"] = [$bx, $by, $bz];
                        }
                        $x += $xd * 0.3;
                        $y += $yd * 0.3;
                        $z += $zd * 0.3;
                    }
                }
            }
        }
        $knockbacks = $this->hurtByExplosion($cx, $cy, $cz, $radius, $owner);
        // ServerExplosion.interactWithBlocks: in shuffled order, each block drops its loot (merged
        // into stacks of up to 16 at the first block's position) and is replaced by air.
        $positions = array_values($blown);
        shuffle($positions);
        $stacks = [];
        $air = $catalog->state('air');
        foreach ($positions as [$x, $y, $z]) {
            $state = $this->world->get($x, $y, $z);
            if ($catalog->air($state)) {
                continue;
            }
            $name = $catalog->blockName($state);
            if ($name === 'tnt') {
                $this->setBlock($x, $y, $z, $air);
                $this->primeTnt($x, $y, $z, null, true);
                continue;
            }
            if ($this->gameRule('block_drops') === 'true') {
                foreach ($this->explodedDrops($x, $y, $z, $state) as $stack) {
                    foreach ($stacks as &$collected) {
                        $room = min(16, $this->maxStackSize($stack)) - $collected[1]['count'];
                        if ($room > 0 && $collected[1]['item'] === $stack['item'] && ($collected[1]['components'] ?? null) === ($stack['components'] ?? null)) {
                            $moved = min($room, $stack['count']);
                            $collected[1]['count'] += $moved;
                            $stack['count'] -= $moved;
                            if ($stack['count'] === 0) {
                                break;
                            }
                        }
                    }
                    unset($collected);
                    if ($stack['count'] > 0) {
                        $stacks[] = [[$x, $y, $z], $stack];
                    }
                }
                $container = $this->world->container([$x, $y, $z]);
                foreach ($container['slots'] ?? [] as $slot) {
                    $this->dropContainerStack($x, $y, $z, $slot);
                }
            }
            $this->setBlock($x, $y, $z, $air);
        }
        foreach ($stacks as [[$x, $y, $z], $stack]) {
            $this->popResource($x, $y, $z, $stack);
        }
        $tail = (string) hex2bin(self::LARGE_EXPLOSION_TAIL);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $cx) ** 2 + ($player->y - $cy) ** 2 + ($player->z - $cz) ** 2 < 4096.0) {
                $knockback = $knockbacks[$player->uuid] ?? null;
                $player->send($this->version, 'explode', pack('EEE', $cx, $cy, $cz) . pack('G', $radius) . pack('N', count($blown))
                    . ($knockback === null ? "\0" : "\1" . pack('EEE', ...$knockback)) . $tail);
            }
        }
    }

    /** Block.dropResources with an explosion: the block's loot without a tool. */
    private function explodedDrops(int $x, int $y, int $z, int $state): array
    {
        $catalog = $this->version->catalog;
        $name = $catalog->blockName($state);
        $blockAt = function (int $offset) use ($x, $y, $z): array {
            $above = $this->world->get($x, $y + $offset, $z);
            return ['minecraft:' . $this->version->catalog->blockName($above), array_map('strval', $this->version->catalog->properties($above))];
        };
        $context = ['block' => 'minecraft:' . $name, 'state' => array_map('strval', $catalog->properties($state)), 'tool' => null, 'blockAt' => $blockAt];
        $out = [];
        foreach ($this->loot()->drops('minecraft:blocks/' . $name, $context) as [$drop, $count]) {
            $item = $this->version->blocks['items'][substr($drop, 10)] ?? null;
            if ($item !== null) {
                $out[] = ['item' => $item, 'count' => $count];
            }
        }
        return $out;
    }

    private function maxStackSize(array $stack): int
    {
        return $this->version->catalog->item($stack['item'])['max_stack'] ?? 64;
    }

    /**
     * ServerExplosion.hurtEntities: every entity within twice the radius takes damage and a push
     * scaled by its distance and the share of it the explosion sees. Returns the knockback of
     * each pushed player, for their explode packet.
     *
     * @return array<string, array{0: float, 1: float, 2: float}>
     */
    private function hurtByExplosion(float $cx, float $cy, float $cz, float $radius, ?string $owner): array
    {
        $double = $radius * 2.0;
        $knockbacks = [];
        $effect = function (array $box, float $originY, float $x, float $z) use ($cx, $cy, $cz, $double): ?array {
            $centerX = ($box[0] + $box[3]) / 2.0;
            $centerZ = ($box[2] + $box[5]) / 2.0;
            $dist = sqrt(($centerX - $cx) ** 2 + ($box[1] - $cy) ** 2 + ($centerZ - $cz) ** 2) / $double;
            if ($dist > 1.0) {
                return null;
            }
            $direction = [$x - $cx, $originY - $cy, $z - $cz];
            $length = sqrt($direction[0] ** 2 + $direction[1] ** 2 + $direction[2] ** 2);
            $direction = $length < 1.0E-5 ? [0.0, 0.0, 0.0] : [$direction[0] / $length, $direction[1] / $length, $direction[2] / $length];
            $exposure = $this->seenPercent($cx, $cy, $cz, $box);
            $impact = (1.0 - $dist) * $exposure;
            // ExplosionDamageCalculator.getEntityDamageAmount
            $damage = Float32::round(($impact * $impact + $impact) / 2.0 * 7.0 * $double + 1.0);
            return [$dist, $exposure, $direction, $damage];
        };
        foreach ($this->localPlayers() as $player) {
            if ($player->dead || $player->gameMode === 3) {
                continue;
            }
            $box = [$player->x - 0.3, $player->y, $player->z - 0.3, $player->x + 0.3, $player->y + 1.8, $player->z + 0.3];
            $result = $effect($box, $player->y + 1.62, $player->x, $player->z);
            if ($result === null) {
                continue;
            }
            [$dist, $exposure, $direction, $damage] = $result;
            // DamageSources.explosion: TNT lit by a player is that player's explosion.
            $this->damage($player, $damage, $owner !== null ? 'minecraft:player_explosion' : 'minecraft:explosion');
            $power = (1.0 - $dist) * $exposure;
            $push = [$direction[0] * $power, $direction[1] * $power, $direction[2] * $power];
            if (!($player->gameMode === 1 && $player->flying)) {
                $knockbacks[$player->uuid] = $push;
            }
        }
        foreach (array_keys($this->animals) as $id) {
            $mob = $this->animals[$id] ?? null;
            if ($mob === null || isset($mob['deadAt'])) {
                continue;
            }
            $info = $this->version->catalog->mobs['entities'][$mob['kind']];
            $half = $info['width'] / 2.0;
            $box = [$mob['x'] - $half, $mob['y'], $mob['z'] - $half, $mob['x'] + $half, $mob['y'] + $info['height'], $mob['z'] + $half];
            $result = $effect($box, $mob['y'] + $info['eye_height'], $mob['x'], $mob['z']);
            if ($result === null) {
                continue;
            }
            [$dist, $exposure, $direction, $damage] = $result;
            $this->hurtMob($id, $damage, 'minecraft:explosion');
            if (isset($this->animals[$id])) {
                $power = (1.0 - $dist) * $exposure;
                $velocity = $this->animals[$id]['velocity'] ?? [0.0, 0.0, 0.0];
                $this->animals[$id]['velocity'] = [$velocity[0] + $direction[0] * $power, $velocity[1] + $direction[1] * $power, $velocity[2] + $direction[2] * $power];
            }
        }
        foreach (array_keys($this->itemEntities) as $id) {
            $item = $this->itemEntities[$id] ?? null;
            if ($item === null) {
                continue;
            }
            $box = [$item['x'] - 0.125, $item['y'], $item['z'] - 0.125, $item['x'] + 0.125, $item['y'] + 0.25, $item['z'] + 0.125];
            $result = $effect($box, $item['y'] + 0.2125, $item['x'], $item['z']);
            if ($result === null) {
                continue;
            }
            [$dist, $exposure, $direction, $damage] = $result;
            $this->hurtItem($id, $damage, 'minecraft:explosion');
            if (isset($this->itemEntities[$id])) {
                $power = (1.0 - $dist) * $exposure;
                $velocity = $this->itemEntities[$id]['velocity'];
                $this->itemEntities[$id]['velocity'] = [$velocity[0] + $direction[0] * $power, $velocity[1] + $direction[1] * $power, $velocity[2] + $direction[2] * $power];
                $this->itemEntities[$id]['needsSync'] = true;
            }
        }
        foreach (array_keys($this->tntEntities) as $id) {
            $tnt = $this->tntEntities[$id];
            $box = [$tnt['x'] - 0.49, $tnt['y'], $tnt['z'] - 0.49, $tnt['x'] + 0.49, $tnt['y'] + 0.98, $tnt['z'] + 0.49];
            // Primed TNT is pushed from its feet rather than its eyes.
            $result = $effect($box, $tnt['y'], $tnt['x'], $tnt['z']);
            if ($result === null) {
                continue;
            }
            [$dist, $exposure, $direction] = $result;
            $power = (1.0 - $dist) * $exposure;
            $velocity = $tnt['velocity'];
            $this->tntEntities[$id]['velocity'] = [$velocity[0] + $direction[0] * $power, $velocity[1] + $direction[1] * $power, $velocity[2] + $direction[2] * $power];
        }
        return $knockbacks;
    }

    /**
     * ServerExplosion.getSeenPercent: the share of sample points across the entity's box from
     * which a ray to the explosion meets no block collision shape.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $box
     */
    private function seenPercent(float $cx, float $cy, float $cz, array $box): float
    {
        $xs = 1.0 / (($box[3] - $box[0]) * 2.0 + 1.0);
        $ys = 1.0 / (($box[4] - $box[1]) * 2.0 + 1.0);
        $zs = 1.0 / (($box[5] - $box[2]) * 2.0 + 1.0);
        $xOffset = (1.0 - floor(1.0 / $xs) * $xs) / 2.0;
        $zOffset = (1.0 - floor(1.0 / $zs) * $zs) / 2.0;
        $collision = new Collision($this->version->catalog);
        $hits = 0;
        $count = 0;
        for ($xx = 0.0; $xx <= 1.0; $xx += $xs) {
            for ($yy = 0.0; $yy <= 1.0; $yy += $ys) {
                for ($zz = 0.0; $zz <= 1.0; $zz += $zs) {
                    $from = [$box[0] + $xx * ($box[3] - $box[0]) + $xOffset, $box[1] + $yy * ($box[4] - $box[1]), $box[2] + $zz * ($box[5] - $box[2]) + $zOffset];
                    if (!$this->blockClip($collision, $from, [$cx, $cy, $cz])) {
                        ++$hits;
                    }
                    ++$count;
                }
            }
        }
        return $count === 0 ? 0.0 : Float32::round($hits / $count);
    }

    /** Level.clip with block colliders and no fluids: whether a block's collision shape stops the ray. */
    private function blockClip(Collision $collision, array $from, array $to): bool
    {
        // BlockGetter.traverseBlocks starts a hair inside each end.
        $start = [$to[0] + ($from[0] - $to[0]) * (1.0 - 1.0E-7), $to[1] + ($from[1] - $to[1]) * (1.0 - 1.0E-7), $to[2] + ($from[2] - $to[2]) * (1.0 - 1.0E-7)];
        $end = [$from[0] + ($to[0] - $from[0]) * (1.0 - 1.0E-7), $from[1] + ($to[1] - $from[1]) * (1.0 - 1.0E-7), $from[2] + ($to[2] - $from[2]) * (1.0 - 1.0E-7)];
        [$x, $y, $z] = [(int) floor($start[0]), (int) floor($start[1]), (int) floor($start[2])];
        $delta = [$end[0] - $start[0], $end[1] - $start[1], $end[2] - $start[2]];
        $step = array_map(static fn (float $d): int => $d > 0 ? 1 : ($d < 0 ? -1 : 0), $delta);
        $tDelta = array_map(static fn (float $d): float => $d == 0.0 ? INF : 1.0 / abs($d), $delta);
        $tMax = [];
        foreach ([0, 1, 2] as $axis) {
            $cell = (int) floor($start[$axis]);
            $tMax[$axis] = $tDelta[$axis] === INF ? INF : $tDelta[$axis] * ($step[$axis] > 0 ? 1.0 - ($start[$axis] - $cell) : $start[$axis] - $cell);
        }
        for ($guard = 0; $guard < 1024; ++$guard) {
            if ($this->shapeHit($collision, $x, $y, $z, $start, $end)) {
                return true;
            }
            if ($tMax[0] > 1.0 && $tMax[1] > 1.0 && $tMax[2] > 1.0) {
                return false;
            }
            $axis = $tMax[0] < $tMax[1] ? ($tMax[0] < $tMax[2] ? 0 : 2) : ($tMax[1] < $tMax[2] ? 1 : 2);
            if ($axis === 0) {
                $x += $step[0];
            } elseif ($axis === 1) {
                $y += $step[1];
            } else {
                $z += $step[2];
            }
            $tMax[$axis] += $tDelta[$axis];
        }
        return false;
    }

    /** VoxelShape.clip: whether the segment crosses one of the block's collision boxes. */
    private function shapeHit(Collision $collision, int $x, int $y, int $z, array $start, array $end): bool
    {
        if ($y < $this->world->minY || $y >= $this->world->minY + $this->world->height) {
            return false;
        }
        foreach ($collision->shape($this->world->get($x, $y, $z)) as $box) {
            [$t0, $t1] = [0.0, 1.0];
            foreach ([0, 1, 2] as $axis) {
                $offset = [$x, $y, $z][$axis];
                $d = $end[$axis] - $start[$axis];
                [$lo, $hi] = [$box[$axis] + $offset, $box[$axis + 3] + $offset];
                if (abs($d) < 1.0E-12) {
                    if ($start[$axis] < $lo || $start[$axis] > $hi) {
                        continue 2;
                    }
                    continue;
                }
                $a = ($lo - $start[$axis]) / $d;
                $b = ($hi - $start[$axis]) / $d;
                [$t0, $t1] = [max($t0, min($a, $b)), min($t1, max($a, $b))];
                if ($t0 > $t1) {
                    continue 2;
                }
            }
            return true;
        }
        return false;
    }
}
