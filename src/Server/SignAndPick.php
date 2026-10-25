<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;
use PhpMinecraftJavaServer\Protocol\{Binary,ProtocolException};

trait SignAndPick
{
    private function openSign(Client $c,int $x,int $y,int $z,int $side):bool {
        if(!$this->editable($c,$x,$y,$z) || !str_ends_with($this->version->catalog->blockName($this->world->get($x,$y,$z)),'_sign')){return false;}
        foreach($this->players() as $other){$edit=$other->editingSign;if($other!==$c && $edit!==null && $edit['position']===[$x,$y,$z] && $edit['dimension']===$c->dimension && $edit['expires']>$this->worldAge){return false;}}
        $c->editingSign=['position'=>[$x,$y,$z],'dimension'=>$c->dimension,'side'=>$side,'expires'=>$this->worldAge+1200];
        $this->blockUpdate($c,$x,$y,$z);$c->send($this->version,'open_sign_editor',Binary::writePosition($x,$y,$z).Binary::writeVarInt($side));return true;
    }
    private function updateSign(Client $c,Binary $r):void {
        $position=$r->position();$lines=[];for($i=0;$i<4;++$i){$line=$r->string(384);$lines[]=preg_replace('/[\x00-\x1f\x7f\x{00a7}]/u','',$line);}
        $slot=$r->varInt();$r->end();$slot=$slot===1?1:0;$edit=$c->editingSign;
        if($edit===null || $edit['position']!==$position || $edit['dimension']!==$c->dimension || $edit['side']!==$slot || $edit['expires']<$this->worldAge || !$this->editable($c,...$position)){return;}
        if(!str_ends_with($this->version->catalog->blockName($this->world->get(...$position)),'_sign')){$c->editingSign=null;return;}
        $data=$this->world->container($position);$data[$slot===1?'front':'back']=$lines;$this->world->container($position,$data);$c->editingSign=null;
        foreach($this->localPlayers() as $player){if(isset($player->loadedChunks[($position[0]>>4).':'.($position[2]>>4)])){$this->blockUpdate($player,...$position);}}
    }
    private function signSide(Client $c,int $x,int $y,int $z):int {
        $p=$this->version->catalog->properties($this->world->get($x,$y,$z));
        $angle=isset($p['rotation'])?(int)$p['rotation']*22.5:match($p['facing']??'south'){'north'=>180,'east'=>270,'west'=>90,default=>0};
        $facing=deg2rad($angle);return ($c->x-$x-.5)*-sin($facing)+($c->z-$z-.5)*cos($facing)>=0?1:0;
    }
    private function pickBlock(Client $c,Binary $r):void {
        [$x,$y,$z]=$r->position();$includeData=$r->byte()!==0;$r->end();
        if($c->dead || !in_array($c->gameMode,[0,1],true) || $c->teleport!==null || !isset($c->loadedChunks[($x>>4).':'.($z>>4)]) || ($x+.5-$c->x)**2+($y+.5-$c->y-1.62)**2+($z+.5-$c->z)**2>64){return;}
        $catalog=$this->version->catalog;$name=$catalog->blockName($this->world->get($x,$y,$z));$item=$catalog->simulation['pick_items'][$name]??0;if($item===0){return;}
        $found=null;foreach(array_merge(range(36,44),range(9,35)) as $slot){$stack=$c->inventory[$slot]??null;if(($stack['item']??0)===$item && ($stack['damage']??0)===0){$found=$slot;break;}}
        if($found!==null && $found>=36){$c->heldSlot=$found-36;}
        elseif($found!==null || $c->gameMode===1){
            $target=$c->heldSlot;for($i=0;$i<9;++$i){$candidate=($c->heldSlot+$i)%9;if(!isset($c->inventory[36+$candidate])){$target=$candidate;break;}}
            $slot=36+$target;$previous=$c->inventory[$slot]??null;
            if($found!==null){$c->inventory[$slot]=$c->inventory[$found];if($previous===null){unset($c->inventory[$found]);}else{$c->inventory[$found]=$previous;}}
            else{
                if($previous!==null){foreach(array_merge(range(36,44),range(9,35)) as $free){if(!isset($c->inventory[$free])){$c->inventory[$free]=$previous;break;}}}
                $c->inventory[$slot]=['item'=>$item,'count'=>1];
            }
            $c->heldSlot=$target;
        }
        // Full Ctrl-pick component copying is still tracked under the item-component parity issue.
        $c->send($this->version,'set_held_slot',Binary::writeVarInt($c->heldSlot));$this->syncInventory($c);
    }
}
