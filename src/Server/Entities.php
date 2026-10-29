<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\Generation\Float32;

trait Entities
{
    private array $itemEntities = [];

    /** An item entity; $age 5999 is ItemEntity.makeFakeItem's, gone on its next tick. */
    private function dropStack(float $x, float $y, float $z, array $stack, int $age = 0): void
    {
        if ($stack['count'] <= 0) {
            return;
        }
        // The entity ID is taken even when too many items already lie around to keep this one.
        $id = $this->nextEntity++;
        if (count($this->itemEntities) >= 256) {
            return;
        }
        $this->itemEntities[$id] = ['id' => $id, 'uuid' => random_bytes(16), 'x' => $x, 'y' => $y, 'z' => $z, 'stack' => $stack, 'age' => $age, 'created' => $this->ticks];
        foreach ($this->localPlayers() as $player) {
            $this->showItem($player, $this->itemEntities[$id]);
        }
    }

    private function itemMetadata(array $entity): string
    {
        return Binary::writeVarInt($entity['id']) . chr($this->version->catalog->itemMetadataIndex) . Binary::writeVarInt($this->version->catalog->itemMetadataSerializer) . Packets::stack($entity['stack'], $this->version->blocks['component_damage'], $this->version->catalog->itemStacks) . "\xff";
    }

    private function showItem(Client $c, array $entity): void
    {
        $id = $entity['id'];
        if (isset($c->entities[$id]) || !$this->tracksPosition($c, $entity['x'], $entity['z'], 'item')) {
            return;
        }
        $c->send($this->version, 'add_entity', Packets::addPlayer($id, $entity['uuid'], $this->version->blocks['entity_types']['item'], $entity['x'], $entity['y'], $entity['z'], 0, 0));
        $c->send($this->version, 'set_entity_data', $this->itemMetadata($entity));
        $c->entities[$id] = true;
    }

    /**
     * ItemEntity.hurtServer: items resisting the damage type (netherite against fire), or fall
     * damage for a fall-immune entity type, take none; otherwise their health of 5 falls by the
     * damage, truncated, and the item is removed at 0 or below.
     */
    private function hurtItem(int $id, float $amount, string $type): bool
    {
        $item = $this->itemEntities[$id] ?? null;
        if ($item === null) {
            return false;
        }
        static $reference = null;
        $reference ??= json_decode((string) file_get_contents($this->version->directory . '/item-entities.json'), true, 512, JSON_THROW_ON_ERROR);
        $name = $this->version->catalog->itemName($item['stack']['item']);
        $fall = in_array($type, $this->version->catalog->damageTypes['tags']['minecraft:is_fall'] ?? [], true);
        if (in_array($type, $reference['damage_resistant'][$name] ?? [], true)
            || ($fall && in_array('item', $this->version->registryTags('minecraft:entity_type')['minecraft:fall_damage_immune'] ?? [], true))) {
            return false;
        }
        $health = (int) Float32::round(($item['health'] ?? 5) - $amount);
        $this->itemEntities[$id]['health'] = $health;
        if ($health <= 0) {
            $this->removeItem($id);
        }
        return true;
    }

    private function removeItem(int $id): void
    {
        if (isset($this->itemEntities[$id])) {
            $this->entityRemoved($this->itemEntities[$id]['uuid']);
        }
        unset($this->itemEntities[$id]);
        foreach ($this->localPlayers() as $player) {
            if (isset($player->entities[$id])) {
                $player->send($this->version, 'remove_entities', "\1" . Binary::writeVarInt($id));
                unset($player->entities[$id]);
            }
        }
    }

    private function tickItems(): void
    {
        if ($this->ticks % 4 !== 0) {
            return;
        }
        foreach ($this->itemEntities as $id => &$entity) {
            $entity['age'] += 4;
            if ($entity['age'] >= 6000 || $entity['y'] < -80) {
                $this->removeItem($id);
                continue;
            }
            $x = (int) floor($entity['x']);
            $z = (int) floor($entity['z']);
            $y = (int) floor($entity['y'] - 0.15);
            if (!$this->version->catalog->motionBlocking($this->world->get($x, $y, $z))) {
                $entity['y'] -= 0.25;
                foreach ($this->localPlayers() as $p) {
                    if (isset($p->entities[$id])) {
                        $p->send($this->version, 'teleport_entity', Packets::teleportEntity($id, $entity['x'], $entity['y'], $entity['z'], 0, 0, false));
                    }
                }
            }
            foreach ($this->localPlayers() as $player) {
                $this->showItem($player, $entity);
                if ($entity['age'] < 12 || $player->dead || $player->gameMode === 3 || $player->teleport !== null || ($player->x - $entity['x']) ** 2 + ($player->z - $entity['z']) ** 2 > 2.25 || abs($player->y - $entity['y']) > 1.8) {
                    continue;
                }
                $before = $entity['stack']['count'];
                $left = $this->giveStack($player, $entity['stack']);
                $picked = $before - $left;
                if ($picked > 0) {
                    foreach ($this->localPlayers() as $p) {
                        if (isset($p->entities[$id])) {
                            $p->send($this->version, 'take_item_entity', Binary::writeVarInt($id) . Binary::writeVarInt($player->entityId) . Binary::writeVarInt($picked));
                        }
                    }
                    $this->syncInventory($player);
                    if ($left === 0) {
                        $this->removeItem($id);
                        break;
                    }
                    $entity['stack']['count'] = $left;
                    foreach ($this->localPlayers() as $p) {
                        if (isset($p->entities[$id])) {
                            $p->send($this->version, 'set_entity_data', $this->itemMetadata($entity));
                        }
                    }
                }
            }
        }unset($entity);
    }
}
