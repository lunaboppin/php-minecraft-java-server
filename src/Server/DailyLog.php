<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;

/** Append-only UTC daily logs; prune only this logger's own dated files. */
final class DailyLog
{
    private string $prunedDay='';
    private readonly \Closure $clock;
    public function __construct(private readonly string $directory,private readonly int $retentionDays=90,?callable $clock=null) {
        if($retentionDays<1 || $retentionDays>90 || $directory===''){throw new \InvalidArgumentException('Log retention must be between 1 and 90 days');}
        $this->clock=$clock===null?static fn():int=>time():\Closure::fromCallable($clock);
        if(!is_dir($directory) && !mkdir($directory,0750,true) && !is_dir($directory)){throw new \RuntimeException('Cannot create log directory');}
        $this->prune(($this->clock)());
    }
    public function write(string $text):void {
        $now=($this->clock)();$this->prune($now);
        $text=str_replace(["\r","\n","\0"],['\\r','\\n','\\0'],$text);
        $file=$this->directory.'/server-'.gmdate('Y-m-d',$now).'.log';
        if(@file_put_contents($file,'['.gmdate('Y-m-d\TH:i:s\Z',$now).'] '.$text.PHP_EOL,FILE_APPEND|LOCK_EX)===false){throw new \RuntimeException('Cannot append server log');}
    }
    private function prune(int $now):void {
        $day=gmdate('Y-m-d',$now);if($day===$this->prunedDay){return;}
        $cutoff=gmdate('Y-m-d',$now-($this->retentionDays-1)*86400);
        foreach(glob($this->directory.'/server-????-??-??.log')?:[] as $file){
            if(is_link($file) || !is_file($file) || !preg_match('/^server-(\d{4}-\d{2}-\d{2})\.log$/D',basename($file),$match)){continue;}
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$match[1],new \DateTimeZone('UTC'));
            if($date!==false && $date->format('Y-m-d')===$match[1] && $match[1]<$cutoff && !unlink($file)){throw new \RuntimeException('Cannot remove expired server log');}
        }
        $this->prunedDay=$day;
    }
}
