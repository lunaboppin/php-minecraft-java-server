<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World\Generation;

/** Java 26.3 NormalNoise's modern scalar stack, with its three normalization modes. */
final class NormalNoise
{
    private array $layers=[];
    public function __construct(RandomSource $random,array $parameters) {
        // Optional JSON fields with null use their codec defaults in the official registry.
        $p=$parameters;foreach(['base_amplitude'=>1.0,'octave_count'=>1,'normalize'=>true,'amplitude_modifiers'=>[]] as $key=>$default){$p[$key]??=$default;}
        $p['base_octave']??=null;
        $base=$p['base_amplitude'];$octave=$p['base_octave'];$count=$p['octave_count'];$mode=$p['normalize'];$modifiers=$p['amplitude_modifiers'];
        if(!is_int($octave) || $octave<-32 || $octave>32 || !is_int($count) || $count<1 || $count>32 || (!is_int($base) && !is_float($base)) || !is_finite((float)$base) || $base<9.999999747378752e-6 || $base>1000000 || !in_array($mode,[true,false,'legacy'],true) || !is_array($modifiers) || !array_is_list($modifiers) || ($modifiers!==[] && count($modifiers)!==$count)){throw new \InvalidArgumentException('Invalid normal noise parameters');}
        foreach($modifiers as $m){if(!is_int($m) && !is_float($m) || !is_finite((float)$m) || $m<0 || $m>1000000){throw new \InvalidArgumentException('Invalid octave amplitude modifier');}}
        $amplitude=(float)$base;if($mode!==false){$amplitude*=pow(0.5,-($count-1))/(pow(0.5,-$count)-1);}
        $frequency=pow(2,$octave);$octaves=[];$sum=0.0;$compensation=0.0;$variance=0.0;$first=2147483647;$last=-2147483648;
        for($i=0;$i<$count;++$i){
            $modifier=$modifiers[$i]??1.0;
            if($modifier!=0){
                $a=$amplitude*$modifier;$octaves[]=['seed'=>'octave_'.($octave+$i),'frequency'=>$frequency,'amplitude'=>$a];
                $corrected=abs($a)-$compensation;$next=$sum+$corrected;$compensation=($next-$sum)-$corrected;$sum=$next;
                $deviation=0.2702247831245211*abs($a);$variance+=$deviation*$deviation;$first=min($first,$i);$last=max($last,$i);
            }
            $frequency*=2;$amplitude*=0.5;
        }
        $deviation=sqrt($variance);$normalization=$deviation==0?0.0:(($sum-$compensation)*(1/3))/($deviation*sqrt(2));
        if($mode==='legacy' && $normalization!=0){$normalization=$base*0.5*(1/3)/(0.1*(1+1/($last-$first+1)));}
        $firstRandom=$random->forkPositional();$secondRandom=$random->forkPositional();
        foreach($octaves as $o){
            $weight=Float32::round($normalization*$o['amplitude']);
            $this->layers[]=[new PerlinNoise($firstRandom->fromHashOf($o['seed'])),$o['frequency'],$weight];
            $this->layers[]=[new PerlinNoise($secondRandom->fromHashOf($o['seed'])),$o['frequency']*1.0181268882175227,$weight];
        }
    }
    public function get(float $x,float $y,float $z):float {
        $result=0.0;foreach($this->layers as [$noise,$frequency,$amplitude]){$result=Float32::round($result+Float32::round($amplitude*$noise->get($x*$frequency,$y*$frequency,$z*$frequency)));}return $result;
    }
    public function get2D(float $x,float $z):float {
        $result=0.0;foreach($this->layers as [$noise,$frequency,$amplitude]){$result=Float32::round($result+Float32::round($amplitude*$noise->get2D($x*$frequency,$z*$frequency)));}return $result;
    }
}
