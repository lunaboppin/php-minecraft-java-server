<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\LowPrecisionVector;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Vanilla\Decoration\Blocks;

/**
 * Painting: a picture hung on a wall, as large as its variant, which needs solid blocks behind
 * all of it and drops as a painting when hit or when its wall goes.
 */
trait Paintings
{
    /** @var array<int, array> paintings by entity ID */
    private array $paintings = [];

    /** @var array<string, array{int, int, int}>|null each variant's registry ID, width and height */
    private ?array $paintingVariants = null;

    /** Direction.LEGACY_ID_CODEC_2D: the facings a painting may have, by their saved IDs. */
    private const PAINTING_FACINGS = ['south', 'west', 'north', 'east'];

    /** The painting_variant registry as the client has it: each variant's ID and size in blocks. */
    private function paintingVariants(): array
    {
        if ($this->paintingVariants !== null) {
            return $this->paintingVariants;
        }
        $names = array_values($this->version->registryEntries('minecraft:painting_variant'));
        $bytes = (string) file_get_contents($this->version->directory . '/registries/painting_variant.bin');
        $variants = [];
        $from = 0;
        foreach ($names as $id => $name) {
            $at = strpos($bytes, chr(strlen($name)) . $name, $from);
            $next = isset($names[$id + 1]) ? strpos($bytes, chr(strlen($names[$id + 1])) . $names[$id + 1], $at + 1) : strlen($bytes);
            $entry = substr($bytes, (int) $at, (int) $next - (int) $at);
            $size = static fn (string $key): int => preg_match('/\x03\x00' . chr(strlen($key)) . $key . '(.{4})/s', $entry, $m) === 1 ? unpack('N', $m[1])[1] : 1;
            $variants[substr($name, 10)] = [$id, $size('width'), $size('height')];
            $from = (int) $next;
        }
        return $this->paintingVariants = $variants;
    }

    private function addPainting(array $pos, string $facing, string $variant, array $saved = []): int
    {
        $id = $this->nextEntity++;
        $this->paintings[$id] = $saved + ['uuid' => random_bytes(16), 'pos' => $pos, 'facing' => $facing, 'variant' => $variant, 'checkTicks' => 0];
        $this->paintings[$id]['id'] = $id;
        return $id;
    }

    /** Paintings saved with the level hang where they were. */
    private function restorePaintings(): void
    {
        foreach ($this->world->setting('paintings') ?? [] as $saved) {
            if (!isset($saved['uuid'], $saved['pos'], $saved['facing'], $saved['variant'], $this->paintingVariants()[$saved['variant']]) || !in_array($saved['facing'], self::PAINTING_FACINGS, true)) {
                continue;
            }
            $saved['uuid'] = hex2bin($saved['uuid']);
            $saved['pos'] = array_map('intval', $saved['pos']);
            $this->addPainting($saved['pos'], $saved['facing'], $saved['variant'], $saved);
        }
    }

    private function savedPaintings(): array
    {
        return array_values(array_map(static fn (array $painting): array => ['uuid' => bin2hex($painting['uuid'])]
            + array_intersect_key($painting, array_flip(['pos', 'facing', 'variant'])), $this->paintings));
    }

    private function removePainting(int $id): void
    {
        unset($this->paintings[$id]);
        foreach ($this->localPlayers() as $player) {
            $this->forgetEntity($player, $id);
        }
    }

    /** The selector-shaped reference a command names a painting by. */
    private function paintingReference(int $id): array
    {
        $painting = $this->paintings[$id];
        $box = $this->paintingBox($painting);
        return ['painting' => $id, 'x' => ($box[0] + $box[3]) / 2, 'y' => ($box[1] + $box[4]) / 2, 'z' => ($box[2] + $box[5]) / 2,
            'yaw' => (float) (array_search($painting['facing'], self::PAINTING_FACINGS, true) * 90), 'pitch' => 0.0, 'type' => 'minecraft:painting',
            'width' => 0.5, 'height' => 0.5, 'id' => $id, 'uuid' => $painting['uuid']];
    }

    /**
     * Painting.calculateBoundingBox: from 0.46875 back from the block's centre, shifted half a
     * block left and up for an even width or height, the variant's size across and 0.0625 deep.
     */
    private function paintingBox(array $painting): array
    {
        [, $width, $height] = $this->paintingVariants()[$painting['variant']];
        $step = self::FRAME_FACES[$painting['facing']][1];
        // Direction.getCounterClockWise of the facing: its left.
        $left = ['north' => [-1, 0], 'south' => [1, 0], 'west' => [0, 1], 'east' => [0, -1]][$painting['facing']];
        $horizontal = $width % 2 === 0 ? 0.5 : 0.0;
        $vertical = $height % 2 === 0 ? 0.5 : 0.0;
        $x = $painting['pos'][0] + 0.5 - $step[0] * 0.46875 + $left[0] * $horizontal;
        $y = $painting['pos'][1] + 0.5 + $vertical;
        $z = $painting['pos'][2] + 0.5 - $step[2] * 0.46875 + $left[1] * $horizontal;
        $size = [$step[0] !== 0 ? 0.0625 : $width, $height, $step[2] !== 0 ? 0.0625 : $width];
        return [$x - $size[0] / 2, $y - $size[1] / 2, $z - $size[2] / 2, $x + $size[0] / 2, $y + $size[1] / 2, $z + $size[2] / 2];
    }

    /**
     * HangingEntity.survives: nothing solid in its box, solid blocks (or repeaters and
     * comparators) behind all of it, and no other painting in its box nor any hanging entity
     * facing the same way.
     */
    private function paintingSurvives(array $painting): bool
    {
        $box = $this->paintingBox($painting);
        $collision = new Collision($this->version->catalog);
        foreach ($collision->boxes($this->world, $box) as $other) {
            if ($other[0] < $box[3] && $other[3] > $box[0] && $other[1] < $box[4] && $other[4] > $box[1] && $other[2] < $box[5] && $other[5] > $box[2]) {
                return false;
            }
        }
        $step = self::FRAME_FACES[$painting['facing']][1];
        $support = [$box[0] - $step[0] * 0.5 + 1.0E-7, $box[1] + 1.0E-7, $box[2] - $step[2] * 0.5 + 1.0E-7, $box[3] - $step[0] * 0.5 - 1.0E-7, $box[4] - 1.0E-7, $box[5] - $step[2] * 0.5 - 1.0E-7];
        for ($x = (int) floor($support[0]); $x <= (int) floor($support[3]); ++$x) {
            for ($y = (int) floor($support[1]); $y <= (int) floor($support[4]); ++$y) {
                for ($z = (int) floor($support[2]); $z <= (int) floor($support[5]); ++$z) {
                    $state = $this->world->get($x, $y, $z);
                    $name = $this->version->catalog->blockName($state);
                    if (!$this->fireBlocks()->flag($state, Blocks::SOLID) && $name !== 'repeater' && $name !== 'comparator') {
                        return false;
                    }
                }
            }
        }
        $meets = static fn (array $o): bool => $o[0] < $box[3] && $o[3] > $box[0] && $o[1] < $box[4] && $o[4] > $box[1] && $o[2] < $box[5] && $o[5] > $box[2];
        foreach ($this->paintings as $id => $other) {
            if ($id !== ($painting['id'] ?? null) && $meets($this->paintingBox($other))) {
                return false;
            }
        }
        foreach ($this->itemFrames as $frame) {
            if ($frame['facing'] === $painting['facing'] && $meets($this->itemFrameBox($frame))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Painting.create for the painting item used on a wall: of the placeable variants that would
     * stay there, one of the largest at random; then its placing sound, and the item is used up.
     */
    private function placePainting(Client $c, int $slot, int $x, int $y, int $z, string $face): bool
    {
        if ($face === 'up' || $face === 'down') {
            return false;
        }
        $step = self::FRAME_FACES[$face][1];
        $pos = [$x + $step[0], $y, $z + $step[2]];
        $fitting = [];
        foreach ($this->version->registryTags('minecraft:painting_variant')['minecraft:placeable'] ?? [] as $variant) {
            if (isset($this->paintingVariants()[$variant]) && $this->paintingSurvives(['pos' => $pos, 'facing' => $face, 'variant' => $variant])) {
                $fitting[$variant] = $this->paintingVariants()[$variant][1] * $this->paintingVariants()[$variant][2];
            }
        }
        if ($fitting === []) {
            return true;
        }
        $largest = array_keys($fitting, max($fitting), true);
        $id = $this->addPainting($pos, $face, $largest[random_int(0, count($largest) - 1)]);
        $this->paintingSound($this->paintings[$id], 'place');
        foreach ($this->localPlayers() as $player) {
            $this->showPainting($player, $id);
        }
        if ($c->gameMode !== 1) {
            \PhpMinecraftJavaServer\World\Inventory::consume($c->inventory, $slot);
            $this->syncInventory($c);
        }
        return true;
    }

    /** Entity.playSound at the painting's centre. */
    private function paintingSound(array $painting, string $event): void
    {
        $box = $this->paintingBox($painting);
        [$x, $y, $z] = [($box[0] + $box[3]) / 2, ($box[1] + $box[4]) / 2, ($box[2] + $box[5]) / 2];
        $payload = Packets::sound($this->version->blocks['sounds']['entity.painting.' . $event], 6, $x, $y, $z, 1.0, 1.0, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $x) ** 2 + ($player->y - $y) ** 2 + ($player->z - $z) ** 2 < 256.0) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    /**
     * BlockAttachedEntity.hurtServer, then Painting.dropItem: the painting is gone, and with
     * entity drops on it plays its breaking sound and leaves a painting, unless a creative
     * player broke it.
     */
    private function breakPainting(int $id, ?Client $cause): void
    {
        $painting = $this->paintings[$id];
        $this->removePainting($id);
        if ($this->gameRule('entity_drops') !== 'true') {
            return;
        }
        $this->paintingSound($painting, 'break');
        if ($cause !== null && $cause->gameMode === 1) {
            return;
        }
        $box = $this->paintingBox($painting);
        $step = self::FRAME_FACES[$painting['facing']][1];
        // HangingEntity.spawnAtLocation: 0.15 out along the face.
        $this->spawnAtLocation(
            ($box[0] + $box[3]) / 2 + $step[0] * 0.15000000596046448,
            ($box[1] + $box[4]) / 2,
            ($box[2] + $box[5]) / 2 + $step[2] * 0.15000000596046448,
            ['item' => $this->version->blocks['items']['painting'], 'count' => 1]
        );
    }

    private function attackPainting(Client $c, int $id): bool
    {
        if (!isset($this->paintings[$id])) {
            return false;
        }
        $this->breakPainting($id, $c);
        return true;
    }

    /** BlockAttachedEntity.tick: every 100 ticks a painting that can no longer stay drops. */
    private function tickPaintings(): void
    {
        foreach (array_keys($this->paintings) as $id) {
            $this->synchronizePainting($id);
            if ($this->paintings[$id]['checkTicks']++ < 100) {
                continue;
            }
            $this->paintings[$id]['checkTicks'] = 0;
            if (!$this->paintingSurvives($this->paintings[$id])) {
                $this->breakPainting($id, null);
            }
        }
    }

    /** ServerEntity.sendChanges for a painting, which never moves: one move of nothing on its first tick. */
    private function synchronizePainting(int $id): void
    {
        $painting = $this->paintings[$id];
        $box = $this->paintingBox($painting);
        foreach ($this->localPlayers() as $player) {
            if (!isset($player->entities[$id])) {
                $this->showPainting($player, $id);
            } elseif (!$this->tracksPosition($player, ($box[0] + $box[3]) / 2, ($box[2] + $box[5]) / 2, 'painting')) {
                $this->forgetEntity($player, $id);
            }
        }
        if (!($painting['tracked'] ?? false)) {
            $this->paintings[$id]['tracked'] = true;
            foreach ($this->localPlayers() as $player) {
                if (isset($player->entities[$id])) {
                    $player->send($this->version, 'move_entity_pos', Binary::writeVarInt($id) . pack('nnn', 0, 0, 0) . "\0");
                }
            }
        }
    }

    /**
     * ServerEntity.sendPairingData: Painting.getAddEntityPacket puts it at its block's corner
     * with its face as the data, then its facing (8) and variant (9, the registry ID plus one).
     */
    private function showPainting(Client $player, int $id): bool
    {
        $painting = $this->paintings[$id];
        $box = $this->paintingBox($painting);
        if (isset($player->entities[$id]) || !$this->tracksPosition($player, ($box[0] + $box[3]) / 2, ($box[2] + $box[5]) / 2, 'painting')) {
            return isset($player->entities[$id]);
        }
        $yaw = array_search($painting['facing'], self::PAINTING_FACINGS, true) * 90;
        $payload = Binary::writeVarInt($id) . $painting['uuid'] . Binary::writeVarInt($this->version->blocks['entity_types']['painting'])
            . pack('EEE', (float) $painting['pos'][0], (float) $painting['pos'][1], (float) $painting['pos'][2]) . LowPrecisionVector::encode(0.0, 0.0, 0.0)
            . chr(0) . chr(((int) floor($yaw * 256.0 / 360.0)) & 255) . chr(0) . Binary::writeVarInt(self::FRAME_FACES[$painting['facing']][0]);
        $data = '';
        if ($painting['facing'] !== 'south') {
            $data .= "\x08" . Binary::writeVarInt(12) . Binary::writeVarInt(self::FRAME_FACES[$painting['facing']][0]);
        }
        $variant = $this->paintingVariants()[$painting['variant']][0];
        if ($variant !== 0) {
            $data .= "\x09" . Binary::writeVarInt(34) . Binary::writeVarInt($variant + 1);
        }
        $player->send($this->version, 'bundle_delimiter', '');
        $player->send($this->version, 'add_entity', $payload);
        if ($data !== '') {
            $player->send($this->version, 'set_entity_data', Binary::writeVarInt($id) . $data . "\xff");
        }
        $player->send($this->version, 'bundle_delimiter', '');
        $player->entities[$id] = true;
        return true;
    }
}
