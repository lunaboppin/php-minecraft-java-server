<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;
use PhpMinecraftJavaServer\Authentication\{Challenge,SessionVerifier,MojangSessionVerifier};
use PhpMinecraftJavaServer\Protocol\{Binary,Packets};

trait Authentication
{
    private ?Challenge $challenge=null;
    private ?SessionVerifier $sessionVerifier=null;
    private function initializeAuthentication(?SessionVerifier $sessions):void {
        if($this->config->onlineMode){$this->challenge=new Challenge();$this->sessionVerifier=$sessions??new MojangSessionVerifier();}
    }
    private function finishLogin(Client $c):void {
        $c->authenticationPhase='ready';
        if($this->config->compressionThreshold>=0){$c->send($this->version,'login_compression',Binary::writeVarInt($this->config->compressionThreshold));$c->compression=$this->config->compressionThreshold;}
        $c->send($this->version,'login_finished',Packets::loginFinished($c->uuid,$c->name,random_bytes(16),$c->profileProperties));
    }
    private function pollAuthentication():void {
        foreach($this->sessionVerifier?->poll()??[] as $id=>$result){
            $c=$this->clients[$id]??null;
            if($c===null || $c->closing || $c->authenticationPhase!=='verifying'){continue;}
            if(isset($result['error'])){$this->disconnect($c,$result['error']);continue;}
            $p=$result['profile'];
            $c->uuid=$p['uuid'];$c->name=$p['name'];$c->profileProperties=$p['properties'];$c->authenticated=true;
            foreach($this->clients as $other){if($other!==$c && !$other->closing && $other->authenticated && $other->uuid===$c->uuid){$this->disconnect($other,'You logged in from another location');}}
            $this->log('Verified account '.$c->name.' ('.Binary::formatUuid($c->uuid).')');$this->finishLogin($c);
        }
    }
}
