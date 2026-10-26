<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World\Generation;

/** Scalar Java 26.3 density graph for an unblended world without structure contributions.
 * Definitions and noise parameters must be trusted, decoded registry resources.
 */
final class DensityGraph
{
    private array $compiled=[];
    private array $compiling=[];
    private array $noises=[];
    private array $cache=[];
    private int $nextCache=0;
    private PositionalRandom $random;
    public function __construct(private readonly array $definitions,private readonly array $noiseParameters,private readonly int $seed,private readonly bool $legacy=false){
        $this->random=($legacy?new LegacyRandom($seed):new XoroshiroRandom($seed))->forkPositional();
    }
    public function sample(string $name,int $x,int $y,int $z):float {
        foreach([$x,$y,$z] as $coordinate){if($coordinate<-2147483648 || $coordinate>2147483647){throw new \InvalidArgumentException('Coordinate must be a Java int');}}
        $sampler=$this->compile($name);$this->cache=[];
        try{return $sampler($x,$y,$z);}finally{$this->cache=[];}
    }
    private static function f(float $n):float {return Float32::round($n);}
    private function noise(string $name):NormalNoise {
        if(!isset($this->noiseParameters[$name])){throw new \InvalidArgumentException('Unknown noise '.$name);}
        return $this->noises[$name]??=new NormalNoise($this->random->fromHashOf($name),$this->noiseParameters[$name]);
    }
    private function compile(mixed $node):\Closure {
        if(is_int($node)||is_float($node)){$value=self::f((float)$node);return static fn(int $x,int $y,int $z):float=>$value;}
        if(is_string($node)){
            if(isset($this->compiled[$node])){return $this->compiled[$node];}
            if(isset($this->compiling[$node])){throw new \InvalidArgumentException('Cyclic density reference '.$node);}
            if(!array_key_exists($node,$this->definitions)){throw new \InvalidArgumentException('Unknown density '.$node);}
            $this->compiling[$node]=true;
            try{return $this->compiled[$node]=$this->compile($this->definitions[$node]);}finally{unset($this->compiling[$node]);}
        }
        if(!is_array($node)||!is_string($node['type']??null)){throw new \InvalidArgumentException('Invalid density node');}
        $type=substr($node['type'],10);
        switch($type){
            case 'blend_alpha':return static fn(int $x,int $y,int $z):float=>1.0;
            case 'blend_offset':case 'beardifier':return static fn(int $x,int $y,int $z):float=>0.0;
            case 'blend_density':return $this->compile($node['input']);
            case 'cache':
                $input=$this->compile($node['input']);$id=$this->nextCache++;
                return function(int $x,int $y,int $z)use($input,$id):float{$key=$id.':'.$x.':'.$y.':'.$z;return $this->cache[$key]??=$input($x,$y,$z);};
            case 'end_outer_islands':
                $islands=new EndIslands($this->seed);return $islands->get(...);
            case 'old_blended_noise':
                $noise=new BlendedNoise($this->legacy?new LegacyRandom($this->seed):$this->random->fromHashOf('minecraft:terrain'),$node);
                return $noise->get(...);
            case 'distance_to_point':
                [$px,$py,$pz]=$node['point'];$metric=$node['metric'];
                return static function(int $x,int $y,int $z)use($px,$py,$pz,$metric):float{
                    $a=self::f(Int64::signed32($px-$x));$b=self::f(Int64::signed32($py-$y));$c=self::f(Int64::signed32($pz-$z));
                    $square=self::f(self::f(self::f($a*$a)+self::f($b*$b))+self::f($c*$c));
                    return match($metric){'euclidean'=>self::f(sqrt($square)),'euclidean_squared'=>$square,'manhattan'=>self::f(self::f(abs($a)+abs($b))+abs($c)),'chebyshev'=>max(abs($a),abs($b),abs($c))};
                };
            case 'noise':
                $noise=$this->noise($node['noise']);$xz=$node['xz_scale'];$ys=$node['y_scale'];
                $sx=$this->compile($node['shift_x']??0);$sy=$this->compile($node['shift_y']??0);$sz=$this->compile($node['shift_z']??0);
                return static fn(int $x,int $y,int $z):float=>$noise->get($x*$xz+$sx($x,$y,$z),$y*$ys+$sy($x,$y,$z),$z*$xz+$sz($x,$y,$z));
            case 'shift':case 'shift_a':case 'shift_b':
                $noise=$this->noise($node['noise']);
                return static fn(int $x,int $y,int $z):float=>self::f(4*$noise->get(...match($type){'shift_a'=>[$x*0.25,0,$z*0.25],'shift_b'=>[$z*0.25,$x*0.25,0],default=>[$x*0.25,$y*0.25,$z*0.25]}));
            case 'gradient':
                $from=$node['from_coordinate'];$to=$node['to_coordinate'];$first=self::f($node['from_value']);$factor=self::f(self::f(self::f($node['to_value'])-$first)/self::f($to-$from));$axis=$node['axis'];
                if(($node['tiling']??'clamp')!=='clamp'){throw new \LogicException('Unsupported gradient tiling');}
                return static function(int $x,int $y,int $z)use($from,$to,$first,$factor,$axis):float{
                    $v=match($axis){'x'=>$x,'y'=>$y,'z'=>$z};$offset=Int64::signed32(min(max($v,min($from,$to)),max($from,$to))-$from);
                    return self::f($first+self::f(self::f($offset)*$factor));
                };
            case 'add':case 'sub':case 'mul':case 'div':case 'min':case 'max':
                $a=$this->compile($node['left']);$b=$this->compile($node['right']);
                return static function(int $x,int $y,int $z)use($a,$b,$type):float{
                    $left=$a($x,$y,$z);$right=$b($x,$y,$z);
                    return self::f(match($type){'add'=>$left+$right,'sub'=>$left-$right,'mul'=>$left*$right,'div'=>fdiv($left,$right),'min'=>min($left,$right),'max'=>max($left,$right)});
                };
            case 'clamp':
                $input=$this->compile($node['input']);$min=self::f($node['min']);$max=self::f($node['max']);
                return static fn(int $x,int $y,int $z):float=>min(max($input($x,$y,$z),$min),$max);
            case 'abs':case 'square':case 'cube':case 'half_negative':case 'quarter_negative':case 'squeeze':case 'negate':
                $input=$this->compile($node['input']);
                return static function(int $x,int $y,int $z)use($input,$type):float{
                    $v=$input($x,$y,$z);
                    return match($type){'abs'=>abs($v),'square'=>self::f($v*$v),'cube'=>self::f(self::f($v*$v)*$v),'negate'=>-$v,
                        'half_negative'=>$v>0?$v:self::f($v*0.5),'quarter_negative'=>$v>0?$v:self::f($v*0.25),
                        'squeeze'=>(function()use($v){$v=min(max($v,-1.0),1.0);return self::f(self::f($v/2)-self::f(self::f(self::f($v*$v)*$v)/24));})()};
                };
            case 'lerp':
                $alpha=$this->compile($node['alpha']);$first=$this->compile($node['first']);$second=$this->compile($node['second']);
                return static function(int $x,int $y,int $z)use($alpha,$first,$second):float{$a=$alpha($x,$y,$z);return $a==0?$first($x,$y,$z):($a==1?$second($x,$y,$z):Float32::lerp($a,$first($x,$y,$z),$second($x,$y,$z)));};
            case 'range_choice':
                $input=$this->compile($node['input']);$a=$this->compile($node['when_in_range']);$b=$this->compile($node['when_out_of_range']);$min=self::f($node['min_inclusive']);$max=self::f($node['max_exclusive']);
                return static function(int $x,int $y,int $z)use($input,$a,$b,$min,$max):float{$v=$input($x,$y,$z);return ($v>=$min&&$v<$max?$a:$b)($x,$y,$z);};
            case 'interval_select':
                $input=$this->compile($node['input']);$thresholds=array_map(self::f(...),$node['thresholds']);$functions=array_map($this->compile(...),$node['functions']);
                return static function(int $x,int $y,int $z)use($input,$thresholds,$functions):float{$v=$input($x,$y,$z);foreach($thresholds as $i=>$threshold){if($v<$threshold){return $functions[$i]($x,$y,$z);}}return $functions[count($functions)-1]($x,$y,$z);};
            case 'slice':
                $input=$this->compile($node['input']);$axis=$node['axis'];$coordinate=$node['coordinate'];
                return static fn(int $x,int $y,int $z):float=>$input($axis==='x'?$coordinate:$x,$axis==='y'?$coordinate:$y,$axis==='z'?$coordinate:$z);
            case 'spline':return $this->spline($node['spline']);
            case 'interpolated':
                $input=$this->compile($node['input']);$xz=$node['cell_size_xz'];$ys=$node['cell_size_y'];
                return static function(int $x,int $y,int $z)use($input,$xz,$ys):float{
                    $mx=(($x%$xz)+$xz)%$xz;$my=(($y%$ys)+$ys)%$ys;$mz=(($z%$xz)+$xz)%$xz;
                    if(($mx|$my|$mz)===0){return $input($x,$y,$z);}
                    $x-=$mx;$y-=$my;$z-=$mz;$tx=self::f($mx/$xz);$ty=self::f($my/$ys);$tz=self::f($mz/$xz);
                    return Float32::lerp($tz,Float32::lerp($ty,Float32::lerp($tx,$input($x,$y,$z),$input($x+$xz,$y,$z)),Float32::lerp($tx,$input($x,$y+$ys,$z),$input($x+$xz,$y+$ys,$z))),Float32::lerp($ty,Float32::lerp($tx,$input($x,$y,$z+$xz),$input($x+$xz,$y,$z+$xz)),Float32::lerp($tx,$input($x,$y+$ys,$z+$xz),$input($x+$xz,$y+$ys,$z+$xz))));
                };
            case 'find_top_surface':
                $density=$this->compile($node['density']);$upper=$this->compile($node['upper_bound']);$lower=$node['lower_bound'];$height=$node['cell_height'];
                return static function(int $x,int $y,int $z)use($density,$upper,$lower,$height):float{
                    $top=(int)floor(self::f($upper($x,$y,$z)/$height))*$height;
                    for($h=$top;$h>=$lower;$h-=$height){if($density($x,$h,$z)>0){return self::f($h);}}
                    return self::f($lower);
                };
            default:throw new \LogicException('Unsupported density type '.$node['type']);
        }
    }
    private function spline(mixed $node):\Closure {
        if(is_int($node)||is_float($node)){return $this->compile($node);}
        $coordinate=$this->compile($node['coordinate']);$points=[];
        foreach($node['points'] as $point){$points[]=[self::f($point['location']),self::f($point['derivative']),$this->spline($point['value'])];}
        return static function(int $x,int $y,int $z)use($coordinate,$points):float{
            $v=$coordinate($x,$y,$z);$i=-1;foreach($points as $index=>$point){if($v<$point[0]){break;}$i=$index;}
            $last=count($points)-1;
            if($i<0||$i===$last){[$location,$derivative,$value]=$points[$i<0?0:$last];$a=$value($x,$y,$z);return $derivative==0?$a:self::f($a+self::f($derivative*self::f($v-$location)));}
            [$l0,$d0,$f0]=$points[$i];[$l1,$d1,$f1]=$points[$i+1];$span=self::f($l1-$l0);$t=self::f(self::f($v-$l0)/$span);
            $a=$f0($x,$y,$z);$b=$f1($x,$y,$z);$difference=self::f($b-$a);$p=self::f(self::f($d0*$span)-$difference);$q=self::f(-self::f($d1*$span)+$difference);
            return self::f(Float32::lerp($t,$a,$b)+self::f(self::f($t*self::f(1-$t))*Float32::lerp($t,$p,$q)));
        };
    }
}
