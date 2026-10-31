<?php
declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';
use PhpMinecraftJavaServer\Authentication\Cfb8;
use PhpMinecraftJavaServer\Protocol\{Binary,Framing,Version};

final class AuthProbe {
    private mixed $socket;
    private ?Cfb8 $encrypt=null;
    private ?Cfb8 $decrypt=null;
    private int $compression=-1;
    private string $buffer='';
    private Version $version;
    public string $state='handshake';
    public array $capture=[];
    public function __construct(int $port){$this->version=new Version();$this->socket=stream_socket_client('tcp://127.0.0.1:'.$port);stream_set_timeout($this->socket,5);}
    public function send(string $name,string $payload='',bool $fragment=false):void {
        $id=$this->version->id($this->state,$name,'serverbound');$this->capture[]=['state'=>$this->state,'direction'=>'serverbound','hex'=>bin2hex(Binary::writeVarInt($id).$payload)];
        $frame=Framing::encode($id,$payload,$this->compression);
        $frame=$this->encrypt?->transform($frame)??$frame;
        foreach($fragment?str_split($frame,3):[$frame] as $bytes){while($bytes!==''){$n=fwrite($this->socket,$bytes);if(!$n){throw new RuntimeException('Client write failed');}$bytes=substr($bytes,$n);}}
    }
    public function receive():array {
        while(($frame=Framing::extract($this->buffer,$this->compression))===null){$bytes=fread($this->socket,65536);if($bytes===false || $bytes===''){throw new RuntimeException('Client read failed');}$this->buffer.=$this->decrypt?->transform($bytes)??$bytes;}
        $this->capture[]=['state'=>$this->state,'direction'=>'clientbound','hex'=>bin2hex($frame)];
        $reader=new Binary($frame);$id=$reader->varInt();$payload=$reader->bytes($reader->remaining());
        if($this->state==='login' && $id===$this->version->id('login','login_compression')){$this->compression=(new Binary($payload))->varInt();}
        // Like the client, acknowledge each chunk batch so the server keeps sending chunks.
        if($this->state==='play' && $id===$this->version->id('play','chunk_batch_finished')){$this->send('chunk_batch_received',pack('G',9.0));}
        return [$id,$payload];
    }
    public function until(string $name):string {
        for($i=0;$i<1000;++$i){[$id,$payload]=$this->receive();if($id===$this->version->id($this->state,$name)){return $payload;}}
        throw new RuntimeException('Missing packet '.$name);
    }
    public function challenge(bool $invalid=false,?string $replayed=null,string $name='ParityProbe'):string {
        $this->send('intention',Binary::writeVarInt(777).Binary::writeString('localhost').pack('n',25565)."\2");$this->state='login';
        $this->send('hello',Binary::writeString($name).str_repeat("\x44",16));
        $r=new Binary($this->until('hello'));authNetworkCheck($r->string()==='','Empty official server ID');$der=$r->bytes($r->varInt());$nonce=$r->bytes($r->varInt());authNetworkCheck($r->bool(),'Server requires authentication');$r->end();
        $public="-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n";
        $secret=random_bytes(16);openssl_public_encrypt($secret,$encrypted,$public,OPENSSL_PKCS1_PADDING);openssl_public_encrypt($invalid?str_repeat("\0",4):$nonce,$token,$public,OPENSSL_PKCS1_PADDING);
        $packet=Binary::writeVarInt(strlen($encrypted)).$encrypted.Binary::writeVarInt(strlen($token)).$token;
        $this->send('key',$replayed??$packet,true);
        if(!$invalid && $replayed===null){$this->encrypt=new Cfb8($secret);$this->decrypt=new Cfb8($secret,true);$this->buffer=$this->decrypt->transform($this->buffer);}
        return $packet;
    }
    /** Returns the play login payload, which starts with the entity ID. */
    public function configure():string {
        $this->send('login_acknowledged');$this->state='configuration';$this->until('select_known_packs');$this->send('select_known_packs',"\0");$this->until('finish_configuration');$this->send('finish_configuration');$this->state='play';return $this->until('login');
    }
    public function close():void{fclose($this->socket);}
}
function authNetworkCheck(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}}
