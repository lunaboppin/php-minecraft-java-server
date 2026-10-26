<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World\Generation;

/** Legacy terrain's vertical smear, with the original interpolation coordinate retained. */
final class SmearedPerlinNoise extends PerlinNoise
{
    public function __construct(RandomSource $random,private readonly float $fudgeYScale){parent::__construct($random);}
    public function get(float $x,float $y,float $z):float {
        $rawY=$y;$x=self::wrap($x)+$this->offsetX;$y=self::wrap($y)+$this->offsetY;$z=self::wrap($z)+$this->offsetZ;
        $ix=(int)floor($x);$iy=(int)floor($y);$iz=(int)floor($z);$fractionY=$y-$iy;
        $limited=$rawY>=0&&$rawY<$fractionY?$rawY:$fractionY;
        $fudge=floor($limited/$this->fudgeYScale+1.0000000116860974e-7)*$this->fudgeYScale;
        return $this->sampleAndLerp($ix,$iy,$iz,Float32::round($x-$ix),Float32::round($fractionY-$fudge),Float32::round($z-$iz),Float32::round($fractionY));
    }
}
