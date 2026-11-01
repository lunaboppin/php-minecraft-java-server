<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Nbt;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\Generation\Float32;

/**
 * ServerPlayer's RespawnConfig: the bed or respawn anchor a player last used (or a forced
 * point), the dimension it is in, and PlayerList.respawn finding where to stand up there.
 * RespawnAnchorBlock charges with glowstone, sets the spawn in the Nether and explodes elsewhere.
 */
trait RespawnPoints
{
    /** RespawnAnchorBlock.RESPAWN_HORIZONTAL_OFFSETS, then the same one block down and one up. */
    private const ANCHOR_OFFSETS = [[0, -1], [-1, 0], [0, 1], [1, 0], [-1, -1], [1, -1], [-1, 1], [1, 1]];

    /**
     * The player's RespawnConfig, with an older saved [x, y, z] stand-up point read as the bed
     * under it in the Overworld.
     *
     * @return array{dimension: string, pos: array{int, int, int}, yaw: float, pitch: float, forced: bool}|null
     */
    private function respawnConfig(Client $c): ?array
    {
        $spawn = $c->personalSpawn;
        if ($spawn === null || isset($spawn['dimension'])) {
            return $spawn;
        }
        return ['dimension' => 'overworld', 'pos' => [(int) floor($spawn[0]), (int) floor($spawn[1]), (int) floor($spawn[2])], 'yaw' => 0.0, 'pitch' => 0.0, 'forced' => false];
    }

    /** ServerPlayer.setRespawnPosition: "Respawn point set" when it moves, if asked. */
    private function setRespawnPoint(Client $c, ?array $config, bool $message): void
    {
        $old = $this->respawnConfig($c);
        if ($message && $config !== null && ($old === null || $old['dimension'] !== $config['dimension'] || $old['pos'] !== $config['pos'])) {
            $c->send($this->version, 'system_chat', Nbt::component(['translate' => 'block.minecraft.set_spawn']) . "\0");
        }
        $c->personalSpawn = $config;
        $this->savePlayer($c);
    }

    private function blockSoundAt(int $x, int $y, int $z, string $sound): void
    {
        $payload = Packets::sound($this->version->blocks['sounds'][$sound], 4, $x + 0.5, $y + 0.5, $z + 0.5, 1.0, 1.0, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $x - 0.5) ** 2 + ($player->y - $y - 0.5) ** 2 + ($player->z - $z - 0.5) ** 2 < 16.0 ** 2) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    /**
     * RespawnAnchorBlock.useItemOn and useWithoutItem: glowstone charges it up to four; used
     * charged, it sets the respawn point in the Nether and explodes anywhere else.
     */
    private function useRespawnAnchor(Client $c, int $x, int $y, int $z, int $slot): bool
    {
        $catalog = $this->version->catalog;
        $state = $this->world->get($x, $y, $z);
        $charge = (int) $catalog->properties($state)['charges'];
        $stack = $c->inventory[$slot] ?? null;
        if ($stack !== null && $catalog->itemName($stack['item']) === 'glowstone' && $charge < 4) {
            $this->setBlock($x, $y, $z, $catalog->state('respawn_anchor', ['charges' => (string) ($charge + 1)]));
            $this->blockSoundAt($x, $y, $z, 'block.respawn_anchor.charge');
            if ($c->gameMode !== 1) {
                \PhpMinecraftJavaServer\World\Inventory::consume($c->inventory, $slot);
                $this->syncInventory($c);
            }
            return true;
        }
        if ($charge === 0) {
            return false;
        }
        if ($this->world->dimension !== 'the_nether') {
            // RespawnAnchorBlock.explode: the anchor goes first.
            $this->setBlock($x, $y, $z, $catalog->state('air'));
            $this->explode($x + 0.5, $y + 0.5, $z + 0.5, 5.0, null, null, true, 'minecraft:bad_respawn_point');
            return true;
        }
        $config = ['dimension' => $this->world->dimension, 'pos' => [$x, $y, $z], 'yaw' => 0.0, 'pitch' => 0.0, 'forced' => false];
        $old = $this->respawnConfig($c);
        if ($old === null || $old['dimension'] !== $config['dimension'] || $old['pos'] !== $config['pos']) {
            $this->setRespawnPoint($c, $config, true);
            $this->blockSoundAt($x, $y, $z, 'block.respawn_anchor.set_spawn');
        }
        return true;
    }

    /**
     * ServerPlayer.findRespawnAndUseSpawnBlock in the spawn's dimension: beside a charged anchor
     * (using a charge) or the bed, or at a forced point with room; null when nothing is there.
     *
     * @return array{0: string, 1: array{float, float, float}, 2: float, 3: float}|null dimension, position, yaw, pitch
     */
    private function respawnTarget(Client $c, bool $consume = true): ?array
    {
        $config = $this->respawnConfig($c);
        if ($config === null || !isset($this->worlds[$config['dimension']])) {
            return null;
        }
        return $this->withDimension($config['dimension'], function () use ($config, $consume): ?array {
            $catalog = $this->version->catalog;
            [$x, $y, $z] = $config['pos'];
            $state = $this->world->get($x, $y, $z);
            $name = $catalog->blockName($state);
            $position = null;
            if ($name === 'respawn_anchor' && ($config['forced'] || (int) $catalog->properties($state)['charges'] > 0) && $this->world->dimension === 'the_nether') {
                foreach ([true, false] as $safe) {
                    foreach ([0, -1, 1] as $dy) {
                        foreach (self::ANCHOR_OFFSETS as [$dx, $dz]) {
                            $position ??= $this->bedDismountLocation($x + $dx, $y + $dy, $z + $dz, $safe);
                        }
                    }
                }
                if ($position !== null && !$config['forced'] && $consume) {
                    $this->setBlock($x, $y, $z, $catalog->state('respawn_anchor', ['charges' => (string) ((int) $catalog->properties($state)['charges'] - 1)]));
                    // PlayerList.respawn: the anchor sounds as it is used up.
                    $this->blockSoundAt($x, $y, $z, 'block.respawn_anchor.deplete');
                }
            } elseif (str_ends_with($name, '_bed') && $this->world->dimension === 'overworld') {
                $position = $this->standUpPosition($x, $y, $z, $catalog->properties($state)['facing'], $config['yaw']);
            } elseif ($config['forced']) {
                $free = fn (int $b): bool => !$this->fireBlocks()->isSolid($this->world->get($x, $b, $z)) && !$this->fireBlocks()->hasFluid($this->world->get($x, $b, $z));
                $position = $free($y) && $free($y + 1) ? [$x + 0.5, $y + 0.1, $z + 0.5] : null;
                return $position === null ? null : [$config['dimension'], $position, $config['yaw'], $config['pitch']];
            }
            if ($position === null) {
                return null;
            }
            // RespawnPosAngle.of: facing the block's bottom centre.
            $yaw = rad2deg(atan2($z + 0.5 - $position[2], $x + 0.5 - $position[0])) - 90.0;
            $yaw = Float32::round(fmod(fmod($yaw + 180.0, 360.0) + 360.0, 360.0) - 180.0);
            return [$config['dimension'], $position, $yaw, 0.0];
        });
    }
}
