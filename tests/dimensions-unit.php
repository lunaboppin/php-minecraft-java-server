<?php
declare(strict_types=1);
use PhpMinecraftJavaServer\World\{DimensionGenerator,TerrainGenerator,World};
foreach(['the_nether','the_end'] as $dimension){
    $g=new DimensionGenerator($v,42,$dimension);$chunks=$g->chunk(0,0);
    check(count($chunks)===16 && count($chunks[0])===4096,'dimension has 16 full sections');
    check($chunks===(new DimensionGenerator($v,42,$dimension))->chunk(0,0),'dimension generation deterministic');
    $path=sys_get_temp_dir().'/php-minecraft-dimension-'.bin2hex(random_bytes(8)).'.sqlite';
    try{
        $w=new World($v,$path,'terrain',42,$dimension);
        check($w->minY===0 && $w->height===256,'dimension build limits');
        rejects(fn()=>$w->set(0,-1,0,1),'negative dimension height rejected');
        rejects(fn()=>$w->set(0,256,0,1),'dimension ceiling rejected');
        $w->set(-1,80,-1,$catalog->state('diamond_block'));check($w->get(-1,80,-1)===$catalog->state('diamond_block'),'dimension negative chunk edits');
        check(strlen($w->chunk(0,0))>1000,'dimension chunk lighting encoded');unset($w);
        $w=new World($v,$path,'terrain',42,$dimension);check($w->get(-1,80,-1)===$catalog->state('diamond_block'),'dimension edits persist');unset($w);
    }finally{foreach(glob($path.'*') as $file){unlink($file);}}
    if($dimension==='the_nether'){
        $all=array_merge(...$chunks);check(in_array($catalog->state('lava'),$all,true) && in_array($catalog->state('nether_quartz_ore'),$all,true) && in_array($catalog->state('netherrack'),$all,true),'Nether has lava caverns and ores');
        check($chunks[0][0]===$catalog->state('bedrock') && $chunks[7][3840]===$catalog->state('bedrock'),'Nether floor and ceiling bedrock');
    }else{
        check($g->column(400,0)['thickness']===0 && $g->column(0,0)['thickness']>0,'End central island and surrounding void');
        check($g->chunk(6,0)[4][4]===$catalog->state('obsidian'),'End arrival platform');
        $outer=false;for($x=1024;$x<4096;$x+=64){if($g->column($x,1234)['thickness']>5){$outer=true;break;}}check($outer,'End outer islands');
    }
}
$path=sys_get_temp_dir().'/php-minecraft-migration-'.bin2hex(random_bytes(8)).'.sqlite';
try{
    $w=new World($v,$path,'terrain',42);$w->setting('algorithm','terrain-v1');$w->setting('naturalSpawns',['0:0'=>true]);unset($w);
    $w=new World($v,$path,'terrain',42);check($w->sections(0,0)===(new TerrainGenerator($v,42,false))->chunk(0,0),'migration preserves explored v1 chunks');
    check($w->sections(1,0)===(new TerrainGenerator($v,42))->chunk(1,0),'new chunks use enhanced generation');unset($w);
    $w=new World($v,$path,'terrain',42);check($w->sections(0,0)===(new TerrainGenerator($v,42,false))->chunk(0,0),'legacy selection persists after restart');unset($w);
}finally{foreach(glob($path.'*') as $file){unlink($file);}}
echo "PASS: Nether/End terrain, dimension storage and legacy terrain migration\n";
