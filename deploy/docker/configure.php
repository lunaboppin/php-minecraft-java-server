<?php
declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
use PhpMinecraftJavaServer\Server\Config;
$path=$argv[1]??throw new InvalidArgumentException('Configuration path required');
if(is_file($path)){exit(0);}
$values=['host'=>'0.0.0.0','port'=>25676,'worldPath'=>'data/world-terrain.sqlite','generator'=>'terrain','seed'=>42,'viewDistance'=>4,'logDirectory'=>'data/logs','logRetentionDays'=>90,
    'operators'=>array_values(array_filter(array_map('trim',explode(',',getenv('MINECRAFT_OPERATORS')?:'')),static fn(string $v):bool=>$v!==''))];
new Config(...$values);
$directory=dirname($path);if(!is_dir($directory) && !mkdir($directory,0750,true) && !is_dir($directory)){throw new RuntimeException('Cannot create data directory');}
$handle=@fopen($path,'x');if($handle===false){throw new RuntimeException('Cannot create configuration (existing files are never overwritten)');}
try{if(fwrite($handle,json_encode($values,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n")===false){throw new RuntimeException('Cannot write configuration');}}finally{fclose($handle);}
