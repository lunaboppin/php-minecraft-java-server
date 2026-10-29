<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;
use PhpMinecraftJavaServer\Protocol\{Binary,Packets};

/** Pairings follow sent chunks and horizontal distance, as ChunkMap.TrackedEntity does. */
trait EntityTracking
{
    private function tracksPosition(Client $viewer,float $x,float $z,string $kind):bool {
        $range=min($this->viewDistance($viewer)*16,$this->version->catalog->tracking['ranges'][$kind]);
        return isset($viewer->loadedChunks[((int)floor($x/16)).':'.((int)floor($z/16))])
            && ($viewer->x-$x)**2+($viewer->z-$z)**2<=$range**2;
    }
    private function tracksPlayer(Client $viewer,Client $player):bool {
        return $viewer!==$player && $viewer->dimension===$player->dimension
            && ($viewer->gameMode===3 || $player->gameMode!==3)
            && $this->tracksPosition($viewer,$player->x,$player->z,'player');
    }
    private function forgetEntity(Client $viewer,int $id):void {
        if(!isset($viewer->entities[$id])){return;}
        $viewer->send($this->version,'remove_entities',"\1".Binary::writeVarInt($id));unset($viewer->entities[$id]);
    }
    private function updatePlayerTracking(Client $viewer,Client $player):void {
        if($this->tracksPlayer($viewer,$player)){$this->spawnPlayer($viewer,$player);}
        else{$this->forgetEntity($viewer,$player->entityId);}
    }
    private function refreshTracking(Client $viewer):void {
        if($viewer->dimension!==$this->world->dimension){$this->withDimension($viewer->dimension,fn()=>$this->refreshTracking($viewer));return;}
        $wanted=[];
        foreach($this->players() as $player){
            // Vanilla rechecks player pairings on movement/chunk events, not every metadata change.
            if(!$viewer->trackingDirty){if($player->dimension===$viewer->dimension && isset($viewer->entities[$player->entityId])){$wanted[$player->entityId]=true;}continue;}
            if($this->tracksPlayer($viewer,$player)){$wanted[$player->entityId]=true;$this->spawnPlayer($viewer,$player);}
        }
        foreach($this->animals as $animal){
            if($this->tracksPosition($viewer,$animal['x'],$animal['z'],$animal['kind'])){$wanted[$animal['id']]=true;$this->showAnimal($viewer,$animal);}
        }
        foreach($this->itemEntities as $entity){
            if($this->tracksPosition($viewer,$entity['x'],$entity['z'],'item')){$wanted[$entity['id']]=true;$this->showItem($viewer,$entity);}
        }
        foreach($this->experienceOrbs as $orb){
            if($this->tracksPosition($viewer,$orb['x'],$orb['z'],'experience_orb')){$wanted[$orb['id']]=true;$this->showOrb($viewer,$orb);}
        }
        foreach(array_keys($viewer->entities) as $id){if(!isset($wanted[$id])){$this->forgetEntity($viewer,$id);}}
        $viewer->trackingDirty=false;
    }
    private function playerMetadata(Client $player):string {
        $catalog=$this->version->catalog;$tracking=$catalog->tracking;
        $flags=($player->fireTicks>0?1:0)|($player->sneaking?2:0)|($player->sprinting?8:0)|($player->gameMode===3?32:0);
        $pose=$player->dead?'dying':($player->sneaking && !$player->flying && $player->gameMode!==3?'crouching':'standing');
        $vitals=Packets::vitals($catalog,$player->entityId,max(0,$player->air),$flags);
        return substr($vitals,0,-1).chr($tracking['pose_index']).Binary::writeVarInt($tracking['pose_serializer'])
            .Binary::writeVarInt($tracking['poses'][$pose])."\xff";
    }
    private function syncPlayerMetadata(Client $player):void {
        $payload=$this->playerMetadata($player);
        if($player->lastPlayerMetadata===$payload){return;}$player->lastPlayerMetadata=$payload;
        foreach($this->players() as $viewer){
            if($viewer===$player || isset($viewer->entities[$player->entityId])){$viewer->send($this->version,'set_entity_data',$payload);}
        }
    }
    private function broadcastPlayerPosition(Client $player,bool $head=true):void {
        $player->trackingDirty=true;$this->refreshTracking($player);
        foreach($this->players() as $viewer){
            if($viewer===$player){continue;}$this->updatePlayerTracking($viewer,$player);
            if(!isset($viewer->entities[$player->entityId])){continue;}
            $viewer->send($this->version,'teleport_entity',Packets::teleportEntity($player->entityId,$player->x,$player->y,$player->z,$player->yaw,$player->pitch,$player->ground));
            if($head){$viewer->send($this->version,'rotate_head',Packets::headRotation($player->entityId,$player->yaw));}
        }
    }
}
