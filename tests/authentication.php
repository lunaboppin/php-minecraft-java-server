<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
use PhpMinecraftJavaServer\Authentication\{Challenge,Cfb8};
use PhpMinecraftJavaServer\Protocol\{Binary,ProtocolException};
$f=json_decode(file_get_contents(__DIR__.'/../reference/26.3/authentication.json'),true,512,JSON_THROW_ON_ERROR);
function authCheck(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}}
$pem="-----BEGIN PRIVATE KEY-----\n".chunk_split(base64_encode(hex2bin($f['private_pkcs8'])),64,"\n")."-----END PRIVATE KEY-----\n";
$challenge=new Challenge(openssl_pkey_get_private($pem));$secret=hex2bin($f['secret']);$nonce=hex2bin($f['nonce']);
authCheck(bin2hex($challenge->public)===$f['public_der'],'Official public key encoding');
authCheck(bin2hex($challenge->packet($nonce))===$f['hello_packet'],'Official encryption request codec');
authCheck($challenge->secret(new Binary(hex2bin($f['key_packet'])),$nonce)===$secret,'Official RSA key packet');
authCheck($challenge->hash($secret)===$f['server_hash'],'Official server digest');
foreach($f['signed_sha1'] as $input=>$expected){authCheck(Challenge::signedHex(sha1($input,true))===$expected,'Java signed digest '.$input);}
authCheck(Challenge::signedHex(str_repeat("\0",20))==='0','Zero signed digest');
foreach([1,3,15,16,17,64,513] as $size){
    $encrypt=new Cfb8($secret);$decrypt=new Cfb8($secret,true);$cipher='';$decoded='';
    foreach(str_split(hex2bin($f['plaintext']),$size) as $part){$cipher.=$encrypt->transform($part);}
    authCheck(bin2hex($cipher)===$f['ciphertext'],'Official continuous cipher, split '.$size);
    foreach(str_split($cipher,$size+1) as $part){$decoded.=$decrypt->transform($part);}
    authCheck(bin2hex($decoded)===$f['plaintext'],'Independent fragmented decryption '.$size);
}
foreach([substr(hex2bin($f['key_packet']),0,-1),hex2bin($f['key_packet'])."\0"] as $bad){
    try{$challenge->secret(new Binary($bad),$nonce);throw new RuntimeException('Accepted malformed key packet');}catch(ProtocolException){}
}
try{$challenge->secret(new Binary(hex2bin($f['key_packet'])),"\0\0\0\0");throw new RuntimeException('Accepted another connection challenge');}catch(ProtocolException){}
$fresh=new Challenge();authCheck(strlen($fresh->public)>128 && $fresh->public!==$challenge->public,'Fresh runtime key generation');
echo "PASS: official RSA challenge codecs, signed session hashes and fragmented continuous AES/CFB8 streams\n";
use PhpMinecraftJavaServer\Authentication\Profile;
use PhpMinecraftJavaServer\Server\Client;
use PhpMinecraftJavaServer\World\World;
use PhpMinecraftJavaServer\Protocol\Version;
$profile=['id'=>'112233445566778899aabbccddeeff00','name'=>'ParityProbe','properties'=>[['name'=>'textures','value'=>'test-texture','signature'=>'test-signature']]];
$parsed=Profile::parse(json_encode($profile),'ParityProbe');authCheck($parsed['name']==='ParityProbe' && bin2hex($parsed['uuid'])===$profile['id'] && $parsed['properties']===$profile['properties'],'Session service owns identity and properties');
foreach(['{}','{',json_encode([...$profile,'id'=>'invalid']),json_encode([...$profile,'properties'=>['bad']]),json_encode([...$profile,'properties'=>[['name'=>'textures','value'=>3]]])] as $bad){
    try{Profile::parse($bad,'ParityProbe');throw new RuntimeException('Accepted malformed/mismatched session profile');}catch(InvalidArgumentException|JsonException){}
}
$socket=fopen('php://memory','r+');$client=new Client($socket,'local-test');$cipher=new Cfb8($secret);$plaintext='a following encrypted frame';$client->input=$cipher->transform($plaintext);$client->encrypt($secret);
authCheck($client->input===$plaintext,'Decrypt buffered bytes following key response');fclose($socket);
$path=sys_get_temp_dir().'/php-minecraft-auth-'.bin2hex(random_bytes(8)).'.sqlite';
try{
    $w=new World(new Version(),$path,'flat');$uuid=$parsed['uuid'];$w->savePlayer($uuid,['marker'=>'offline']);$w->savePlayer($uuid,['marker'=>'online'],true);
    authCheck($w->loadPlayer($uuid)['marker']==='offline' && $w->loadPlayer($uuid,true)['marker']==='online','Online and offline records are separate');
    unset($w);$w=new World(new Version(),$path,'flat');authCheck($w->loadPlayer($uuid)['marker']==='offline' && $w->loadPlayer($uuid,true)['marker']==='online','Both identity namespaces survive reopen');
}finally{unset($w);foreach(glob($path.'*') as $file){unlink($file);}}
echo "PASS: authenticated profile validation, buffered encryption transition and separate persistent identities\n";
use PhpMinecraftJavaServer\Authentication\Discovery;
$discovery=file_get_contents(__DIR__.'/fixtures/services-discovery.json');
authCheck(Discovery::sessionEndpoint($discovery)==='https://sessionserver.mojang.com/session/minecraft/hasJoined','Official production discovery response');
foreach([str_replace('https://sessionserver','http://sessionserver',$discovery),str_replace('https://sessionserver','https://attacker@sessionserver',$discovery),str_replace('"prod"','"staging"',$discovery),'{}','invalid'] as $bad){
    try{Discovery::sessionEndpoint($bad);throw new RuntimeException('Accepted invalid authentication discovery');}catch(InvalidArgumentException|JsonException){}
}
$withoutName=$profile;unset($withoutName['name']);authCheck(Profile::parse(json_encode($withoutName),'ParityProbe')['name']==='ParityProbe','Official HasJoined response does not require a name field');
echo "PASS: official discovery response and rejection of insecure/malformed endpoints\n";
