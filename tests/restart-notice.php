<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
use PhpMinecraftJavaServer\Server\{RestartNotice,Announcements};
$restartDirectory=sys_get_temp_dir().'/php-minecraft-notice-'.bin2hex(random_bytes(6));mkdir($restartDirectory);
try{
    $worldPath=$restartDirectory.'/world.sqlite';$notice=$restartDirectory.'/restart-reason.txt';
    if(RestartNotice::consume($worldPath)!==RestartNotice::FALLBACK){throw new RuntimeException('Missing restart notice fallback');}
    $description='Server restarting: density graphs added — please reconnect shortly.';
    file_put_contents($notice,"  ".$description."\n");
    if(RestartNotice::consume($worldPath)!==$description || file_exists($notice)){throw new RuntimeException('Descriptive notice not consumed exactly');}
    if(RestartNotice::consume($worldPath)!==RestartNotice::FALLBACK){throw new RuntimeException('Stale restart description reused');}
    foreach(["\n\t",str_repeat('x',1025),"\xff"] as $invalid){file_put_contents($notice,$invalid);if(RestartNotice::consume($worldPath)!==RestartNotice::FALLBACK || file_exists($notice)){throw new RuntimeException('Malformed restart notice fallback/cleanup');}}
    $now=0.0;$announcements=new Announcements($worldPath,static function()use(&$now):float{return $now;});$pending=$restartDirectory.'/server-announcement.txt';
    file_put_contents($pending,'Development: mob movement is being improved.');
    if($announcements->poll(false)!==null || !is_file($pending)){throw new RuntimeException('No-player update lost');}
    if($announcements->poll(true)!=='Development: mob movement is being improved.' || file_exists($pending)){throw new RuntimeException('Announcement delivery');}
    file_put_contents($pending,'Development: damage reactions are being improved.');$now=299.0;
    if($announcements->poll(true)!==null || !is_file($pending)){throw new RuntimeException('Announcement cooldown');}
    $now=300.0;if($announcements->poll(true)!=='Development: damage reactions are being improved.'){throw new RuntimeException('Pending update not delivered after cooldown');}
    file_put_contents($pending,'Development: damage reactions are being improved.');$now=600.0;
    if($announcements->poll(true)!==null || file_exists($pending)){throw new RuntimeException('Duplicate announcement repeated');}
    file_put_contents($pending,'Development: sounds are being improved.');$now=601.0;
    if($announcements->poll(true)!=='Development: sounds are being improved.'){throw new RuntimeException('Duplicate incorrectly delayed a new update');}
    if(glob($notice.'.consuming-*')!==[]){throw new RuntimeException('Claimed restart notice left behind');}
}finally{foreach(glob($restartDirectory.'/*') as $file){unlink($file);}rmdir($restartDirectory);}
echo "PASS: descriptive restart notices and rate-limited, non-repeating player announcements\n";
