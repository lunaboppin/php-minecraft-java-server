<?php
declare(strict_types=1);
require_once __DIR__.'/AuthProbe.php';
$probe=new AuthProbe((int)$argv[1]);
try{$probe->challenge();$payload=$probe->until('login_disconnect');$message=json_decode((new \PhpMinecraftJavaServer\Protocol\Binary($payload))->string(),true,512,JSON_THROW_ON_ERROR);authNetworkCheck(($message['text']??null)==='Failed to verify username!' || ($message['translate']??null)==='multiplayer.disconnect.unverified_username','Expected explicit unverified-session rejection, got '.json_encode($message));file_put_contents($argv[2],json_encode($probe->capture,JSON_PRETTY_PRINT));echo "PASS: encrypted rejection of an unverified session\n";}
finally{$probe->close();}
