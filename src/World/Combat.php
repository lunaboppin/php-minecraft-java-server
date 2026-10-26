<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World;
use PhpMinecraftJavaServer\World\Generation\Float32;

/** Ordinary unenchanted melee/armor arithmetic; species effects and special attacks remain separate. */
final class Combat
{
    private static function f(float $n):float{return Float32::round($n);}
    public static function strength(float $speed,int $ticks,float $partial=0.5):float {
        $delay=self::f((1/$speed)*20);return min(max(self::f(self::f(self::f($ticks)+$partial)/$delay),0.0),1.0);
    }
    public static function attack(float $damage,float $speed,int $ticks):float {
        $strength=self::strength($speed,$ticks);$factor=self::f(self::f(0.2)+self::f(self::f($strength*$strength)*self::f(0.8)));
        return self::f(self::f($damage)*$factor);
    }
    public static function afterArmor(float $incoming,float $armor,float $toughness):float {
        $incoming=self::f($incoming);$armor=self::f($armor);$toughness=self::f($toughness);
        $factor=self::f(2+self::f($toughness/4));$effective=min(max(self::f($armor-self::f($incoming/$factor)),self::f($armor*self::f(0.2))),20.0);
        return self::f($incoming*self::f(1-self::f($effective/25)));
    }
}
