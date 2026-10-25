<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;
use PhpMinecraftJavaServer\World\{Collision,Inventory};

/** Plain vanilla eggs; custom entity components and spawner editing remain separate work. */
trait SpawnEggs
{
    private function eggKind(Client $player,int $slot):?string {
        $item=$this->version->catalog->itemName($player->inventory[$slot]['item']??-1);
        return $this->version->catalog->mobs['spawn_eggs'][$item??'']??null;
    }
    private function useSpawnEggOn(Client $player,int $slot,int $x,int $y,int $z,int $face):bool {
        $kind=$this->eggKind($player,$slot);if($kind===null){return false;}
        if(!$this->editable($player,$x,$y,$z)){return true;}
        $collision=new Collision($this->version->catalog);$empty=$collision->shape($this->world->get($x,$y,$z))===[];
        $offset=[[0,-1,0],[0,1,0],[0,0,-1],[0,0,1],[-1,0,0],[1,0,0]][$face];
        [$tx,$ty,$tz]=$empty?[$x,$y,$z]:[$x+$offset[0],$y+$offset[1],$z+$offset[2]];
        if($ty<$this->world->minY || $ty>=$this->world->minY+$this->world->height || !isset($player->loadedChunks[($tx>>4).':'.($tz>>4)])){return true;}
        $type=$this->version->catalog->mobs['entities'][$kind];
        $height=$collision->eggHeight($this->world,$tx,$ty,$tz,$type['width'],$type['height'],!$empty && $face===1);
        $this->spawnFromEgg($player,$slot,$kind,$tx+0.5,$height,$tz+0.5);return true;
    }
    private function spawnFromEgg(Client $player,int $slot,string $kind,float $x,float $y,float $z):void {
        if($this->spawnAnimal($kind,$x,$y,$z)===null){return;}
        if($player->gameMode===0){Inventory::consume($player->inventory,$slot);$this->syncInventory($player);}
    }
    private function useSpawnEggInAir(Client $player,int $slot):bool {
        $kind=$this->eggKind($player,$slot);if($kind===null){return false;}
        if(!in_array($player->gameMode,[0,1],true) || $player->teleport!==null){return true;}
        $yaw=deg2rad($player->yaw);$pitch=deg2rad($player->pitch);$direction=[-sin($yaw)*cos($pitch),-sin($pitch),cos($yaw)*cos($pitch)];
        $reach=$player->gameMode===1?5.0:4.5;
        $eyeHeight=$player->sneaking && !$player->flying && $player->gameMode!==3?1.27:1.62;
        $hit=(new Collision($this->version->catalog))->sourceRay($this->world,[$player->x,$player->y+$eyeHeight,$player->z],$direction,$reach);
        if($hit===null || !$hit['fluid']){return true;}[$x,$y,$z]=$hit['position'];
        if(isset($player->loadedChunks[($x>>4).':'.($z>>4)]) && in_array($this->version->catalog->blockName($this->world->get($x,$y,$z)),['water','lava'],true)){$this->spawnFromEgg($player,$slot,$kind,$x+0.5,(float)$y,$z+0.5);}
        return true;
    }
}
