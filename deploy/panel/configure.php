<?php
declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
use PhpMinecraftJavaServer\Server\Config;

// Panel variables seed the first configuration. Reinstall/restart keeps user settings intact.
if(is_file('server.json')){Config::load('server.json');echo "Keeping existing server.json\n";return;}
$settings=json_decode(file_get_contents(__DIR__.'/../../server.example.json'),true,512,JSON_THROW_ON_ERROR);
$settings['host']='0.0.0.0';
$online=getenv('ONLINE_MODE');if($online!==false && $online!==''){if(!in_array($online,['true','false'],true)){throw new InvalidArgumentException('ONLINE_MODE must be true or false');}$settings['onlineMode']=$online==='true';}
$integers=[
    'SERVER_PORT'=>['port',1,65535], 'WORLD_SEED'=>['seed',-2147483648,2147483647],
    'MAX_PLAYERS'=>['maxPlayers',1,100], 'VIEW_DISTANCE'=>['viewDistance',2,4],
    'GAME_MODE'=>['defaultGameMode',0,3], 'DIFFICULTY'=>['difficulty',0,3],
];
foreach($integers as $name=>[$key,$min,$max]){
    $value=getenv($name);if($value===false || $value===''){continue;}
    $number=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
    if($number===false){throw new InvalidArgumentException($name.' must be an integer between '.$min.' and '.$max);}
    $settings[$key]=$number;
}
$motd=getenv('SERVER_MOTD');if($motd!==false){$settings['motd']=$motd;}
$operators=getenv('OPERATORS');if($operators!==false){$settings['operators']=array_values(array_filter(array_map('trim',explode(',',$operators)),static fn(string $s):bool=>$s!==''));}
// Validate every value before creating any configuration file.
new Config(...$settings);
$json=json_encode($settings,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
$handle=fopen('server.json','x');if($handle===false){throw new RuntimeException('Cannot create server.json');}
try{if(fwrite($handle,$json)!==strlen($json)){throw new RuntimeException('Cannot write server.json');}}finally{fclose($handle);}
echo "Created server.json from initial panel settings\n";
