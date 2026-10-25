<?php
use PhpMinecraftJavaServer\World\Collision;
$path=sys_get_temp_dir().'/php-minecraft-collision-'.bin2hex(random_bytes(8)).'.sqlite';
try {
    $w=new \PhpMinecraftJavaServer\World\World($v,$path,'flat');$collision=new Collision($v->catalog);
    check($collision->shape(0)===[],'air has no collision');
    $slab=$v->catalog->state('stone_slab',['type'=>'bottom']);$w->set(0,64,0,$slab);
    check(abs($collision->eggHeight($w,0,65,0,0.9,1.4,true)-64.5)<1e-8,'spawn egg rests on bottom slab');
    $w->set(0,64,0,$v->catalog->state('oak_fence'));
    check(abs($collision->eggHeight($w,0,65,0,0.9,1.4,true)-65.5)<1e-8,'spawn egg rests on tall fence collision');
    $w->set(0,64,0,0);
    $delta=$collision->move($w,Collision::bounds(.5,70,.5,.9,1.4),[0,-20,0]);
    check(abs($delta[1]+6)<1e-8,'swept collision lands on ground without tunneling');
    $w->set(1,64,0,$v->catalog->state('stone'));
    $delta=$collision->move($w,Collision::bounds(.5,64,.5,.9,1.4),[3,0,0]);
    check(abs($delta[0]-.05)<1e-8,'swept horizontal collision stops at wall');
    $w->set(0,64,3,$v->catalog->state('water'));
    $hit=$collision->sourceRay($w,[.5,64.5,.5],[0,0,1],5);
    check($hit!==null && $hit['fluid'] && $hit['position']===[0,64,3],'POV ray selects source water');
    $w->set(0,64,1,$v->catalog->state('short_grass'));
    $hit=$collision->sourceRay($w,[.5,64.5,.5],[0,0,1],5);
    check($hit!==null && !$hit['fluid'] && $hit['position']===[0,64,1],'outline-only grass occludes egg source ray');
    // Air edits, reverted edits and negative coordinates must survive cache eviction/reopen.
    for($i=-40;$i<40;++$i){$w->set($i*16,63,0,0);check($w->get($i*16,63,0)===0,'cached air overlay');}
    for($i=-40;$i<40;++$i){check($w->get($i*16,63,0)===0,'air overlay survives eviction');$w->set($i*16,63,0,$v->catalog->state('grass_block'));check($w->get($i*16,63,0)===$v->catalog->state('grass_block'),'reverted overlay uses procedural base');}
    unset($w);$w=new \PhpMinecraftJavaServer\World\World($v,$path,'flat');
    check($w->get(1,64,0)===$v->catalog->state('stone'),'cached writes persist after reopen');
    echo "PASS: voxel collision, slab/fence spawn height, swept movement and cached block-overlay lifecycle\n";
} finally {unset($w);foreach(glob($path.'*') as $file){unlink($file);}}
