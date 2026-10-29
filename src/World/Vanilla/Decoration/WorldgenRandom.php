<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World\Vanilla\Decoration;

use PhpMinecraftJavaServer\World\Generation\Int64;
use PhpMinecraftJavaServer\World\Generation\PositionalRandom;
use PhpMinecraftJavaServer\World\Generation\RandomSource;
use PhpMinecraftJavaServer\World\Generation\XoroshiroRandom;

/**
 * levelgen.WorldgenRandom over xoroshiro: LegacyRandomSource's algorithms (BitRandomSource)
 * fed with the top bits of each xoroshiro long, so nextDouble and nextLong use two longs.
 */
final class WorldgenRandom extends RandomSource
{
    private XoroshiroRandom $source;

    public function __construct(int $seed = 0)
    {
        $this->source = new XoroshiroRandom($seed);
    }

    public function next(int $bits): int
    {
        $value = Int64::unsignedShift($this->source->nextLong(), 64 - $bits);
        return $bits === 32 ? Int64::signed32($value) : $value;
    }

    public function setSeed(int $seed): void
    {
        $this->source->setSeed($seed);
        $this->gaussian = null;
    }

    public function nextInt(?int $bound = null): int
    {
        if ($bound === null) {
            return $this->next(32);
        }
        self::bound($bound);
        if (($bound & ($bound - 1)) === 0) {
            return ($bound * $this->next(31)) >> 31;
        }
        do {
            $bits = $this->next(31);
            $value = $bits % $bound;
        } while ($bits - $value + $bound - 1 >= 2147483648);
        return $value;
    }

    public function nextLong(): int
    {
        $upper = $this->next(32);
        $lower = $this->next(32);
        return Int64::add($upper << 32, $lower);
    }

    public function nextFloat(): float
    {
        return $this->next(24) / 16777216.0;
    }

    public function nextDouble(): float
    {
        $upper = $this->next(26);
        $lower = $this->next(27);
        return (($upper << 27) + $lower) / 9007199254740992.0;
    }

    public function nextBoolean(): bool
    {
        return $this->next(1) !== 0;
    }

    public function fork(): RandomSource
    {
        return $this->source->fork();
    }

    public function forkPositional(): PositionalRandom
    {
        return $this->source->forkPositional();
    }

    /** WorldgenRandom.setDecorationSeed */
    public function setDecorationSeed(int $seed, int $x, int $z): int
    {
        $this->setSeed($seed);
        $xScale = $this->nextLong() | 1;
        $zScale = $this->nextLong() | 1;
        $result = Int64::add(Int64::multiply($x, $xScale), Int64::multiply($z, $zScale)) ^ $seed;
        $this->setSeed($result);
        return $result;
    }

    /** WorldgenRandom.setFeatureSeed */
    public function setFeatureSeed(int $seed, int $index, int $step): void
    {
        $this->setSeed(Int64::add(Int64::add($seed, $index), 10000 * $step));
    }
}
