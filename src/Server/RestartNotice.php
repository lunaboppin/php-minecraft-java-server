<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;

/** Read a one-shot deployment disconnect message next to the primary world database. */
final class RestartNotice
{
    public const FALLBACK='Server restarting. Please reconnect shortly.';
    public static function consume(string $worldPath):string {
        $path=dirname($worldPath).'/restart-reason.txt';
        if(!is_file($path)){return self::FALLBACK;}
        // Claim before reading, so a concurrent deployment cannot have its new notice removed.
        $claimed=$path.'.consuming-'.bin2hex(random_bytes(8));
        if(!@rename($path,$claimed)){return self::FALLBACK;}
        try{
            $bytes=@file_get_contents($claimed,false,null,0,1025);
            if($bytes===false || strlen($bytes)>1024 || preg_match('//u',$bytes)!==1){return self::FALLBACK;}
            $message=trim(preg_replace('/[\x00-\x20\x7f]+/u',' ',$bytes));
            return $message===''?self::FALLBACK:$message;
        }finally{@unlink($claimed);}
    }
}
