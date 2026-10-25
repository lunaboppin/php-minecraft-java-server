<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;
use PhpMinecraftJavaServer\Protocol\Packets;
use PhpMinecraftJavaServer\World\{Collision,Noise};

/** Shared movement primitives. Complete species goal stacks/navigation remain tracked in #35/#44. */
trait MobMovement
{
    private const FLYING_MOBS=['allay','bat','bee','blaze','ender_dragon','ghast','happy_ghast','parrot','phantom','vex','wither'];
    private const SWIMMING_MOBS=['axolotl','cod','dolphin','elder_guardian','glow_squid','guardian','nautilus','pufferfish','salmon','squid','tadpole','tropical_fish','zombie_nautilus'];
    private function moveMob(array &$mob,Noise $noise):void {
        $kind=$mob['kind'];$info=$this->version->catalog->mobs['entities'][$kind];$collision=new Collision($this->version->catalog);
        $flying=in_array($kind,self::FLYING_MOBS,true);$swimming=in_array($kind,self::SWIMMING_MOBS,true);
        if($this->ticks>=$mob['nextWander']){
            $hash=$noise->hash($mob['id'],$this->ticks>>5,0,121);$mob['targetX']=$mob['x']+$hash%17-8;$mob['targetZ']=$mob['z']+intdiv($hash,19)%17-8;
            $mob['targetY']=$mob['y']+(($flying || $swimming)?intdiv($hash,47)%5-2:0);$mob['nextWander']=$this->ticks+80+$hash%160;
        }
        $dx=$mob['targetX']-$mob['x'];$dz=$mob['targetZ']-$mob['z'];$dy=($mob['targetY']??$mob['y'])-$mob['y'];
        $distance=sqrt($dx*$dx+$dz*$dz+(($flying || $swimming)?$dy*$dy:0));
        $speed=min(0.5,($info['attributes']['movement_speed']??0.2)*0.6);$scale=$distance>0.2?min($speed,$distance)/$distance:0;
        $desired=[$dx*$scale,$dy*$scale,$dz*$scale];$before=[$mob['x'],$mob['y'],$mob['z']];
        if($kind==='giant'){$desired=[0.0,0.0,0.0];}
        $inWater=$this->version->catalog->blockName($this->world->get((int)floor($mob['x']),(int)floor($mob['y']),(int)floor($mob['z'])))==='water';
        if($swimming && !$inWater){$desired[0]=0;$desired[2]=0;}
        if($swimming && $inWater){
            $target=$this->world->get((int)floor($mob['x']+$desired[0]),(int)floor($mob['y']+$desired[1]),(int)floor($mob['z']+$desired[2]));
            if($this->version->catalog->blockName($target)!=='water'){$desired=[0.0,0.0,0.0];$mob['nextWander']=$this->ticks;}
        }
        $box=Collision::bounds($mob['x'],$mob['y'],$mob['z'],$info['width'],$info['height']);
        if($flying || ($swimming && $inWater)){
            $motion=$collision->move($this->world,$box,$desired);
            foreach(['x','y','z'] as $axis=>$field){$mob[$field]+=$motion[$axis];}$mob['velocity']=[0.0,0.0,0.0];
        }else{
            $mob['velocity']??=[0.0,0.0,0.0];
            // Resolve four 20 Hz gravity steps between AI updates, against actual voxel shapes.
            for($i=0;$i<4;++$i){
                $gravity=$info['attributes']['gravity']??0.08;
                $mob['velocity'][1]=$inWater?min(0.1,$mob['velocity'][1]+0.02):max(-3.92,($mob['velocity'][1]-$gravity)*0.98);
                $motion=$collision->move($this->world,$box,[$desired[0]/4,$mob['velocity'][1],$desired[2]/4]);
                $mob['ground']=$mob['velocity'][1]<0 && abs($motion[1]-$mob['velocity'][1])>1.0e-7;
                if(abs($motion[1]-$mob['velocity'][1])>1.0e-7){$mob['velocity'][1]=0.0;}
                $blocked=abs($motion[0]-$desired[0]/4)>1.0e-7 || abs($motion[2]-$desired[2]/4)>1.0e-7;
                if($blocked && $mob['ground']){$mob['velocity'][1]=$info['attributes']['jump_strength']??0.42;}
                foreach(['x','y','z'] as $axis=>$field){$mob[$field]+=$motion[$axis];$box[$axis]+=$motion[$axis];$box[$axis+3]+=$motion[$axis];}
            }
        }
        if($mob['x']!==$before[0] || $mob['z']!==$before[2]){$mob['yaw']=rad2deg(atan2(-$dx,$dz));}
        if([$mob['x'],$mob['y'],$mob['z']]===$before){return;}
        foreach($this->localPlayers() as $viewer){if(isset($viewer->entities[$mob['id']])){
            $viewer->send($this->version,'teleport_entity',Packets::teleportEntity($mob['id'],$mob['x'],$mob['y'],$mob['z'],$mob['yaw'],0,$mob['ground']??false));
            $viewer->send($this->version,'rotate_head',Packets::headRotation($mob['id'],$mob['yaw']));
        }}
    }
}
