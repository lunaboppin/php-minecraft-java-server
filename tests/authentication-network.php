<?php
declare(strict_types=1);
require_once __DIR__.'/support/AuthProbe.php';
use PhpMinecraftJavaServer\Protocol\Binary;
$directory=sys_get_temp_dir().'/php-minecraft-auth-network-'.bin2hex(random_bytes(8));mkdir($directory);$process=null;
try {
    $world=$directory.'/world.sqlite';
    foreach(['verified','verified-restart','unavailable'] as $mode){
        file_put_contents($directory.'/server.json',json_encode(['onlineMode'=>true,'host'=>'127.0.0.1','port'=>0,'generator'=>'flat','worldPath'=>$world,'logDirectory'=>$directory.'/logs','operators'=>['ParityProbe']]));
        $log=$directory.'/'.$mode.'.log';$process=proc_open([PHP_BINARY,__DIR__.'/support/auth-server.php',$directory.'/server.json',str_starts_with($mode,'verified')?'verified':'unavailable'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes);
        $deadline=microtime(true)+15;$port=null;
        do{if(preg_match('/Listening on 127\.0\.0\.1:(\d+)/',@file_get_contents($log)?:'',$match)){$port=(int)$match[1];break;}usleep(20000);}while(microtime(true)<$deadline);
        authNetworkCheck($port!==null,'Fixture server startup: '.file_get_contents($log));
        $probe=new AuthProbe($port);$response=$probe->challenge();
        if(str_starts_with($mode,'verified')){
            $r=new Binary($probe->until('login_finished'));authNetworkCheck(bin2hex($r->bytes(16))==='112233445566778899aabbccddeeff00','Ignore spoofed hello UUID');authNetworkCheck($r->string()==='ParityProbe','Verified name');authNetworkCheck($r->varInt()===1 && $r->string()==='textures' && $r->string()==='fixture-value' && $r->bool() && $r->string()==='fixture-signature','Verified profile properties');$r->bytes(16);$r->end();
            $probe->configure();if($mode==='verified'){$probe->send('chat_command',Binary::writeString('give @s minecraft:diamond 2'));}
            // A clock update fences the simulation after the operator command.
            $probe->until('set_time');$probe->until('set_time');$probe->close();usleep(100000);
            $bad=new AuthProbe($port);$bad->challenge(true);$bad->until('login_disconnect');$bad->close();
            $replay=new AuthProbe($port);$replay->challenge(replayed:$response);$replay->until('login_disconnect');$replay->close();
        }else{$probe->until('login_disconnect');$probe->close();}
        proc_terminate($process);proc_close($process);$process=null;
    }
    $db=new PDO('sqlite:'.$world);$rows=$db->query('SELECT uuid,data FROM players')->fetchAll(PDO::FETCH_ASSOC);authNetworkCheck(count($rows)===1 && $rows[0]['uuid']==='online:112233445566778899aabbccddeeff00','Persist only verified identity');$data=json_decode($rows[0]['data'],true);$found=false;foreach($data['inventory'] as $stack){if(($stack['count']??0)===2){$found=true;}}authNetworkCheck($found,'Verified operator command persisted');
    echo "PASS: encrypted login/configuration, verified identity/properties/operator, invalid/replayed challenge rejection and unavailable service\n";
}finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}/* Retain isolated logs/evidence for failed runs. */}
