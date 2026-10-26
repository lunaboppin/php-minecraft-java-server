<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World\Generation;

/** Scalar GradientNoise/PerlinNoise sampling from Java 26.3, including float interpolation. */
final class PerlinNoise
{
    private array $permutations;
    private float $offsetX;
    private float $offsetY;
    private float $offsetZ;
    private const GRADIENTS=[[1,1,0],[-1,1,0],[1,-1,0],[-1,-1,0],[1,0,1],[-1,0,1],[1,0,-1],[-1,0,-1],[0,1,1],[0,-1,1],[0,1,-1],[0,-1,-1],[1,1,0],[0,-1,1],[-1,1,0],[0,-1,-1]];
    public function __construct(RandomSource $random) {
        $this->offsetX=$random->nextDouble()*256;$this->offsetY=$random->nextDouble()*256;$this->offsetZ=$random->nextDouble()*256;
        $this->permutations=range(0,255);
        for($i=0;$i<256;++$i){$j=$i+$random->nextInt(256-$i);[$this->permutations[$i],$this->permutations[$j]]=[$this->permutations[$j],$this->permutations[$i]];}
    }
    private function permute(int $n):int {return $this->permutations[$n&255];}
    private function dot(int $hash,float $x,float $y,float $z):float {
        $g=self::GRADIENTS[$hash&15];return Float32::round(Float32::round($g[0]*$x+$g[1]*$y)+$g[2]*$z);
    }
    public static function wrap(float $coordinate):float {
        if(!is_finite($coordinate)){throw new \InvalidArgumentException('Noise coordinates must be finite');}
        return $coordinate>=-16777215.999999998 && $coordinate<16777215.999999998?$coordinate:$coordinate-floor($coordinate/33554432+0.5)*33554432;
    }
    public function get2D(float $x,float $z):float {return $this->get(self::wrap($x),0,self::wrap($z));}
    public function get(float $x,float $y,float $z):float {
        $x=self::wrap($x)+$this->offsetX;$y=self::wrap($y)+$this->offsetY;$z=self::wrap($z)+$this->offsetZ;
        $ix=(int)floor($x);$iy=(int)floor($y);$iz=(int)floor($z);
        $x=Float32::round($x-$ix);$y=Float32::round($y-$iy);$z=Float32::round($z-$iz);
        $mx=Float32::round($x-1);$my=Float32::round($y-1);$mz=Float32::round($z-1);
        $a=$this->permute($ix);$b=$this->permute($ix+1);$aa=$this->permute($a+$iy);$ab=$this->permute($a+$iy+1);$ba=$this->permute($b+$iy);$bb=$this->permute($b+$iy+1);
        $n000=$this->dot($this->permute($aa+$iz),$x,$y,$z);$n100=$this->dot($this->permute($ba+$iz),$mx,$y,$z);
        $n010=$this->dot($this->permute($ab+$iz),$x,$my,$z);$n110=$this->dot($this->permute($bb+$iz),$mx,$my,$z);
        $n001=$this->dot($this->permute($aa+$iz+1),$x,$y,$mz);$n101=$this->dot($this->permute($ba+$iz+1),$mx,$y,$mz);
        $n011=$this->dot($this->permute($ab+$iz+1),$x,$my,$mz);$n111=$this->dot($this->permute($bb+$iz+1),$mx,$my,$mz);
        $sx=Float32::smoothstep($x);$sy=Float32::smoothstep($y);$sz=Float32::smoothstep($z);
        return Float32::lerp($sz,Float32::lerp($sy,Float32::lerp($sx,$n000,$n100),Float32::lerp($sx,$n010,$n110)),Float32::lerp($sy,Float32::lerp($sx,$n001,$n101),Float32::lerp($sx,$n011,$n111)));
    }
}
