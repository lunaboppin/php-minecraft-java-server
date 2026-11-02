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
    private function changedSimulation(int $x,int $y,int $z):void {
        $this->fluidsChanged($x,$y,$z);
        $this->spongesChanged($x,$y,$z);
        $this->randomTickChanged($x,$y,$z,$this->world->get($x,$y,$z));
        $this->leavesChanged($x,$y,$z);
        $this->poweredRailsChanged($x,$y,$z);
        $this->fireChanged($x,$y,$z);
        $this->heightChanged($x,$z);
        $this->tntChanged($x,$y,$z);
        $this->bellsChanged($x,$y,$z);
        $this->tripwiresChanged($x,$y,$z);
        $this->coralsChanged($x,$y,$z);
        $this->turtleEggPlaced($x,$y,$z);
        $this->pistonsChanged($x,$y,$z);
        $this->observersChanged($x,$y,$z);
        $this->daylightDetectorChanged($x,$y,$z);
        $this->chorusChanged($x,$y,$z);
        $this->fallingChanged($x,$y,$z);
        $this->speleothemsChanged($x,$y,$z);
        $this->redstoneChanged($x,$y,$z);
    }
    private function tickBlocks():void {
        // ServerLevel.tick: the due block ticks run, then the due fluid ticks, each in the order
        // they were scheduled, up to 65536 of each per game tick.
        uasort($this->blockTicks,static fn(array $a,array $b):int=>$a[0]<=>$b[0]);
        $due=array_filter($this->blockTicks,fn(array $t):bool=>$t[0]<=$this->worldAge);
        $fluid=array_filter($due,static fn(array $t):bool=>in_array($t[1],self::FLUID_TICKS,true));
        $this->handlingBlockTicks=true;
        foreach([array_slice(array_diff_key($due,$fluid),0,65536,true),array_slice($fluid,0,65536,true)] as $batch)foreach($batch as $key=>[,$kind,$position]){
            unset($this->blockTicks[$key]);[$x,$y,$z]=$position;
            if(in_array($kind,self::FLUID_TICKS,true)){$this->tickFluid($kind,$x,$y,$z);}
            elseif($kind==='water_block' || $kind==='bubble_column'){$this->tickColumn($kind,$x,$y,$z);}
            elseif($kind==='leaves'){$this->tickLeaves($x,$y,$z);}
            elseif($kind==='fire'){$this->tickFire($x,$y,$z);}
            elseif($kind==='tripwire' || $kind==='tripwire_hook'){$this->tickTripwire($kind,$x,$y,$z);}
            elseif($kind==='coral'){$this->tickCoral($x,$y,$z);}
            elseif($kind==='observer'){$this->tickObserver($x,$y,$z);}
            elseif($kind==='plate'){$this->tickPressurePlate($x,$y,$z);}
            elseif($kind==='chorus'){$this->tickChorus($x,$y,$z);}
            elseif($kind==='falling'){$this->tickFallingBlock($x,$y,$z);}
            elseif($kind==='speleothem'){$this->tickSpeleothem($x,$y,$z);}
            elseif($kind==='cauldron'){$this->tickCauldron($x,$y,$z);}
            elseif(in_array($kind,['circuit','torch','repeater','comparator','lamp'],true)){$this->tickRedstone($kind,$x,$y,$z);}
            elseif($kind==='button'){
                $state=$this->world->get($x,$y,$z);$name=$this->version->catalog->blockName($state);
                if(str_ends_with($name,'_button')){$p=$this->version->catalog->properties($state);$p['powered']='false';$this->setBlock($x,$y,$z,$this->version->catalog->state($name,$p));}
            }
            elseif($kind==='lightning_rod'){$this->unpowerLightningRod($x,$y,$z);}
        }
        $this->handlingBlockTicks=false;
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
        if($name==='daylight_detector'){return $this->useDaylightDetector($c,$x,$y,$z);}
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
    /** Level.getBestNeighborSignal at a position, without wires' own signal when asked, leaving out one side. */
    private function powerAt(int $x,int $y,int $z,bool $wires=true,?array $exclude=null):int {
        $skip=$exclude===null?null:array_search($exclude,self::RS_DIRECTIONS,true);
        $previous=$this->wireSignals;$this->wireSignals=$previous && $wires;
        try{return $this->bestNeighborSignal([$x,$y,$z],$skip===false?null:$skip);}finally{$this->wireSignals=$previous;}
    }
    private function facing(string $name):array {return ['north'=>[0,0,-1],'east'=>[1,0,0],'south'=>[0,0,1],'west'=>[-1,0,0]][$name];}
    /** Note blocks and doors follow their neighbours' power when told of a change. */
    private function tickCircuit(int $x,int $y,int $z):void {
        $c=$this->version->catalog;$state=$this->world->get($x,$y,$z);$name=$c->blockName($state);$p=$c->properties($state);$old=$p;
        if($name==='note_block'){
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
