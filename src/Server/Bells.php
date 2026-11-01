<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Packets;

/**
 * BellBlock: a bell rings when used on a side it swings from or when it becomes powered, with
 * its block event (which sets clients' bells swinging) and its sound.
 */
trait Bells
{
    /** BellBlock.attemptToRing: BellBlockEntity.onHit's block event, toward the hit side or the bell's facing. */
    private function ringBell(int $x, int $y, int $z, ?string $direction): void
    {
        $state = $this->world->get($x, $y, $z);
        $direction ??= $this->version->catalog->properties($state)['facing'];
        $data = array_search($direction, ['down', 'up', 'north', 'south', 'west', 'east'], true);
        $event = [[$x, $y, $z], 'bell', 1, $data];
        if (!in_array($event, $this->blockEvents[$this->world->dimension] ?? [], true)) {
            $this->blockEvents[$this->world->dimension][] = $event;
        }
        $payload = Packets::sound($this->version->blocks['sounds']['block.bell.use'], 4, $x + 0.5, $y + 0.5, $z + 0.5, 2.0, 1.0, unpack('J', random_bytes(8))[1]);
        foreach ($this->localPlayers() as $player) {
            if (($player->x - $x - 0.5) ** 2 + ($player->y - $y - 0.5) ** 2 + ($player->z - $z - 0.5) ** 2 < 32.0 ** 2) {
                $player->send($this->version, 'sound', $payload);
            }
        }
    }

    /**
     * BellBlock.useWithoutItem and isProperHit: a horizontal click below the bell's top part, on a
     * side the bell swings from; true when it rang.
     */
    private function useBell(int $x, int $y, int $z, int $face, float $cursorY): bool
    {
        $p = $this->version->catalog->properties($this->world->get($x, $y, $z));
        $clicked = ['down', 'up', 'north', 'south', 'west', 'east'][$face];
        if ($face < 2 || $cursorY > 0.8124) {
            return false;
        }
        $axis = static fn (string $d): string => $d === 'north' || $d === 'south' ? 'z' : 'x';
        $proper = match ($p['attachment']) {
            'floor' => $axis($p['facing']) === $axis($clicked),
            'single_wall', 'double_wall' => $axis($p['facing']) !== $axis($clicked),
            default => true,
        };
        if ($proper) {
            $this->ringBell($x, $y, $z, $clicked);
        }
        return $proper;
    }

    /** BellBlock.neighborChanged: a bell rings as it becomes powered, and keeps its powered state. */
    private function bellsChanged(int $x, int $y, int $z): void
    {
        $catalog = $this->version->catalog;
        foreach ([[0, 0, 0], [0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]] as [$dx, $dy, $dz]) {
            [$a, $b, $c] = [$x + $dx, $y + $dy, $z + $dz];
            $state = $this->world->get($a, $b, $c);
            if ($catalog->blockName($state) !== 'bell') {
                continue;
            }
            $p = $catalog->properties($state);
            $signal = $this->powerAt($a, $b, $c) > 0;
            if ($signal !== ($p['powered'] === 'true')) {
                if ($signal) {
                    $this->ringBell($a, $b, $c, null);
                }
                $this->setBlock($a, $b, $c, $catalog->state('bell', ['powered' => $signal ? 'true' : 'false'] + $p));
            }
        }
    }
}
