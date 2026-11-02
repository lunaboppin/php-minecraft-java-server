<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World;

/** Matches server-owned crafting grids; client result predictions never create items. */
final class Crafting
{
    private array $recipes;
    public function __construct(string $directory){$this->recipes=json_decode(file_get_contents($directory.'/recipes.json'),true,512,JSON_THROW_ON_ERROR);}
    public function match(array $grid,int $size):?array {
        $occupied=[];foreach($grid as $i=>$stack){if($stack!==null && $stack['count']>0){$occupied[$i]=$stack['item'];}}
        if($occupied===[]){return null;}
        foreach($this->recipes as $recipe){
            if($recipe['type']==='crafting_shaped'){
                $w=$recipe['width'];$h=$recipe['height'];if($w>$size || $h>$size){continue;}
                for($oy=0;$oy<=$size-$h;++$oy){for($ox=0;$ox<=$size-$w;++$ox){foreach([false,true] as $mirror){
                    $matches=true;$used=[];
                    for($y=0;$y<$size && $matches;++$y){for($x=0;$x<$size;++$x){
                        $slot=$y*$size+$x;$rx=$x-$ox;$ry=$y-$oy;
                        $allowed=($rx>=0 && $rx<$w && $ry>=0 && $ry<$h)?$recipe['ingredients'][$ry*$w+($mirror?$w-1-$rx:$rx)]:[];
                        $item=$occupied[$slot]??null;
                        if(($item===null)!==($allowed===[]) || ($item!==null && !in_array($item,$allowed,true))){$matches=false;break;}
                        if($item!==null){$used[]=$slot;}
                    }}
                    if($matches){return ['result'=>$recipe['result'],'used'=>$used];}
                }}}
            }elseif($recipe['type']==='crafting_shapeless' && count($occupied)===count($recipe['ingredients'])){
                $remaining=$recipe['ingredients'];usort($remaining,static fn(array $a,array $b):int=>count($a)<=>count($b));
                if($this->assign($remaining,array_values($occupied))){return ['result'=>$recipe['result'],'used'=>array_keys($occupied)];}
            }
        }
        return null;
    }
    private function assign(array $ingredients,array $items):bool {
        if($ingredients===[]){return true;}$allowed=array_shift($ingredients);
        foreach($items as $i=>$item){if(in_array($item,$allowed,true)){$left=$items;unset($left[$i]);if($this->assign($ingredients,array_values($left))){return true;}}}
        return false;
    }
    public function recipe(string $name):?array {foreach($this->recipes as $recipe){if($recipe['name']===$name){return $recipe;}}return null;}
    public function smelt(int $item,string $type='smelting'):?array {foreach($this->recipes as $recipe){if($recipe['type']===$type && in_array($item,$recipe['ingredients'][0],true)){return $recipe;}}return null;}
}
