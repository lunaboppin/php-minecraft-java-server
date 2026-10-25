<?php
declare(strict_types=1);
namespace Jva\World;
use Jva\Protocol\Version;

/** Original seeded Nether caverns and End islands, with vanilla dimension sizes/biome IDs. */
final class DimensionGenerator
{
    private readonly Noise $noise;
    private array $states;
    public function __construct(private readonly Version $version,int $seed,private readonly string $dimension){
        $this->noise=new Noise($seed^($dimension==='the_nether'?0x4e455448:0x454e44));$this->states=$version->blocks['states'];
    }
    public function column(int $x,int $z):array {
        if($this->dimension==='the_nether'){
            $heat=$this->noise->sample2($x/120,$z/120,201);$wet=$this->noise->sample2($x/95,$z/95,202);
            $biome=match(true){$heat>0.3=>'basalt_deltas',$heat< -0.3=>'soul_sand_valley',$wet>0.25=>'crimson_forest',$wet< -0.25=>'warped_forest',default=>'nether_wastes'};
            $h=(int)round(52+$this->noise->sample2($x/75,$z/75,203)*24+$this->noise->sample2($x/23,$z/23,204)*8);
            return ['height'=>$h,'roof'=>(int)round(101+$this->noise->sample2($x/48,$z/48,205)*13),'biome'=>$biome];
        }
        $radius=sqrt($x*$x+$z*$z);$n=$this->noise->sample2($x/110,$z/110,220);$island=$radius<140?1-$radius/140:($radius<900?-1:$n-0.1);
        $h=(int)round(62+$n*12+$this->noise->sample2($x/31,$z/31,221)*4);
        $thickness=$radius<140?(int)round(44*max(0,$island)):(int)round(max(0,$island)*75);
        if($radius>125 && $radius<900){$thickness=0;}
        return ['height'=>$h,'bottom'=>$h-$thickness,'thickness'=>$thickness,'biome'=>$radius<300?'the_end':($thickness>18?'end_highlands':($thickness>5?'end_midlands':($thickness>0?'small_end_islands':'end_barrens')))];
    }
    public function biome(int $x,int $z):int {return $this->version->registryId('worldgen/biome',$this->column($x,$z)['biome']);}
    public function chunk(int $cx,int $cz):array {
        $sections=array_fill(0,16,array_fill(0,4096,0));
        $put=static function(int $x,int $y,int $z,int $state)use(&$sections,$cx,$cz):void{
            if($y<0 || $y>255 || ($x>>4)!==$cx || ($z>>4)!==$cz){return;}
            $sections[$y>>4][(($y&15)<<8)|(($z&15)<<4)|($x&15)]=$state;
        };
        for($z=$cz*16;$z<$cz*16+16;++$z){for($x=$cx*16;$x<$cx*16+16;++$x){
            $c=$this->column($x,$z);$hash=$this->noise->hash($x,0,$z,230);
            if($this->dimension==='the_nether'){
                for($y=0;$y<=127;++$y){
                    $state=0;
                    if($y===0 || $y===127 || ($y<4 && $hash%4>= $y) || ($y>123 && $hash%4>127-$y)){$state=$this->states['bedrock'];}
                    elseif($y<=$c['height'] || $y>=$c['roof']){
                        $cave=$y>5 && $y<$c['height']-5 && abs($this->noise->sample3($x/25,$y/16,$z/25,231))<0.09;
                        if($cave){$state=$y<32?$this->states['lava']:0;}
                        else{
                            $block='netherrack';$roll=$this->noise->hash($x>>1,$y>>1,$z>>1,232)%1000;
                            if($y<$c['height']-2){$block=$roll<22?'nether_quartz_ore':($roll<33?'nether_gold_ore':($roll===34 && $y<24?'ancient_debris':'netherrack'));}
                            elseif($y<=$c['height']){$block=match($c['biome']){'soul_sand_valley'=>($hash%3===0?'soul_soil':'soul_sand'),'basalt_deltas'=>($hash%4===0?'blackstone':'basalt'),'crimson_forest'=>$y===$c['height']?'crimson_nylium':'netherrack','warped_forest'=>$y===$c['height']?'warped_nylium':'netherrack',default=>'netherrack'};}
                            if($y===$c['roof'] && $hash%29===0){$block='glowstone';}$state=$this->states[$block];
                        }
                    }elseif($y<=32){$state=$this->states['lava'];}
                    $put($x,$y,$z,$state);
                }
                if($c['height']>32 && $hash%23===0){$plant=match($c['biome']){'crimson_forest'=>'crimson_roots','warped_forest'=>'warped_roots',default=>$hash%2===0?'brown_mushroom':'red_mushroom'};$put($x,$c['height']+1,$z,$this->states[$plant]);}
                if($c['height']>32 && $c['biome']==='basalt_deltas' && $hash%11===0){for($y=1;$y<5+$hash%8;++$y){$put($x,$c['height']+$y,$z,$this->states['basalt']);}}
            }else{
                if($c['thickness']>0){for($y=max(1,$c['bottom']);$y<=$c['height'];++$y){$put($x,$y,$z,$this->states['end_stone']);}}
                for($i=0;$i<10;++$i){$px=(int)round(cos($i*pi()/5)*42);$pz=(int)round(sin($i*pi()/5)*42);$r=2+$i%3;
                    if(($x-$px)**2+($z-$pz)**2<=$r*$r){for($y=45;$y<76+$i*3;++$y){$put($x,$y,$z,$this->states['obsidian']);}}
                }
                if($c['thickness']>5 && $x*$x+$z*$z>900*900 && $hash%53===0){for($y=1;$y<=2+$hash%4;++$y){$put($x,$c['height']+$y,$z,$this->states['chorus_plant']);}$put($x,$c['height']+3+$hash%4,$z,$this->states['chorus_flower']);}
                // A fixed arrival platform and a functional central exit fountain.
                if(abs($x-100)<=2 && abs($z)<=2){$put($x,64,$z,$this->states['obsidian']);for($y=65;$y<=68;++$y){$put($x,$y,$z,0);}}
                if($x*$x+$z*$z<=12){$put($x,62,$z,$this->states['bedrock']);$put($x,63,$z,$x*$x+$z*$z<=5?$this->states['end_portal']:$this->states['bedrock']);for($y=64;$y<=68;++$y){$put($x,$y,$z,0);}}
                if($x===0 && $z===0){for($y=63;$y<=66;++$y){$put($x,$y,$z,$this->states['bedrock']);}$put($x,67,$z,$this->states['torch']);}
            }
        }}
        if($this->dimension==='the_nether'){
            // Sample anchors outside this chunk so giant fungi cross chunk boundaries consistently.
            for($gz=$cz*16-3;$gz<=$cz*16+18;++$gz){for($gx=$cx*16-3;$gx<=$cx*16+18;++$gx){
                if($gx%12!==0 || $gz%12!==0){continue;}$c=$this->column($gx,$gz);$hash=$this->noise->hash($gx,0,$gz,240);
                if(!in_array($c['biome'],['crimson_forest','warped_forest'],true) || $c['height']<33 || $hash%3===0){continue;}
                $wood=$c['biome']==='crimson_forest'?'crimson':'warped';$base=$c['height']+1;$h=5+$hash%4;
                if($base+$h+2>=$c['roof']){continue;}
                for($dy=$h-2;$dy<=$h;++$dy){for($dx=-2;$dx<=2;++$dx){for($dz=-2;$dz<=2;++$dz){
                    if(abs($dx)===2 && abs($dz)===2 && $dy===$h){continue;}
                    $cap=$wood==='crimson'?'nether_wart_block':'warped_wart_block';if($dy===$h-2 && abs($dx)+abs($dz)===3){$cap='shroomlight';}
                    $put($gx+$dx,$base+$dy,$gz+$dz,$this->states[$cap]);
                }}}
                for($dy=0;$dy<$h;++$dy){$put($gx,$base+$dy,$gz,$this->states[$wood.'_stem']);}
            }}
        }
        return $sections;
    }
}
