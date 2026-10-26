<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;

/** Explicit, infrequent operator updates; no recurring messages or automatic release-note forwarding. */
final class Announcements
{
    private float $nextPoll=0.0;
    private float $lastSent=-INF;
    private ?string $lastMessage=null;
    private readonly \Closure $clock;
    public function __construct(private readonly string $worldPath,?callable $clock=null){
        $this->clock=$clock===null?static fn():float=>hrtime(true)/1e9:\Closure::fromCallable($clock);
    }
    public function poll(bool $playersPresent):?string {
        if(!$playersPresent){return null;}
        $now=($this->clock)();
        if($now<$this->nextPoll || $now-$this->lastSent<300){return null;}
        $this->nextPoll=$now+1;
        $message=OperatorMessage::consume(dirname($this->worldPath).'/server-announcement.txt');
        if($message===null || $message===$this->lastMessage){return null;}
        $this->lastMessage=$message;$this->lastSent=$now;return $message;
    }
}
