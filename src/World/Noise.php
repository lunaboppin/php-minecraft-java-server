<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World;

/** Integer hash and smoothly interpolated value noise, stable across chunk boundaries. */
final class Noise
{
    public function __construct(private readonly int $seed) {}
    public function hash(int $x,int $y,int $z,int $salt=0): int {
        $n=($x*374761393+$y*1442695041+$z*668265263+$this->seed+$salt*1013904223)&0x7fffffff;
        $n=(($n^($n>>13))*1274126177)&0x7fffffff;
        return ($n^($n>>16))&0x7fffffff;
    }
    /**
     * Lattice value for a precomputed hash input. Integer sums are exact here, so splitting
     * hash()'s linear terms into per-axis parts gives the same values without a lookup cache.
     */
    public static function node(int $sum): float {
        $n=$sum&0x7fffffff;
        $n=(($n^($n>>13))*1274126177)&0x7fffffff;
        return (($n^($n>>16))&0x7fffffff)/1073741823.5-1;
    }
    public static function fade(float $v): float { return $v*$v*$v*($v*($v*6-15)+10); }
    public static function lerp(float $a,float $b,float $t): float { return $a+($b-$a)*$t; }
    public function sample2(float $x,float $z,int $salt=0): float {
        $ix=(int)floor($x);$iz=(int)floor($z);$fx=self::fade($x-$ix);$fz=self::fade($z-$iz);
        $x0=$ix*374761393+$this->seed+$salt*1013904223;$x1=$x0+374761393;$z0=$iz*668265263;$z1=$z0+668265263;
        return self::lerp(self::lerp(self::node($x0+$z0),self::node($x1+$z0),$fx),self::lerp(self::node($x0+$z1),self::node($x1+$z1),$fx),$fz);
    }
    /** A sampler for sample3($x,$y,$z,$salt) at fixed X/Z and varying Y. */
    public function column(float $x,float $z,int $salt=0): NoiseColumn {
        $ix=(int)floor($x);$iz=(int)floor($z);
        return new NoiseColumn($ix*374761393+$this->seed+$salt*1013904223,$iz*668265263,self::fade($x-$ix),self::fade($z-$iz));
    }
    public function sample3(float $x,float $y,float $z,int $salt=0): float {
        $ix=(int)floor($x);$iy=(int)floor($y);$iz=(int)floor($z);
        $fx=self::fade($x-$ix);$fy=self::fade($y-$iy);$fz=self::fade($z-$iz);
        $x0=$ix*374761393+$this->seed+$salt*1013904223;$x1=$x0+374761393;
        $y0=$iy*1442695041;$y1=$y0+1442695041;$z0=$iz*668265263;$z1=$z0+668265263;
        $a=self::lerp(self::node($x0+$y0+$z0),self::node($x1+$y0+$z0),$fx);
        $b=self::lerp(self::node($x0+$y1+$z0),self::node($x1+$y1+$z0),$fx);
        $c=self::lerp(self::node($x0+$y0+$z1),self::node($x1+$y0+$z1),$fx);
        $d=self::lerp(self::node($x0+$y1+$z1),self::node($x1+$y1+$z1),$fx);
        return self::lerp(self::lerp($a,$b,$fy),self::lerp($c,$d,$fy),$fz);
    }
}
