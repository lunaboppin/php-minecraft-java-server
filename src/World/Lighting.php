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
    /** @var array<string, list<int>> per directory: light dampening | emission << 8, by state */
    private static array $light=[];
    /**
     * Light for a chunk's sections as the official light engine propagates it inside the chunk:
     * sky light enters from above at 15 and passes down undimmed through blocks with no light
     * dampening; from there, and from each block's light emission, light spreads to the six
     * neighbours losing the larger of 1 and the dampening of the block it enters. Light from
     * neighbouring chunks and partial-block occlusion are not modelled.
     */
    public static function build(array $sections,array $surface,Catalog $catalog,bool $hasSkylight=true,?string $directory=null): string {
        $count=count($sections);$height=$count*16;
        $table=$directory===null?null:(self::$light[$directory]??=self::table($directory));
        $damp=static fn(int $state):int=>$table===null?((($catalog->flags[$state]??0)&8)!==0?15:((($catalog->flags[$state]??0)&4)!==0?1:0)):$table[$state]&15;
        $emit=static fn(int $state):int=>$table===null?(($catalog->flags[$state]??0)>>4&15):$table[$state]>>8&15;
        $sky=array_fill(0,$count+2,str_repeat($hasSkylight?"\xff":"\0",2048));$sky[0]=str_repeat("\0",2048);
        $block=array_fill(0,$count+2,str_repeat("\0",2048));
        $dampening=[];
        $cellDamp=function(int $global) use (&$dampening,$sections,$damp):int{
            return $dampening[$global]??=$damp($sections[$global>>12][$global&4095]);
        };
        $spread=function(string &$levels,\SplQueue $queue) use ($cellDamp,$height):void{
            while(!$queue->isEmpty()){
                $global=$queue->dequeue();$value=ord($levels[$global]);
                $x=$global&15;$z=($global>>4)&15;$y=$global>>8;
                foreach([[$x>0,-1],[$x<15,1],[$z>0,-16],[$z<15,16],[$y>0,-256],[$y<$height-1,256]] as [$inside,$delta]){
                    if(!$inside){continue;}$next=$global+$delta;
                    $light=$value-max(1,$cellDamp($next));
                    if($light<=0 || ord($levels[$next])>=$light){continue;}
                    $levels[$next]=chr($light);$queue->enqueue($next);
                }
            }
        };
        // Block light from every emitting block.
        $levels=str_repeat("\0",$count*4096);$queue=new \SplQueue();
        foreach($sections as $section=>$states){
            $emitting=false;
            foreach(array_count_values($states) as $state=>$_){if($emit($state)>0){$emitting=true;break;}}
            if(!$emitting){continue;}
            foreach($states as $index=>$state){
                $emission=$emit($state);
                if($emission>0){$global=$section*4096+$index;$levels[$global]=chr($emission);$queue->enqueue($global);}
            }
        }
        $spread($levels,$queue);
        self::store($block,$levels,$count);
        if($hasSkylight){
            // Each column is lit at 15 down to its first block with any dampening; the lit cells
            // beside a lower-lit column, and each column's lowest lit cell, start the spread.
            $levels=str_repeat("\0",$count*4096);$floor=[];
            for($column=0;$column<256;++$column){
                $y=$height;
                while($y>0 && $cellDamp((($y-1)<<8)|$column)===0){--$y;}
                $floor[$column]=$y;
                for($lit=$y;$lit<$height;++$lit){$levels[($lit<<8)|$column]="\x0f";}
            }
            $queue=new \SplQueue();
            for($column=0;$column<256;++$column){
                $x=$column&15;$z=$column>>4;
                if($floor[$column]<$height && $floor[$column]>0){$queue->enqueue(($floor[$column]<<8)|$column);}
                foreach([[$x>0,-1],[$x<15,1],[$z>0,-16],[$z<15,16]] as [$inside,$delta]){
                    if(!$inside){continue;}
                    for($y=$floor[$column];$y<$floor[$column+$delta];++$y){$queue->enqueue(($y<<8)|$column);}
                }
            }
            $spread($levels,$queue);
            for($section=0;$section<$count;++$section){$sky[$section+1]=str_repeat("\0",2048);}
            self::store($sky,$levels,$count);
        }
        return self::encode($sky,$block);
    }
    /** Copy byte levels into the sections' nibble arrays (index 0 is the section below the world). */
    private static function store(array &$nibbles,string $levels,int $count):void {
        for($section=0;$section<$count;++$section){
            $offset=$section*4096;
            if(strspn($levels,"\0",$offset,4096)===4096){continue;}
            for($index=0;$index<4096;++$index){$value=ord($levels[$offset+$index]);if($value>0){self::nibble($nibbles[$section+1],$index,$value);}}
        }
    }
    /** @return list<int> */
    private static function table(string $directory):array {
        $values=array_values(unpack('v*',base64_decode(json_decode((string)file_get_contents($directory.'/worldgen/block-behaviour.json'),true,512,JSON_THROW_ON_ERROR)['spawning'])));
        return array_map(static fn(int $v):int=>($v&15)|(($v>>8&15)<<8),$values);
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
