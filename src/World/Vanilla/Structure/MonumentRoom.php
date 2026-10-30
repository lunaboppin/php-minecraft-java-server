<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Structure;

/**
 * OceanMonumentPieces.RoomDefinition: one cell of the monument's 5 by 4 by 3 room grid (or a
 * wing or the penthouse, from index 1001), its neighbours by Direction.get3DDataValue (down, up,
 * north, south, west, east) and which of them it opens to.
 */
final class MonumentRoom
{
    /** @var array<int, ?self> */
    public array $connections = [null, null, null, null, null, null];

    /** @var array<int, bool> */
    public array $hasOpening = [false, false, false, false, false, false];

    public bool $claimed = false;

    public bool $isSource = false;

    private int $scanIndex = 0;

    public function __construct(public readonly int $index)
    {
    }

    public function setConnection(int $direction, self $room): void
    {
        $this->connections[$direction] = $room;
        $room->connections[$direction ^ 1] = $this;
    }

    public function updateOpenings(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $this->hasOpening[$i] = $this->connections[$i] !== null;
        }
    }

    public function findSource(int $scanIndex): bool
    {
        if ($this->isSource) {
            return true;
        }
        $this->scanIndex = $scanIndex;
        for ($i = 0; $i < 6; ++$i) {
            $next = $this->connections[$i];
            if ($next !== null && $this->hasOpening[$i] && $next->scanIndex !== $scanIndex && $next->findSource($scanIndex)) {
                return true;
            }
        }
        return false;
    }

    public function isSpecial(): bool
    {
        return $this->index >= 75;
    }

    public function countOpenings(): int
    {
        return count(array_filter($this->hasOpening));
    }
}
