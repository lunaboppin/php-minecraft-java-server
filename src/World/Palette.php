<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World;
use PhpMinecraftJavaServer\Protocol\Binary;

final class Palette
{
    /** Padded little-bit-order entries inside network-order 64-bit words, no length prefix in 26.3. */
    public static function pack(array $values, int $bits): string {
        if ($bits < 1 || $bits > 32) { throw new \InvalidArgumentException('Invalid palette width'); }
        // One range check for the whole list; the packing loop is the hot path of chunk sending.
        if ($values !== [] && (min($values) < 0 || max($values) >= (1 << $bits))) { throw new \InvalidArgumentException('Palette value does not fit'); }
        $perLong = intdiv(64, $bits); $words = [];
        foreach (array_chunk($values, $perLong) as $entries) {
            $word = 0; $shift = 0;
            foreach ($entries as $value) { $word |= $value << $shift; $shift += $bits; }
            $words[] = $word;
        }
        return $words === [] ? '' : pack('J*', ...$words);
    }
    public static function blocks(array $states, int $globalBits): string {
        if (count($states) !== 4096) { throw new \InvalidArgumentException('Section requires 4096 states'); }
        // Distinct states in first-occurrence order, as array_unique would list them.
        $unique = array_keys(array_count_values($states));
        if (count($unique) === 1) { return "\0" . Binary::writeVarInt($unique[0]); }
        // Mojang's initially empty container retains air as its first palette entry.
        $unique = [0, ...array_values(array_filter($unique, static fn (int $state): bool => $state !== 0))];
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
