<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World\Generation;

/** Explicit rounding at Java float bytecode boundaries; PHP arithmetic otherwise uses binary64. */
final class Float32
{
    public static function round(float $value):float {return unpack('g',pack('g',$value))[1];}
    public static function lerp(float $t,float $a,float $b):float {return self::round($a+self::round($t*self::round($b-$a)));}
    public static function smoothstep(float $t):float {
        $cube=self::round(self::round($t*$t)*$t);
        $inner=self::round(self::round($t*self::round(self::round($t*6)-15))+10);
        return self::round($cube*$inner);
    }
}
