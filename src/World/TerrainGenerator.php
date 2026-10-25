<?php
declare(strict_types=1);
namespace Jva\World;
use Jva\Protocol\Version;

/** Seeded terrain, biome columns, underground features and decorations; algorithm version 1. */
final class TerrainGenerator
{
    public const ALGORITHM='terrain-v2';
    public const SEA_LEVEL=63;
    private readonly Noise $noise;
    private array $columns=[];
    private array $trees=[];
    private array $states;
    public function __construct(private readonly Version $version,public readonly int $seed,private readonly bool $enhanced=true) {
        $this->noise=new Noise($seed);$this->states=$version->blocks['states'];
    }
    public function column(int $x,int $z): array {
        $key=$x.':'.$z;
        if(isset($this->columns[$key])){return $this->columns[$key];}
        $continental=$this->noise->sample2($x/320,$z/320,1);
        $erosion=$this->noise->sample2($x/96,$z/96,2);
        $detail=$this->noise->sample2($x/30,$z/30,3);
        $mountain=max(0,$continental-0.1)*max(0,$erosion+0.3)*65;
        $height=(int)floor(66+$continental*33+$erosion*10+$detail*3+$mountain);
        $temperature=$this->noise->sample2($x/260,$z/260,4);
        $moisture=$this->noise->sample2($x/180,$z/180,5);
        $river=abs($this->noise->sample2($x/145+37,$z/145-81,6));
        if($height>64 && $river<0.055){$height=min($height,59+(int)floor($river*70));}
        // A small safe clearing ensures every seed has a dry, tree-free initial spawn.
        $blend=min(1,sqrt($x*$x+$z*$z)/32);
        $height=(int)round(64*(1-$blend)+$height*$blend);
        $height=max(30,min(180,$height));
        $biome=match(true){
            $height<54=>'ocean', $height<63=>($river<0.055?'river':'ocean'),
            $height<=64 && $blend>=1=>'beach', $temperature>0.3 && $moisture<0.05=>'desert',
            $temperature< -0.4=>'snowy_plains', $temperature< -0.1 && $moisture>0=>'taiga',
            $height>100=>'meadow', $moisture>0.05=>'forest', default=>'plains',
        };
        if($blend<0.7){$biome='plains';}
        $column=['height'=>$height,'biome'=>$biome,'temperature'=>$temperature,'moisture'=>$moisture];
        if(count($this->columns)>8192){$this->columns=[];}
        return $this->columns[$key]=$column;
    }
    private function underground(int $x,int $y,int $z,array $column): int {
        if($y===-64 || ($y< -60 && $this->noise->hash($x,$y,$z,7)%5< -60-$y)){return $this->states['bedrock'];}
        if($y<$column['height']-6 && $y> -60){
            $cave=$this->noise->sample3($x/22,$y/14,$z/22,8);
            if(abs($cave)<0.075 && $this->noise->sample3($x/39,$y/29,$z/39,9)>-0.1){
                return $y< -54?$this->states['lava']:0;
            }
        }
        if($this->enhanced && $y> -55 && $y<$column['height']-5){
            // Narrow twisting tunnels intersect the broad v1 cave network.
            $tunnel=abs($this->noise->sample3($x/43,$y/23,$z/43,31));
            $cross=abs($this->noise->sample3($x/37+17,$y/31,$z/37-29,32));
            if($tunnel<0.055 && $cross<0.2){return $y< -48?$this->states['lava']:($y<20 && $this->noise->sample2($x/64,$z/64,33)>0.3?$this->states['water']:0);}
        }
        $gx=$x>>2;$gy=$y>>2;$gz=$z>>2;$oreHash=$this->noise->hash($gx,$gy,$gz,10);
        $roll=$oreHash%1000;$ore=null;
        if(abs(($x&3)-1)+abs(($y&3)-1)+abs(($z&3)-1)<=2){
            $ore=match(true){
                $roll<15 && $y< -16=>'diamond_ore', $roll<45 && $y<16=>'redstone_ore',
                $roll<65 && $y<32=>'gold_ore', $roll<85 && $y<32=>'lapis_ore',
                $roll<140 && $y<64=>'iron_ore', $roll<190 && $y> -16 && $y<96=>'copper_ore',
                $roll<260 && $y>0 && $y<128=>'coal_ore',default=>null,
            };
        }
        if($ore!==null){return $this->states[($y<0?'deepslate_':'').$ore]??$this->states[$ore];}
        return $this->states[$y<0?'deepslate':'stone'];
    }
    private function base(int $x,int $y,int $z,array $column): int {
        $h=$column['height'];
        if($y>max($h,self::SEA_LEVEL)){return 0;}
        if($y>$h){return $this->states[$column['temperature']< -0.45 && $y===63?'ice':'water'];}
        $sandy=in_array($column['biome'],['desert','beach','ocean','river'],true);
        if($y===$h){return $this->states[$sandy?'sand':'grass_block'];}
        if($y>=$h-3){return $this->states[$sandy?($this->enhanced && $y<$h-1?'sandstone':'sand'):'dirt'];}
        return $this->underground($x,$y,$z,$column);
    }
    private function tree(int $gx,int $gz): ?array {
        $key=$gx.':'.$gz;
        if(array_key_exists($key,$this->trees)){return $this->trees[$key];}
        $hash=$this->noise->hash($gx,0,$gz,21);
        $x=$gx*8+1+($hash%6);$z=$gz*8+1+(intdiv($hash,7)%6);
        $column=$this->column($x,$z);$biome=$column['biome'];
        $chance=match($biome){'forest','taiga'=>75,'plains'=>10,default=>0};
        $result=null;
        if($x*$x+$z*$z>100 && $column['height']>=64 && intdiv($hash,41)%100<$chance){
            $wood=$biome==='taiga'?'spruce':(intdiv($hash,47)%4===0?'birch':'oak');
            $result=['x'=>$x,'z'=>$z,'y'=>$column['height']+1,'height'=>4+intdiv($hash,53)%3,'wood'=>$wood];
        }
        if(count($this->trees)>4096){$this->trees=[];}
        return $this->trees[$key]=$result;
    }
    private function decorate(array &$sections,int $cx,int $cz): void {
        $catalog=$this->version->catalog;$enhanced=$this->enhanced;
        $put=static function(int $x,int $y,int $z,int $state)use(&$sections,$cx,$cz,$catalog,$enhanced):void{
            if($x<$cx*16 || $x>$cx*16+15 || $z<$cz*16 || $z>$cz*16+15 || $y< -64 || $y>319){return;}
            if($enhanced && str_ends_with($catalog->blockName($state),'_leaves')){
                $existing=$sections[($y+64)>>4][(($y&15)<<8)|(($z&15)<<4)|($x&15)];
                if(!$catalog->air($existing) && !$catalog->blocks[$catalog->blockName($existing)]['replaceable'] && !str_ends_with($catalog->blockName($existing),'_leaves')){return;}
            }
            $sections[($y+64)>>4][(($y&15)<<8)|(($z&15)<<4)|($x&15)]=$state;
        };
        for($gz=(($cz*16-3)>>3);$gz<=(($cz*16+18)>>3);++$gz){
            for($gx=(($cx*16-3)>>3);$gx<=(($cx*16+18)>>3);++$gx){
                $tree=$this->tree($gx,$gz);if($tree===null){continue;}
                ['x'=>$x,'z'=>$z,'y'=>$y,'height'=>$h,'wood'=>$wood]=$tree;
                for($ly=$h-3;$ly<=$h;++$ly){
                    $radius=$ly===$h?1:2;
                    for($dz=-$radius;$dz<=$radius;++$dz){for($dx=-$radius;$dx<=$radius;++$dx){
                        if(abs($dx)===$radius && abs($dz)===$radius && $this->noise->hash($x+$dx,$y+$ly,$z+$dz,22)%2===0){continue;}
                        $put($x+$dx,$y+$ly,$z+$dz,$this->states[$wood.'_leaves']);
                    }}
                }
                for($dy=0;$dy<$h;++$dy){$put($x,$y+$dy,$z,$this->states[$wood.'_log']);}
            }
        }
        for($z=$cz*16;$z<$cz*16+16;++$z){for($x=$cx*16;$x<$cx*16+16;++$x){
            $column=$this->column($x,$z);$y=$column['height']+1;
            $index=(($y&15)<<8)|(($z&15)<<4)|($x&15);$section=($y+64)>>4;
            if($sections[$section][$index]!==0 || $x*$x+$z*$z<49){continue;}
            $hash=$this->noise->hash($x,0,$z,25);
            if(in_array($column['biome'],['plains','forest','meadow','taiga'],true) && $hash%7===0){
                $put($x,$y,$z,$this->states[$hash%31===0?'poppy':($hash%17===0?'dandelion':'short_grass')]);
            }elseif($column['biome']==='snowy_plains'){$put($x,$y,$z,$this->states['snow']);}
        }}
    }
    private function enhance(array &$sections,int $cx,int $cz):void {
        $set=static function(int $x,int $y,int $z,int $state)use(&$sections):void {if($y>=-64 && $y<320){$sections[($y+64)>>4][(($y&15)<<8)|(($z&15)<<4)|($x&15)]=$state;}};
        for($z=$cz*16;$z<$cz*16+16;++$z){for($x=$cx*16;$x<$cx*16+16;++$x){
            $c=$this->column($x,$z);$h=$c['height'];$hash=$this->noise->hash($x,0,$z,34);
            if($x*$x+$z*$z<100){continue;}
            if($c['biome']==='desert'){
                if($hash%113===0){for($dy=1;$dy<=1+intdiv($hash,113)%3;++$dy){$set($x,$h+$dy,$z,$this->states['cactus']);}}
                elseif($hash%37===0){$set($x,$h+1,$z,$this->states['dead_bush']);}
            }
            if($h<61 && $hash%7===0){$set($x,$h,$z,$this->states['gravel']);}
            if($h===63 && $hash%13===0 && min($this->column($x-1,$z)['height'],$this->column($x+1,$z)['height'],$this->column($x,$z-1)['height'],$this->column($x,$z+1)['height'])<63){for($dy=1;$dy<=2;++$dy){$set($x,$h+$dy,$z,$this->states['sugar_cane']);}}
            if($h>110 && $c['temperature']<0){$set($x,$h,$z,$this->states['snow_block']);$set($x,$h+1,$z,$this->states['snow']);}
            if($c['biome']==='forest' && $hash%97===0){$state=$sections[(($h+1+64)>>4)][((($h+1)&15)<<8)|(($z&15)<<4)|($x&15)];if($this->version->catalog->air($state)){$set($x,$h+1,$z,$this->states['brown_mushroom']);}}
        }}
    }
    /** Sections use the same Y/Z/X indexing as Minecraft's network palettes. */
    public function chunk(int $cx,int $cz): array {
        $sections=array_fill(0,24,array_fill(0,4096,0));
        for($z=0;$z<16;++$z){for($x=0;$x<16;++$x){
            $wx=$cx*16+$x;$wz=$cz*16+$z;$column=$this->column($wx,$wz);
            for($y=-64,$top=max(63,$column['height']);$y<=$top;++$y){
                $sections[($y+64)>>4][(($y&15)<<8)|($z<<4)|$x]=$this->base($wx,$y,$wz,$column);
            }
        }}
        $this->decorate($sections,$cx,$cz);
        if($this->enhanced){$this->enhance($sections,$cx,$cz);}
        return $sections;
    }
    public function biome(int $x,int $z): int {return $this->version->registryId('worldgen/biome',$this->column($x,$z)['biome']);}
}
