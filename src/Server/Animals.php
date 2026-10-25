<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;
use PhpMinecraftJavaServer\Protocol\{Binary,Packets};
use PhpMinecraftJavaServer\World\{Noise,Collision};

/** Persistent mob state; species-specific AI and progression remain separate parity work. */
trait Animals
{
    private array $animals=[];
    private array $naturalSpawns=[];
    /** Guaranteed main-hand items in the pinned skeleton/illager populateDefaultEquipmentSlots methods. */
    private function mobEquipment(string $kind):array {
        $name=match($kind){'skeleton','stray','bogged','parched'=>'bow','wither_skeleton'=>'stone_sword','pillager'=>'crossbow','vindicator'=>'iron_axe',default=>null};
        return $name===null?[]:[0=>['item'=>$this->version->blocks['items'][$name],'count'=>1]];
    }
    private function restoreAnimals():void {
        $this->naturalSpawns=$this->world->setting('naturalSpawns')??[];
        foreach($this->world->setting('animals')??[] as $animal){
            if(isset($animal['deadAt']) || !isset($this->version->catalog->mobs['entities'][$animal['kind']??'']['attributes']['max_health'])){continue;}
            if($this->config->difficulty===0 && !$this->version->catalog->mobs['entities'][$animal['kind']]['peaceful']){continue;}
            $id=$this->nextEntity++;if($animal['kind']==='ender_dragon'){$this->nextEntity+=8;}
            foreach(['x','y','z','yaw'] as $field){$animal[$field]=(float)$animal[$field];}
            $animal['equipment']??=$this->mobEquipment($animal['kind']);
            $animal['id']=$id;$animal['nextWander']=0;$animal['velocity']??=[0.0,0.0,0.0];$this->animals[$id]=$animal;
        }
    }
    private function spawnAnimal(string $kind,float $x,float $y,float $z):?int {
        $info=$this->version->catalog->mobs['entities'][$kind]??null;
        if($info===null || in_array($kind,['player','armor_stand','mannequin'],true) || !isset($info['attributes']['max_health']) || !$info['summonable'] || !$info['enabled'] || ($this->config->difficulty===0 && !$info['peaceful']) || count($this->animals)>=256){return null;}
        $id=$this->nextEntity++;if($kind==='ender_dragon'){$this->nextEntity+=8;}
        $this->animals[$id]=['id'=>$id,'uuid'=>bin2hex(random_bytes(16)),'kind'=>$kind,'health'=>$info['attributes']['max_health'],'x'=>$x,'y'=>$y,'z'=>$z,'yaw'=>0.0,'targetX'=>$x,'targetY'=>$y,'targetZ'=>$z,'nextWander'=>0,'velocity'=>[0.0,0.0,0.0]];
        $this->animals[$id]['equipment']=$this->mobEquipment($kind);
        foreach($this->localPlayers() as $p){$this->showAnimal($p,$this->animals[$id]);}return $id;
    }
    private function naturalAnimals(int $cx,int $cz):void {
        if($this->world->generator!=='terrain'){return;}$key=$cx.':'.$cz;if(isset($this->naturalSpawns[$key])){return;}$this->naturalSpawns[$key]=true;
        $noise=new Noise($this->world->seed);$hash=$noise->hash($cx,0,$cz,120);
        if($hash%6!==0){return;}$x=$cx*16+4+$hash%8;$z=$cz*16+4+intdiv($hash,11)%8;$column=$this->world->column($x,$z);
        if(!in_array($column['biome'],['plains','forest','taiga','meadow'],true) || $column['height']<64 || !$this->version->catalog->blocks[$this->version->catalog->blockName($this->world->get($x,$column['height']+1,$z))]['replaceable']){return;}
        $kind=['cow','pig','sheep','chicken'][intdiv($hash,19)%4];$this->spawnAnimal($kind,$x+0.5,$column['height']+1,$z+0.5);$this->spawnAnimal($kind,$x+1.5,$column['height']+1,$z+0.5);
    }
    private function showAnimal(Client $c,array $animal):void {
        $id=$animal['id'];
        if(isset($c->entities[$id]) || !$this->tracksPosition($c,$animal['x'],$animal['z'],$animal['kind'])){return;}
        $c->send($this->version,'add_entity',Packets::addPlayer($id,hex2bin($animal['uuid']),$this->version->blocks['entity_types'][$animal['kind']],$animal['x'],$animal['y'],$animal['z'],$animal['yaw'],0));$c->entities[$id]=true;
        $c->send($this->version,'set_entity_data',Packets::mobHealth($this->version->catalog,$id,$animal['health']));
        $attributes=array_intersect_key($this->version->catalog->mobs['entities'][$animal['kind']]['attributes'],array_flip($this->version->catalog->mobs['sync_attributes']));
        $c->send($this->version,'update_attributes',Packets::attributes($this->version->catalog,$id,$attributes));
        if(($animal['equipment']??[])!==[]){$c->send($this->version,'set_equipment',Packets::equipment($id,$animal['equipment'],$this->version->blocks['component_damage']));}
    }
    private function removeAnimal(int $id):void {
        unset($this->animals[$id]);foreach($this->localPlayers() as $p){if(isset($p->entities[$id])){$p->send($this->version,'remove_entities',"\1".Binary::writeVarInt($id));unset($p->entities[$id]);}}
    }
    private function attackAnimal(Client $c,int $id):bool {
        if(!isset($this->animals[$id])){return false;}$animal=&$this->animals[$id];
        if(isset($animal['deadAt']) || ($c->x-$animal['x'])**2+($c->y-$animal['y'])**2+($c->z-$animal['z'])**2>4.5**2){return true;}
        $c->lastAttackTick=$this->ticks;$tool=$this->version->catalog->itemName($c->inventory[36+$c->heldSlot]['item']??-1)??'';$damage=str_ends_with($tool,'_sword') || str_ends_with($tool,'_axe')?6:1;$animal['health']-=$damage;
        foreach($this->localPlayers() as $p){if(isset($p->entities[$id])){$p->send($this->version,'set_entity_data',Packets::mobHealth($this->version->catalog,$id,max(0,$animal['health'])));$p->send($this->version,'entity_event',pack('N',$id).chr($animal['health']<=0?3:2));}}
        if($animal['health']<=0){
            $animal['deadAt']=$this->ticks+20;
            $drops=match($animal['kind']){'cow'=>['beef'=>random_int(1,3),'leather'=>random_int(0,2)],'pig'=>['porkchop'=>random_int(1,3)],'sheep'=>['mutton'=>random_int(1,2),'white_wool'=>1],'chicken'=>['chicken'=>1,'feather'=>random_int(0,2)],default=>[]};
            foreach($drops as $name=>$count){if($count>0){$this->dropStack($animal['x'],$animal['y']+0.4,$animal['z'],['item'=>$this->version->blocks['items'][$name],'count'=>$count]);}}if(in_array($animal['kind'],['cow','pig','sheep','chicken'],true)){$c->experience+=random_int(1,3);}
        }
        return true;
    }
    private function tickAnimals():void {
        if($this->ticks%4!==0){return;}$noise=new Noise($this->world->seed);
        foreach($this->animals as $id=>&$animal){
            if(isset($animal['deadAt'])){if($this->ticks>=$animal['deadAt']){$this->removeAnimal($id);}continue;}
            $near=false;foreach($this->localPlayers() as $p){$this->showAnimal($p,$animal);if(($p->x-$animal['x'])**2+($p->z-$animal['z'])**2<80**2){$near=true;}}
            if(!$near){continue;}
            $this->moveMob($animal,$noise);
        }unset($animal);
    }
}
