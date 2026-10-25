<?php
declare(strict_types=1);
namespace Jva\World;
use Jva\Protocol\{Binary,Catalog};

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
        foreach($sections as $section=>$states){
            foreach($states as $index=>$state){
                $emission=$catalog->emission($state);
                if($emission>0){$global=$section*4096+$index;$levels[$global]=chr($emission);$queue->enqueue($global);}
            }
        }
        if($hasSkylight){for($z=0;$z<16;++$z){for($x=0;$x<16;++$x){
            $light=15;
            for($y=$surface[$z*16+$x]-1;$y>=0;--$y){
                $section=$y>>4;$index=(($y&15)<<8)|($z<<4)|$x;$state=$sections[$section][$index];
                if($catalog->opaque($state)){$light=0;}
                elseif($catalog->fluid($state)){$light=max(0,$light-1);}
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
                if(ord($levels[$next])>=$value || $catalog->opaque($sections[$next>>12][$next&4095])){continue;}
                $levels[$next]=chr($value);$queue->enqueue($next);
            }
        }
        for($global=0;$global<$count*4096;++$global){$value=ord($levels[$global]);if($value>0){self::nibble($block[($global>>12)+1],$global&4095,$value);}}
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
