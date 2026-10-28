<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
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
