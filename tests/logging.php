<?php
declare(strict_types=1);
use PhpMinecraftJavaServer\Server\DailyLog;
$directory=sys_get_temp_dir().'/php-minecraft-log-'.bin2hex(random_bytes(6));mkdir($directory);
$now=strtotime('2026-10-25T12:00:00Z');
try{
    $old=$directory.'/server-'.gmdate('Y-m-d',$now-90*86400).'.log';$last=$directory.'/server-'.gmdate('Y-m-d',$now-89*86400).'.log';
    file_put_contents($old,'expired');file_put_contents($last,'retained');file_put_contents($directory.'/latest.log','unrelated');file_put_contents($directory.'/server-invalid.log','unrelated');
    $logger=new DailyLog($directory,90,static function()use(&$now):int{return $now;});
    check(!file_exists($old) && file_exists($last),'retain at most 90 dated logs');
    $logger->write("chat\nforged\rline\0");$today=$directory.'/server-2026-10-25.log';$lines=file($today);
    check(count($lines)===1 && str_contains($lines[0],'chat\\nforged\\rline\\0'),'chat cannot forge separate log records');
    $now+=86400;$logger->write('next day');check(!file_exists($last) && file_exists($directory.'/server-2026-10-26.log'),'retention rolls over without process restart');
    check(file_get_contents($directory.'/latest.log')==='unrelated' && file_exists($directory.'/server-invalid.log'),'retention preserves unrelated logs');
    rejects(fn()=>new DailyLog($directory,91),'retention cannot exceed 90 days');
    echo "PASS: daily logging, record boundaries and 90-day retention\n";
}finally{foreach(glob($directory.'/*') as $file){unlink($file);}rmdir($directory);}
