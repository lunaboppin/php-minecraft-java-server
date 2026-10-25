<?php
declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
use PhpMinecraftJavaServer\Protocol\Binary;
try{
    $port=(int)(getenv('SERVER_PORT')?:25676);$socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,1);
    if($socket===false){exit(1);}stream_set_timeout($socket,1);
    $hello="\0".Binary::writeVarInt(777).Binary::writeString('localhost').pack('n',$port)."\1";
    fwrite($socket,Binary::writeVarInt(strlen($hello)).$hello."\1\0");
    $length=0;for($i=0;$i<3;++$i){$byte=fread($socket,1);if($byte===''){exit(1);}$n=ord($byte);$length|=($n&127)<<($i*7);if($n<128){break;}}
    if($length<1 || $length>65535){exit(1);}$bytes='';while(strlen($bytes)<$length){$part=fread($socket,$length-strlen($bytes));if($part===false || $part===''){exit(1);}$bytes.=$part;}fclose($socket);
    $reader=new Binary($bytes);if($reader->varInt()!==0){exit(1);}$status=json_decode($reader->string(),true,512,JSON_THROW_ON_ERROR);$reader->end();
    exit(($status['version']['protocol']??null)===777?0:1);
}catch(Throwable){exit(1);}
