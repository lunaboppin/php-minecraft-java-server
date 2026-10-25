<?php
declare(strict_types=1);
use Jva\World\{TerrainGenerator,World,Inventory,Crafting};
$catalog=$v->catalog;
foreach(['oak_log'=>['axis'=>'x'],'oak_stairs'=>['facing'=>'east','half'=>'top'],'oak_door'=>['open'=>'true','half'=>'upper'],'water'=>['level'=>'5']] as $name=>$properties){
    $state=$catalog->state($name,$properties);check($catalog->blockName($state)===$name,'state name round trip');foreach($properties as $key=>$value){check($catalog->properties($state)[$key]===$value,'state property round trip');}
}
$slots=[36=>['item'=>$v->blocks['items']['stone'],'count'=>60]];
check(Inventory::add($slots,['item'=>$v->blocks['items']['stone'],'count'=>10],$catalog,[36,37])===0 && $slots[36]['count']===64 && $slots[37]['count']===6,'inventory merge and overflow');
$cursor=null;Inventory::pickup($slots,$cursor,36,true,$catalog);check($cursor['count']===32 && $slots[36]['count']===32,'right click picks half');
Inventory::pickup($slots,$cursor,37,true,$catalog);check($cursor['count']===31 && $slots[37]['count']===7,'right click places one');
$recipes=new Crafting($v->directory);$log=['item'=>$v->blocks['items']['oak_log'],'count'=>1];
check($recipes->match([$log,null,null,null],2)['result']===['item'=>$v->blocks['items']['oak_planks'],'count'=>4],'vanilla log to planks recipe');
$plank=['item'=>$v->blocks['items']['birch_planks'],'count'=>1];$stick=['item'=>$v->blocks['items']['stick'],'count'=>1];
check($recipes->match([$plank,$plank,$plank,null,$stick,null,null,$stick,null],3)['result']['item']===$v->blocks['items']['wooden_pickaxe'],'tag ingredients and shaped 3x3 recipe');
check($recipes->match([$plank,null,null,$plank],2)===null,'invalid crafting grid rejected');
check($recipes->smelt($v->blocks['items']['raw_iron'])['result']['item']===$v->blocks['items']['iron_ingot'],'vanilla smelting recipe');
$generator=new TerrainGenerator($v,12345);$other=new TerrainGenerator($v,12345);
$a=$generator->chunk(-1,0);$b=$generator->chunk(0,0);
check($b===$other->chunk(0,0) && $a===$other->chunk(-1,0),'terrain independent of generation order across negative boundaries');
check($b!==(new TerrainGenerator($v,54321))->chunk(0,0),'seed changes terrain');
check($b[0][0]===$v->blocks['states']['bedrock'],'bedrock world floor');
$ores=0;$caves=0;
foreach($b as $section=>$states){foreach($states as $index=>$state){if(str_contains($catalog->blockName($state),'_ore')){++$ores;}if($section<6 && $section>0 && $catalog->air($state)){++$caves;}}}
check($ores>100 && $caves>100,'underground contains ore veins and caves');
$biomes=[];$heights=[];
for($x=-1024;$x<=1024;$x+=128){for($z=-1024;$z<=1024;$z+=128){$column=$generator->column($x,$z);$biomes[$column['biome']]=true;$heights[]=$column['height'];}}
check(count($biomes)>=7 && isset($biomes['ocean'],$biomes['forest'],$biomes['river'],$biomes['desert']),'terrain contains multiple land and water biomes');
check(max($heights)-min($heights)>60,'terrain has substantial elevation variation');
$path=sys_get_temp_dir().'/jva-terrain-'.bin2hex(random_bytes(8)).'.sqlite';
try{
    $world=new World($v,$path,'terrain',12345);check(bin2hex(Jva\Protocol\Binary::writeLong($world->hashedSeed()))===$f['hashed_seed'],'seed hashing matches Mojang');check($world->spawn()===[0.5,65.0,0.5],'safe dry terrain spawn');
    check($world->get(-1,64,0)===$a[8][15],'world lookup agrees with generated neighbor section');
    $world->set(0,66,0,$catalog->state('torch'));$chunk=$world->chunk(0,0);check(strlen($chunk)>10000,'terrain chunk with lighting encoded');
    $uuid=Jva\Protocol\Binary::offlineUuid('Persistence');$world->savePlayer($uuid,['inventory'=>[36=>['item'=>1,'count'=>5]],'x'=>3.5]);
    $world->container([2,65,0],['slots'=>[0=>['item'=>1,'count'=>10]],'burn'=>100,'cook'=>50,'fuelTotal'=>1600,'position'=>[2,65,0]]);
    unset($world);$world=new World($v,$path,'terrain',12345);
    check($world->get(0,66,0)===$catalog->state('torch'),'terrain edits persist');
    check($world->loadPlayer($uuid)['x']===3.5 && $world->container([2,65,0])['slots'][0]['count']===10,'player and container persistence');
    unset($world);$rejected=false;try{new World($v,$path,'terrain',999);}catch(RuntimeException){$rejected=true;}check($rejected,'saved world rejects seed changes');
}finally{foreach(glob($path.'*') as $file){unlink($file);}}
echo "PASS: terrain determinism, biome diversity, ores/caves, recipes, inventories and persistence\n";
