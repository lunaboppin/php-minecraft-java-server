<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;
use PhpMinecraftJavaServer\Protocol\{Binary,Packets};
use PhpMinecraftJavaServer\World\Noise;

/** Small persistent passive-animal simulation; deliberately separate from terrain generation. */
trait Animals
{
    private array $animals=[];
    private array $naturalSpawns=[];
    private function restoreAnimals():void {
        $this->naturalSpawns=$this->world->setting('naturalSpawns')??[];
        foreach($this->world->setting('animals')??[] as $animal){if(isset($animal['deadAt'])){continue;}$id=$this->nextEntity++;$animal['id']=$id;$animal['nextWander']=0;$this->animals[$id]=$animal;}
    }
    private function spawnAnimal(string $kind,float $x,float $y,float $z):?int {
        if(!in_array($kind,['cow','pig','sheep','chicken'],true) || count($this->animals)>=32){return null;}
        $id=$this->nextEntity++;$health=match($kind){'chicken'=>4,'sheep'=>8,default=>10};
        $this->animals[$id]=['id'=>$id,'uuid'=>bin2hex(random_bytes(16)),'kind'=>$kind,'health'=>$health,'x'=>$x,'y'=>$y,'z'=>$z,'yaw'=>0.0,'targetX'=>$x,'targetZ'=>$z,'nextWander'=>0];
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
    }
    private function removeAnimal(int $id):void {
        unset($this->animals[$id]);foreach($this->localPlayers() as $p){if(isset($p->entities[$id])){$p->send($this->version,'remove_entities',"\1".Binary::writeVarInt($id));unset($p->entities[$id]);}}
    }
    private function attackAnimal(Client $c,int $id):bool {
        if(!isset($this->animals[$id])){return false;}$animal=&$this->animals[$id];
        if(isset($animal['deadAt']) || ($c->x-$animal['x'])**2+($c->y-$animal['y'])**2+($c->z-$animal['z'])**2>4.5**2){return true;}
        $c->lastAttackTick=$this->ticks;$tool=$this->version->catalog->itemName($c->inventory[36+$c->heldSlot]['item']??-1)??'';$damage=str_ends_with($tool,'_sword') || str_ends_with($tool,'_axe')?6:1;$animal['health']-=$damage;
        foreach($this->localPlayers() as $p){if(isset($p->entities[$id])){$p->send($this->version,'entity_event',pack('N',$id).chr($animal['health']<=0?3:2));}}
        if($animal['health']<=0){
            $animal['deadAt']=$this->ticks+20;
            $drops=match($animal['kind']){'cow'=>['beef'=>random_int(1,3),'leather'=>random_int(0,2)],'pig'=>['porkchop'=>random_int(1,3)],'sheep'=>['mutton'=>random_int(1,2),'white_wool'=>1],default=>['chicken'=>1,'feather'=>random_int(0,2)]};
            foreach($drops as $name=>$count){if($count>0){$this->dropStack($animal['x'],$animal['y']+0.4,$animal['z'],['item'=>$this->version->blocks['items'][$name],'count'=>$count]);}}$c->experience+=random_int(1,3);
        }
        return true;
    }
    private function tickAnimals():void {
        if($this->ticks%4!==0){return;}$noise=new Noise($this->world->seed);
        foreach($this->animals as $id=>&$animal){
            if(isset($animal['deadAt'])){if($this->ticks>=$animal['deadAt']){$this->removeAnimal($id);}continue;}
            $near=false;foreach($this->localPlayers() as $p){$this->showAnimal($p,$animal);if(($p->x-$animal['x'])**2+($p->z-$animal['z'])**2<80**2){$near=true;}}
            if(!$near){continue;}
            if($this->ticks>=$animal['nextWander']){$hash=$noise->hash($id,$this->ticks>>5,0,121);$animal['targetX']=$animal['x']+$hash%17-8;$animal['targetZ']=$animal['z']+intdiv($hash,19)%17-8;$animal['nextWander']=$this->ticks+80+$hash%160;}
            $dx=$animal['targetX']-$animal['x'];$dz=$animal['targetZ']-$animal['z'];$distance=sqrt($dx*$dx+$dz*$dz);if($distance<0.2){continue;}
            $nx=$animal['x']+$dx/$distance*0.12;$nz=$animal['z']+$dz/$distance*0.12;$column=$this->world->column((int)floor($nx),(int)floor($nz));$ny=$column['height']+1;
            if($column['height']<63 || abs($ny-$animal['y'])>1.1){$animal['nextWander']=$this->ticks;continue;}
            if((int)floor($nx)!==(int)floor($animal['x']) || (int)floor($nz)!==(int)floor($animal['z'])){
                $foot=$this->world->get((int)floor($nx),$ny,(int)floor($nz));$ground=$this->world->get((int)floor($nx),$ny-1,(int)floor($nz));
                if($this->version->catalog->motionBlocking($foot) || !$this->version->catalog->motionBlocking($ground)){$animal['nextWander']=$this->ticks;continue;}
            }
            $animal['x']=$nx;$animal['z']=$nz;$animal['y']=(float)$ny;$animal['yaw']=rad2deg(atan2(-$dx,$dz));
            foreach($this->localPlayers() as $p){if(isset($p->entities[$id])){$p->send($this->version,'teleport_entity',Packets::teleportEntity($id,$nx,$ny,$nz,$animal['yaw'],0,true));}}
        }unset($animal);
    }
}
