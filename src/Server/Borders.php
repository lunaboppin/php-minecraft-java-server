<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\World\Collision;
use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\WorldBorder;

/** Each dimension's world border, saved in its world and synchronized to its players. */
trait Borders
{
    /** @var array<string, WorldBorder> */
    private array $borders = [];

    private function border(string $dimension): WorldBorder
    {
        return $this->borders[$dimension] ??= WorldBorder::fromArray($this->worlds[$dimension]->setting('worldBorder'));
    }

    /**
     * Save a changed border and send its packet to the dimension's players (BorderChangeListener);
     * damage settings are server-side only.
     */
    private function borderChanged(string $dimension, ?string $packet = null, string $payload = ''): void
    {
        $this->worlds[$dimension]->setting('worldBorder', $this->border($dimension)->toArray());
        foreach ($packet === null ? [] : $this->players() as $player) {
            if ($player->dimension === $dimension) {
                $player->send($this->version, $packet, $payload);
            }
        }
    }

    private function sendBorder(Client $c): void
    {
        $c->send($this->version, 'initialize_border', $this->border($c->dimension)->initializePacket($this->worldAge));
    }

    /**
     * Entity.isInWall: a suffocating block whose collision shape overlaps a box 0.8 of the
     * entity's width wide and 1e-6 high around its eyes.
     */
    private function inWall(float $x, float $eyeY, float $z, float $width): bool
    {
        $half = Float32::round($width * Float32::round(0.8)) / 2.0;
        $box = [$x - $half, $eyeY - 5.0E-7, $z - $half, $x + $half, $eyeY + 5.0E-7, $z + $half];
        $collision = new Collision($this->version->catalog);
        for ($bx = (int) floor($box[0]); $bx <= (int) floor($box[3]); ++$bx) {
            for ($by = (int) floor($box[1]); $by <= (int) floor($box[4]); ++$by) {
                for ($bz = (int) floor($box[2]); $bz <= (int) floor($box[5]); ++$bz) {
                    $state = $this->world->get($bx, $by, $bz);
                    if (!$this->suffocates($state)) {
                        continue;
                    }
                    foreach ($collision->shape($state) as $shape) {
                        if ($box[0] < $bx + $shape[3] && $box[3] > $bx + $shape[0] && $box[1] < $by + $shape[4] && $box[4] > $by + $shape[1] && $box[2] < $bz + $shape[5] && $box[5] > $bz + $shape[2]) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * BlockState.isSuffocating: a block's own predicate (flag 256), or the default one (flag 512):
     * in the causes_suffocation tag with a full-block collision shape.
     */
    private function suffocates(int $state): bool
    {
        $catalog = $this->version->catalog;
        if ($catalog->flag($state, 256)) {
            return true;
        }
        if (!$catalog->flag($state, 512) || $catalog->air($state)) {
            return false;
        }
        static $tag = null;
        $tag ??= array_flip($this->version->registryTags('minecraft:block')['minecraft:causes_suffocation']);
        return isset($tag[$catalog->blockName($state)]) && (new Collision($catalog))->shape($state) === [[0.0, 0.0, 0.0, 1.0, 1.0, 1.0]];
    }

    /** LivingEntity.baseTick: outside the safe zone, damage grows with the distance past it. */
    private function borderDamage(Client $c): void
    {
        $border = $this->border($c->dimension);
        $distance = $border->distanceToBorder($c->x, $c->z, $this->worldAge) + $border->safeZone;
        if ($distance < 0.0 && $border->damagePerBlock > 0.0) {
            $this->damage($c, (float) max(1, (int) floor(-$distance * $border->damagePerBlock)), 'outside_border');
        }
    }

    private static function borderSizePacket(float $size): string
    {
        return pack('E', $size);
    }

    private static function borderLerpPacket(float $from, float $to, int $ticks): string
    {
        return pack('EE', $from, $to) . Binary::writeVarLong($ticks);
    }
}
