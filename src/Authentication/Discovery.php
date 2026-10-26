<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Authentication;
final class Discovery
{
    public const URL='https://discovery.minecraftservices.com/minecraft/client';
    public static function sessionEndpoint(string $json):string {
        $data=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        $uri=$data['discovery']['session']['endpoints']['verify']['uri']??null;
        if(($data['environment']??null)!=='prod' || ($data['product']??null)!=='minecraft' || !is_string($uri)){throw new \InvalidArgumentException('Invalid Minecraft services discovery');}
        $url=parse_url($uri);
        if($url===false || ($url['scheme']??null)!=='https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || isset($url['query']) || (isset($url['port']) && $url['port']!==443)){throw new \InvalidArgumentException('Invalid session verification URI');}
        return $uri;
    }
}
