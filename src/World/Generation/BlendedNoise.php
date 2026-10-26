<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World\Generation;

/** Java 26.3's legacy terrain stacks and clamped blending, evaluated as scalar samples. */
final class BlendedNoise
{
    private array $min;
    private array $max;
    private array $main;
    private float $xz;
    private float $y;
    public function __construct(RandomSource $random,private readonly array $parameters){
        $this->xz=684.412*$parameters['xz_scale'];$this->y=684.412*$parameters['y_scale'];
        $smear=$this->y*$parameters['smear_scale_multiplier'];
        $this->min=self::stack($random,16,$smear,0.9999847412109375);
        $this->max=self::stack($random,16,$smear,0.9999847412109375);
        $this->main=self::stack($random,8,$smear/$parameters['y_factor'],12.75);
    }
    private static function stack(RandomSource $random,int $count,float $smear,float $amplitude):array {
        $layers=[];$frequency=1.0;$amplitude/=pow(2,$count)-1;
        for($i=$count-1;$i>=0;--$i){$layers[]=[new SmearedPerlinNoise($random,$smear*$frequency),$frequency,Float32::round($amplitude)];$frequency/=2;$amplitude*=2;}
        return $layers;
    }
    private static function getStack(array $layers,float $x,float $y,float $z):float {
        $value=0.0;foreach($layers as [$noise,$frequency,$amplitude]){$value=Float32::round($value+Float32::round($amplitude*$noise->get($x*$frequency,$y*$frequency,$z*$frequency)));}return $value;
    }
    public function get(int $x,int $y,int $z):float {
        $x*=$this->xz;$y*=$this->y;$z*=$this->xz;
        $alpha=min(max(Float32::round(self::getStack($this->main,$x/$this->parameters['xz_factor'],$y/$this->parameters['y_factor'],$z/$this->parameters['xz_factor'])+0.5),0.0),1.0);
        if($alpha==0){return self::getStack($this->min,$x,$y,$z);}if($alpha==1){return self::getStack($this->max,$x,$y,$z);}
        return Float32::lerp($alpha,self::getStack($this->min,$x,$y,$z),self::getStack($this->max,$x,$y,$z));
    }
}
