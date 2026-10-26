<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World\Generation;

/** The zero-offset two-dimensional simplex sampler used by End outer islands. */
final class SimplexNoise2D
{
    private array $permutations;
    private const GRADIENTS=[[1,1],[-1,1],[1,-1],[-1,-1],[1,0],[-1,0],[1,0],[-1,0],[0,1],[0,-1],[0,1],[0,-1]];
    public function __construct(RandomSource $random){
        // A zero offset still consumes the three doubles in GradientNoise's constructor.
        $random->nextDouble();$random->nextDouble();$random->nextDouble();$this->permutations=range(0,255);
        for($i=0;$i<256;++$i){$j=$i+$random->nextInt(256-$i);[$this->permutations[$i],$this->permutations[$j]]=[$this->permutations[$j],$this->permutations[$i]];}
    }
    private function permute(int $n):int{return $this->permutations[$n&255];}
    private static function corner(int $index,float $x,float $y):float {
        $t=0.5-$x*$x-$y*$y;if($t<0){return 0.0;}$t*=$t;$g=self::GRADIENTS[$index];return $t*$t*($g[0]*$x+$g[1]*$y);
    }
    public function get(float $x,float $y):float {
        $f=0.5*(sqrt(3)-1);$g=(3-sqrt(3))/6;$skew=($x+$y)*$f;$i=(int)floor($x+$skew);$j=(int)floor($y+$skew);$unskew=Int64::signed32($i+$j)*$g;
        $a=$x-($i-$unskew);$b=$y-($j-$unskew);$ix=$a>$b?1:0;$iy=$a>$b?0:1;
        $first=$this->permute($i+$this->permute($j))%12;$second=$this->permute($i+$ix+$this->permute($j+$iy))%12;$third=$this->permute($i+1+$this->permute($j+1))%12;
        return Float32::round(70*(self::corner($first,$a,$b)+self::corner($second,$a-$ix+$g,$b-$iy+$g)+self::corner($third,$a-1+2*$g,$b-1+2*$g)));
    }
}
