<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
use PhpMinecraftJavaServer\World\Generation\DensityGraph;
$densityFixture=json_decode(file_get_contents(__DIR__.'/../reference/26.3/worldgen-density.json'),true,512,JSON_THROW_ON_ERROR);
$noiseFixture=json_decode(file_get_contents(__DIR__.'/../reference/26.3/worldgen-noise.json'),true,512,JSON_THROW_ON_ERROR)['normal_parameters'];
$densityChecks=0;$densityFailures=[];
// Fresh samplers in reversed definition/point order verify named seed independence
// and ensure caching does not make terrain depend on which chunk is requested first.
foreach([false,true] as $reverse){
    $graphs=[];$observations=$reverse?array_reverse($densityFixture['observations']):$densityFixture['observations'];
    foreach($observations as $observation){
        $graph=$graphs[$observation['seed']]??=new DensityGraph($densityFixture['definitions'],$noiseFixture,(int)$observation['seed']);
        $samples=$reverse?array_reverse($observation['samples']):$observation['samples'];
        foreach($samples as $sample){
            $actual=bin2hex(pack('G',$graph->sample($observation['name'],...$sample['point'])));++$densityChecks;
            if($actual!==$sample['bits']){$densityFailures[]=$observation['name'].' seed '.$observation['seed'].' '.json_encode($sample['point']).' expected '.$sample['bits'].' got '.$actual;}
        }
    }
}
$cyclic=new DensityGraph(['minecraft:a'=>'minecraft:b','minecraft:b'=>'minecraft:a','minecraft:valid'=>1],[],42);
foreach(['minecraft:a','minecraft:missing'] as $name){
    try{$cyclic->sample($name,0,0,0);throw new RuntimeException('Accepted invalid density reference');}catch(InvalidArgumentException){++$densityChecks;}
}
if($cyclic->sample('minecraft:valid',0,0,0)!==1.0){throw new RuntimeException('Invalid reference poisoned subsequent density compilation');}++$densityChecks;
if($densityFailures){throw new RuntimeException(count($densityFailures).' mismatched density samples of '.$densityChecks." checks:\n".implode("\n",array_slice($densityFailures,0,20)));}
echo 'PASS: '.$densityChecks.' official density checks across '.count($densityFixture['definitions'])." built-in graphs, both request orders and reference failures\n";
