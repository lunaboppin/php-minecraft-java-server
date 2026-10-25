<?php
declare(strict_types=1);
namespace Jva\World;
use Jva\Protocol\Binary;

final class Palette
{
    /** Padded little-bit-order entries inside network-order 64-bit words, no length prefix in 26.3. */
    public static function pack(array $values, int $bits): string {
        if ($bits < 1 || $bits > 32) { throw new \InvalidArgumentException('Invalid palette width'); }
        $perLong = intdiv(64, $bits); $output = ''; $word = 0; $index = 0;
        foreach ($values as $value) {
            if (!is_int($value) || $value < 0 || $value >= (1 << $bits)) { throw new \InvalidArgumentException('Palette value does not fit'); }
            $word |= $value << ($index * $bits);
            if (++$index === $perLong) { $output .= pack('J', $word); $word = 0; $index = 0; }
        }
        if ($index !== 0) { $output .= pack('J', $word); }
        return $output;
    }
    public static function blocks(array $states, int $globalBits): string {
        if (count($states) !== 4096) { throw new \InvalidArgumentException('Section requires 4096 states'); }
        $unique = array_values(array_unique($states, SORT_NUMERIC));
        if (count($unique) === 1) { return "\0" . Binary::writeVarInt($unique[0]); }
        // Mojang's initially empty container retains air as its first palette entry.
        $unique = array_values(array_unique(array_merge([0], $unique), SORT_NUMERIC));
        if (count($unique) > 256) { return chr($globalBits) . self::pack($states, $globalBits); }
        $bits = max(4, (int)ceil(log(count($unique), 2)));
        $map = array_flip($unique);
        $out = chr($bits) . Binary::writeVarInt(count($unique));
        foreach ($unique as $state) { $out .= Binary::writeVarInt($state); }
        return $out . self::pack(array_map(static fn(int $s): int => $map[$s], $states), $bits);
    }
    public static function biomes(array $biomes, int $globalBits): string {
        if(count($biomes)!==64){throw new \InvalidArgumentException('Biome palette requires 64 entries');}
        $unique=array_values(array_unique($biomes,SORT_NUMERIC));
        if(count($unique)===1){return "\0".Binary::writeVarInt($unique[0]);}
        $bits=max(1,(int)ceil(log(count($unique),2)));
        if($bits>3){return chr($globalBits).self::pack($biomes,$globalBits);}
        $map=array_flip($unique);$out=chr($bits).Binary::writeVarInt(count($unique));
        foreach($unique as $biome){$out.=Binary::writeVarInt($biome);}
        return $out.self::pack(array_map(static fn(int $v):int=>$map[$v],$biomes),$bits);
    }
    public static function section(array $states, int $biome, int $globalBits): string {
        $count = count(array_filter($states, static fn(int $s): bool => $s !== 0));
        return pack('nn', $count, 0) . self::blocks($states, $globalBits) . "\0" . Binary::writeVarInt($biome);
    }
}
