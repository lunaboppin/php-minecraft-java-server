<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;
use PhpMinecraftJavaServer\Protocol\{Binary,Packets};
use PhpMinecraftJavaServer\World\Generation\Float32;
use PhpMinecraftJavaServer\World\Inventory;

/** Scheduled ticks and basic circuits; fluids are in FluidSimulation. Advanced piston/observer and shape behavior remain tracked. */
trait BlockSimulation
{
    private array $blockTicks=[];
    private array $jukeboxes=[];
    private const SIDES=[[0,0,-1],[1,0,0],[0,0,1],[-1,0,0]];
    private const NEIGHBORS=[[0,-1,0],[0,1,0],[0,0,-1],[1,0,0],[0,0,1],[-1,0,0]];
    private function restoreBlockSimulation():void {
        $this->blockTicks=$this->world->setting('blockTicks')??[];$this->jukeboxes=$this->world->setting('jukeboxes')??[];
    }
    private function saveBlockSimulation():void {
        $this->world->setting('blockTicks',$this->blockTicks);$this->world->setting('jukeboxes',$this->jukeboxes);
        foreach($this->jukeboxes as $record){$this->world->container($record['position'],$record);}
    }
    private function scheduleBlock(string $kind,int $x,int $y,int $z,int $delay=1):void {
        if($y<$this->world->minY || $y>=$this->world->minY+$this->world->height || abs($x)>29999984 || abs($z)>29999984){return;}
        $key=$kind.':'.$x.':'.$y.':'.$z;$due=$this->worldAge+$delay;
        if(!isset($this->blockTicks[$key]) || $this->blockTicks[$key][0]>$due){$this->blockTicks[$key]=[$due,$kind,[$x,$y,$z]];}
    }
    private function circuit(string $name):bool {
        return in_array($name,['redstone_wire','redstone_lamp','redstone_torch','redstone_wall_torch','repeater','comparator','note_block'],true)
            || str_ends_with($name,'_door') || str_ends_with($name,'_trapdoor');
    }
    private function changedSimulation(int $x,int $y,int $z):void {
        $this->fluidsChanged($x,$y,$z);
        $catalog=$this->version->catalog;
        foreach(array_merge([[0,0,0]],self::NEIGHBORS) as [$dx,$dy,$dz]){
            $a=$x+$dx;$b=$y+$dy;$c=$z+$dz;$name=$catalog->blockName($this->world->get($a,$b,$c));
            if($this->circuit($name)){$this->scheduleBlock('circuit',$a,$b,$c);}
            // Power can pass through one opaque block to a device on its far side.
            if(!$catalog->blocks[$name]['full_cube']){continue;}
            foreach(self::SIDES as [$ex,$ey,$ez]){$other=$catalog->blockName($this->world->get($a+$ex,$b,$c+$ez));if($this->circuit($other)){$this->scheduleBlock('circuit',$a+$ex,$b,$c+$ez);}}
        }
    }
    private function tickBlocks():void {
        // ServerLevel.tick: the due block ticks run, then the due fluid ticks, each in the order
        // they were scheduled, up to 65536 of each per game tick.
        uasort($this->blockTicks,static fn(array $a,array $b):int=>$a[0]<=>$b[0]);
        $due=array_filter($this->blockTicks,fn(array $t):bool=>$t[0]<=$this->worldAge);
        $fluid=array_filter($due,static fn(array $t):bool=>in_array($t[1],self::FLUID_TICKS,true));
        foreach([array_slice(array_diff_key($due,$fluid),0,65536,true),array_slice($fluid,0,65536,true)] as $batch)foreach($batch as $key=>[,$kind,$position]){
            unset($this->blockTicks[$key]);[$x,$y,$z]=$position;
            if(in_array($kind,self::FLUID_TICKS,true)){$this->tickFluid($kind,$x,$y,$z);}
            elseif($kind==='circuit'){$this->tickCircuit($x,$y,$z);}
            elseif($kind==='button'){
                $state=$this->world->get($x,$y,$z);$name=$this->version->catalog->blockName($state);
                if(str_ends_with($name,'_button')){$p=$this->version->catalog->properties($state);$p['powered']='false';$this->setBlock($x,$y,$z,$this->version->catalog->state($name,$p));}
            }elseif($kind==='repeater'){$this->tickCircuit($x,$y,$z,true);}
        }
        foreach($this->jukeboxes as $key=>&$record){
            if($record['remaining']>0 && --$record['remaining']===0){$this->levelEvent(1011,$record['position'],0);$this->world->container($record['position'],$record);}
        }unset($record);
    }
    private function levelEvent(int $event,array $position,int $data):void {
        $payload=pack('N',$event).Binary::writePosition(...$position).pack('N',$data)."\0";
        foreach($this->localPlayers() as $p){if(isset($p->loadedChunks[($position[0]>>4).':'.($position[2]>>4)])){$p->send($this->version,'level_event',$payload);}}
    }
    private function ejectJukebox(array $position,bool $update=true):void {
        $key=implode(':',$position);$record=$this->jukeboxes[$key]??$this->world->container($position);
        if(isset($record['slots'][0])){$stack=$record['slots'][0];$this->levelEvent(1011,$position,0);$this->dropStack($position[0]+.5+(self::randomUnit()-.5)*Float32::round(.7),$position[1]+1.01,$position[2]+.5+(self::randomUnit()-.5)*Float32::round(.7),$stack,0,null,10);}
        unset($this->jukeboxes[$key]);$this->world->deleteContainer($position);
        if($update){$this->setBlock(...array_merge($position,[$this->version->catalog->state('jukebox',['has_record'=>'false'])]));}
    }
    private function useSpecialBlock(Client $c,int $x,int $y,int $z,int $slot):bool {
        if(!$this->editable($c,$x,$y,$z)){return false;}$catalog=$this->version->catalog;$state=$this->world->get($x,$y,$z);$name=$catalog->blockName($state);$props=$catalog->properties($state);
        if(str_ends_with($name,'_sign')){return $this->openSign($c,$x,$y,$z,$this->signSide($c,$x,$y,$z));}
        if($name==='jukebox'){
            $position=[$x,$y,$z];$data=$this->world->container($position);
            if(isset($data['slots'][0])){$this->ejectJukebox($position);return true;}
            $stack=$c->inventory[$slot]??null;$song=$stack===null?null:($catalog->simulation['songs'][$catalog->itemName($stack['item'])]??null);
            if($song===null){return false;}
            $stack['count']=1;$record=['position'=>$position,'slots'=>[$stack],'remaining'=>$song['ticks'],'song'=>$song['song']];
            $this->world->container($position,$record);$this->jukeboxes[implode(':',$position)]=$record;
            $this->setBlock($x,$y,$z,$catalog->state('jukebox',['has_record'=>'true']));
            if($c->gameMode===0){Inventory::consume($c->inventory,$slot);$this->syncInventory($c);}
            $this->levelEvent(1010,$position,$this->version->registryId('jukebox_song',$song['song']));return true;
        }
        if($name==='note_block'){$props['note']=(string)(((int)$props['note']+1)%25);$this->setBlock($x,$y,$z,$catalog->state($name,$props));$this->playNote($x,$y,$z);return true;}
        if($name==='lever' || str_ends_with($name,'_button')){
            $props['powered']=$name==='lever' && $props['powered']==='true'?'false':'true';$this->setBlock($x,$y,$z,$catalog->state($name,$props));
            if($name!=='lever'){$this->scheduleBlock('button',$x,$y,$z,str_starts_with($name,'stone_') || $name==='polished_blackstone_button'?20:30);}return true;
        }
        if($name==='repeater'){$props['delay']=(string)((int)$props['delay']%4+1);$this->setBlock($x,$y,$z,$catalog->state($name,$props));return true;}
        if($name==='comparator'){$props['mode']=$props['mode']==='compare'?'subtract':'compare';$this->setBlock($x,$y,$z,$catalog->state($name,$props));return true;}
        return false;
    }
    private function noteInstrument(int $x,int $y,int $z):string {
        $c=$this->version->catalog;$map=$c->simulation['block_instruments'];
        $above=$map[$c->blockName($this->world->get($x,$y+1,$z))]??'harp';
        if($c->simulation['instruments'][$above]['above']){return $above;}
        $below=$map[$c->blockName($this->world->get($x,$y-1,$z))]??'harp';return $c->simulation['instruments'][$below]['above']?'harp':$below;
    }
    private function playNote(int $x,int $y,int $z):void {
        $c=$this->version->catalog;$state=$this->world->get($x,$y,$z);if($c->blockName($state)!=='note_block'){return;}
        $p=$c->properties($state);$instrument=$this->noteInstrument($x,$y,$z);$info=$c->simulation['instruments'][$instrument];
        if(!$info['above'] && !$c->air($this->world->get($x,$y+1,$z))){return;}if($info['custom']){return;}
        if($p['instrument']!==$instrument){$p['instrument']=$instrument;$this->setBlock($x,$y,$z,$c->state('note_block',$p));}
        $event=Binary::writePosition($x,$y,$z)."\0\0".Binary::writeVarInt($c->simulation['note_block_id']);
        $pitch=$info['tunable']?2**(((int)$p['note']-12)/12):1;
        $sound=Binary::writeVarInt($info['sound']+1).Binary::writeVarInt(2).pack('NNNGG',($x*8+4)&0xffffffff,($y*8+4)&0xffffffff,($z*8+4)&0xffffffff,3,$pitch).random_bytes(8);
        foreach($this->localPlayers() as $player){if(($player->x-$x)**2+($player->y-$y)**2+($player->z-$z)**2<=64**2){$player->send($this->version,'block_event',$event);$player->send($this->version,'sound',$sound);}}
    }
    private function directSignal(int $x,int $y,int $z,array $target,bool $wires=true):int {
        $c=$this->version->catalog;$state=$this->world->get($x,$y,$z);$name=$c->blockName($state);$p=$c->properties($state);
        if($name==='redstone_block'){return 15;}
        if($name==='trapped_chest'){return $this->trappedChestSignal([$x,$y,$z]);}
        if(($name==='lever' || str_ends_with($name,'_button')) && ($p['powered']??'false')==='true'){return 15;}
        if($name==='redstone_wire'){return $wires?(int)$p['power']:0;}
        if(in_array($name,['redstone_torch','redstone_wall_torch'],true) && $p['lit']==='true'){
            $support=$name==='redstone_torch'?[$x,$y-1,$z]:[$x-$this->facing($p['facing'])[0],$y,$z-$this->facing($p['facing'])[2]];
            return $target===$support?0:15;
        }
        if(in_array($name,['repeater','comparator'],true) && $p['powered']==='true'){
            [$dx,,$dz]=$this->facing($p['facing']);if($target!==[$x-$dx,$y,$z-$dz]){return 0;}
            if($name==='repeater'){return 15;}
            return $this->comparatorOutput($x,$y,$z,$p);
        }
        return 0;
    }
    private function powerAt(int $x,int $y,int $z,bool $wires=true):int {
        $power=0;$c=$this->version->catalog;
        foreach(self::NEIGHBORS as [$dx,$dy,$dz]){
            $a=$x+$dx;$b=$y+$dy;$d=$z+$dz;$power=max($power,$this->directSignal($a,$b,$d,[$x,$y,$z],$wires));
            $state=$this->world->get($a,$b,$d);$name=$c->blockName($state);
            if($c->blocks[$name]['full_cube'] && !$this->circuit($name)){
                foreach(self::NEIGHBORS as [$ex,$ey,$ez]){
                    if([$a+$ex,$b+$ey,$d+$ez]===[$x,$y,$z]){continue;}
                    $source=$c->blockName($this->world->get($a+$ex,$b+$ey,$d+$ez));
                    if(in_array($source,['redstone_block','lever','repeater','comparator'],true) || str_ends_with($source,'_button')){$power=max($power,$this->directSignal($a+$ex,$b+$ey,$d+$ez,[$a,$b,$d],false));}
                }
            }
        }
        return $power;
    }
    private function facing(string $name):array {return ['north'=>[0,0,-1],'east'=>[1,0,0],'south'=>[0,0,1],'west'=>[-1,0,0]][$name];}
    private function comparatorOutput(int $x,int $y,int $z,array $p):int {
        [$dx,,$dz]=$this->facing($p['facing']);$rear=$this->directSignal($x+$dx,$y,$z+$dz,[$x,$y,$z]);
        $c=$this->version->catalog;
        if($c->blockName($this->world->get($x+$dx,$y,$z+$dz))==='jukebox'){
            $stack=$this->world->container([$x+$dx,$y,$z+$dz])['slots'][0]??null;
            $rear=$stack===null?0:($c->simulation['songs'][$c->itemName($stack['item'])]['comparator']??0);
        }
        $side=max($this->directSignal($x+$dz,$y,$z-$dx,[$x,$y,$z]),$this->directSignal($x-$dz,$y,$z+$dx,[$x,$y,$z]));
        return $p['mode']==='subtract'?max(0,$rear-$side):($rear>=$side?$rear:0);
    }
    private function tickCircuit(int $x,int $y,int $z,bool $delayed=false):void {
        $c=$this->version->catalog;$state=$this->world->get($x,$y,$z);$name=$c->blockName($state);$p=$c->properties($state);$old=$p;
        if($name==='redstone_wire'){
            $power=$this->powerAt($x,$y,$z,false);$connected=[];
            foreach(array_combine(['north','east','south','west'],self::SIDES) as $direction=>[$dx,$dy,$dz]){
                $other=$this->world->get($x+$dx,$y,$z+$dz);$kind=$c->blockName($other);
                if($kind==='redstone_wire'){$power=max($power,(int)$c->properties($other)['power']-1);}
                $connected[$direction]=$this->circuit($kind) || in_array($kind,['lever','redstone_block'],true) || str_ends_with($kind,'_button');
            }
            if(!in_array(true,$connected,true)){$connected=array_fill_keys(array_keys($connected),true);}
            if(!$connected['north'] && !$connected['south']){$connected['east']=$connected['west']=true;}
            if(!$connected['east'] && !$connected['west']){$connected['north']=$connected['south']=true;}
            foreach($connected as $direction=>$yes){$p[$direction]=$yes?'side':'none';}$p['power']=(string)max(0,$power);
        }elseif(in_array($name,['redstone_torch','redstone_wall_torch'],true)){
            $support=$name==='redstone_torch'?[$x,$y-1,$z]:[$x-$this->facing($p['facing'])[0],$y,$z-$this->facing($p['facing'])[2]];
            $p['lit']=$this->powerAt(...$support)>0?'false':'true';
        }elseif($name==='repeater' || $name==='comparator'){
            [$dx,,$dz]=$this->facing($p['facing']);$power=$name==='repeater'?$this->directSignal($x+$dx,$y,$z+$dz,[$x,$y,$z]):$this->comparatorOutput($x,$y,$z,$p);
            if($name==='repeater'){
                $locked=max($this->directSignal($x+$dz,$y,$z-$dx,[$x,$y,$z]),$this->directSignal($x-$dz,$y,$z+$dx,[$x,$y,$z]))>0;
                $p['locked']=$locked?'true':'false';if(!$locked){$p['powered']=$power>0?'true':'false';}
            }else{$p['powered']=$power>0?'true':'false';}
            if(!$delayed && $p['powered']!==$old['powered']){$this->scheduleBlock('repeater',$x,$y,$z,$name==='repeater'?2*(int)$p['delay']:2);$p['powered']=$old['powered'];}
        }elseif($name==='redstone_lamp'){
            $powered=$this->powerAt($x,$y,$z)>0;
            if(!$powered && $p['lit']==='true' && !$delayed){$this->scheduleBlock('repeater',$x,$y,$z,4);return;}$p['lit']=$powered?'true':'false';
        }elseif($name==='note_block'){
            $p['instrument']=$this->noteInstrument($x,$y,$z);$p['powered']=$this->powerAt($x,$y,$z)>0?'true':'false';
            if($p['powered']==='true' && $old['powered']==='false'){$this->playNote($x,$y,$z);}
        }elseif(str_ends_with($name,'_door') || str_ends_with($name,'_trapdoor')){
            $power=$this->powerAt($x,$y,$z);
            if(str_ends_with($name,'_door')){$power=max($power,$this->powerAt($x,$y+($p['half']==='lower'?1:-1),$z));}
            $p['powered']=$power>0?'true':'false';if($p['powered']!==$old['powered']){$p['open']=$p['powered'];}
        }
        if($p!==$old){$this->setBlock($x,$y,$z,$c->state($name,$p));}
    }
}
