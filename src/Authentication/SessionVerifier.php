<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Authentication;
interface SessionVerifier
{
    public function start(int $connection,string $username,string $hash):void;
    /** @return array<int,array{profile?:array,error?:string}> */
    public function poll():array;
    public function cancel(int $connection):void;
}
