<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World;
use PhpMinecraftJavaServer\Protocol\{Binary,Catalog};

final class Lighting
{
    private static function bitset(int $mask): string {
        $bytes=rtrim(pack('V',$mask),"\0");return Binary::writeVarInt(strlen($bytes)).$bytes;
    }
    private static function nibble(string &$bytes,int $index,int $value):void {
        $offset=$index>>1;$shift=($index&1)*4;
        $bytes[$offset]=chr((ord($bytes[$offset])&~(15<<$shift))|($value<<$shift));
    }
    public static function build(array $sections,array $surface,Catalog $catalog,bool $hasSkylight=true): string {
        $count=count($sections);$height=$count*16;
        $sky=array_fill(0,$count+2,str_repeat($hasSkylight?"\xff":"\0",2048));$sky[0]=str_repeat("\0",2048);
        $block=array_fill(0,$count+2,str_repeat("\0",2048));
        $levels=str_repeat("\0",$count*4096);$queue=new \SplQueue();
        // Catalog flags: 4 fluid, 8 opaque, emission above bit 4. Only sections holding a light
        // source are scanned for emitters, and only lit sections are copied into the nibbles.
        $flags=$catalog->flags;
        foreach($sections as $section=>$states){
            $emitting=false;
            foreach(array_count_values($states) as $state=>$_){if((($flags[$state]??0)>>4)>0){$emitting=true;break;}}
            if(!$emitting){continue;}
            foreach($states as $index=>$state){
                $emission=($flags[$state]??0)>>4;
                if($emission>0){$global=$section*4096+$index;$levels[$global]=chr($emission);$queue->enqueue($global);}
            }
        }
        if($hasSkylight){for($z=0;$z<16;++$z){for($x=0;$x<16;++$x){
            $light=15;
            for($y=$surface[$z*16+$x]-1;$y>=0;--$y){
                $section=$y>>4;$index=(($y&15)<<8)|($z<<4)|$x;$flag=$flags[$sections[$section][$index]]??0;
                if(($flag&8)!==0){$light=0;}
                elseif(($flag&4)!==0){$light=max(0,$light-1);}
                self::nibble($sky[$section+1],$index,$light);
            }
        }}
        }
        while(!$queue->isEmpty()){
            $global=$queue->dequeue();$value=ord($levels[$global])-1;
            if($value<=0){continue;}
            $x=$global&15;$z=($global>>4)&15;$y=$global>>8;
            foreach([[$x>0,-1],[$x<15,1],[$z>0,-16],[$z<15,16],[$y>0,-256],[$y<$height-1,256]] as [$inside,$delta]){
                if(!$inside){continue;}$next=$global+$delta;
                if(ord($levels[$next])>=$value || ((($flags[$sections[$next>>12][$next&4095]]??0)&8)!==0)){continue;}
                $levels[$next]=chr($value);$queue->enqueue($next);
            }
        }
        for($section=0;$section<$count;++$section){
            $offset=$section*4096;
            if(strspn($levels,"\0",$offset,4096)===4096){continue;}
            for($index=0;$index<4096;++$index){$value=ord($levels[$offset+$index]);if($value>0){self::nibble($block[$section+1],$index,$value);}}
        }
        return self::encode($sky,$block);
    }
    public static function encode(array $sky,array $block):string {
        $skyMask=0;$blockMask=0;$skyData=[];$blockData=[];
        foreach($sky as $i=>$bytes){if($bytes!==str_repeat("\0",2048)){$skyMask|=1<<$i;$skyData[]=$bytes;}}
        foreach($block as $i=>$bytes){if($bytes!==str_repeat("\0",2048)){$blockMask|=1<<$i;$blockData[]=$bytes;}}
        $all=(1<<count($sky))-1;
        $out=self::bitset($skyMask).self::bitset($blockMask).self::bitset($all^$skyMask).self::bitset($all^$blockMask);
        foreach([$skyData,$blockData] as $list){$out.=Binary::writeVarInt(count($list));foreach($list as $bytes){$out.=Binary::writeVarInt(2048).$bytes;}}
        return $out;
    }
}
