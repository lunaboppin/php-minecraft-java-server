<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
use PhpMinecraftJavaServer\Server\RestartNotice;
$restartDirectory=sys_get_temp_dir().'/php-minecraft-notice-'.bin2hex(random_bytes(6));mkdir($restartDirectory);
try{
    $worldPath=$restartDirectory.'/world.sqlite';$notice=$restartDirectory.'/restart-reason.txt';
    if(RestartNotice::consume($worldPath)!==RestartNotice::FALLBACK){throw new RuntimeException('Missing restart notice fallback');}
    $description='Server restarting: density graphs added — please reconnect shortly.';
    file_put_contents($notice,"  ".$description."\n");
    if(RestartNotice::consume($worldPath)!==$description || file_exists($notice)){throw new RuntimeException('Descriptive notice not consumed exactly');}
    if(RestartNotice::consume($worldPath)!==RestartNotice::FALLBACK){throw new RuntimeException('Stale restart description reused');}
    foreach(["\n\t",str_repeat('x',1025),"\xff"] as $invalid){file_put_contents($notice,$invalid);if(RestartNotice::consume($worldPath)!==RestartNotice::FALLBACK || file_exists($notice)){throw new RuntimeException('Malformed restart notice fallback/cleanup');}}
    if(glob($notice.'.consuming-*')!==[]){throw new RuntimeException('Claimed restart notice left behind');}
}finally{foreach(glob($restartDirectory.'/*') as $file){unlink($file);}rmdir($restartDirectory);}
echo "PASS: descriptive UTF-8 restart notice, single consumption and malformed/missing fallback\n";
