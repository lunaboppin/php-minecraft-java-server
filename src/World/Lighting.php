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
    /** @var array<string, array<int, list<string>>> per directory: face occlusion masks by state, for shaped states */
    private static array $faces=[];
    /** Direction index (down, up, north, south, west, east) of each step in the spread order. */
    private const STEP_FACES=[4,5,2,3,0,1];
    /**
     * LightEngine.getOcclusionShape masks of the states that use their shape for light occlusion:
     * six 32-byte coverage masks (down, up, north, south, west, east).
     *
     * @return array<int, list<string>>
     */
    public static function faces(?string $directory):array {
        if($directory===null){return [];}
        if(!isset(self::$faces[$directory])){
            $data=json_decode((string)file_get_contents($directory.'/worldgen/block-behaviour.json'),true,512,JSON_THROW_ON_ERROR)['light_faces']??['masks'=>[],'states'=>[]];
            $masks=array_map('base64_decode',$data['masks']);$out=[];
            foreach($data['states'] as $state=>$indexes){$out[(int)$state]=array_map(static fn(int $i):string=>$masks[$i],$indexes);}
            self::$faces[$directory]=$out;
        }
        return self::$faces[$directory];
    }
    /** Shapes.faceShapeOccludes: the two touching faces together cover the whole face (face: from's side). */
    private static function occludes(array $faces,int $from,int $to,int $face):bool {
        $a=$faces[$from][$face]??null;$b=$faces[$to][$face^1]??null;
        if($a===null && $b===null){return false;}
        return (($a??str_repeat("\0",32))|($b??str_repeat("\0",32)))===str_repeat("\xff",32);
    }
    /**
     * Light for a chunk's sections as the official light engine propagates it inside the chunk:
     * sky light enters from above at 15 and passes down undimmed through blocks with no light
     * dampening; from there, and from each block's light emission, light spreads to the six
     * neighbours losing the larger of 1 and the dampening of the block it enters. Light from
     * neighbouring chunks and partial-block occlusion are not modelled.
     */
    public static function build(array $sections,array $surface,Catalog $catalog,bool $hasSkylight=true,?string $directory=null,?array $neighbours=null): string {
        [$skyLevels,$blockLevels]=self::levels($sections,$catalog,$hasSkylight,$directory);
        if($neighbours!==null){
            $neighbours[4]=[self::dampening($sections,$catalog,$directory),$skyLevels,$blockLevels,self::shapes($sections,$directory)];
            return self::buildArea($neighbours,count($sections),$hasSkylight,$directory);
        }
        return self::encodeLevels($skyLevels,$blockLevels,count($sections),$hasSkylight);
    }
    /**
     * The middle chunk's light packet data from its 3×3 area of dampening and own levels.
     *
     * @param array<int, array{0: string, 1: string, 2: string, 3: array<int, int>}|null> $area
     */
    public static function buildArea(array $area,int $count,bool $hasSkylight=true,?string $directory=null):string {
        self::crossBorders($area,$count,$hasSkylight,self::faces($directory));
        return self::encodeLevels($area[4][1],$area[4][2],$count,$hasSkylight);
    }
    /** @return array<int, int> the cells holding states that occlude light by their shape, by cell index */
    public static function shapes(array $sections,?string $directory):array {
        $faces=self::faces($directory);$out=[];
        if($faces===[]){return $out;}
        foreach($sections as $section=>$states){
            foreach(array_keys(array_count_values($states)) as $state){
                if(!isset($faces[$state])){continue;}
                foreach(array_keys($states,$state,true) as $index){$out[$section*4096+$index]=$state;}
            }
        }
        return $out;
    }
    /** Each cell's light dampening as a byte, in section, y, z, x order. */
    public static function dampening(array $sections,Catalog $catalog,?string $directory=null):string {
        $damp=self::damper($catalog,$directory);$out='';
        foreach($sections as $states){
            $chars=[];foreach(array_keys(array_count_values($states)) as $state){$chars[$state]=chr($damp($state));}
            if(count($chars)===1){$out.=str_repeat(reset($chars),4096);continue;}
            $section='';foreach($states as $state){$section.=$chars[$state];}
            $out.=$section;
        }
        return $out;
    }
    /** Whether two states block (by dampening and shape) and give light alike, so swapping one for the other leaves light unchanged. */
    public static function sameLight(int $a,int $b,Catalog $catalog,?string $directory):bool {
        if($directory===null){return (($catalog->flags[$a]??0)&0xfc)===(($catalog->flags[$b]??0)&0xfc);}
        $table=self::$light[$directory]??=self::table($directory);$faces=self::faces($directory);
        return $table[$a]===$table[$b] && ($faces[$a]??null)===($faces[$b]??null);
    }
    /** @return \Closure(int): int light dampening by state */
    private static function damper(Catalog $catalog,?string $directory):\Closure {
        $table=$directory===null?null:(self::$light[$directory]??=self::table($directory));
        return static fn(int $state):int=>$table===null?((($catalog->flags[$state]??0)&8)!==0?15:((($catalog->flags[$state]??0)&4)!==0?1:0)):$table[$state]&15;
    }
    /**
     * A chunk's own sky and block light levels, one byte per cell in section, y, z, x order, as
     * the light engine propagates them inside the chunk alone.
     *
     * @return array{0: string, 1: string}
     */
    public static function levels(array $sections,Catalog $catalog,bool $hasSkylight=true,?string $directory=null):array {
        $count=count($sections);$height=$count*16;
        $table=$directory===null?null:(self::$light[$directory]??=self::table($directory));
        $damp=self::damper($catalog,$directory);
        $emit=static fn(int $state):int=>$table===null?(($catalog->flags[$state]??0)>>4&15):$table[$state]>>8&15;
        $dampening=[];$faces=self::faces($directory);
        $cellDamp=function(int $global) use (&$dampening,$sections,$damp):int{
            return $dampening[$global]??=$damp($sections[$global>>12][$global&4095]);
        };
        $stateAt=static fn(int $global):int=>$sections[$global>>12][$global&4095];
        $spread=function(string &$levels,\SplQueue $queue) use ($cellDamp,$height,$faces,$stateAt):void{
            while(!$queue->isEmpty()){
                $global=$queue->dequeue();$value=ord($levels[$global]);
                $x=$global&15;$z=($global>>4)&15;$y=$global>>8;
                foreach([[$x>0,-1],[$x<15,1],[$z>0,-16],[$z<15,16],[$y>0,-256],[$y<$height-1,256]] as $step=>[$inside,$delta]){
                    if(!$inside){continue;}$next=$global+$delta;
                    $light=$value-max(1,$cellDamp($next));
                    if($light<=0 || ord($levels[$next])>=$light){continue;}
                    if($faces!==[] && self::occludes($faces,$stateAt($global),$stateAt($next),self::STEP_FACES[$step])){continue;}
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
        $blockLevels=$levels;$skyLevels=str_repeat("\0",$count*4096);
        if($hasSkylight){
            // Each column is lit at 15 down to its first block with any dampening; the lit cells
            // beside a lower-lit column, and each column's lowest lit cell, start the spread.
            $levels=str_repeat("\0",$count*4096);$floor=[];
            // Sections whose blocks all pass light freely are skipped whole on the way down.
            $clear=[];
            foreach($sections as $section=>$states){$clear[$section]=true;foreach(array_keys(array_count_values($states)) as $kind){if($damp($kind)!==0 || isset($faces[$kind])){$clear[$section]=false;break;}}}
            $top=$count;while($top>0 && $clear[$top-1]){--$top;}
            for($column=0;$column<256;++$column){
                // ChunkSkyLightSources: the column's light source ends at a block with dampening or
                // where the faces of two shaped blocks close the edge between them.
                $y=$top*16;$above=0;
                while($y>0 && $cellDamp((($y-1)<<8)|$column)===0){
                    $below=$stateAt((($y-1)<<8)|$column);
                    if($faces!==[] && (isset($faces[$above]) || isset($faces[$below])) && self::occludes($faces,$above,$below,0)){break;}
                    $above=$below;--$y;
                }
                $floor[$column]=$y;
            }
            // Sections from the highest floor up are fully lit; only those below need the spread.
            $full=min($count,intdiv(max($floor),16)+1);
            for($column=0;$column<256;++$column){for($lit=$floor[$column];$lit<$full*16;++$lit){$levels[($lit<<8)|$column]="\x0f";}}
            $queue=new \SplQueue();
            for($column=0;$column<256;++$column){
                $x=$column&15;$z=$column>>4;
                if($floor[$column]<$full*16 && $floor[$column]>0){$queue->enqueue(($floor[$column]<<8)|$column);}
                foreach([[$x>0,-1],[$x<15,1],[$z>0,-16],[$z<15,16]] as [$inside,$delta]){
                    if(!$inside){continue;}
                    for($y=$floor[$column];$y<min($floor[$column+$delta],$full*16);++$y){$queue->enqueue(($y<<8)|$column);}
                }
            }
            $spread($levels,$queue);
            $skyLevels=substr($levels,0,$full*4096).str_repeat("\x0f",($count-$full)*4096);
        }
        return [$skyLevels,$blockLevels];
    }
    /** The light packet data of a chunk's levels, with the sections below and above the world. */
    public static function encodeLevels(string $skyLevels,string $blockLevels,int $count,bool $hasSkylight=true):string {
        $sky=array_fill(0,$count+2,str_repeat("\0",2048));$sky[$count+1]=str_repeat($hasSkylight?"\xff":"\0",2048);
        $block=array_fill(0,$count+2,str_repeat("\0",2048));
        self::store($sky,$skyLevels,$count);self::store($block,$blockLevels,$count);
        return self::encode($sky,$block);
    }
    /**
     * Light crossing chunk borders. Entry 4 of the 3×3 area (index (dz+1)*3+dx+1) is the chunk
     * being lit; each entry holds a chunk's dampening and its own sky and block levels, or null for
     * a chunk not generated yet. Each chunk's own levels are already settled inside it, so only a
     * cell beside a border can raise its neighbour across it; from there light spreads on through
     * the area as inside a chunk. Light travels at most 15 blocks, so cells further out cannot
     * reach the middle chunk.
     *
     * @param array<int, array{0: string, 1: string, 2: string, 3: array<int, int>}|null> $area dampening, sky and block levels, shaped cells
     * @param array<int, list<string>> $faces
     */
    private static function crossBorders(array &$area,int $count,bool $hasSkylight,array $faces=[]):void {
        $height=$count*16;
        $shapes=array_map(static fn(?array $entry):array=>$entry[3]??[],$area);
        // A step between two cells is blocked where shaped blocks' faces close it (face: the step's side of $k).
        $closed=static function(int $k,int $index,int $nk,int $next,int $face) use ($faces,$shapes):bool{
            $a=$shapes[$k][$index]??null;$b=$shapes[$nk][$next]??null;
            return ($a!==null || $b!==null) && self::occludes($faces,$a??0,$b??0,$face);
        };
        $damps=array_map(static fn(?array $entry):?string=>$entry[0]??null,$area);
        $cellDamp=static fn(int $k,int $index):int=>ord($damps[$k][$index]);
        foreach($hasSkylight?[1,2]:[2] as $layer){
            $queue=new \SplQueue();
            // Borders between horizontally adjacent chunks of the area: [chunk, neighbour, axis].
            foreach([[0,1,'x'],[1,2,'x'],[3,4,'x'],[4,5,'x'],[6,7,'x'],[7,8,'x'],[0,3,'z'],[3,6,'z'],[1,4,'z'],[4,7,'z'],[2,5,'z'],[5,8,'z']] as [$a,$b,$axis]){
                if($area[$a]===null || $area[$b]===null){continue;}
                $la=&$area[$a][$layer];$lb=&$area[$b][$layer];$da=$damps[$a];$db=$damps[$b];
                for($section=0;$section<$count;++$section){
                    $offset=$section*4096;
                    // Sections with no light on either side, or full light on both, exchange nothing.
                    if(strspn($la,"\0",$offset,4096)===4096 && strspn($lb,"\0",$offset,4096)===4096){continue;}
                    if(strspn($la,"\x0f",$offset,4096)===4096 && strspn($lb,"\x0f",$offset,4096)===4096){continue;}
                    for($y=0;$y<16;++$y){for($i=0;$i<16;++$i){
                        // a's cell on its +x (or +z) face against b's cell on its -x (or -z) face.
                        if($axis==='x'){$ia=$offset|($y<<8)|($i<<4)|15;$ib=$ia-15;}else{$ia=$offset|($y<<8)|240|$i;$ib=$ia-240;}
                        $va=ord($la[$ia]);$vb=ord($lb[$ib]);
                        // a's +x (east) or +z (south) face meets b's opposite face.
                        $face=$axis==='x'?5:3;
                        if($va>$vb+1){$light=$va-max(1,ord($db[$ib]));if($light>$vb && !$closed($a,$ia,$b,$ib,$face)){$lb[$ib]=chr($light);$queue->enqueue([$b,$ib]);}}
                        elseif($vb>$va+1){$light=$vb-max(1,ord($da[$ia]));if($light>$va && !$closed($b,$ib,$a,$ia,$face^1)){$la[$ia]=chr($light);$queue->enqueue([$a,$ia]);}}
                    }}
                }
                unset($la,$lb);
            }
            while(!$queue->isEmpty()){
                [$k,$index]=$queue->dequeue();$value=ord($area[$k][$layer][$index]);
                if($value<=1){continue;}
                $x=$index&15;$z=($index>>4)&15;$y=$index>>8;
                foreach([[-1,0,0,4],[1,0,0,5],[0,0,-1,2],[0,0,1,3],[0,-1,0,0],[0,1,0,1]] as [$dx,$dy,$dz,$face]){
                    $ny=$y+$dy;if($ny<0 || $ny>=$height){continue;}
                    $nx=$x+$dx;$nz=$z+$dz;$nk=$k;
                    if($nx<0 || $nx>15){$nk+=$nx<0?-1:1;if(intdiv($k,3)!==intdiv($nk,3) || $nk<0 || $nk>8){continue;}$nx&=15;}
                    if($nz<0 || $nz>15){$nk+=$nz<0?-3:3;if($nk<0 || $nk>8){continue;}$nz&=15;}
                    if($area[$nk]===null){continue;}
                    $next=($ny<<8)|($nz<<4)|$nx;
                    $light=$value-max(1,$cellDamp($nk,$next));
                    if($light<=ord($area[$nk][$layer][$next]) || $closed($k,$index,$nk,$next,$face)){continue;}
                    $area[$nk][$layer][$next]=chr($light);$queue->enqueue([$nk,$next]);
                }
            }
        }
    }
    /** Copy byte levels into the sections' nibble arrays (index 0 is the section below the world). */
    private static function store(array &$nibbles,string $levels,int $count):void {
        for($section=0;$section<$count;++$section){
            $offset=$section*4096;
            if(strspn($levels,"\0",$offset,4096)===4096){continue;}
            if(strspn($levels,"\x0f",$offset,4096)===4096){$nibbles[$section+1]=str_repeat("\xff",2048);continue;}
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
