<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;
use PhpMinecraftJavaServer\Protocol\{Binary,Packets,Version};

trait Commands
{
    private function operator(Client $c):bool {foreach($this->config->operators as $name){if(strcasecmp($name,$c->name)===0){return true;}}return false;}
    private function tell(Client $c,string $text):void {$c->send($this->version,'system_chat',Packets::chat($text));}
    private function playerByName(string $name,Client $sender):Client {
        if($name==='@s'){$name=$sender->name;}
        foreach($this->players() as $player){if(strcasecmp($player->name,$name)===0){return $player;}}
        throw new \InvalidArgumentException('Player not found: '.$name);
    }
    private function commandNames(Client $c):array {
        $names=['help','spawn','list','version','msg','tell','me'];
        return $this->operator($c)?array_merge($names,['gamemode','give','tp','teleport','time','weather','seed','setworldspawn','spawnpoint','kill','heal','setblock','fill','gamerule','save-all','summon','dimension']):$names;
    }
    private function commands(Client $c):void {
        $names=$this->commandNames($c);$nodes=[];$children=[];
        foreach($names as $i=>$name){$children[]=1+$i*2;}
        $root="\0".Binary::writeVarInt(count($children));foreach($children as $id){$root.=Binary::writeVarInt($id);}$nodes[]=$root;
        foreach($names as $i=>$name){
            $nodes[]="\5\1".Binary::writeVarInt(2+$i*2).Binary::writeString($name);
            $nodes[]="\6\0".Binary::writeString('arguments').Binary::writeVarInt($this->version->blocks['argument_string'])."\2";
        }
        $c->send($this->version,'commands',Binary::writeVarInt(count($nodes)).implode('',$nodes)."\0");
    }
    private function coordinate(string $text,float $relative):float {
        if(str_starts_with($text,'~')){$tail=substr($text,1);if($tail!=='' && !is_numeric($tail)){throw new \InvalidArgumentException('Invalid relative coordinate');}$value=$relative+($tail===''?0:(float)$tail);}
        else{if(!is_numeric($text)){throw new \InvalidArgumentException('Invalid coordinate');}$value=(float)$text;}
        if(!is_finite($value) || abs($value)>29999900){throw new \InvalidArgumentException('Coordinate out of bounds');}return $value;
    }
    private function coordinates(array $args,Client $c):array {
        if(count($args)!==3){throw new \InvalidArgumentException('Expected x y z coordinates');}
        $result=[$this->coordinate($args[0],$c->x),$this->coordinate($args[1],$c->y),$this->coordinate($args[2],$c->z)];
        $world=$this->worlds[$c->dimension];if($result[1]<$world->minY || $result[1]>=$world->minY+$world->height){throw new \InvalidArgumentException('Y outside dimension build height');}return $result;
    }
    private function blockArgument(string $text):int {
        $text=preg_replace('/^minecraft:/','',$text);
        if(!preg_match('/^([a-z0-9_]+)(?:\[([^\]]+)\])?$/',$text,$match)){throw new \InvalidArgumentException('Invalid block state');}
        $properties=[];
        if(isset($match[2])){foreach(explode(',',$match[2]) as $pair){$parts=explode('=',$pair,2);if(count($parts)!==2){throw new \InvalidArgumentException('Invalid block property');}$properties[$parts[0]]=$parts[1];}}
        return $this->version->catalog->state($match[1],$properties);
    }
    private function command(Client $c,string $text):void {
        try{
            $parts=preg_split('/\s+/',trim($text));$name=strtolower(array_shift($parts)??'');
            if(!in_array($name,$this->commandNames($c),true)){throw new \InvalidArgumentException('Unknown command or insufficient permission. Use /help.');}
            switch($name){
                case 'help':$this->tell($c,'Commands: /'.implode(', /',$this->commandNames($c)));return;
                case 'list':$this->tell($c,'Players: '.implode(', ',array_map(static fn(Client $p):string=>$p->name,$this->players())));return;
                case 'version':$this->tell($c,'PHP Minecraft Java Server — Minecraft '.Version::NAME.' / protocol '.Version::PROTOCOL);return;
                case 'spawn':$this->teleport($c);$this->tell($c,'Returned to spawn.');return;
                case 'me':$this->broadcast('* '.$c->name.' '.implode(' ',$parts));return;
                case 'tell':case 'msg':
                    if(count($parts)<2){throw new \InvalidArgumentException('Usage: /msg player message');}$target=$this->playerByName(array_shift($parts),$c);$message=implode(' ',$parts);$this->tell($target,'['.$c->name.' -> you] '.$message);$this->tell($c,'[you -> '.$target->name.'] '.$message);return;
                case 'dimension':
                    $dimension=preg_replace('/^minecraft:/','',$parts[0]??'');
                    $this->changeDimension($c,$dimension);$this->tell($c,'Entered minecraft:'.$dimension);return;
                case 'summon':
                    $kind=preg_replace('/^minecraft:/','',array_shift($parts)??'');if(!in_array($kind,['cow','pig','sheep','chicken'],true)){throw new \InvalidArgumentException('Supported animals: cow, pig, sheep, chicken');}
                    $position=$parts===[]?[$c->x+2,$c->y,$c->z]:$this->coordinates($parts,$c);$id=$this->spawnAnimal($kind,...$position);$this->tell($c,$id===null?'Animal limit reached':'Summoned '.$kind);return;
                case 'seed':$this->tell($c,'Seed: '.$this->world->seed.' ('.$this->world->generator.')');return;
                case 'gamemode':
                    $mode=$parts[0]??'';$mode=match($mode){'survival','0'=>0,'creative','1'=>1,'adventure','2'=>2,'spectator','3'=>3,default=>throw new \InvalidArgumentException('Usage: /gamemode survival|creative|adventure|spectator [player]')};
                    $target=isset($parts[1])?$this->playerByName($parts[1],$c):$c;$this->setGameMode($target,$mode);$this->tell($target,'Game mode changed to '.['survival','creative','adventure','spectator'][$mode]);return;
                case 'give':
                    if(count($parts)<2){throw new \InvalidArgumentException('Usage: /give player item [count]');}
                    $target=$this->playerByName($parts[0],$c);$name=preg_replace('/^minecraft:/','',$parts[1]);$item=$this->version->blocks['items'][$name]??throw new \InvalidArgumentException('Unknown item');
                    $count=isset($parts[2])?filter_var($parts[2],FILTER_VALIDATE_INT):1;if($count===false || $count<1 || $count>2304){throw new \InvalidArgumentException('Count must be 1–2304');}
                    $left=$this->giveStack($target,['item'=>$item,'count'=>$count]);$this->syncInventory($target);if($left>0){$this->withDimension($target->dimension,fn()=>$this->dropStack($target->x,$target->y+0.5,$target->z,['item'=>$item,'count'=>$left]));}$this->tell($c,'Gave '.$count.' '.$name.' to '.$target->name);return;
                case 'tp':case 'teleport':
                    if(count($parts)===1){$target=$this->playerByName($parts[0],$c);$this->changeDimension($c,$target->dimension,[$target->x,$target->y,$target->z]);}
                    elseif(count($parts)===3){$this->teleport($c,$this->coordinates($parts,$c));}
                    elseif(count($parts)===4){$target=$this->playerByName(array_shift($parts),$c);$this->teleport($target,$this->coordinates($parts,$target));}
                    else{throw new \InvalidArgumentException('Usage: /tp player OR /tp [player] x y z');}return;
                case 'time':
                    if(($parts[0]??'')==='query'){$this->tell($c,'Daytime: '.($this->dayTime%24000));return;}
                    if(!in_array($parts[0]??'',['set','add'],true) || !isset($parts[1])){throw new \InvalidArgumentException('Usage: /time set day|noon|night|midnight|ticks OR /time add ticks');}
                    $value=match($parts[1]){'day'=>1000,'noon'=>6000,'night'=>13000,'midnight'=>18000,default=>filter_var($parts[1],FILTER_VALIDATE_INT)};
                    if($value===false || $value<0 || $value>2147483647){throw new \InvalidArgumentException('Invalid time');}$this->dayTime=$parts[0]==='add'?$this->dayTime+$value:$value;
                    foreach($this->players() as $p){$this->sendClock($p);}$this->tell($c,'Time set to '.$this->dayTime);return;
                case 'weather':
                    $weather=$parts[0]??'';if(!in_array($weather,['clear','rain','thunder'],true)){throw new \InvalidArgumentException('Usage: /weather clear|rain|thunder');}$this->weather=$weather;foreach($this->players() as $p){$this->sendWeather($p);}$this->tell($c,'Weather changed to '.$weather);return;
                case 'gamerule':
                    $map=['doDaylightCycle'=>'daylightCycle','keepInventory'=>'keepInventory','naturalRegeneration'=>'naturalRegeneration'];$field=$map[$parts[0]??'']??throw new \InvalidArgumentException('Supported gamerules: '.implode(', ',array_keys($map)));
                    if(!isset($parts[1])){$this->tell($c,$parts[0].' = '.($this->$field?'true':'false'));return;}
                    if(!in_array($parts[1],['true','false'],true)){throw new \InvalidArgumentException('Expected true or false');}$this->$field=$parts[1]==='true';$this->saveWorld();foreach($this->players() as $p){$this->sendClock($p);}$this->tell($c,$parts[0].' = '.$parts[1]);return;
                case 'heal':$target=isset($parts[0])?$this->playerByName($parts[0],$c):$c;if($target->dead){$this->respawnPlayer($target);}$target->health=20;$target->food=20;$target->saturation=20;$this->health($target);$this->tell($c,'Healed '.$target->name);return;
                case 'kill':$target=isset($parts[0])?$this->playerByName($parts[0],$c):$c;$this->damage($target,1000,'was killed',true);return;
                case 'setworldspawn':if($c->dimension!=='overworld'){throw new \InvalidArgumentException('World spawn requires the Overworld');}$position=$parts===[]?[$c->x,$c->y,$c->z]:$this->coordinates($parts,$c);$this->world->setting('spawn',$position);foreach($this->players() as $p){$p->send($this->version,'set_default_spawn_position',Packets::spawn($position));}$this->tell($c,'World spawn updated');return;
                case 'spawnpoint':if($c->dimension!=='overworld'){throw new \InvalidArgumentException('Personal spawn points require the Overworld');}$c->personalSpawn=$parts===[]?[$c->x,$c->y,$c->z]:$this->coordinates($parts,$c);$this->savePlayer($c);$this->tell($c,'Respawn point updated');return;
                case 'setblock':
                    if(count($parts)!==4){throw new \InvalidArgumentException('Usage: /setblock x y z block[properties]');}$position=$this->coordinates(array_slice($parts,0,3),$c);[$x,$y,$z]=array_map('intval',array_map('floor',$position));$this->setBlock($x,$y,$z,$this->blockArgument($parts[3]));$this->tell($c,'Block updated');return;
                case 'fill':
                    if(count($parts)!==7){throw new \InvalidArgumentException('Usage: /fill x1 y1 z1 x2 y2 z2 block');}
                    $a=array_map('intval',array_map('floor',$this->coordinates(array_slice($parts,0,3),$c)));$b=array_map('intval',array_map('floor',$this->coordinates(array_slice($parts,3,3),$c)));
                    $volume=(abs($a[0]-$b[0])+1)*(abs($a[1]-$b[1])+1)*(abs($a[2]-$b[2])+1);if($volume>32768){throw new \InvalidArgumentException('Fill limit is 32768 blocks');}$state=$this->blockArgument($parts[6]);$chunks=[];
                    $this->world->transaction(function()use($a,$b,$state,&$chunks){for($x=min($a[0],$b[0]);$x<=max($a[0],$b[0]);++$x){for($z=min($a[2],$b[2]);$z<=max($a[2],$b[2]);++$z){for($y=min($a[1],$b[1]);$y<=max($a[1],$b[1]);++$y){$this->world->set($x,$y,$z,$state);}$chunks[($x>>4).':'.($z>>4)]=[$x>>4,$z>>4];}}});
                    foreach($this->localPlayers() as $p){foreach($chunks as $key=>$chunk){if(isset($p->loadedChunks[$key])){$p->queuedChunks[$key]=$chunk;}}}$this->tell($c,'Filled '.$volume.' blocks');return;
                case 'save-all':$this->saveWorld();$this->tell($c,'Saved world and player data');return;
            }
        }catch(\InvalidArgumentException $e){$this->tell($c,$e->getMessage());}
    }
    private function sendClock(Client $c):void {$c->send($this->version,'set_time',Packets::clockTime($this->version,$this->worldAge,$this->dayTime,$this->daylightCycle));}
    private function sendWeather(Client $c):void {
        $rain=$c->dimension==='overworld' && $this->weather!=='clear'?1.0:0.0;$thunder=$c->dimension==='overworld' && $this->weather==='thunder'?1.0:0.0;
        $c->send($this->version,'game_event',chr($rain>0?1:2).pack('G',0));$c->send($this->version,'game_event',"\7".pack('G',$rain));$c->send($this->version,'game_event',"\x08".pack('G',$thunder));
    }
}
