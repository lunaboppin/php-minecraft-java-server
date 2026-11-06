<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\LowPrecisionVector;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;

/**
 * ItemFrame and GlowItemFrame: a frame hung on the face of a block, which holds one item a
 * player puts in, turns it an eighth each use, gives it up when hit and breaks when hit empty
 * or when its block goes.
 */
trait ItemFrames
{
    /** @var array<int, array> item frames by entity ID */
    private array $itemFrames = [];

    /** Direction.get3DDataValue, and the unit step toward each face. */
    private const FRAME_FACES = ['down' => [0, [0, -1, 0]], 'up' => [1, [0, 1, 0]], 'north' => [2, [0, 0, -1]], 'south' => [3, [0, 0, 1]], 'west' => [4, [-1, 0, 0]], 'east' => [5, [1, 0, 0]]];

    /** The frame item each kind of frame is made from and drops. */
    private const FRAME_KINDS = ['item_frame' => 'item_frame', 'glow_item_frame' => 'glow_item_frame'];

    private function addItemFrame(string $kind, array $pos, string $facing, array $saved = []): int
    {
        $id = $this->nextEntity++;
        $this->itemFrames[$id] = $saved + ['kind' => $kind, 'uuid' => random_bytes(16), 'pos' => $pos, 'facing' => $facing, 'item' => null, 'rotation' => 0,
            'fixed' => false, 'invisible' => false, 'checkTicks' => 0, 'dataDirty' => []];
        $this->itemFrames[$id]['id'] = $id;
        return $id;
    }

    /** Frames saved with the level hang where they were. */
    private function restoreItemFrames(): void
    {
        foreach ($this->world->setting('itemFrames') ?? [] as $saved) {
            if (!isset($saved['uuid'], $saved['kind'], $saved['pos'], $saved['facing'], self::FRAME_KINDS[$saved['kind']], self::FRAME_FACES[$saved['facing']])) {
                continue;
            }
            $saved['uuid'] = hex2bin($saved['uuid']);
            $saved['pos'] = array_map('intval', $saved['pos']);
            $this->addItemFrame($saved['kind'], $saved['pos'], $saved['facing'], $saved);
        }
    }

    private function savedItemFrames(): array
    {
        return array_values(array_map(static fn (array $frame): array => ['uuid' => bin2hex($frame['uuid'])]
            + array_intersect_key($frame, array_flip(['kind', 'pos', 'facing', 'item', 'rotation', 'fixed', 'invisible'])), $this->itemFrames));
    }

    private function removeItemFrame(int $id): void
    {
        unset($this->itemFrames[$id]);
        foreach ($this->localPlayers() as $player) {
            $this->forgetEntity($player, $id);
        }
    }

    /** The selector-shaped reference a command names a frame by. */
    private function itemFrameReference(int $id): array
    {
        $frame = $this->itemFrames[$id];
        [$x, $y, $z] = $this->itemFrameCenter($frame);
        return ['frame' => $id, 'x' => $x, 'y' => $y, 'z' => $z, 'yaw' => $this->itemFrameRotation($frame)[0], 'pitch' => $this->itemFrameRotation($frame)[1],
            'type' => 'minecraft:' . $frame['kind'], 'width' => 0.5, 'height' => 0.5, 'id' => $id, 'uuid' => $frame['uuid']];
    }

    /** HangingEntity.recalculateBoundingBox: the centre, 0.46875 back from the block's centre toward the wall. */
    private function itemFrameCenter(array $frame): array
    {
        $step = self::FRAME_FACES[$frame['facing']][1];
        return [$frame['pos'][0] + 0.5 - $step[0] * 0.46875, $frame['pos'][1] + 0.5 - $step[1] * 0.46875, $frame['pos'][2] + 0.5 - $step[2] * 0.46875];
    }

    /** ItemFrame.createBoundingBox without a map: 0.75 across the face and 0.0625 deep. */
    private function itemFrameBox(array $frame): array
    {
        [$x, $y, $z] = $this->itemFrameCenter($frame);
        $step = self::FRAME_FACES[$frame['facing']][1];
        $size = [$step[0] !== 0 ? 0.0625 : 0.75, $step[1] !== 0 ? 0.0625 : 0.75, $step[2] !== 0 ? 0.0625 : 0.75];
        return [$x - $size[0] / 2, $y - $size[1] / 2, $z - $size[2] / 2, $x + $size[0] / 2, $y + $size[1] / 2, $z + $size[2] / 2];
    }

    /** ItemFrame.setDirection: a wall frame turns with its face, a floor or ceiling one pitches. */
    private function itemFrameRotation(array $frame): array
    {
        return match ($frame['facing']) {
            'up' => [0.0, -90.0],
            'down' => [0.0, 90.0],
            default => [(float) (['south' => 0, 'west' => 1, 'north' => 2, 'east' => 3][$frame['facing']] * 90), 0.0],
        };
    }

    /**
     * ItemFrame.survives: nothing solid in its box, a solid block behind it (a repeater or
     * comparator will do for a wall frame), and no other hanging entity facing the same way
     * in its box.
     */
    private function itemFrameSurvives(array $frame): bool
    {
        if ($frame['fixed']) {
            return true;
        }
        $box = $this->itemFrameBox($frame);
        $collision = new Collision($this->version->catalog);
        foreach ($collision->boxes($this->world, $box) as $other) {
            if ($other[0] < $box[3] && $other[3] > $box[0] && $other[1] < $box[4] && $other[4] > $box[1] && $other[2] < $box[5] && $other[5] > $box[2]) {
                return false;
            }
        }
        $step = self::FRAME_FACES[$frame['facing']][1];
        $behind = $this->world->get($frame['pos'][0] - $step[0], $frame['pos'][1] - $step[1], $frame['pos'][2] - $step[2]);
        $name = $this->version->catalog->blockName($behind);
        if (!$this->fireBlocks()->flag($behind, Blocks::SOLID) && !($step[1] === 0 && ($name === 'repeater' || $name === 'comparator'))) {
            return false;
        }
        foreach ($this->itemFrames as $id => $other) {
            if ($id !== ($frame['id'] ?? null) && $other['facing'] === $frame['facing']) {
                $o = $this->itemFrameBox($other);
                if ($o[0] < $box[3] && $o[3] > $box[0] && $o[1] < $box[4] && $o[4] > $box[1] && $o[2] < $box[5] && $o[5] > $box[2]) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * HangingEntityItem.useOn for a frame: one hangs in the block beside the face clicked, if it
     * can stay there, with its placing sound; the item is used up unless the player has
     * infinite materials.
     */
    private function placeItemFrame(Client $c, int $slot, int $x, int $y, int $z, string $face, string $kind): bool
    {
        $step = self::FRAME_FACES[$face][1];
        $pos = [$x + $step[0], $y + $step[1], $z + $step[2]];
        if ($pos[1] < $this->world->minY || $pos[1] >= $this->world->minY + $this->world->height) {
            return false;
        }
        $frame = ['kind' => $kind, 'pos' => $pos, 'facing' => $face, 'fixed' => false];
        if (!$this->itemFrameSurvives($frame)) {
            return true;
        }
        $id = $this->addItemFrame($kind, $pos, $face);
        $this->itemFrameSound($this->itemFrames[$id], 'place');
        foreach ($this->localPlayers() as $player) {
            $this->showItemFrame($player, $id);
        }
        if ($c->gameMode !== 1) {
            \PhpMinecraftJavaServer\World\Inventory::consume($c->inventory, $slot);
            $this->syncInventory($c);
        }
        return true;
    }

    /** ItemFrame.getAnalogOutput of a frame facing $facing whose box is in the block: nothing without one. */
    private function itemFrameSignal(array $block, string $facing): ?int
    {
        foreach ($this->itemFrames as $frame) {
            if ($frame['facing'] !== $facing) {
                continue;
            }
            $box = $this->itemFrameBox($frame);
            if ($box[0] < $block[0] + 1 && $box[3] > $block[0] && $box[1] < $block[1] + 1 && $box[4] > $block[1] && $box[2] < $block[2] + 1 && $box[5] > $block[2]) {
                return $frame['item'] === null ? 0 : $frame['rotation'] % 8 + 1;
            }
        }
        return null;
    }

    /** Entity.playSound: the frame's sound at its centre, for nearby players. */
    private function itemFrameSound(array $frame, string $event): void
    {
        [$x, $y, $z] = $this->itemFrameCenter($frame);
        $payload = Packets::sound($this->version->blocks['sounds']['entity.' . $frame['kind'] . '.' . $event], 6, $x, $y, $z, 1.0, 1.0, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $x) ** 2 + ($player->y - $y) ** 2 + ($player->z - $z) ** 2 < 256.0) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    /**
     * ItemFrame.interact: an empty frame takes one of the item held, with the adding sound; a
     * full one turns its item an eighth, with the rotating sound. A fixed frame does neither.
     */
    private function interactItemFrame(Client $player, int $id, int $hand): void
    {
        $frame = &$this->itemFrames[$id];
        if ($frame['fixed'] || $player->dead || $player->gameMode === 3 || $hand < 0 || $hand > 1) {
            return;
        }
        // Player.isWithinEntityInteractionRange with the server's 3-block allowance.
        $box = $this->itemFrameBox($frame);
        $eye = [$player->x, $player->y + ($player->sneaking ? 1.27 : 1.62), $player->z];
        $distance = 0.0;
        foreach ([0, 1, 2] as $axis) {
            $distance += max($box[$axis] - $eye[$axis], 0.0, $eye[$axis] - $box[$axis + 3]) ** 2;
        }
        if ($distance >= (3.0 + 3.0) ** 2) {
            return;
        }
        $slot = $hand === 0 ? 36 + $player->heldSlot : 45;
        $held = $player->inventory[$slot] ?? null;
        if ($frame['item'] === null) {
            if ($held === null) {
                return;
            }
            $frame['item'] = array_replace($held, ['count' => 1]);
            $frame['dataDirty'][9] = true;
            $this->itemFrameSound($frame, 'add_item');
            if ($player->gameMode !== 1) {
                \PhpMinecraftJavaServer\World\Inventory::consume($player->inventory, $slot);
                $this->syncInventory($player);
            }
        } else {
            $this->itemFrameSound($frame, 'rotate_item');
            $frame['rotation'] = ($frame['rotation'] + 1) % 8;
            $frame['dataDirty'][10] = true;
        }
        // ItemFrame.setItem and setRotation: comparators reading the frame look again.
        $pos = $frame['pos'];
        unset($frame);
        $this->containerContentsChanged($pos);
        $this->useSwing($player, $hand);
    }

    /**
     * BlockAttachedEntity.skipAttackInteraction: a player's hit counts as no damage. A frame
     * holding an item gives it up with the removing sound; an empty one breaks with its
     * breaking sound and drops itself, unless the player is creative or entity drops are off.
     */
    private function attackItemFrame(Client $c, int $id): bool
    {
        if (!isset($this->itemFrames[$id])) {
            return false;
        }
        $frame = $this->itemFrames[$id];
        if ($frame['fixed'] && $c->gameMode !== 1) {
            return true;
        }
        if ($frame['item'] !== null && !$frame['fixed']) {
            $this->dropItemFrameContents($id, $c, false);
            $this->itemFrameSound($frame, 'remove_item');
            return true;
        }
        $this->removeItemFrame($id);
        $this->itemFrameSound($frame, 'break');
        $this->itemFrames[$id] = $frame;
        $this->dropItemFrameContents($id, $c, true);
        unset($this->itemFrames[$id]);
        return true;
    }

    /** ItemFrame.dropItem: the item (and the frame) at the frame, a little out from the wall. */
    private function dropItemFrameContents(int $id, ?Client $cause, bool $withFrame): void
    {
        $frame = $this->itemFrames[$id];
        if ($frame['fixed']) {
            return;
        }
        $item = $frame['item'];
        $this->itemFrames[$id]['item'] = null;
        $this->itemFrames[$id]['dataDirty'][9] = true;
        $this->containerContentsChanged($frame['pos']);
        if ($this->gameRule('entity_drops') !== 'true' || ($cause !== null && $cause->gameMode === 1)) {
            return;
        }
        [$x, $y, $z] = $this->itemFrameCenter($frame);
        $step = self::FRAME_FACES[$frame['facing']][1];
        // HangingEntity.spawnAtLocation: 0.15 out along the face.
        [$x, $z] = [$x + $step[0] * 0.15000000596046448, $z + $step[2] * 0.15000000596046448];
        if ($withFrame) {
            $this->spawnAtLocation($x, $y, $z, ['item' => $this->version->blocks['items'][self::FRAME_KINDS[$frame['kind']]], 'count' => 1]);
        }
        if ($item !== null) {
            $this->spawnAtLocation($x, $y, $z, $item);
        }
    }

    /** BlockAttachedEntity.tick: every 100 ticks a frame that can no longer stay drops. */
    private function tickItemFrames(): void
    {
        foreach (array_keys($this->itemFrames) as $id) {
            $this->synchronizeItemFrame($id);
            if ($this->itemFrames[$id]['checkTicks']++ < 100) {
                continue;
            }
            $this->itemFrames[$id]['checkTicks'] = 0;
            if (!$this->itemFrameSurvives($this->itemFrames[$id])) {
                $frame = $this->itemFrames[$id];
                $this->removeItemFrame($id);
                $this->itemFrameSound($frame, 'break');
                $this->itemFrames[$id] = $frame;
                $this->dropItemFrameContents($id, null, true);
                unset($this->itemFrames[$id]);
            }
        }
    }

    /** The frame's data: its facing (8), item (9) and rotation (10), each only once changed. */
    private function itemFrameData(array $frame, array $indices): string
    {
        $catalog = $this->version->catalog;
        $data = '';
        ksort($indices);
        foreach (array_keys($indices) as $index) {
            $data .= match ($index) {
                8 => "\x08" . Binary::writeVarInt(12) . Binary::writeVarInt(self::FRAME_FACES[$frame['facing']][0]),
                9 => "\x09" . Binary::writeVarInt($catalog->itemMetadataSerializer) . ($frame['item'] === null ? "\0" : Packets::stack($frame['item'], $this->version->blocks['component_damage'], $catalog->itemStacks)),
                10 => "\x0a\x01" . Binary::writeVarInt($frame['rotation']),
            };
        }
        return $data;
    }

    /** ServerEntity.sendChanges for a frame, which never moves: only its changed data. */
    private function synchronizeItemFrame(int $id): void
    {
        $frame = $this->itemFrames[$id];
        foreach ($this->localPlayers() as $player) {
            if (!isset($player->entities[$id])) {
                $this->showItemFrame($player, $id);
            } elseif (!$this->tracksPosition($player, $this->itemFrameCenter($frame)[0], $this->itemFrameCenter($frame)[2], $frame['kind'])) {
                $this->forgetEntity($player, $id);
            }
        }
        if (!($frame['tracked'] ?? false)) {
            // ServerEntity.sendChanges on its first tick, the only one an update interval of
            // Integer.MAX_VALUE allows: a move of nothing.
            $this->itemFrames[$id]['tracked'] = true;
            foreach ($this->localPlayers() as $player) {
                if (isset($player->entities[$id])) {
                    $player->send($this->version, 'move_entity_pos', Binary::writeVarInt($id) . pack('nnn', 0, 0, 0) . "\0");
                }
            }
        }
        if ($frame['dataDirty'] === []) {
            return;
        }
        $payload = Binary::writeVarInt($id) . $this->itemFrameData($frame, $frame['dataDirty']) . "\xff";
        $this->itemFrames[$id]['dataDirty'] = [];
        foreach ($this->localPlayers() as $player) {
            if (isset($player->entities[$id])) {
                $player->send($this->version, 'set_entity_data', $payload);
            }
        }
    }

    /**
     * ServerEntity.sendPairingData: ItemFrame.getAddEntityPacket puts the frame at its block's
     * corner with its face as the data, then whatever data differs from the defaults.
     */
    private function showItemFrame(Client $player, int $id): bool
    {
        $frame = $this->itemFrames[$id];
        [$cx, , $cz] = $this->itemFrameCenter($frame);
        if (isset($player->entities[$id]) || !$this->tracksPosition($player, $cx, $cz, $frame['kind'])) {
            return isset($player->entities[$id]);
        }
        [$yaw, $pitch] = $this->itemFrameRotation($frame);
        $angle = static fn (float $degrees): string => chr(((int) floor($degrees * 256.0 / 360.0)) & 255);
        $payload = Binary::writeVarInt($id) . $frame['uuid'] . Binary::writeVarInt($this->version->blocks['entity_types'][$frame['kind']])
            . pack('EEE', (float) $frame['pos'][0], (float) $frame['pos'][1], (float) $frame['pos'][2]) . LowPrecisionVector::encode(0.0, 0.0, 0.0)
            . $angle($pitch) . $angle($yaw) . $angle(0.0) . Binary::writeVarInt(self::FRAME_FACES[$frame['facing']][0]);
        $changed = array_filter([8 => $frame['facing'] !== 'south', 9 => $frame['item'] !== null, 10 => $frame['rotation'] !== 0]);
        $player->send($this->version, 'bundle_delimiter', '');
        $player->send($this->version, 'add_entity', $payload);
        if ($changed !== []) {
            $player->send($this->version, 'set_entity_data', Binary::writeVarInt($id) . $this->itemFrameData($frame, $changed) . "\xff");
        }
        $player->send($this->version, 'bundle_delimiter', '');
        $player->entities[$id] = true;
        return true;
    }
}
