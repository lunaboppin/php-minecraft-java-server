<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Authentication;

final class Profile
{
    /** Accept identities only from a successful session-service response, never the client's UUID. */
    public static function parse(string $json,string $requested):array {
        $p=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($p) || !is_string($p['id']??null) || !preg_match('/\A[0-9a-fA-F]{32}\z/D',$p['id']) || !preg_match('/\A[A-Za-z0-9_]{1,16}\z/D',$requested)){throw new \InvalidArgumentException('Invalid authenticated profile');}
        $properties=$p['properties']??[];
        if(!is_array($properties) || !array_is_list($properties) || count($properties)>16){throw new \InvalidArgumentException('Invalid profile properties');}
        foreach($properties as $property){
            foreach(['name'=>64,'value'=>32767] as $key=>$limit){if(!is_string($property[$key]??null) || strlen($property[$key])>$limit || preg_match('//u',$property[$key])!==1){throw new \InvalidArgumentException('Invalid profile property');}}
            if(isset($property['signature']) && (!is_string($property['signature']) || strlen($property['signature'])>32767 || preg_match('//u',$property['signature'])!==1)){throw new \InvalidArgumentException('Invalid profile signature');}
        }
        return ['uuid'=>hex2bin($p['id']),'name'=>$requested,'properties'=>$properties];
    }
}
