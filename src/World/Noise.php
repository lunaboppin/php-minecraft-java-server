<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World;

/** Integer hash and smoothly interpolated value noise, stable across chunk boundaries. */
final class Noise
{
    private array $nodes=[];
    public function __construct(private readonly int $seed) {}
    public function hash(int $x,int $y,int $z,int $salt=0): int {
        $n=($x*374761393+$y*1442695041+$z*668265263+$this->seed+$salt*1013904223)&0x7fffffff;
        $n=(($n^($n>>13))*1274126177)&0x7fffffff;
        return ($n^($n>>16))&0x7fffffff;
    }
    private function node(int $x,int $y,int $z,int $salt): float {
        $key=$x.':'.$y.':'.$z.':'.$salt;
        if (!isset($this->nodes[$key])) {
            if(count($this->nodes)>16384){$this->nodes=[];}
            $this->nodes[$key]=$this->hash($x,$y,$z,$salt)/1073741823.5-1;
        }
        return $this->nodes[$key];
    }
    private static function fade(float $v): float { return $v*$v*$v*($v*($v*6-15)+10); }
    private static function lerp(float $a,float $b,float $t): float { return $a+($b-$a)*$t; }
    public function sample2(float $x,float $z,int $salt=0): float {
        $ix=(int)floor($x);$iz=(int)floor($z);$fx=self::fade($x-$ix);$fz=self::fade($z-$iz);
        return self::lerp(self::lerp($this->node($ix,0,$iz,$salt),$this->node($ix+1,0,$iz,$salt),$fx),self::lerp($this->node($ix,0,$iz+1,$salt),$this->node($ix+1,0,$iz+1,$salt),$fx),$fz);
    }
    public function sample3(float $x,float $y,float $z,int $salt=0): float {
        $ix=(int)floor($x);$iy=(int)floor($y);$iz=(int)floor($z);
        $fx=self::fade($x-$ix);$fy=self::fade($y-$iy);$fz=self::fade($z-$iz);
        $a=self::lerp($this->node($ix,$iy,$iz,$salt),$this->node($ix+1,$iy,$iz,$salt),$fx);
        $b=self::lerp($this->node($ix,$iy+1,$iz,$salt),$this->node($ix+1,$iy+1,$iz,$salt),$fx);
        $c=self::lerp($this->node($ix,$iy,$iz+1,$salt),$this->node($ix+1,$iy,$iz+1,$salt),$fx);
        $d=self::lerp($this->node($ix,$iy+1,$iz+1,$salt),$this->node($ix+1,$iy+1,$iz+1,$salt),$fx);
        return self::lerp(self::lerp($a,$b,$fy),self::lerp($c,$d,$fy),$fz);
    }
}
