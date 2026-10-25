<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;
use PhpMinecraftJavaServer\Protocol\{Binary,Packets,Nbt};
use PhpMinecraftJavaServer\World\Inventory;

trait Survival
{
    private int $dayTime=6000;
    private int $worldAge=0;
    private bool $daylightCycle=true;
    private bool $keepInventory=false;
    private bool $naturalRegeneration=true;
    private bool $spawnMobs=true;
    private string $weather='clear';
    private function restorePlayer(Client $c):void {
        $c->gameMode=$this->config->defaultGameMode;[$c->x,$c->y,$c->z]=$this->world->spawn();
        $data=$this->worlds['overworld']->loadPlayer($c->uuid);if($data===null){return;}
        $c->dimension=in_array($data['dimension']??'overworld',array_keys($this->worlds),true)?($data['dimension']??'overworld'):'overworld';
        $c->gameMode=max(0,min(3,(int)($data['gameMode']??$c->gameMode)));
        foreach(['x','y','z','yaw','pitch'] as $field){if(isset($data[$field]) && is_numeric($data[$field]) && is_finite((float)$data[$field])){$c->$field=(float)$data[$field];}}
        if(abs($c->x)>29999900 || abs($c->z)>29999900 || $c->y<$this->worlds[$c->dimension]->minY || $c->y>=$this->worlds[$c->dimension]->minY+$this->worlds[$c->dimension]->height){[$c->x,$c->y,$c->z]=$this->worlds[$c->dimension]->spawn();}
        $c->health=max(0,min(20,(float)($data['health']??20)));$c->food=max(0,min(20,(int)($data['food']??20)));$c->saturation=max(0,min($c->food,(float)($data['saturation']??5)));
        $c->experience=max(0,(int)($data['experience']??0));$c->heldSlot=max(0,min(8,(int)($data['heldSlot']??0)));$c->dead=(bool)($data['dead']??false);
        $c->personalSpawn=$data['personalSpawn']??null;
        // Persist a duration, since the process tick counter restarts from zero.
        $c->portalCooldown=$this->ticks+max(0,min(300,(int)($data['portalCooldown']??0)));
        foreach($data['inventory']??[] as $slot=>$stack){$item=$this->version->catalog->item((int)($stack['item']??-1));if($slot>=1 && $slot<=45 && $item!==null && ($stack['count']??0)>0){$c->inventory[(int)$slot]=['item'=>(int)$stack['item'],'count'=>min($item['max_stack'],(int)$stack['count']),'damage'=>max(0,min($item['durability'],(int)($stack['damage']??0)))];}}
    }
    private function savePlayer(Client $c):void {
        if($c->uuid==='' || $c->entityId===0){return;}
        $this->worlds['overworld']->savePlayer($c->uuid,['dimension'=>$c->dimension,'gameMode'=>$c->gameMode,'x'=>$c->x,'y'=>$c->y,'z'=>$c->z,'yaw'=>$c->yaw,'pitch'=>$c->pitch,'health'=>$c->health,'food'=>$c->food,'saturation'=>$c->saturation,'experience'=>$c->experience,'inventory'=>$c->inventory,'heldSlot'=>$c->heldSlot,'dead'=>$c->dead,'personalSpawn'=>$c->personalSpawn,'portalCooldown'=>max(0,$c->portalCooldown-$this->ticks)]);
    }
    private function saveWorld():void {
        foreach($this->players() as $player){$this->savePlayer($player);}
        foreach(['worldAge','dayTime','daylightCycle','weather','keepInventory','naturalRegeneration','spawnMobs'] as $key){$this->worlds['overworld']->setting($key,$this->$key);}
        foreach(array_keys($this->worlds) as $dimension){$this->withDimension($dimension,function():void{
            $this->saveBlockSimulation();$this->world->setting('animals',$this->animals);$this->world->setting('naturalSpawns',$this->naturalSpawns);$this->world->checkpoint();
        });}
    }
    private function health(Client $c):void {
        if($c->dimension!==$this->world->dimension){$this->withDimension($c->dimension,fn()=>$this->health($c));return;}
        $c->send($this->version,'set_health',pack('G',$c->health).Binary::writeVarInt($c->food).pack('G',$c->saturation));
        $this->syncPlayerMetadata($c);
        $remaining=$c->experience;$level=0;
        while(true){$needed=$level<15?2*$level+7:($level<30?5*$level-38:9*$level-158);if($remaining<$needed){break;}$remaining-=$needed;++$level;}
        $c->send($this->version,'set_experience',pack('G',$remaining/$needed).Binary::writeVarInt($level).Binary::writeVarInt($c->experience));
    }
    private function abilities(Client $c):void {
        $flags=match($c->gameMode){1=>0x0d,3=>0x07,default=>0};
        if($c->gameMode===3 || ($c->gameMode===1 && $c->flying)){$flags|=2;}
        $c->send($this->version,'player_abilities',chr($flags).pack('GG',0.05,0.1));
    }
    private function setGameMode(Client $c,int $mode):void {
        if($c->dimension!==$this->world->dimension){$this->withDimension($c->dimension,fn()=>$this->setGameMode($c,$mode));return;}
        $c->gameMode=$mode;$c->flying=$mode===3;$c->fallDistance=0;$c->mining=null;$this->abilities($c);
        $c->send($this->version,'game_event',"\3".pack('G',$mode));
        foreach($this->players() as $p){$p->send($this->version,'player_info_update',"\4\1".$c->uuid.Binary::writeVarInt($mode));$this->refreshTracking($p);}
        $this->syncPlayerMetadata($c);
        $this->savePlayer($c);
    }
    private function damage(Client $c,float $amount,string $reason,bool $force=false):void {
        if($c->dimension!==$this->world->dimension){$this->withDimension($c->dimension,fn()=>$this->damage($c,$amount,$reason,$force));return;}
        if($c->dead || (!$force && (in_array($c->gameMode,[1,3],true) || $this->ticks-$c->lastDamageTick<10))){return;}
        $c->lastDamageTick=$this->ticks;$c->health=max(0,$c->health-$amount);$this->health($c);
        foreach($this->localPlayers() as $p){if($p===$c || isset($p->entities[$c->entityId])){$p->send($this->version,'entity_event',pack('N',$c->entityId).chr($c->health<=0?3:2));}}
        if($c->health<=0){
            $c->dead=true;$c->mining=null;$c->eating=null;$this->closeWindow($c,true);
            if(!$this->keepInventory){foreach($c->inventory as $slot=>$stack){$this->dropStack($c->x,$c->y+0.5,$c->z,$stack);}$c->inventory=[];$c->experience=0;}
            $message=$c->name.' '.$reason;$c->send($this->version,'player_combat_kill',Binary::writeVarInt($c->entityId).Nbt::text($message));$this->broadcast($message);$this->savePlayer($c);
        }
    }
    private function respawnPlayer(Client $c):void {
        if($c->dimension!==$this->world->dimension){$this->withDimension($c->dimension,fn()=>$this->respawnPlayer($c));return;}
        if(!$c->dead){return;}$c->dead=false;$c->health=20;$c->food=20;$c->saturation=5;$c->fallDistance=0;$c->fireTicks=0;$c->air=300;
        $this->changeDimension($c,'overworld',$c->personalSpawn??$this->worlds['overworld']->spawn(),true);
    }
    private function miningTicks(Client $c,int $state):int {
        $name=$this->version->catalog->blockName($state);$info=$this->version->catalog->blocks[$name];
        if($info['hardness']<0){return PHP_INT_MAX;}
        $held=$c->inventory[36+$c->heldSlot]['item']??-1;$tool=$this->version->catalog->itemName($held)??'';
        $correct=$this->correctTool($tool,$name);
        $speed=1;
        if($correct && str_contains($tool,'_')){$material=explode('_',$tool)[0];$speed=match($material){'wooden'=>2,'stone'=>4,'iron'=>6,'golden'=>12,'diamond'=>8,'netherite'=>9,default=>1};}
        return max(1,(int)ceil($info['hardness']*($info['requires_tool'] && !$correct?100:30)/$speed));
    }
    private function correctTool(string $tool,string $block):bool {
        if(str_contains($block,'obsidian') || $block==='ancient_debris'){return in_array($tool,['diamond_pickaxe','netherite_pickaxe'],true);}
        if(str_contains($block,'diamond_ore') || str_contains($block,'gold_ore') || str_contains($block,'redstone_ore') || str_contains($block,'emerald_ore')){return in_array($tool,['iron_pickaxe','diamond_pickaxe','netherite_pickaxe'],true);}
        if(str_contains($block,'iron_ore') || str_contains($block,'copper_ore') || str_contains($block,'lapis_ore')){return str_ends_with($tool,'_pickaxe') && !str_starts_with($tool,'wooden_') && !str_starts_with($tool,'golden_');}
        $required=$this->version->catalog->blocks[$block]['requires_tool'];
        if($required){return str_ends_with($tool,'_pickaxe');}
        if(str_ends_with($block,'_log') || str_ends_with($block,'_planks') || str_contains($block,'wood') || in_array($block,['chest','crafting_table'],true)){return str_ends_with($tool,'_axe');}
        if(in_array($block,['dirt','grass_block','sand','gravel','snow'],true)){return str_ends_with($tool,'_shovel');}
        return false;
    }
    private function breakBlock(Client $c,int $x,int $y,int $z):void {
        $state=$this->world->get($x,$y,$z);$name=$this->version->catalog->blockName($state);if($this->version->catalog->air($state)){return;}
        $containerData=in_array($name,['chest','barrel','furnace'],true)?$this->world->container([$x,$y,$z]):null;
        $properties=$this->version->catalog->properties($state);
        $this->setBlock($x,$y,$z,0);
        if(in_array($name,['obsidian','nether_portal'],true)){$this->invalidatePortal($x,$y,$z);}
        if(str_ends_with($name,'_door')){$otherY=$y+(($properties['half']??'lower')==='lower'?1:-1);if($this->version->catalog->blockName($this->world->get($x,$otherY,$z))===$name){$this->setBlock($x,$otherY,$z,0);}}
        if(str_ends_with($name,'_bed')){$offset=['north'=>[0,-1],'south'=>[0,1],'west'=>[-1,0],'east'=>[1,0]][$properties['facing']];$sign=($properties['part']??'foot')==='foot'?1:-1;$bx=$x+$offset[0]*$sign;$bz=$z+$offset[1]*$sign;if($this->version->catalog->blockName($this->world->get($bx,$y,$bz))===$name){$this->setBlock($bx,$y,$bz,0);}}
        if(in_array($name,['chest','barrel','furnace'],true)){
            foreach($this->localPlayers() as $viewer){if($viewer->windowPosition===[$x,$y,$z]){$this->closeWindow($viewer,true);$this->syncInventory($viewer);}}
            $data=$containerData;foreach($data['slots'] as $stack){$this->dropStack($x+0.5,$y+0.5,$z+0.5,$stack);}$this->world->deleteContainer([$x,$y,$z]);
        }
        if($c->gameMode!==0){return;}
        $tool=$this->version->catalog->itemName($c->inventory[36+$c->heldSlot]['item']??-1)??'';
        $required=$this->version->catalog->blocks[$name]['requires_tool'];
        if(!$required || $this->correctTool($tool,$name)){
            $drop=match($name){'grass_block'=>'dirt','stone'=>'cobblestone','deepslate'=>'cobbled_deepslate',default=>$name};$count=1;
            if(str_contains($name,'ore')){
                foreach(['coal'=>'coal','iron'=>'raw_iron','copper'=>'raw_copper','gold'=>'raw_gold','diamond'=>'diamond','emerald'=>'emerald','redstone'=>'redstone','lapis'=>'lapis_lazuli'] as $ore=>$item){if(str_contains($name,$ore.'_ore')){$drop=$item;$count=in_array($ore,['redstone','lapis','copper'],true)?random_int(2,5):1;if(in_array($ore,['coal','diamond','emerald','redstone','lapis'],true)){$c->experience+=random_int(1,4);}break;}}
            }
            if($name==='nether_quartz_ore'){$drop='quartz';$c->experience+=random_int(2,5);}
            if($name==='nether_gold_ore'){$drop='gold_nugget';$count=random_int(2,6);}
            if(str_ends_with($name,'_leaves')){$drop=random_int(1,20)===1?str_replace('_leaves','_sapling',$name):'';}
            if(str_contains($name,'glass') || in_array($name,['short_grass','snow','ice'],true)){$drop='';}
            $item=$this->version->blocks['items'][$drop]??null;if($item!==null){$this->dropStack($x+0.5,$y+0.5,$z+0.5,['item'=>$item,'count'=>$count]);}
        }
        $slot=36+$c->heldSlot;$held=$c->inventory[$slot]??null;
        if($held!==null && ($max=$this->version->catalog->item($held['item'])['durability'])>0){$held['damage']=($held['damage']??0)+1;if($held['damage']>=$max){unset($c->inventory[$slot]);}else{$c->inventory[$slot]=$held;}$this->syncInventory($c);}
        $c->exhaustion+=0.005;
    }
    private function survivalMovement(Client $c,float $oldY,float $distance):void {
        if($c->gameMode!==0 || $c->dead){return;}$c->exhaustion+=$distance*($c->sprinting?0.1:0.01);
        $feet=$this->world->get((int)floor($c->x),(int)floor($c->y),(int)floor($c->z));
        if($this->version->catalog->fluid($feet)){$c->fallDistance=0;}
        else{if($c->y<$oldY){$c->fallDistance+=$oldY-$c->y;}if($c->ground){if($c->fallDistance>3){$this->damage($c,floor($c->fallDistance-3),'hit the ground too hard');}$c->fallDistance=0;}}
    }
    private function tickSurvival(Client $c):void {
        if($c->dead){return;}
        if(!$this->validWindow($c)){$this->closeWindow($c,true);$this->syncInventory($c);}
        if($c->mining!==null){
            $mine=$c->mining;[$x,$y,$z]=$mine['position'];
            if(!$this->editable($c,$x,$y,$z) || $this->world->get($x,$y,$z)!==$mine['state']){$c->mining=null;}
            elseif($this->ticks-$mine['started']>=$mine['duration']){$this->breakBlock($c,$x,$y,$z);$c->mining=null;}
        }
        if($c->eating!==null && $this->ticks>=$c->eating['complete']){
            $slot=$c->eating['slot'];$stack=$c->inventory[$slot]??null;
            if($stack!==null && $stack['item']===$c->eating['item']){$food=$this->version->catalog->item($stack['item']);$c->food=min(20,$c->food+$food['nutrition']);$c->saturation=min($c->food,$c->saturation+$food['saturation']);if($c->gameMode===0){Inventory::consume($c->inventory,$slot);}$this->health($c);$this->syncInventory($c);$c->send($this->version,'entity_event',pack('N',$c->entityId)."\x09");}$c->eating=null;
        }
        if($c->gameMode!==0 || $this->ticks%20!==0){return;}
        if($c->y<$this->world->minY-16){$this->damage($c,4,'fell out of the world');return;}
        $head=$this->world->get((int)floor($c->x),(int)floor($c->y+1.62),(int)floor($c->z));$feet=$this->world->get((int)floor($c->x),(int)floor($c->y),(int)floor($c->z));
        $water=str_contains($this->version->catalog->blockName($head),'water');
        if($water){$c->air-=20;if($c->air<0){$this->damage($c,2,'drowned');}}else{$c->air=min(300,$c->air+80);}
        if($this->version->catalog->blockName($feet)==='lava'){$c->fireTicks=160;$this->damage($c,4,'tried to swim in lava');}
        elseif($this->version->catalog->blockName($feet)==='water'){$c->fireTicks=0;}
        elseif($c->fireTicks>0){$c->fireTicks-=20;$this->damage($c,1,'burned to death');}
        while($c->exhaustion>=4){$c->exhaustion-=4;if($c->saturation>0){$c->saturation=max(0,$c->saturation-1);}elseif($this->config->difficulty>0){$c->food=max(0,$c->food-1);}}
        if($this->config->difficulty===0){$c->food=20;if($c->health<20){$c->health=min(20,$c->health+1);}}
        elseif($this->ticks%80===0){if($this->naturalRegeneration && $c->food>=18 && $c->health<20){$c->health=min(20,$c->health+1);$c->exhaustion+=6;}elseif($c->food===0 && ($this->config->difficulty===3 || $c->health>($this->config->difficulty===1?10:1))){$this->damage($c,1,'starved to death');}}
        $this->health($c);
    }
    private function useHeldItem(Client $c,int $hand):void {
        if($c->dead || $c->gameMode===3){return;}$slot=$hand===0?36+$c->heldSlot:45;$stack=$c->inventory[$slot]??null;if($stack===null){return;}
        if($this->useSpawnEggInAir($c,$slot)){return;}
        $info=$this->version->catalog->item($stack['item']);
        if(isset($info['nutrition']) && $c->food<20){$c->eating=['slot'=>$slot,'item'=>$stack['item'],'complete'=>$this->ticks+32];return;}
        if($this->version->catalog->itemName($stack['item'])==='bucket'){
            $yaw=deg2rad($c->yaw);$pitch=deg2rad($c->pitch);$direction=[-sin($yaw)*cos($pitch),-sin($pitch),cos($yaw)*cos($pitch)];
            for($step=0;$step<=50;++$step){
                $distance=$step/10;$x=(int)floor($c->x+$direction[0]*$distance);$y=(int)floor($c->y+1.62+$direction[1]*$distance);$z=(int)floor($c->z+$direction[2]*$distance);$state=$this->world->get($x,$y,$z);$name=$this->version->catalog->blockName($state);
                if(in_array($name,['water','lava'],true)){$this->setBlock($x,$y,$z,0);if($c->gameMode!==1){Inventory::consume($c->inventory,$slot);$this->giveStack($c,['item'=>$this->version->blocks['items'][$name.'_bucket'],'count'=>1]);}$this->syncInventory($c);return;}
                if(!$this->version->catalog->air($state) && !$this->version->catalog->blocks[$name]['replaceable']){break;}
            }
        }
    }
    private function attackEntity(Client $attacker,int $id):void {
        if($attacker->dead || $attacker->gameMode===3 || $this->ticks-$attacker->lastAttackTick<10){return;}
        if($this->attackAnimal($attacker,$id)){return;}
        foreach($this->localPlayers() as $target){
            if($target->entityId!==$id || $target===$attacker || !$this->config->pvp){continue;}
            if(($target->x-$attacker->x)**2+($target->y-$attacker->y)**2+($target->z-$attacker->z)**2>4.5**2){return;}
            $attacker->lastAttackTick=$this->ticks;$tool=$this->version->catalog->itemName($attacker->inventory[36+$attacker->heldSlot]['item']??-1)??'';$damage=1;
            if(str_ends_with($tool,'_sword') || str_ends_with($tool,'_axe')){$material=explode('_',$tool)[0];$damage=match($material){'wooden','golden'=>4,'stone'=>5,'iron'=>6,'diamond'=>7,'netherite'=>8,default=>1};}
            $this->damage($target,$damage,'was slain by '.$attacker->name);$attacker->exhaustion+=0.1;return;
        }
    }

}
