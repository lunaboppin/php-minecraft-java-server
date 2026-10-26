<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World\Generation;

/** Java 26.3 outer island density; the central island is a separate density expression. */
final class EndIslands
{
    private SimplexNoise2D $noise;
    public function __construct(int $seed){$random=new LegacyRandom($seed);$random->consumeCount(17292);$this->noise=new SimplexNoise2D($random);}
    public function get(int $x,int $y,int $z):float {
        $x=intdiv($x,8);$z=intdiv($z,8);$cx=intdiv($x,2);$cz=intdiv($z,2);$mx=$x%2;$mz=$z%2;$height=-100.0;
        for($i=-12;$i<=12;++$i){for($j=-12;$j<=12;++$j){
            $a=$cx+$i;$b=$cz+$j;
            if($a*$a+$b*$b<=4096 || $this->noise->get($a,$b)>=Float32::round(-0.9)){continue;}
            $scale=Float32::round(fmod(Float32::round(Float32::round(abs(Float32::round($a))*3439)+Float32::round(abs(Float32::round($b))*147)),13)+9);
            $dx=(float)($mx-$i*2);$dz=(float)($mz-$j*2);
            $distance=Float32::round(sqrt(Float32::round(Float32::round($dx*$dx)+Float32::round($dz*$dz))));
            $candidate=Float32::round(100-Float32::round($distance*$scale));$height=max($height,min(max($candidate,-100.0),80.0));
        }}
        return Float32::round(Float32::round($height-8)/128);
    }
}
