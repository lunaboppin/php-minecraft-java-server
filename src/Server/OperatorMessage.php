<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;

/** Bounded plain text from an operator-owned one-shot file; never executes commands. */
final class OperatorMessage
{
    public static function consume(string $path):?string {
        if(!is_file($path)){return null;}
        // Claim before reading, so a concurrent deployment cannot have its new notice removed.
        $claimed=$path.'.consuming-'.bin2hex(random_bytes(8));
        if(!@rename($path,$claimed)){return null;}
        try{
            $bytes=@file_get_contents($claimed,false,null,0,1025);
            if($bytes===false || strlen($bytes)>1024 || preg_match('//u',$bytes)!==1){return null;}
            $message=trim(preg_replace('/[\x00-\x20\x7f]+/u',' ',$bytes));
            return $message===''?null:$message;
        }finally{@unlink($claimed);}
    }
}
