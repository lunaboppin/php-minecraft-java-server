<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla;

/**
 * BlockAndLightGetter.getRawBrightness with no darkening, for a chunk whose neighbours are
 * decorated: the larger of sky and block light. Light reaches a block over the cheapest path from
 * a cell open to the sky (15, where the dimension has sky light) or a light source (its emission),
 * losing at least 1 per step and the dampening of each block it enters; a search outward from the
 * block finds it. Light occlusion by partial block shapes is not modelled.
 */
final class Light
{
    /** @var list<int> two bytes per state: see block-behaviour.json "spawning_bits" */
    private readonly array $spawning;

    /** @var array<string, int> the lowest Y of each column that sky light reaches undimmed */
    private array $skyFloor = [];

    /** @param \Closure(int, int, int): int $get */
    public function __construct(private readonly \Closure $get, private readonly int $minY, private readonly int $maxY, private readonly bool $skyLight, string $behaviourPath)
    {
        static $spawning = [];
        $spawning[$behaviourPath] ??= array_values(unpack('v*', base64_decode(json_decode((string) file_get_contents($behaviourPath), true, 512, JSON_THROW_ON_ERROR)['spawning'])));
        $this->spawning = $spawning[$behaviourPath];
    }

    public function brightness(int $x, int $y, int $z): int
    {
        if ($this->openToSky($x, $y, $z)) {
            return 15;
        }
        $get = $this->get;
        $best = $this->spawning[$get($x, $y, $z)] >> 8 & 15;
        $start = max(1, $this->spawning[$get($x, $y, $z)] & 15);
        // The cost of light's path into the start block, by the cell it comes from.
        $queue = new \SplPriorityQueue();
        $seen = ["$x,$y,$z" => true];
        foreach ([[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]] as [$dx, $dy, $dz]) {
            $queue->insert([$x + $dx, $y + $dy, $z + $dz, $start], -$start);
        }
        while (!$queue->isEmpty()) {
            [$cx, $cy, $cz, $cost] = $queue->extract();
            if ($cost >= 15 - $best) {
                break;
            }
            $key = "$cx,$cy,$cz";
            if (isset($seen[$key]) || $cy < $this->minY || $cy > $this->maxY) {
                continue;
            }
            $seen[$key] = true;
            if ($this->openToSky($cx, $cy, $cz)) {
                return 15 - $cost;
            }
            $state = $get($cx, $cy, $cz);
            $best = max($best, ($this->spawning[$state] >> 8 & 15) - $cost);
            $step = max(1, $this->spawning[$state] & 15);
            if ($step >= 15) {
                continue;
            }
            foreach ([[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]] as [$dx, $dy, $dz]) {
                $queue->insert([$cx + $dx, $cy + $dy, $cz + $dz, $cost + $step], -($cost + $step));
            }
        }
        return $best;
    }

    /** A block with only light-transparent blocks above it, so sky light reaches it undimmed. */
    private function openToSky(int $x, int $y, int $z): bool
    {
        if (!$this->skyLight) {
            return false;
        }
        $key = "$x,$z";
        if (!isset($this->skyFloor[$key])) {
            $get = $this->get;
            $floor = $this->maxY + 1;
            while ($floor > $this->minY && ($this->spawning[$get($x, $floor - 1, $z)] & 15) === 0) {
                --$floor;
            }
            $this->skyFloor[$key] = $floor;
        }
        return $y >= $this->skyFloor[$key];
    }
}
